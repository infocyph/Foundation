<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;
use Infocyph\Foundation\Process\ProcessTerminationReason;
use Infocyph\Foundation\Runtime\ExecutionId;

final class CommandExecutionCoordinator
{
    private const string EXECUTION_ENV = 'INFOCYPH_FOUNDATION_EXECUTION_ID';

    private const string SUPERVISED_ENV = 'INFOCYPH_FOUNDATION_SUPERVISED';

    private ?LockProviderInterface $locks = null;

    public function __construct(
        private readonly Application $application,
        private readonly ProcessRunner $processes = new ProcessRunner(),
        private readonly ?string $executable = null,
    ) {}

    /**
     * @param list<string> $argv
     * @param callable(ExecutionId):int $inline
     */
    public function run(
        CommandDescriptor $descriptor,
        array $argv,
        callable $inline,
        CommandIO $io,
    ): int {
        $executionId = $this->executionId();
        if (getenv(self::SUPERVISED_ENV) === '1') {
            return $inline($executionId);
        }

        $name = $descriptor->definition->commandName();
        $history = new ExecutionHistory($this->application);
        $this->record($history, $executionId, $name, CommandStatus::Pending);
        $policy = $descriptor->definition->executionPolicy();

        if (!$policy->requiresSupervisor()) {
            $this->record($history, $executionId, $name, CommandStatus::Running);

            try {
                $exitCode = $inline($executionId);
                $this->record(
                    $history,
                    $executionId,
                    $name,
                    $exitCode === ExitCode::SUCCESS ? CommandStatus::Succeeded : CommandStatus::Failed,
                    $exitCode,
                );

                return $exitCode;
            } catch (\Throwable $exception) {
                $this->record($history, $executionId, $name, CommandStatus::Failed, metadata: [
                    'exception' => $exception::class,
                ]);

                throw $exception;
            }
        }

        $lock = null;
        $handle = null;

        try {
            if ($policy->overlap !== OverlapMode::Allow) {
                if ($policy->overlap === OverlapMode::Wait) {
                    $this->record($history, $executionId, $name, CommandStatus::Waiting);
                }
                $lock = $this->lockProvider();
                $handle = $lock->acquire(
                    $policy->mutex ?? $name,
                    $policy->overlap === OverlapMode::Wait ? $policy->waitSeconds : 0.0,
                    $policy->leaseSeconds,
                );
                if ($handle === null) {
                    $this->record($history, $executionId, $name, CommandStatus::Cancelled, ExitCode::SUCCESS, [
                        'reason' => 'overlap',
                    ]);
                    $io->writeln(sprintf('Command "%s" is already running; execution skipped.', $name));

                    return ExitCode::SUCCESS;
                }
            }

            $this->record($history, $executionId, $name, CommandStatus::Running);
            $result = $this->isolated($descriptor, $argv, $policy, $executionId, $handle, $lock);
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

            return $result->exitCode;
        } catch (\Throwable $exception) {
            $this->record($history, $executionId, $name, CommandStatus::Failed, metadata: [
                'exception' => $exception::class,
            ]);

            throw $exception;
        } finally {
            $lock?->release($handle);
        }
    }

    private function executionId(): ExecutionId
    {
        $inherited = getenv(self::EXECUTION_ENV);

        return is_string($inherited) && $inherited !== ''
            ? new ExecutionId($inherited)
            : ExecutionId::generate();
    }

    /** @return Closure():bool|null */
    private function heartbeat(
        CommandExecutionPolicy $policy,
        ?LockHandle $handle,
        ?LockProviderInterface $lock,
    ): ?Closure {
        if ($handle === null || $lock === null) {
            return null;
        }

        $interval = max(100_000_000, (int) round($policy->leaseSeconds * 1_000_000_000 / 3));
        $nextRefresh = hrtime(true) + $interval;

        return static function () use ($handle, $interval, $lock, $policy, &$nextRefresh): bool {
            $now = hrtime(true);
            if ($now < $nextRefresh) {
                return true;
            }
            $nextRefresh = $now + $interval;

            return $lock->refresh($handle, $policy->leaseSeconds);
        };
    }

    private function isolated(
        CommandDescriptor $descriptor,
        array $argv,
        CommandExecutionPolicy $policy,
        ExecutionId $executionId,
        ?LockHandle $handle,
        ?LockProviderInterface $lock,
    ): ProcessResult {
        $executable = $this->executable ?? $argv[0] ?? null;
        if (!is_string($executable) || $executable === '' || !is_file($executable)) {
            throw new \LogicException(sprintf(
                'Isolated command "%s" requires an executable application entry file.',
                $descriptor->definition->commandName(),
            ));
        }

        $command = [PHP_BINARY];
        if ($policy->memoryLimitMegabytes !== null) {
            $command[] = '-d';
            $command[] = 'memory_limit=' . $policy->memoryLimitMegabytes . 'M';
        }
        $command[] = $executable;
        array_push($command, ...array_slice($argv, 1));

        return $this->processes->run(
            $command,
            new ProcessOptions(
                cwd: $this->application->basePath(),
                environment: [
                    self::SUPERVISED_ENV => '1',
                    self::EXECUTION_ENV => $executionId->value,
                ],
                timeoutSeconds: $policy->timeoutSeconds,
                idleTimeoutSeconds: $policy->idleTimeoutSeconds,
                maxOutputBytes: null,
                captureOutput: false,
                passthrough: true,
                inheritInput: true,
                heartbeat: $this->heartbeat($policy, $handle, $lock),
                terminationGraceSeconds: $policy->terminationGraceSeconds,
            ),
        );
    }

    private function lockProvider(): LockProviderInterface
    {
        if ($this->locks !== null) {
            return $this->locks;
        }
        if (!interface_exists(LockProviderInterface::class)) {
            throw new \LogicException(
                'Command overlap policy requires infocyph/cachelayer; install the cache module before using skip/wait overlap policies.',
            );
        }

        return $this->locks = $this->application->make(CacheLayerFactory::class)->lock();
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
            kind: 'command',
            executionId: $executionId->value,
            name: $name,
            status: $status->value,
            exitCode: $exitCode,
            metadata: $metadata + ['runtime' => $this->application->runtimeMode()->value],
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
