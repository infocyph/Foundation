<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Command\CommandStatus;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Process\ProcessTerminationReason;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class ScheduleManager
{
    private const int MANIFEST_VERSION = 1;

    public function __construct(private Application $application) {}

    public function clear(string $manifest = 'bootstrap/cache/schedule.php'): bool
    {
        $path = $this->path($manifest);
        if (!is_file($path)) {
            return false;
        }
        if (!unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove schedule manifest "%s".', $path));
        }

        return true;
    }

    public function configured(string $routes = 'routes/schedule.php'): bool
    {
        return is_file($this->path($routes));
    }

    /** @return list<ScheduledCommand> */
    public function entries(string $routes = 'routes/schedule.php', string $manifest = 'bootstrap/cache/schedule.php'): array
    {
        return $this->load($routes, $manifest)->entries();
    }

    /** @return list<ScheduleRun> */
    public function runDue(
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/schedule.php',
        ?\DateTimeInterface $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $runs = [];
        foreach ($this->load($routes, $manifest)->entries() as $entry) {
            if ($entry->due($now)) {
                $runs[] = $this->runEntry($entry);
            }
        }

        return $runs;
    }

    public function runNamed(
        string $name,
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/schedule.php',
    ): ScheduleRun {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Scheduled entry name cannot be empty.');
        }

        $entries = $this->load($routes, $manifest)->entries();
        $identityMatches = array_values(array_filter(
            $entries,
            static fn(ScheduledCommand $entry): bool => $entry->identity() === $name,
        ));
        if (count($identityMatches) === 1) {
            return $this->runEntry($identityMatches[0]);
        }

        $commandMatches = array_values(array_filter(
            $entries,
            static fn(ScheduledCommand $entry): bool => $entry->command() === $name,
        ));
        if (count($commandMatches) === 1) {
            return $this->runEntry($commandMatches[0]);
        }
        if (count($commandMatches) > 1) {
            throw new \InvalidArgumentException(sprintf(
                'Scheduled command "%s" is ambiguous; assign/use a unique schedule key.',
                $name,
            ));
        }

        throw new \InvalidArgumentException(sprintf('Scheduled entry "%s" is not defined.', $name));
    }

    public function work(
        int $sleepSeconds = 60,
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/schedule.php',
        ?int $maxIterations = null,
    ): int {
        $sleepSeconds = max(1, $sleepSeconds);
        $iterations = 0;
        $control = new RuntimeControl($this->application);
        $registry = new RuntimeProcessRegistry($this->application);
        $runtimeToken = $control->token('runtime');
        $scheduleToken = $control->token('schedule');
        $process = $registry->register('schedule', 'default');

        try {
            while ($maxIterations === null || $iterations < $maxIterations) {
                if ($this->interrupted($control, $runtimeToken, $scheduleToken)) {
                    return 0;
                }

                $this->runDue($routes, $manifest);
                $iterations++;
                $process = $registry->heartbeat($process);
                if ($maxIterations !== null && $iterations >= $maxIterations) {
                    break;
                }

                $remaining = $sleepSeconds;
                while ($remaining-- > 0) {
                    if ($this->interrupted($control, $runtimeToken, $scheduleToken)) {
                        return 0;
                    }
                    usleep(1_000_000);
                }
            }

            return 0;
        } finally {
            $registry->unregister($process);
        }
    }

    public function write(string $routes = 'routes/schedule.php', string $manifest = 'bootstrap/cache/schedule.php'): string
    {
        $path = $this->path($manifest);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create schedule cache directory "%s".', $directory));
        }

        $payload = [
            'version' => self::MANIFEST_VERSION,
            'entries' => array_map(
                static fn(ScheduledCommand $entry): array => $entry->toManifest(),
                $this->load($routes, '')->entries(),
            ),
        ];
        $temporary = tempnam($directory, '.schedule-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create schedule manifest staging file.');
        }

        try {
            $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to publish schedule manifest "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $path;
    }

    private function interrupted(
        RuntimeControl $control,
        string $runtimeToken,
        string $scheduleToken,
    ): bool {
        return $control->changed('runtime', null, $runtimeToken)
            || $control->changed('schedule', null, $scheduleToken);
    }

    private function executable(): string
    {
        $configured = $this->application->config()->get('command.executable');
        if (is_string($configured) && $configured !== '') {
            return $this->path($configured);
        }

        $project = $this->application->basePath('infbyte');

        return is_file($project)
            ? $project
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'infbyte';
    }

    private function load(string $routes, string $manifest): Schedule
    {
        $routePath = $this->path($routes);
        $manifestPath = $manifest === '' ? '' : $this->path($manifest);
        if ($manifestPath !== '' && is_file($manifestPath)) {
            try {
                $payload = require $manifestPath;
                if (is_array($payload) && ($payload['version'] ?? null) === self::MANIFEST_VERSION) {
                    $entries = $payload['entries'] ?? null;
                    if (!is_array($entries)) {
                        throw new \UnexpectedValueException('Schedule manifest entries must be an array.');
                    }

                    $schedule = new Schedule();
                    foreach ($entries as $entry) {
                        if (!is_array($entry)) {
                            throw new \UnexpectedValueException('Schedule manifest entries must be arrays.');
                        }
                        $schedule->add(ScheduledCommand::fromManifest($this->manifestEntry($entry)));
                    }

                    return $schedule;
                }
            } catch (\Throwable) {
                // A schedule cache is an optimization. Source routes remain authoritative.
            }
        }

        if (!is_file($routePath)) {
            return new Schedule();
        }
        $definition = require $routePath;
        if ($definition instanceof Schedule) {
            return $definition;
        }
        if (!is_callable($definition)) {
            throw new \UnexpectedValueException(sprintf(
                'Schedule route file "%s" must return a Schedule or callable.',
                $routePath,
            ));
        }

        $schedule = new Schedule();
        $definition($schedule);

        return $schedule;
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array<string, mixed>
     */
    private function manifestEntry(array $entry): array
    {
        $normalized = [];
        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    private function runEntry(ScheduledCommand $entry): ScheduleRun
    {
        $executionId = ExecutionId::generate();
        $history = new ExecutionHistory($this->application);
        $name = $entry->command();
        $identity = ['schedule_identity' => $entry->identity()];
        $this->record($history, $executionId, $name, CommandStatus::Pending, metadata: $identity);

        $lock = null;
        $handle = null;
        try {
            if ($entry->preventsOverlap() || $entry->requiresSingleServer()) {
                if (!interface_exists(LockProviderInterface::class)) {
                    throw new \LogicException(
                        'Schedule overlap/single-server policy requires infocyph/cachelayer.',
                    );
                }
                if ($entry->overlapWaitSeconds() > 0.0) {
                    $this->record($history, $executionId, $name, CommandStatus::Waiting, metadata: $identity);
                }
                $lock = $this->application->make(CacheLayerFactory::class)->lock();
                $handle = $lock->acquire(
                    'foundation-schedule-' . substr(hash('sha256', $entry->identity()), 0, 44),
                    $entry->overlapWaitSeconds(),
                    $entry->overlapLeaseSeconds(),
                );
                if ($handle === null) {
                    $this->record($history, $executionId, $name, CommandStatus::Cancelled, 0, $identity + [
                        'reason' => 'overlap',
                    ]);

                    return new ScheduleRun($entry, 0, locked: true);
                }
            }

            $process = [PHP_BINARY];
            if ($entry->memoryLimitMegabytes() !== null) {
                $process[] = '-d';
                $process[] = 'memory_limit=' . $entry->memoryLimitMegabytes() . 'M';
            }
            $process[] = $this->executable();
            $process[] = $entry->command();
            array_push($process, ...$entry->commandArguments());

            $heartbeat = null;
            if ($lock !== null && $handle !== null) {
                $leaseSeconds = $entry->overlapLeaseSeconds();
                $refreshIntervalNs = max(
                    1,
                    (int) floor(min($leaseSeconds / 3.0, 5.0) * 1_000_000_000),
                );
                $nextRefreshAt = hrtime(true) + $refreshIntervalNs;
                $heartbeat = static function () use (
                    $lock,
                    $handle,
                    $leaseSeconds,
                    $refreshIntervalNs,
                    &$nextRefreshAt,
                ): bool {
                    $now = hrtime(true);
                    if ($now < $nextRefreshAt) {
                        return true;
                    }
                    if (!$lock->refresh($handle, $leaseSeconds)) {
                        return false;
                    }

                    $nextRefreshAt = $now + $refreshIntervalNs;

                    return true;
                };
            }

            $this->record($history, $executionId, $name, CommandStatus::Running, metadata: $identity);
            $result = new SchedulerRuntime($this->application)->execute(
                fn(): ProcessResult => new ProcessRunner()->run(
                    $process,
                    new ProcessOptions(
                        cwd: $this->application->basePath(),
                        timeoutSeconds: $entry->timeoutSeconds(),
                        interactive: false,
                        captureOutput: false,
                        passthrough: true,
                        heartbeat: $heartbeat,
                    ),
                ),
                [ScheduledCommand::class => $entry],
                $executionId,
            );
            if (!$result instanceof ProcessResult) {
                throw new \UnexpectedValueException('Scheduled process execution must return ProcessResult.');
            }

            $this->record(
                $history,
                $executionId,
                $name,
                $this->status($result),
                $result->exitCode,
                $identity + [
                    'reason' => $result->reason->value,
                    'signal' => $result->signal,
                    'duration_ns' => $result->durationNanoseconds,
                ],
            );

            return new ScheduleRun($entry, $result->exitCode);
        } catch (\Throwable $exception) {
            $this->record($history, $executionId, $name, CommandStatus::Failed, metadata: $identity + [
                'exception' => $exception::class,
            ]);
            throw $exception;
        } finally {
            $lock?->release($handle);
        }
    }

    /** @param array<string, scalar|null> $metadata */
    private function record(
        ExecutionHistory $history,
        ExecutionId $executionId,
        string $name,
        CommandStatus $status,
        ?int $exitCode = null,
        array $metadata = [],
    ): void {
        $history->record(
            kind: 'schedule',
            executionId: $executionId->value,
            name: $name,
            status: $status->value,
            exitCode: $exitCode,
            metadata: $metadata,
        );
    }

    private function status(ProcessResult $result): CommandStatus
    {
        return match ($result->reason) {
            ProcessTerminationReason::TimedOut,
            ProcessTerminationReason::IdleTimedOut => CommandStatus::TimedOut,
            ProcessTerminationReason::Cancelled,
            ProcessTerminationReason::Interrupted => CommandStatus::Cancelled,
            default => $result->successful() ? CommandStatus::Succeeded : CommandStatus::Failed,
        };
    }
}
