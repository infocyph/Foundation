<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Worker\WorkerRestartRequested;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

final class FoundationGeneratedWorkerCancellationProbe {}

final class FoundationGeneratedWorkerCancellationProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->scoped(
            FoundationGeneratedWorkerCancellationProbe::class,
            FactoryDefinition::construct(FoundationGeneratedWorkerCancellationProbe::class),
        );
    }
}

it('releases generated worker scope when restart cancels an active item', function (): void {
    $project = foundationGeneratedWorkerCancellationProject();
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'providers' => [
            'common' => [FoundationGeneratedWorkerCancellationProvider::class],
        ],
    ];
    $artifact = $project . '/bootstrap/cache/worker.php';

    try {
        $report = new GeneratedRuntimeCompiler()->compile(
            $config,
            RuntimeMode::Worker,
            $artifact,
        );
        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\nthrow new RuntimeException('generated worker rebuilt source providers');\n",
        );

        $runtime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Worker,
            $artifact,
            $report['metadata_sha256'],
            $report['digest'],
        );
        $containerId = spl_object_id($runtime->container);
        $restartRequested = false;
        $worker = new WorkerRuntime(
            $runtime->application,
            stopRequested: static function () use (&$restartRequested): bool {
                return $restartRequested;
            },
        );
        $reference = null;

        expect(function () use ($runtime, $worker, &$restartRequested, &$reference): void {
            $worker->execute(
                static function () use ($runtime, $worker, &$restartRequested, &$reference): void {
                    $probe = $runtime->application->make(FoundationGeneratedWorkerCancellationProbe::class);
                    $reference = WeakReference::create($probe);
                    $restartRequested = true;
                    $worker->heartbeat();
                },
                executionId: new ExecutionId('worker-cancelled'),
            );
        })->toThrow(WorkerRestartRequested::class, 'Worker restart requested.');

        gc_collect_cycles();
        expect($reference)->toBeInstanceOf(WeakReference::class)
            ->and($reference?->get())->toBeNull()
            ->and(spl_object_id($runtime->container))->toBe($containerId)
            ->and(fn() => $runtime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class);

        $restartRequested = false;
        $next = $worker->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                spl_object_id($runtime->application->make(FoundationGeneratedWorkerCancellationProbe::class)),
            ],
            executionId: new ExecutionId('worker-after-cancel'),
        );

        expect($next[0])->toBe('worker-after-cancel')
            ->and(spl_object_id($runtime->container))->toBe($containerId);
    } finally {
        foundationGeneratedWorkerCancellationRemove($project);
    }
});

function foundationGeneratedWorkerCancellationProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-generated-worker-cancel-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0777, true);

    return $project;
}

function foundationGeneratedWorkerCancellationRemove(string $directory): void
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
