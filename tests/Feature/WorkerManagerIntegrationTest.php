<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;

final class FoundationMaintenanceWorker implements WorkerProvider
{
    public static int $runs = 0;

    public function run(WorkerRuntime $runtime): int
    {
        $runtime->heartbeat();

        return $runtime->execute(static function (ExecutionId $executionId): int {
            unset($executionId);
            self::$runs++;

            return 0;
        });
    }
}

beforeEach(function (): void {
    FoundationMaintenanceWorker::$runs = 0;
});

it('runs provider workers without acquiring a global lock by default', function (): void {
    $basePath = foundationWorkerDirectory('unlocked');
    foundationWorkerRoutes($basePath, [
        'maintenance' => FoundationMaintenanceWorker::class,
    ]);
    $app = Foundation::worker([
        'app' => ['base_path' => $basePath],
        'cache' => [
            'lock' => ['driver' => 'unsupported-on-purpose'],
        ],
    ]);
    $workers = new WorkerManager($app);

    try {
        expect($workers->all()['maintenance'])->toMatchArray([
            'type' => 'provider',
            'provider' => FoundationMaintenanceWorker::class,
            'singleton' => false,
        ])->and($workers->run('maintenance'))->toBe(0)
            ->and(FoundationMaintenanceWorker::$runs)->toBe(1);
    } finally {
        foundationWorkerRemove($basePath);
    }
});

it('acquires and refreshes CacheLayer ownership only for explicit singleton providers', function (): void {
    $basePath = foundationWorkerDirectory('singleton');
    foundationWorkerRoutes($basePath, [
        'maintenance' => [
            'provider' => FoundationMaintenanceWorker::class,
            'singleton' => true,
            'lock_wait_seconds' => 0.0,
            'lock_lease_seconds' => 30.0,
        ],
    ]);
    $app = Foundation::worker([
        'app' => ['base_path' => $basePath],
        'cache' => [
            'lock' => [
                'driver' => 'file',
                'path' => $basePath . '/storage/cache/locks',
            ],
        ],
    ]);
    $workers = new WorkerManager($app);

    try {
        expect($workers->all()['maintenance'])->toMatchArray([
            'type' => 'provider',
            'singleton' => true,
            'lock_lease_seconds' => 30.0,
        ])->and($workers->run('maintenance'))->toBe(0)
            ->and(FoundationMaintenanceWorker::$runs)->toBe(1);
    } finally {
        foundationWorkerRemove($basePath);
    }
});

it('rejects ambiguous names shared by provider and messaging workers', function (): void {
    $basePath = foundationWorkerDirectory('collision');
    foundationWorkerRoutes($basePath, [
        'jobs' => FoundationMaintenanceWorker::class,
    ]);
    $app = Foundation::worker([
        'app' => ['base_path' => $basePath],
        'messaging' => [
            'workers' => [
                'jobs' => [
                    'transport' => 'memory',
                    'queue' => 'jobs',
                ],
            ],
        ],
    ]);

    try {
        expect(fn() => new WorkerManager($app)->all())
            ->toThrow(UnexpectedValueException::class, 'both a provider worker and a messaging worker');
    } finally {
        foundationWorkerRemove($basePath);
    }
});

/** @param array<string, mixed> $definitions */
function foundationWorkerRoutes(string $basePath, array $definitions): void
{
    $routes = $basePath . '/routes';
    if (!is_dir($routes)) {
        mkdir($routes, 0775, true);
    }

    file_put_contents(
        $routes . '/workers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($definitions, true) . ";\n",
    );
}

function foundationWorkerDirectory(string $suffix): string
{
    $basePath = sys_get_temp_dir() . '/foundation-worker-' . $suffix . '-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/storage/cache', 0775, true);

    return $basePath;
}

function foundationWorkerRemove(string $directory): void
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
