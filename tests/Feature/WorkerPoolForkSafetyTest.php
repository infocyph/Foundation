<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerPool;
use Infocyph\TalkingBytes\Http\HttpClient;

it('rejects a parent cache manager that may retain backend resources before pool fork', function (): void {
    $app = Foundation::worker([
        'cache' => [
            'default' => 'memory',
            'stores' => [
                'memory' => ['driver' => 'memory'],
            ],
        ],
        'messaging' => [
            'workers' => [
                'parallel' => [
                    'transport' => 'shared',
                    'queue' => 'default',
                    'pool' => [
                        'enabled' => true,
                        'concurrency' => 2,
                    ],
                ],
            ],
        ],
    ]);

    $app->make(CacheManager::class)->store();

    expect(fn() => new WorkerManager($app)->run('parallel'))
        ->toThrow(LogicException::class, CacheManager::class);
});

it('rejects an opened DBLayer parent connection before pool fork', function (): void {
    $basePath = foundationForkSafetyDirectory('database');
    if (class_exists(DB::class)) {
        DB::purge();
    }

    try {
        $app = Foundation::worker([
            'app' => ['base_path' => $basePath],
            'database' => [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                    ],
                ],
            ],
            'messaging' => foundationForkSafetyMessagingConfig(),
        ]);
        $app->make(DBLayerFactory::class)->connection()->statement('SELECT 1');

        expect(fn() => new WorkerManager($app)->run('parallel'))
            ->toThrow(LogicException::class, 'DBLayer connections');
    } finally {
        if (class_exists(DB::class)) {
            DB::purge();
        }
        foundationForkSafetyRemove($basePath);
    }
});

it('rejects a resolved TalkingBytes HTTP client before pool fork', function (): void {
    $basePath = foundationForkSafetyDirectory('http');

    try {
        $app = Foundation::worker([
            'app' => ['base_path' => $basePath],
            'messaging' => foundationForkSafetyMessagingConfig(),
        ]);
        expect($app->make(HttpClient::class))->toBeInstanceOf(HttpClient::class);

        expect(fn() => new WorkerManager($app)->run('parallel'))
            ->toThrow(LogicException::class, HttpClient::class);
    } finally {
        foundationForkSafetyRemove($basePath);
    }
});

it('forks initializes terminates and reaps an Omnibus worker child without skipped coverage', function (): void {
    $requiredFunctions = [
        'pcntl_async_signals',
        'pcntl_fork',
        'pcntl_get_last_error',
        'pcntl_signal',
        'pcntl_signal_get_handler',
        'pcntl_sigprocmask',
        'pcntl_wait',
        'pcntl_waitpid',
        'posix_kill',
    ];
    foreach ($requiredFunctions as $function) {
        expect(function_exists($function))->toBeTrue(sprintf('%s must be available for WorkerPool CI coverage.', $function));
    }
    expect(defined('SIGTERM'))->toBeTrue()
        ->and(defined('SIGINT'))->toBeTrue();

    $parentPid = getmypid();
    expect($parentPid)->toBeInt();
    $marker = tempnam(sys_get_temp_dir(), 'foundation-worker-pool-');
    expect($marker)->toBeString();
    if (!is_int($parentPid) || !is_string($marker)) {
        throw new RuntimeException('Unable to initialize WorkerPool fork-safety probe.');
    }

    $terminate = constant('SIGTERM');
    $interrupt = constant('SIGINT');
    if (!is_int($terminate) || !is_int($interrupt)) {
        throw new RuntimeException('WorkerPool signals are unavailable.');
    }
    $previousTerminate = pcntl_signal_get_handler($terminate);
    $previousInterrupt = pcntl_signal_get_handler($interrupt);

    try {
        $pool = new WorkerPool(
            workerFactory: static function (int $slot) use ($parentPid, $marker, $terminate): Worker {
                $childPid = getmypid();
                if (!is_int($childPid)) {
                    throw new RuntimeException('Unable to resolve child PID.');
                }
                file_put_contents(
                    $marker,
                    json_encode(
                        ['parent' => $parentPid, 'child' => $childPid, 'slot' => $slot],
                        JSON_THROW_ON_ERROR,
                    ),
                    LOCK_EX,
                );
                if (!posix_kill($parentPid, $terminate)) {
                    throw new RuntimeException('Unable to request parent WorkerPool shutdown.');
                }

                usleep(250_000);
                throw new RuntimeException('WorkerPool child should have been terminated by the parent.');
            },
            concurrency: 1,
            maximumRestarts: 0,
            restartBackoffSeconds: 0.0,
            shutdownGraceSeconds: 1.0,
        );
        $pool->run();

        $payload = file_get_contents($marker);
        expect($payload)->toBeString();
        $decoded = is_string($payload)
            ? json_decode($payload, true, 16, JSON_THROW_ON_ERROR)
            : [];
        expect($decoded)->toBeArray()
            ->and($decoded['parent'] ?? null)->toBe($parentPid)
            ->and($decoded['child'] ?? null)->toBeInt()
            ->and($decoded['child'] ?? null)->not->toBe($parentPid)
            ->and($decoded['slot'] ?? null)->toBe(0)
            ->and(pcntl_signal_get_handler($terminate))->toBe($previousTerminate)
            ->and(pcntl_signal_get_handler($interrupt))->toBe($previousInterrupt);

        $childPid = $decoded['child'] ?? null;
        if (!is_int($childPid)) {
            throw new RuntimeException('WorkerPool child PID was not recorded.');
        }
        $status = 0;
        expect(pcntl_waitpid($childPid, $status, WNOHANG))->toBe(-1);
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});

/** @return array<string,mixed> */
function foundationForkSafetyMessagingConfig(): array
{
    return [
        'workers' => [
            'parallel' => [
                'transport' => 'shared',
                'queue' => 'default',
                'pool' => [
                    'enabled' => true,
                    'concurrency' => 2,
                ],
            ],
        ],
    ];
}

function foundationForkSafetyDirectory(string $suffix): string
{
    $directory = sys_get_temp_dir() . '/foundation-fork-safety-' . $suffix . '-' . bin2hex(random_bytes(5));
    mkdir($directory . '/storage', 0775, true);

    return $directory;
}

function foundationForkSafetyRemove(string $directory): void
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
