<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Filesystem\StorageLinkManager;
use Infocyph\Foundation\Messaging\ConsumerFactory;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Routing\RouteCacheManager;
use Infocyph\Foundation\Scheduling\ScheduleManager;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Omnibus\Consumer\Command\ConsumeRequest;
use Infocyph\Omnibus\Consumer\Command\ConsumerTask;
use Infocyph\Omnibus\Scheduling\ScheduledMessageDispatcher;

final class RuntimeSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'queue:consume' => $this->consume(),
            'route:cache' => $this->routeCache(),
            'route:clear' => $this->routeClear(),
            'route:list' => $this->routeList(),
            'schedule:cache' => $this->scheduleCache(),
            'schedule:clear' => $this->scheduleClear(),
            'schedule:dispatch-message' => $this->dispatchScheduledMessage(),
            'schedule:list' => $this->scheduleList(),
            'schedule:run' => $this->scheduleRun(),
            'schedule:test' => $this->scheduleTest(),
            'schedule:work' => $this->scheduleWork(),
            'session:prune' => $this->sessionPrune(),
            'storage:link' => $this->storageLink(),
            'storage:status' => $this->storageStatus(),
            'storage:unlink' => $this->storageUnlink(),
            'worker:list' => $this->workerList(),
            'worker:run' => $this->workerRun(),
            default => throw new \LogicException('Unsupported runtime system command.'),
        };
    }

    private function consume(): int
    {
        $queue = $this->option('queue', 'default') ?? 'default';
        $limit = $this->positiveIntOption('limit', 1, 1_000);
        $visibility = $this->positiveFloatOption('visibility', 60.0);
        $request = new ConsumeRequest($queue, $limit, $visibility);
        $transport = $this->option('transport');

        $result = $transport === null
            ? $this->application->make(ConsumerTask::class)->run($request)
            : $this->application->make(ConsumerFactory::class)
                ->make($transport)
                ->run($request->queue, $request->limit, $request->visibilitySeconds);

        $data = [
            'received' => $result->received,
            'succeeded' => $result->succeeded,
            'released' => $result->released,
            'failed' => $result->failed,
        ];
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } else {
            $this->io()->table(
                ['Received', 'Succeeded', 'Released', 'Failed'],
                [[$result->received, $result->succeeded, $result->released, $result->failed]],
            );
        }

        return $result->failed === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function dispatchScheduledMessage(): int
    {
        $name = $this->argument(0)
            ?? throw new \LogicException('Validated scheduled-message name is unavailable.');
        $envelope = $this->application->make(ScheduledMessageDispatcher::class)->dispatch($name);

        return $this->emit(
            ['name' => $name, 'message' => get_debug_type($envelope->message)],
            sprintf('Scheduled message "%s" dispatched.', $name),
        );
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }

        return $this->positiveInt($name, $value);
    }

    private function positiveFloatOption(string $name, float $default): float
    {
        $value = $this->option($name);
        if ($value === null) {
            return $default;
        }
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0.0) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive finite number.', $name));
        }

        return (float) $value;
    }

    private function positiveInt(string $name, string $value, ?int $maximum = null): int
    {
        if (preg_match('/^\d+$/D', $value) !== 1 || (int) $value < 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }
        $resolved = (int) $value;
        if ($maximum !== null && $resolved > $maximum) {
            throw new \InvalidArgumentException(sprintf('--%s must not exceed %d.', $name, $maximum));
        }

        return $resolved;
    }

    private function positiveIntOption(string $name, int $default, ?int $maximum = null): int
    {
        $value = $this->option($name);

        return $value === null ? $default : $this->positiveInt($name, $value, $maximum);
    }

    /** @param list<array<string,mixed>> $links */
    private function renderStorage(array $links, string $state): int
    {
        if ($this->io()->machineReadable()) {
            $this->io()->json($links);
        } else {
            $key = strtolower($state);
            $this->io()->table(
                ['Link', 'Target', $state],
                array_map(
                    static fn(array $link): array => [$link['link'], $link['target'], $link[$key] ?? false],
                    $links,
                ),
            );
        }

        return ExitCode::SUCCESS;
    }

    private function routeCache(): int
    {
        $manager = new RouteCacheManager($this->application);
        $matcher = $this->option('matcher', $manager->configuredMatcher()) ?? $manager->configuredMatcher();
        $cache = $this->option('cache', $manager->cachePath(null)) ?? $manager->cachePath(null);
        $path = $manager->write($matcher, $cache, $this->option('routes'));

        return $this->emit(
            ['matcher' => $matcher, 'path' => $path],
            sprintf('Routes cached using %s matcher at %s.', $matcher, $path),
        );
    }

    private function routeClear(): int
    {
        $removed = new RouteCacheManager($this->application)->clearAll();

        return $this->emit(
            ['removed' => $removed],
            $removed ? 'Route cache cleared.' : 'Route cache is already clear.',
        );
    }

    private function routeList(): int
    {
        $routes = new RouteCacheManager($this->application)->routes($this->option('routes'))->all();
        $data = array_map(
            static fn($route): array => [
                'method' => $route->getMethod(),
                'path' => $route->getPath(),
                'name' => $route->getName(),
                'domain' => $route->getDomain(),
                'handler' => $route->getHandlerId(),
                'middleware' => count($route->getMiddlewares()),
            ],
            $routes,
        );

        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } else {
            $this->io()->table(
                ['Method', 'Path', 'Name', 'Domain', 'Handler', 'Middleware'],
                array_map(
                    static fn(array $route): array => [
                        $route['method'],
                        $route['path'],
                        $route['name'] ?? '',
                        $route['domain'] ?? '',
                        $route['handler'],
                        $route['middleware'],
                    ],
                    $data,
                ),
            );
        }

        return ExitCode::SUCCESS;
    }

    private function scheduleCache(): int
    {
        $path = new ScheduleManager($this->application)->write();

        return $this->emit(['path' => $path], 'Schedule manifest cached: ' . $path);
    }

    private function scheduleClear(): int
    {
        $removed = new ScheduleManager($this->application)->clear();

        return $this->emit(
            ['removed' => $removed],
            $removed ? 'Schedule manifest cleared.' : 'Schedule manifest is already clear.',
        );
    }

    private function scheduleList(): int
    {
        $entries = new ScheduleManager($this->application)->entries();
        $history = new ExecutionHistory($this->application);
        $data = array_map(static function ($entry) use ($history): array {
            $manifest = $entry->toManifest();
            $last = $history->latestByMetadata('schedule', 'schedule_identity', $entry->identity());
            $manifest['last_status'] = $last['status'] ?? null;
            $manifest['last_recorded_at'] = isset($last['recorded_at']) ? (float) $last['recorded_at'] : null;

            return $manifest;
        }, $entries);
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);

            return ExitCode::SUCCESS;
        }

        $this->io()->table(
            ['Key', 'Command', 'Cron', 'Timezone', 'Overlap', 'One Server', 'Last Status'],
            array_map(
                static fn(array $entry): array => [
                    $entry['key'] ?? '',
                    $entry['command'],
                    $entry['cron'],
                    $entry['timezone'],
                    $entry['without_overlap'],
                    $entry['on_one_server'],
                    $entry['last_status'] ?? '',
                ],
                $data,
            ),
        );

        return ExitCode::SUCCESS;
    }

    private function scheduleRun(): int
    {
        $runs = new ScheduleManager($this->application)->runDue();
        $data = array_map($this->scheduleRunData(...), $runs);
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } else {
            $this->io()->table(
                ['Command', 'Exit', 'Locked', 'Successful'],
                array_map(
                    static fn(array $run): array => [
                        $run['command'],
                        $run['exit_code'],
                        $run['locked'],
                        $run['successful'],
                    ],
                    $data,
                ),
            );
        }

        return array_any($runs, static fn($run): bool => !$run->locked && !$run->successful())
            ? ExitCode::FAILURE
            : ExitCode::SUCCESS;
    }

    /** @return array{command:string,identity:string,exit_code:int,locked:bool,successful:bool} */
    private function scheduleRunData($run): array
    {
        return [
            'command' => $run->entry->command(),
            'identity' => $run->entry->identity(),
            'exit_code' => $run->exitCode,
            'locked' => $run->locked,
            'successful' => $run->successful(),
        ];
    }

    private function scheduleTest(): int
    {
        $name = $this->argument(0)
            ?? throw new \LogicException('Validated scheduled entry name is unavailable.');
        $run = new ScheduleManager($this->application)->runNamed($name);
        $data = $this->scheduleRunData($run);
        $message = $run->locked
            ? sprintf('Scheduled entry "%s" was not run because its ownership lock is unavailable.', $name)
            : sprintf('Scheduled entry "%s" completed with exit code %d.', $name, $run->exitCode);
        $this->emit($data, $message);

        return $run->successful() ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function scheduleWork(): int
    {
        $sleep = $this->positiveIntOption('sleep', 60);
        $iterations = $this->nullablePositiveIntOption('max-iterations');

        return new ScheduleManager($this->application)->work(
            sleepSeconds: $sleep,
            maxIterations: $iterations,
        );
    }

    private function sessionPrune(): int
    {
        $limit = $this->positiveIntOption('limit', 1_000);
        $count = $this->application->make(SessionManager::class)->prune($limit);

        return $this->emit(['pruned' => $count], sprintf('Pruned %d expired session(s).', $count));
    }

    private function storageLink(): int
    {
        $links = $this->application->make(StorageLinkManager::class)->create();

        return $this->renderStorage($links, 'Created');
    }

    private function storageStatus(): int
    {
        $links = $this->application->make(StorageLinkManager::class)->status();
        if ($this->io()->machineReadable()) {
            $this->io()->json($links);
        } else {
            $this->io()->table(
                ['Link', 'Target', 'Exists', 'Linked', 'Matches'],
                array_map(static fn(array $link): array => [
                    $link['link'],
                    $link['target'],
                    $link['exists'],
                    $link['linked'],
                    $link['matches'],
                ], $links),
            );
        }

        return array_any($links, static fn(array $link): bool => !$link['matches'])
            ? ExitCode::FAILURE
            : ExitCode::SUCCESS;
    }

    private function storageUnlink(): int
    {
        $links = $this->application->make(StorageLinkManager::class)->remove();

        return $this->renderStorage($links, 'Removed');
    }

    private function workerList(): int
    {
        $workers = new WorkerManager($this->application)->all();
        if ($this->io()->machineReadable()) {
            $this->io()->json($workers);

            return ExitCode::SUCCESS;
        }

        $rows = [];
        foreach ($workers as $name => $worker) {
            $rows[] = [
                $name,
                $worker['type'] ?? '',
                $worker['queue'] ?? '',
                $worker['transport'] ?? '',
                $worker['singleton'] ?? false,
                $worker['pool'] ?? false,
                $worker['concurrency'] ?? '',
            ];
        }
        $this->io()->table(
            ['Worker', 'Type', 'Queue', 'Transport', 'Singleton', 'Pool', 'Concurrency'],
            $rows,
        );

        return ExitCode::SUCCESS;
    }

    private function workerRun(): int
    {
        $name = $this->argument(0)
            ?? throw new \LogicException('Validated worker name is unavailable.');
        $exit = new WorkerManager($this->application)->run($name);
        if ($exit === null) {
            $this->io()->note(sprintf('Worker "%s" is already owned by another singleton process.', $name));

            return ExitCode::SUCCESS;
        }

        return $exit;
    }
}
