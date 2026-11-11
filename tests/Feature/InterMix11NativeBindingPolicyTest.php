<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;

final readonly class FoundationNativeBindingValue
{
    public function __construct(public mixed $value = 'unbound') {}

    public static function fromBoundValue(mixed $value): self
    {
        return new self($value);
    }
}

final readonly class FoundationNativeBindingConsumer
{
    public function __construct(public FoundationNativeBindingValue $probe) {}
}

it('keeps optional InterMix class auto-binding disabled in every native Foundation runtime', function (
    RuntimeMode $mode,
    string $runtimeType,
): void {
    $context = FoundationBuildContext::fromConfig(
        new ConfigRepository(['app' => ['env' => 'testing']]),
        $mode,
        [],
    );
    $builder = FoundationGraph::compose($context);
    $builder->value('probe', 0);
    $builder->autowire('foundation.native.binding.consumer', FoundationNativeBindingConsumer::class);

    $path = sys_get_temp_dir() . '/foundation-native-binding-' . bin2hex(random_bytes(8)) . '.php';

    try {
        if ($runtimeType === 'development') {
            $runtime = $builder->build();
        } else {
            $report = $builder->compile($path, strict: true);
            expect($report['skipped'])->toBe([]);
            $runtime = $runtimeType === 'compiled'
                ? ContainerBuilder::loadProductionArtifact($path, $report['graph'], $context->environment)
                : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
        }

        $consumer = $runtime->get('foundation.native.binding.consumer');
        if (!$consumer instanceof FoundationNativeBindingConsumer) {
            throw new \UnexpectedValueException('Expected a native binding consumer.');
        }
        expect($consumer)->toBeInstanceOf(FoundationNativeBindingConsumer::class)
            ->and($consumer->probe->value)->toBe('unbound')
            ->and($runtime->invoke(static fn(int $probe): int => $probe, ['probe' => 0]))->toBe(0)
            ->and($runtime->invoke(static fn(bool $probe): bool => $probe, ['probe' => false]))->toBeFalse()
            ->and($runtime->invoke(static fn(?int $probe): ?int => $probe, ['probe' => null]))->toBeNull()
            ->and(fn(): mixed => $runtime->invoke(
                static fn(FoundationNativeBindingValue $probe): FoundationNativeBindingValue => $probe,
                ['probe' => 7],
            ))->toThrow(TypeError::class);
    } finally {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        }
        $root = $path . '.builds';
        if (is_dir($root)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo) {
                    throw new \UnexpectedValueException('Expected a filesystem iterator entry.');
                }

                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
})->with([
    [RuntimeMode::Web, 'development'],
    [RuntimeMode::Cli, 'development'],
    [RuntimeMode::Worker, 'development'],
    [RuntimeMode::Scheduler, 'development'],
    [RuntimeMode::Web, 'compiled'],
    [RuntimeMode::Cli, 'compiled'],
    [RuntimeMode::Worker, 'compiled'],
    [RuntimeMode::Scheduler, 'compiled'],
    [RuntimeMode::Web, 'prevalidated'],
    [RuntimeMode::Cli, 'prevalidated'],
    [RuntimeMode::Worker, 'prevalidated'],
    [RuntimeMode::Scheduler, 'prevalidated'],
]);

it('isolates native InterMix definition-cache entries across Foundation environments', function (): void {
    $pool = Cache::memory('foundation-imx11-env-' . bin2hex(random_bytes(8)));
    $build = static function (?string $environment, mixed $value) use ($pool): mixed {
        $config = new ConfigRepository([
            'app' => ['container' => ['environment' => $environment]],
        ]);
        $context = FoundationBuildContext::fromConfig($config, RuntimeMode::Cli, []);
        $builder = FoundationGraph::compose($context);
        $builder->definitionCache($pool, 'foundation-intermix-test', 'release-1', failOpen: false)
            ->factory('cached.probe', static fn(): mixed => $value)
            ->cacheDefinition('cached.probe');

        return $builder->build()->get('cached.probe');
    };

    // The absence of an environment is distinct from literal "default";
    // previously cached int zero must be a hit, not a fallback miss.
    expect($build(null, 0))->toBe(0)
        ->and($build(null, 99))->toBe(0)
        ->and($build('default', 'literal-default'))->toBe('literal-default')
        ->and($build('production', false))->toBeFalse()
        ->and($build('default', 'unexpected'))->toBe('literal-default')
        ->and($build('production', true))->toBeFalse();
});

it('caches only safe scalar null and nested values from native Foundation InterMix factories', function (): void {
    $pool = Cache::memory('foundation-imx11-types-' . bin2hex(random_bytes(8)));
    $build = static function (array $values) use ($pool): \Infocyph\InterMix\DI\Container {
        $context = FoundationBuildContext::fromConfig(
            new ConfigRepository(['app' => ['container' => ['environment' => 'testing']]]),
            RuntimeMode::Cli,
            [],
        );
        $builder = FoundationGraph::compose($context);
        $builder->definitionCache($pool, 'foundation-intermix-types', 'release-1', failOpen: false);

        foreach ($values as $name => $value) {
            $id = 'cached.' . $name;
            $builder->factory($id, static fn(): mixed => $value)->cacheDefinition($id);
        }

        return $builder->build();
    };

    $firstObject = (object) ['source' => 'first'];
    $first = $build([
        'zero' => 0,
        'false' => false,
        'null' => null,
        'nested' => ['safe' => [0, null, false]],
        'object' => $firstObject,
    ]);
    expect($first->get('cached.zero'))->toBe(0)
        ->and($first->get('cached.false'))->toBeFalse()
        ->and($first->get('cached.null'))->toBeNull()
        ->and($first->get('cached.nested'))->toBe(['safe' => [0, null, false]])
        ->and($first->get('cached.object'))->toBe($firstObject);

    $secondObject = (object) ['source' => 'second'];
    $second = $build([
        'zero' => 1,
        'false' => true,
        'null' => 'changed',
        'nested' => ['changed' => true],
        'object' => $secondObject,
    ]);
    expect($second->get('cached.zero'))->toBe(0)
        ->and($second->get('cached.false'))->toBeFalse()
        ->and($second->get('cached.null'))->toBeNull()
        ->and($second->get('cached.nested'))->toBe(['safe' => [0, null, false]])
        ->and($second->get('cached.object'))->toBe($secondObject);
});
