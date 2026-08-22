<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

final class CommandExecutionCoordinator
{
    private const string SUPERVISED_ENV = 'INFOCYPH_FOUNDATION_SUPERVISED';

    private ?LockProviderInterface $locks = null;

    public function __construct(
        private readonly Application $application,
        private readonly ProcessRunner $processes = new ProcessRunner(),
        private readonly ?string $executable = null,
    ) {}

    /**
     * @param list<string> $argv
     * @param callable():int $inline
     */
    public function run(
        CommandDescriptor $descriptor,
        array $argv,
        callable $inline,
        CommandIO $io,
    ): int {
        $policy = $descriptor->definition->executionPolicy();
        if (!$policy->requiresSupervisor() || getenv(self::SUPERVISED_ENV) === '1') {
            return $inline();
        }

        $lock = null;
        $handle = null;
        if ($policy->overlap !== OverlapMode::Allow) {
            $lock = $this->lockProvider();
            $handle = $lock->acquire(
                $policy->mutex ?? $descriptor->definition->commandName(),
                $policy->overlap === OverlapMode::Wait ? $policy->waitSeconds : 0.0,
                $policy->leaseSeconds,
            );
            if ($handle === null) {
                $io->writeln(sprintf(
                    'Command "%s" is already running; execution skipped.',
                    $descriptor->definition->commandName(),
                ));

                return ExitCode::SUCCESS;
            }
        }

        try {
            return $this->isolated($descriptor, $argv, $policy, $handle, $lock)->exitCode;
        } finally {
            $lock?->release($handle);
        }
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
        ?LockHandle $handle,
        ?LockProviderInterface $lock,
    ): \Infocyph\Foundation\Process\ProcessResult {
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
                environment: [self::SUPERVISED_ENV => '1'],
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
}
