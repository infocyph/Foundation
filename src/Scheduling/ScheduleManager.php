<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Command\CommandStatus;
use Infocyph\Foundation\Operations\ExecutionHistory;
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

    public function work(
        int $sleepSeconds = 60,
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/schedule.php',
        ?int $maxIterations = null,
    ): int {
        $sleepSeconds = max(1, $sleepSeconds);
        $iterations = 0;
        while ($maxIterations === null || $iterations < $maxIterations) {
            $this->runDue($routes, $manifest);
            $iterations++;
            if ($maxIterations !== null && $iterations >= $maxIterations) {
                break;
            }
            time_nanosleep($sleepSeconds, 0);
        }

        return 0;
    }

    public function write(string $routes = 'routes/schedule.php', string $manifest = 'bootstrap/cache/schedule.php'): string
    {
        $path = $this->path($manifest);
        $routePath = $this->path($routes);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create schedule cache directory "%s".', $directory));
        }

        $payload = [
            'version' => self::MANIFEST_VERSION,
            'source' => $this->sourceMetadata($routePath),
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
                if (is_array($payload) && $this->manifestFresh($payload, $routePath)) {
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
                // Cached schedule metadata is optional; source remains authoritative.
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

    /** @param array<string, mixed> $manifest */
    private function manifestFresh(array $manifest, string $routePath): bool
    {
        if (($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
            return false;
        }
        $source = $manifest['source'] ?? null;
        if (!is_array($source) || !is_bool($source['exists'] ?? null)) {
            return false;
        }

        $exists = is_file($routePath);
        if ($source['exists'] !== $exists) {
            return false;
        }
        if (!$exists) {
            return ($source['sha256'] ?? null) === null;
        }

        $expected = $source['sha256'] ?? null;
        if (!is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            return false;
        }
        $actual = hash_file('sha256', $routePath);

        return is_string($actual) && hash_equals($expected, $actual);
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
        $this->record($history, $executionId, $name, CommandStatus::Pending, metadata: [
            'schedule_identity' => $entry->identity(),
        ]);

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
                    $this->record($history, $executionId, $name, CommandStatus::Waiting);
                }
                $lock = $this->application->make(CacheLayerFactory::class)->lock();
                $handle = $lock->acquire(
                    'foundation-schedule-' . substr(hash('sha256', $entry->identity()), 0, 44),
                    $entry->overlapWaitSeconds(),
                    $entry->overlapLeaseSeconds(),
                );
                if ($handle === null) {
                    $this->record($history, $executionId, $name, CommandStatus::Cancelled, 0, [
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

            $this->record($history, $executionId, $name, CommandStatus::Running);
            $result = new SchedulerRuntime($this->application)->execute(
                fn(): ProcessResult => new ProcessRunner()->run(
                    $process,
                    new ProcessOptions(
                        cwd: $this->application->basePath(),
                        timeoutSeconds: $entry->timeoutSeconds(),
                        interactive: false,
                        captureOutput: false,
                        passthrough: true,
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
                [
                    'reason' => $result->reason->value,
                    'signal' => $result->signal,
                    'duration_ns' => $result->durationNanoseconds,
                ],
            );

            return new ScheduleRun($entry, $result->exitCode);
        } catch (\Throwable $exception) {
            $this->record($history, $executionId, $name, CommandStatus::Failed, metadata: [
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

    /** @return array{exists:bool,path:string,sha256:?string} */
    private function sourceMetadata(string $routePath): array
    {
        $exists = is_file($routePath);
        $hash = $exists ? hash_file('sha256', $routePath) : null;
        if ($exists && !is_string($hash)) {
            throw new \RuntimeException(sprintf('Unable to hash schedule route file "%s".', $routePath));
        }

        return [
            'exists' => $exists,
            'path' => 'routes/schedule.php',
            'sha256' => $hash,
        ];
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
