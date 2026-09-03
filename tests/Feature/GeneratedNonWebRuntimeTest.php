<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

final readonly class FoundationGeneratedRuntimeProbe
{
    public function __construct(public RuntimeMode $runtime) {}
}

final class FoundationGeneratedRuntimeScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationGeneratedRuntimeProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->singleton(FoundationGeneratedRuntimeProbe::class, FactoryDefinition::construct(
            FoundationGeneratedRuntimeProbe::class,
            [new ServiceReference(RuntimeMode::class)],
        ));
        $builder->scoped(
            FoundationGeneratedRuntimeScopedProbe::class,
            FactoryDefinition::construct(FoundationGeneratedRuntimeScopedProbe::class),
        );
    }
}

it('compiles and reuses minimal generated CLI and scheduler runtimes', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $compiler = new GeneratedRuntimeCompiler();

    try {
        foreach ([RuntimeMode::Cli, RuntimeMode::Scheduler] as $mode) {
            $artifact = $project . '/bootstrap/cache/' . $mode->value . '.php';
            $report = $compiler->compile($config, $mode, $artifact);
            expect($report['runtime'])->toBe($mode->value)
                ->and($report['skipped'])->toBe([])
                ->and($report['capabilities'])->toBe([])
                ->and(is_file($artifact))->toBeTrue()
                ->and(is_file($artifact . '.meta.json'))->toBeTrue()
                ->and(is_file($artifact . '.foundation.json'))->toBeTrue();

            $runtime = GeneratedRuntime::load($config, $mode, $artifact);
            expect($runtime->application->runtimeMode())->toBe($mode)
                ->and($runtime->application->make(FoundationGeneratedRuntimeProbe::class)->runtime)->toBe($mode)
                ->and($runtime->container->has('foundation.http'))->toBeFalse()
                ->and($runtime->container->has('foundation.cache'))->toBeFalse()
                ->and($runtime->container->has('foundation.messaging'))->toBeFalse();

            $first = $runtime->application->execution()->run(
                static fn(ExecutionId $id): array => [
                    (string) $id,
                    $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                ],
                executionId: new ExecutionId($mode->value . '-one'),
            );
            $second = $runtime->application->execution()->run(
                static fn(ExecutionId $id): array => [
                    (string) $id,
                    $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                ],
                executionId: new ExecutionId($mode->value . '-two'),
            );

            expect($first[0])->toBe($mode->value . '-one')
                ->and($second[0])->toBe($mode->value . '-two')
                ->and($first[1])->not->toBe($second[1]);
        }
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('builds a worker only with explicitly selected messaging capability and keeps one runtime across executions', function (): void {
    if (!class_exists(\Infocyph\Omnibus\MessageBus::class)) {
        $this->markTestSkipped('Install the messaging module to run the generated worker integration test.');
    }

    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/worker.php';

    try {
        $report = new GeneratedRuntimeCompiler()->compile(
            $config,
            RuntimeMode::Worker,
            $artifact,
            ['messaging'],
        );
        expect($report['skipped'])->toBe([])
            ->and($report['capabilities'])->toBe(['messaging' => true]);

        $runtime = GeneratedRuntime::load($config, RuntimeMode::Worker, $artifact, ['messaging']);
        expect($runtime->container->has('foundation.messaging'))->toBeTrue()
            ->and($runtime->container->has('foundation.http'))->toBeFalse()
            ->and($runtime->container->has('foundation.cache'))->toBeFalse();

        $containerId = spl_object_id($runtime->container);
        $seen = [];
        foreach (range(1, 64) as $index) {
            $seen[] = $runtime->application->execution()->run(
                static fn(): int => $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                executionId: new ExecutionId('worker-' . $index),
            );
        }

        expect(spl_object_id($runtime->container))->toBe($containerId)
            ->and(array_unique($seen))->toHaveCount(64);
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('rejects stale runtime, configuration, and capability identities before loading an artifact', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/cli.php';

    try {
        new GeneratedRuntimeCompiler()->compile($config, RuntimeMode::Cli, $artifact);

        expect(fn() => GeneratedRuntime::load($config, RuntimeMode::Worker, $artifact))
            ->toThrow(RuntimeException::class, 'identity does not match');

        $changed = $config;
        $changed['app']['name'] = 'Changed generated runtime';
        expect(fn() => GeneratedRuntime::load($changed, RuntimeMode::Cli, $artifact))
            ->toThrow(RuntimeException::class, 'identity does not match');

        expect(fn() => GeneratedRuntime::load($config, RuntimeMode::Cli, $artifact, ['cache']))
            ->toThrow(RuntimeException::class, 'identity does not match');
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationGeneratedRuntimeConfig(string $project): array
{
    return [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'providers' => [
            'common' => [FoundationGeneratedRuntimeProvider::class],
        ],
    ];
}

function foundationGeneratedRuntimeProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-generated-runtime-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0777, true);

    return $project;
}

function foundationGeneratedRuntimeRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
