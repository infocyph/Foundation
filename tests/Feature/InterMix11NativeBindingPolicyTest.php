<?php

declare(strict_types=1);

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
                ? ContainerBuilder::loadProductionArtifact($path, $report['graph'])
                : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
        }

        $consumer = $runtime->get('foundation.native.binding.consumer');
        expect($consumer)->toBeInstanceOf(FoundationNativeBindingConsumer::class)
            ->and($consumer->probe->value)->toBe('unbound')
            ->and($runtime->invoke(static fn(int $probe): int => $probe, ['probe' => 0]))->toBe(0)
            ->and($runtime->invoke(static fn(bool $probe): bool => $probe, ['probe' => false]))->toBeFalse()
            ->and($runtime->invoke(static fn(?int $probe): ?int => $probe, ['probe' => null]))->toBeNull()
            ->and(fn() => $runtime->invoke(
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
