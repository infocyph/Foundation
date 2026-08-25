<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class FoundationWorkerLifecycleScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationWorkerLifecycleServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->bind(
            'worker.lifecycle.scoped',
            static fn(): FoundationWorkerLifecycleScopedProbe => new FoundationWorkerLifecycleScopedProbe(),
            LifetimeEnum::Scoped,
        );
    }
}

final class FoundationWorkerLifecycleScopeProvider implements WorkerProvider
{
    /** @var list<array{first:int,second:int}> */
    public static array $scopes = [];

    /** @var list<string> */
    public static array $executionIds = [];

    public function __construct(private readonly Application $application) {}

    public function run(WorkerRuntime $runtime): int
    {
        for ($iteration = 0; $iteration < 2; $iteration++) {
            $runtime->execute(function (ExecutionId $executionId): void {
                /** @var FoundationWorkerLifecycleScopedProbe $first */
                $first = $this->application->make('worker.lifecycle.scoped');
                /** @var FoundationWorkerLifecycleScopedProbe $second */
                $second = $this->application->make('worker.lifecycle.scoped');
                self::$scopes[] = ['first' => $first->sequence, 'second' => $second->sequence];
                self::$executionIds[] = (string) $executionId;
            });
        }

        return 0;
    }
}

final class FoundationWorkerLifecycleRestartProvider implements WorkerProvider
{
    public static int $registryCount = 0;

    public static bool $stopObserved = false;

    public static bool $continuedAfterHeartbeat = false;

    public function __construct(private readonly Application $application) {}

    public function run(WorkerRuntime $runtime): int
    {
        self::$registryCount = count(
            (new RuntimeProcessRegistry($this->application))->all('worker', 'restartable'),
        );

        (new RuntimeControl($this->application))->signal('worker', 'restartable');
        self::$stopObserved = $runtime->stopRequested();
        $runtime->heartbeat();
        self::$continuedAfterHeartbeat = true;

        return 17;
    }
}

final class FoundationWorkerLifecycleSingletonProvider implements WorkerProvider
{
    public static int $runs = 0;

    public function run(WorkerRuntime $runtime): int
    {
        unset($runtime);
        self::$runs++;

        return 0;
    }
}

beforeEach(function (): void {
    FoundationWorkerLifecycleScopeProvider::$scopes = [];
    FoundationWorkerLifecycleScopeProvider::$executionIds = [];
    FoundationWorkerLifecycleRestartProvider::$registryCount = 0;
    FoundationWorkerLifecycleRestartProvider::$stopObserved = false;
    FoundationWorkerLifecycleRestartProvider::$continuedAfterHeartbeat = false;
    FoundationWorkerLifecycleSingletonProvider::$runs = 0;
});

it('creates a fresh InterMix scope for every provider worker execution unit', function (): void {
    $basePath = foundationWorkerLifecycleDirectory('scope');
    foundationWorkerLifecycleRoutes($basePath, [
        'scoped' => FoundationWorkerLifecycleScopeProvider::class,
    ]);

    try {
        $app = Foundation::worker([
            'app' => ['base_path' => $basePath],
            'providers' => ['worker' => [FoundationWorkerLifecycleServiceProvider::class]],
        ]);

        expect((new WorkerManager($app))->run('scoped'))->toBe(0)
            ->and(FoundationWorkerLifecycleScopeProvider::$scopes)->toHaveCount(2)
            ->and(FoundationWorkerLifecycleScopeProvider::$scopes[0]['first'])
            ->toBe(FoundationWorkerLifecycleScopeProvider::$scopes[0]['second'])
            ->and(FoundationWorkerLifecycleScopeProvider::$scopes[1]['first'])
            ->toBe(FoundationWorkerLifecycleScopeProvider::$scopes[1]['second'])
            ->and(FoundationWorkerLifecycleScopeProvider::$scopes[0]['first'])
            ->not->toBe(FoundationWorkerLifecycleScopeProvider::$scopes[1]['first'])
            ->and(FoundationWorkerLifecycleScopeProvider::$executionIds)->toHaveCount(2)
            ->and(FoundationWorkerLifecycleScopeProvider::$executionIds[0])
            ->not->toBe(FoundationWorkerLifecycleScopeProvider::$executionIds[1]);
    } finally {
        foundationWorkerLifecycleRemove($basePath);
    }
});

it('observes named restart control on heartbeat and unregisters the worker process', function (): void {
    $basePath = foundationWorkerLifecycleDirectory('restart');
    foundationWorkerLifecycleRoutes($basePath, [
        'restartable' => FoundationWorkerLifecycleRestartProvider::class,
    ]);

    try {
        $app = Foundation::worker([
            'app' => ['base_path' => $basePath],
            'operations' => [
                'runtime_control' => [
                    'driver' => 'file',
                    'path' => $basePath . '/storage/framework/runtime-control.json',
                ],
                'runtime_registry' => [
                    'path' => $basePath . '/storage/framework/runtime',
                    'visibility' => 'host',
                    'stale_seconds' => 30,
                ],
            ],
        ]);
        $manager = new WorkerManager($app);

        expect($manager->run('restartable'))->toBe(0)
            ->and(FoundationWorkerLifecycleRestartProvider::$registryCount)->toBe(1)
            ->and(FoundationWorkerLifecycleRestartProvider::$stopObserved)->toBeTrue()
            ->and(FoundationWorkerLifecycleRestartProvider::$continuedAfterHeartbeat)->toBeFalse()
            ->and((new RuntimeProcessRegistry($app))->all('worker', 'restartable'))->toBe([]);
    } finally {
        foundationWorkerLifecycleRemove($basePath);
    }
});

it('does not enter an explicit singleton provider when another owner holds its lock', function (): void {
    $basePath = foundationWorkerLifecycleDirectory('singleton-contention');
    $lockPath = $basePath . '/storage/cache/locks';
    foundationWorkerLifecycleRoutes($basePath, [
        'singleton-blocked' => [
            'provider' => FoundationWorkerLifecycleSingletonProvider::class,
            'singleton' => true,
            'lock_wait_seconds' => 0.0,
            'lock_lease_seconds' => 30.0,
        ],
    ]);

    $externalLocks = new FileLockProvider($lockPath);
    $held = $externalLocks->acquire('foundation:worker:singleton-blocked', 0.0, 30.0);
    expect($held)->not->toBeNull();

    try {
        $app = Foundation::worker([
            'app' => ['base_path' => $basePath],
            'cache' => [
                'lock' => [
                    'driver' => 'file',
                    'path' => $lockPath,
                ],
            ],
        ]);

        expect((new WorkerManager($app))->run('singleton-blocked'))->toBeNull()
            ->and(FoundationWorkerLifecycleSingletonProvider::$runs)->toBe(0)
            ->and((new RuntimeProcessRegistry($app))->all('worker', 'singleton-blocked'))->toBe([]);
    } finally {
        if ($held !== null) {
            $externalLocks->release($held);
        }
        foundationWorkerLifecycleRemove($basePath);
    }
});

/** @param array<string,mixed> $definitions */
function foundationWorkerLifecycleRoutes(string $basePath, array $definitions): void
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

function foundationWorkerLifecycleDirectory(string $suffix): string
{
    $basePath = sys_get_temp_dir() . '/foundation-worker-lifecycle-' . $suffix . '-' . bin2hex(random_bytes(5));
    mkdir($basePath . '/storage/cache', 0775, true);

    return $basePath;
}

function foundationWorkerLifecycleRemove(string $directory): void
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
