<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Messaging\InterMixExecutionScope;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Infocyph\Foundation\Scheduling\ScheduledCommand;
use Infocyph\Foundation\Scheduling\ScheduleManager;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final class FoundationPhase7GeneratedScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationPhase7GeneratedProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->scoped(
            FoundationPhase7GeneratedScopedProbe::class,
            FactoryDefinition::construct(FoundationPhase7GeneratedScopedProbe::class),
        );
    }
}

it('runs the existing scheduler manager on one trusted generated container with one scope per entry', function (): void {
    if (!class_exists(FileLockProvider::class)) {
        $this->markTestSkipped('Install CacheLayer to run generated scheduler lock acceptance.');
    }

    $project = foundationPhase7GeneratedProject();
    $marker = $project . '/storage/scheduler.log';
    $artifact = $project . '/bootstrap/cache/scheduler.php';
    $config = foundationPhase7GeneratedConfig($project) + [
        'cache' => [
            'default' => 'memory',
            'stores' => ['memory' => ['driver' => 'memory']],
            'lock' => [
                'driver' => 'file',
                'path' => $project . '/storage/cache/locks',
                'retry_sleep_micros' => 1_000,
            ],
        ],
        'operations' => [
            'history' => [
                'enabled' => true,
                'path' => $project . '/storage/logs/executions.jsonl',
            ],
        ],
    ];

    try {
        foundationPhase7GeneratedScheduleRoutes($project, $marker);
        $report = new GeneratedRuntimeCompiler()->compile(
            $config,
            RuntimeMode::Scheduler,
            $artifact,
            ['cache'],
        );
        expect($report['skipped'])->toBe([])
            ->and($report['capabilities'])->toBe(['cache' => true]);

        foundationPhase7PoisonProviders($project, 'generated scheduler rebuilt source providers');
        $runtime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Scheduler,
            $artifact,
            $report['metadata_sha256'],
            $report['digest'],
            ['cache'],
        );
        $containerId = spl_object_id($runtime->container);
        $manager = new ScheduleManager($runtime->application);
        $manager->write();
        file_put_contents(
            $project . '/routes/schedule.php',
            "<?php\n\nthrow new RuntimeException('generated scheduler ignored cached manifest');\n",
        );

        $entry = $manager->entries()[0];
        expect($entry->identity())->toBe('phase7-generated')
            ->and($manager->runNamed('phase7-generated')->successful())->toBeTrue()
            ->and($manager->runNamed('phase7-generated')->successful())->toBeTrue()
            ->and(foundationPhase7Lines($marker))->toBe(['generated', 'generated'])
            ->and(spl_object_id($runtime->container))->toBe($containerId)
            ->and(fn() => $runtime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class)
            ->and(fn() => $runtime->application->make(ScheduledCommand::class))
            ->toThrow(ServiceResolutionException::class);

        $locks = new FileLockProvider($project . '/storage/cache/locks');
        $handle = $locks->acquire(
            'foundation-schedule-' . substr(hash('sha256', $entry->identity()), 0, 44),
            0.0,
            5.0,
        );
        expect($handle)->not->toBeNull();
        if ($handle !== null) {
            $locks->release($handle);
        }

        $scheduler = new SchedulerRuntime($runtime->application);
        $first = $scheduler->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $runtime->application->make(ScheduledCommand::class)->identity(),
                $runtime->application->make(FoundationPhase7GeneratedScopedProbe::class)->sequence,
            ],
            [ScheduledCommand::class => $entry],
            new ExecutionId('phase7-scheduler-one'),
        );
        $second = $scheduler->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $runtime->application->make(ScheduledCommand::class)->identity(),
                $runtime->application->make(FoundationPhase7GeneratedScopedProbe::class)->sequence,
            ],
            [ScheduledCommand::class => $entry],
            new ExecutionId('phase7-scheduler-two'),
        );

        expect($first[0])->toBe('phase7-scheduler-one')
            ->and($second[0])->toBe('phase7-scheduler-two')
            ->and($first[1])->toBe('phase7-generated')
            ->and($second[1])->toBe('phase7-generated')
            ->and($first[2])->not->toBe($second[2])
            ->and(spl_object_id($runtime->container))->toBe($containerId);
    } finally {
        foundationPhase7GeneratedRemove($project);
    }
});

it('keeps trusted worker and scheduler production containers bounded across long sequential execution', function (): void {
    if (!class_exists(\Infocyph\Omnibus\MessageBus::class)) {
        $this->markTestSkipped('Install Omnibus to run generated persistent runtime acceptance.');
    }
    if (!extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite is required for generated transaction-isolation acceptance.');
    }

    $project = foundationPhase7GeneratedProject();
    $config = foundationPhase7GeneratedConfig($project);
    $workerArtifact = $project . '/bootstrap/cache/worker.php';
    $schedulerArtifact = $project . '/bootstrap/cache/scheduler.php';
    $database = $project . '/storage/runtime.sqlite';
    $pdo = null;

    try {
        $compiler = new GeneratedRuntimeCompiler();
        $workerReport = $compiler->compile(
            $config,
            RuntimeMode::Worker,
            $workerArtifact,
            ['messaging'],
        );
        $schedulerReport = $compiler->compile(
            $config,
            RuntimeMode::Scheduler,
            $schedulerArtifact,
        );
        foundationPhase7PoisonProviders($project, 'persistent runtime rebuilt source providers');

        $workerRuntime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Worker,
            $workerArtifact,
            $workerReport['metadata_sha256'],
            $workerReport['digest'],
            ['messaging'],
        );
        $schedulerRuntime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Scheduler,
            $schedulerArtifact,
            $schedulerReport['metadata_sha256'],
            $schedulerReport['digest'],
        );
        $workerContainerId = spl_object_id($workerRuntime->container);
        $schedulerContainerId = spl_object_id($schedulerRuntime->container);
        $worker = new WorkerRuntime($workerRuntime->application);
        $scheduler = new SchedulerRuntime($schedulerRuntime->application);

        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE phase7_runtime (value TEXT NOT NULL)');
        $connectionConfig = ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $database,
        ]);

        foreach (range(1, 32) as $index) {
            $worker->execute(
                static fn(): int => $workerRuntime->application
                    ->make(FoundationPhase7GeneratedScopedProbe::class)->sequence,
                ['foundation.phase7.worker.item' => 'warm-' . $index],
            );
            $entry = (new ScheduledCommand('phase7:warm'))->key('warm-' . $index);
            $scheduler->execute(
                static fn(): int => $schedulerRuntime->application
                    ->make(FoundationPhase7GeneratedScopedProbe::class)->sequence,
                [ScheduledCommand::class => $entry],
            );
        }
        gc_collect_cycles();

        $workerMemory = memory_get_usage(true);
        $workerFailures = 0;
        $workerReferences = [];
        $workerTemps = [];
        foreach (range(1, 512) as $index) {
            $item = 'worker-item-' . $index;
            try {
                $worker->execute(
                    static function (ExecutionId $id) use (
                        $workerRuntime,
                        $connectionConfig,
                        $project,
                        $index,
                        $item,
                        &$workerReferences,
                        &$workerTemps,
                    ): void {
                        expect((string) $id)->toBe('phase7-worker-' . $index)
                            ->and($workerRuntime->application->make('foundation.phase7.worker.item'))->toBe($item);

                        $probe = $workerRuntime->application->make(FoundationPhase7GeneratedScopedProbe::class);
                        $workerReferences[] = WeakReference::create($probe);
                        $state = $workerRuntime->application->make(RuntimeExecutionState::class);

                        if ($index % 16 === 0) {
                            $connection = $state->connection('phase7', $connectionConfig);
                            $connection->beginTransaction();
                            $connection->insert('INSERT INTO phase7_runtime (value) VALUES (?)', [$item]);
                            expect($connection->transactionLevel())->toBe(1);
                        }

                        if ($index % 32 === 0) {
                            $temporary = $project . '/storage/worker-' . $index . '.tmp';
                            $handle = fopen($temporary, 'c+');
                            if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
                                throw new RuntimeException('Unable to acquire generated worker temporary lock.');
                            }
                            $workerTemps[] = $temporary;
                            $state->deferCleanup(static function () use ($handle, $temporary): void {
                                flock($handle, LOCK_UN);
                                fclose($handle);
                                if (is_file($temporary)) {
                                    unlink($temporary);
                                }
                            });
                        }

                        if ($index % 23 === 0) {
                            throw new RuntimeException('deliberate generated worker persistence failure');
                        }
                    },
                    ['foundation.phase7.worker.item' => $item],
                    new ExecutionId('phase7-worker-' . $index),
                );
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())->toBe('deliberate generated worker persistence failure');
                ++$workerFailures;
            }

            if ($index % 64 === 0) {
                gc_collect_cycles();
                foreach ($workerReferences as $reference) {
                    expect($reference->get())->toBeNull();
                }
                $workerReferences = [];
            }
        }

        $messageScope = $workerRuntime->application->make(InterMixExecutionScope::class);
        $messageSequences = [];
        foreach (range(1, 128) as $index) {
            $message = new stdClass();
            $message->index = $index;
            $envelope = new Envelope($message, [new MessageIdStamp('phase7-' . $index)]);
            $messageScope->run(
                $envelope,
                static function (object $current, Envelope $delivery) use (
                    $workerRuntime,
                    $message,
                    $envelope,
                    $index,
                    &$messageSequences,
                ): void {
                    expect($current)->toBe($message)
                        ->and($delivery)->toBe($envelope)
                        ->and($workerRuntime->application->make(Envelope::class))->toBe($envelope)
                        ->and($workerRuntime->application->make('omnibus.message'))->toBe($message)
                        ->and((string) $workerRuntime->application->make(ExecutionId::class))
                        ->toBe('omnibus:phase7-' . $index);
                    $messageSequences[] = $workerRuntime->application
                        ->make(FoundationPhase7GeneratedScopedProbe::class)->sequence;
                },
            );
        }
        gc_collect_cycles();

        expect($workerFailures)->toBe(intdiv(512, 23))
            ->and(array_unique($messageSequences))->toHaveCount(128)
            ->and((int) $pdo->query('SELECT COUNT(*) FROM phase7_runtime')->fetchColumn())->toBe(0)
            ->and(array_filter($workerTemps, 'is_file'))->toBe([])
            ->and(fn() => $workerRuntime->application->make('foundation.phase7.worker.item'))
            ->toThrow(ServiceResolutionException::class)
            ->and(fn() => $workerRuntime->application->make(Envelope::class))
            ->toThrow(ServiceResolutionException::class)
            ->and(fn() => $workerRuntime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class)
            ->and(spl_object_id($workerRuntime->container))->toBe($workerContainerId)
            ->and(max(0, memory_get_usage(true) - $workerMemory))->toBeLessThanOrEqual(32 * 1024 * 1024);

        gc_collect_cycles();
        $schedulerMemory = memory_get_usage(true);
        $schedulerFailures = 0;
        $schedulerReferences = [];
        $schedulerTemps = [];
        $schedulerSequences = [];
        foreach (range(1, 512) as $index) {
            $item = 'scheduler-item-' . $index;
            $entry = (new ScheduledCommand('phase7:noop'))->key('phase7-' . $index);

            try {
                $schedulerSequences[] = $scheduler->execute(
                    static function (ExecutionId $id) use (
                        $schedulerRuntime,
                        $project,
                        $index,
                        $item,
                        &$schedulerReferences,
                        &$schedulerTemps,
                    ): int {
                        expect((string) $id)->toBe('phase7-scheduler-' . $index)
                            ->and($schedulerRuntime->application->make('foundation.phase7.scheduler.item'))->toBe($item)
                            ->and($schedulerRuntime->application->make(ScheduledCommand::class)->identity())
                            ->toBe('phase7-' . $index);

                        $probe = $schedulerRuntime->application->make(FoundationPhase7GeneratedScopedProbe::class);
                        $schedulerReferences[] = WeakReference::create($probe);
                        $state = $schedulerRuntime->application->make(RuntimeExecutionState::class);

                        if ($index % 32 === 0) {
                            $temporary = $project . '/storage/scheduler-' . $index . '.tmp';
                            $handle = fopen($temporary, 'c+');
                            if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
                                throw new RuntimeException('Unable to acquire generated scheduler temporary lock.');
                            }
                            $schedulerTemps[] = $temporary;
                            $state->deferCleanup(static function () use ($handle, $temporary): void {
                                flock($handle, LOCK_UN);
                                fclose($handle);
                                if (is_file($temporary)) {
                                    unlink($temporary);
                                }
                            });
                        }

                        if ($index % 29 === 0) {
                            throw new RuntimeException('deliberate generated scheduler persistence failure');
                        }

                        return $probe->sequence;
                    },
                    [
                        ScheduledCommand::class => $entry,
                        'foundation.phase7.scheduler.item' => $item,
                    ],
                    new ExecutionId('phase7-scheduler-' . $index),
                );
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())->toBe('deliberate generated scheduler persistence failure');
                ++$schedulerFailures;
            }

            if ($index % 64 === 0) {
                gc_collect_cycles();
                foreach ($schedulerReferences as $reference) {
                    expect($reference->get())->toBeNull();
                }
                $schedulerReferences = [];
            }
        }
        gc_collect_cycles();

        expect($schedulerFailures)->toBe(intdiv(512, 29))
            ->and(array_unique($schedulerSequences))->toHaveCount(512 - $schedulerFailures)
            ->and(array_filter($schedulerTemps, 'is_file'))->toBe([])
            ->and(fn() => $schedulerRuntime->application->make('foundation.phase7.scheduler.item'))
            ->toThrow(ServiceResolutionException::class)
            ->and(fn() => $schedulerRuntime->application->make(ScheduledCommand::class))
            ->toThrow(ServiceResolutionException::class)
            ->and(fn() => $schedulerRuntime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class)
            ->and(spl_object_id($schedulerRuntime->container))->toBe($schedulerContainerId)
            ->and(max(0, memory_get_usage(true) - $schedulerMemory))->toBeLessThanOrEqual(32 * 1024 * 1024);
    } finally {
        $pdo = null;
        foundationPhase7GeneratedRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationPhase7GeneratedConfig(string $project): array
{
    return [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'providers' => [
            'common' => [FoundationPhase7GeneratedProvider::class],
        ],
    ];
}

function foundationPhase7GeneratedProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase7-generated-' . bin2hex(random_bytes(5));
    foreach ([
        '/bootstrap/cache',
        '/routes',
        '/storage/cache/locks',
        '/storage/logs',
    ] as $directory) {
        mkdir($project . $directory, 0777, true);
    }

    file_put_contents($project . '/infbyte', <<<'PHP'
<?php

declare(strict_types=1);

if (($argv[1] ?? '') === 'phase7:scheduled') {
    file_put_contents($argv[2], ($argv[3] ?? 'generated') . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

exit(64);
PHP);

    return $project;
}

function foundationPhase7GeneratedScheduleRoutes(string $project, string $marker): void
{
    $exportedMarker = var_export($marker, true);
    file_put_contents($project . '/routes/schedule.php', <<<PHP
<?php

declare(strict_types=1);

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule \$schedule): void {
    \$schedule->command('phase7:scheduled')
        ->arguments([{$exportedMarker}, 'generated'])
        ->key('phase7-generated')
        ->everyMinute()
        ->withoutOverlap(true, 5.0, 0.0);
};
PHP);
}

function foundationPhase7PoisonProviders(string $project, string $message): void
{
    $export = var_export($message, true);
    file_put_contents(
        $project . '/bootstrap/providers.php',
        "<?php\n\nthrow new RuntimeException({$export});\n",
    );
}

/** @return list<string> */
function foundationPhase7Lines(string $path): array
{
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return is_array($lines) ? array_values($lines) : [];
}

function foundationPhase7GeneratedRemove(string $directory): void
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
