<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Scheduling\ScheduleManager;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\UID\ULID;

it('uses UID monotonic ULID only for Foundation-generated execution fallbacks', function (): void {
    $first = ExecutionId::generate();
    $second = ExecutionId::generate();
    $supplied = new ExecutionId('external-correlation-id:/42');

    expect(ULID::isValid($first->value))->toBeTrue()
        ->and(ULID::isValid($second->value))->toBeTrue()
        ->and(strlen($first->value))->toBe(26)
        ->and(strlen($second->value))->toBe(26)
        ->and(strcmp($first->value, $second->value))->toBeLessThan(0)
        ->and($first->value)->not->toBe($second->value)
        ->and($supplied->value)->toBe('external-correlation-id:/42');
});

it('uses fresh UID-backed ids per worker and scheduler unit while preserving supplied correlation', function (): void {
    $project = foundationUidRuntimeProject();
    $config = foundationUidRuntimeConfig($project);

    try {
        $worker = Foundation::worker($config)->boot();
        $workerRuntime = new WorkerRuntime($worker);
        $workerFirst = $workerRuntime->execute(static fn(ExecutionId $id): string => $id->value);
        $workerSecond = $workerRuntime->execute(static fn(ExecutionId $id): string => $id->value);
        $workerSupplied = $workerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
            executionId: new ExecutionId('worker:external-correlation'),
        );

        $scheduler = Foundation::scheduler($config)->boot();
        $schedulerRuntime = new SchedulerRuntime($scheduler);
        $schedulerFirst = $schedulerRuntime->execute(static fn(ExecutionId $id): string => $id->value);
        $schedulerSecond = $schedulerRuntime->execute(static fn(ExecutionId $id): string => $id->value);
        $schedulerSupplied = $schedulerRuntime->execute(
            static fn(ExecutionId $id): string => $id->value,
            executionId: new ExecutionId('scheduler:external-correlation'),
        );

        expect(ULID::isValid($workerFirst))->toBeTrue()
            ->and(ULID::isValid($workerSecond))->toBeTrue()
            ->and($workerFirst)->not->toBe($workerSecond)
            ->and($workerSupplied)->toBe('worker:external-correlation')
            ->and(ULID::isValid($schedulerFirst))->toBeTrue()
            ->and(ULID::isValid($schedulerSecond))->toBeTrue()
            ->and($schedulerFirst)->not->toBe($schedulerSecond)
            ->and($schedulerSupplied)->toBe('scheduler:external-correlation');
    } finally {
        foundationUidRuntimeRemove($project);
    }
});

it('keeps one UID-backed execution id through a successful scheduler history lifecycle', function (): void {
    $project = foundationUidRuntimeProject();
    $executable = $project . '/uid-noop.php';
    $routes = $project . '/routes/schedule.php';

    try {
        file_put_contents($executable, "<?php\n\ndeclare(strict_types=1);\n\nexit(0);\n");
        file_put_contents($routes, <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('uid:no-op')->key('uid-success')->everyMinute();
};
PHP);

        $config = foundationUidRuntimeConfig($project);
        $config['command'] = ['executable' => $executable];
        $config['operations'] = [
            'history' => [
                'enabled' => true,
                'path' => $project . '/storage/logs/executions.jsonl',
            ],
        ];

        $app = Foundation::scheduler($config);
        $manager = new ScheduleManager($app);
        $run = $manager->runNamed('uid-success');
        $history = new ExecutionHistory($app);
        $latest = $history->latestByMetadata('schedule', 'schedule_identity', 'uid-success');
        $executionId = $latest['execution_id'] ?? null;

        expect($run->successful())->toBeTrue()
            ->and($latest['status'] ?? null)->toBe('succeeded')
            ->and($executionId)->toBeString()
            ->and(ULID::isValid($executionId))->toBeTrue();

        $records = $history->find($executionId);
        expect(array_column($records, 'status'))->toBe(['pending', 'running', 'succeeded'])
            ->and(array_values(array_unique(array_column($records, 'execution_id'))))->toBe([$executionId]);
    } finally {
        foundationUidRuntimeRemove($project);
    }
});

it('keeps UID-backed fallback generation unique across a fork when pcntl is available', function (): void {
    if (!function_exists('pcntl_fork')
        || !function_exists('pcntl_waitpid')
        || !function_exists('pcntl_wifexited')
        || !function_exists('pcntl_wexitstatus')
        || !function_exists('pcntl_exec')
    ) {
        expect(true)->toBeTrue();

        return;
    }

    $exchange = tempnam(sys_get_temp_dir(), 'foundation-uid-fork-');
    if ($exchange === false) {
        throw new RuntimeException('Unable to allocate UID fork exchange file.');
    }

    $beforeFork = ExecutionId::generate()->value;
    $pid = pcntl_fork();
    if ($pid === -1) {
        unlink($exchange);
        throw new RuntimeException('Unable to fork UID runtime test process.');
    }

    if ($pid === 0) {
        $child = ExecutionId::generate()->value;
        if (file_put_contents($exchange, $child, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write child UID to fork exchange file.');
        }

        pcntl_exec(PHP_BINARY, ['-r', '']);
        throw new RuntimeException('Unable to replace forked UID test process.');
    }

    try {
        $parent = ExecutionId::generate()->value;
        $status = 0;
        pcntl_waitpid($pid, $status);
        $child = trim((string) file_get_contents($exchange));

        expect(pcntl_wifexited($status))->toBeTrue()
            ->and(pcntl_wexitstatus($status))->toBe(0)
            ->and(ULID::isValid($parent))->toBeTrue()
            ->and(ULID::isValid($child))->toBeTrue()
            ->and($beforeFork)->not->toBe($parent)
            ->and($beforeFork)->not->toBe($child)
            ->and($parent)->not->toBe($child);
    } finally {
        if (is_file($exchange)) {
            unlink($exchange);
        }
    }
});

/** @return array<string, mixed> */
function foundationUidRuntimeConfig(string $project): array
{
    return [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => [
            'base_path' => $project,
            'env' => 'testing',
        ],
    ];
}

function foundationUidRuntimeProject(): string
{
    $project = sys_get_temp_dir() . '/foundation-uid-runtime-' . bin2hex(random_bytes(8));
    foreach (['routes', 'storage/logs', 'storage/framework'] as $directory) {
        $path = $project . '/' . $directory;
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Unable to create UID runtime test directory "%s".', $path));
        }
    }

    return $project;
}

function foundationUidRuntimeRemove(string $project): void
{
    if (!is_dir($project)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($project);
}
