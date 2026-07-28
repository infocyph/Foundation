<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Console\Cache\CommandMutex;
use Infocyph\Console\Process\ProcessMode;
use Infocyph\Console\Process\ProcessOptions;
use Infocyph\Console\Process\ProcessRunner;
use Infocyph\Console\Scheduling\Schedule;
use Infocyph\Console\Scheduling\ScheduledCommand;
use Infocyph\Console\Scheduling\ScheduleLease;
use Infocyph\Console\Scheduling\ScheduleManifest;
use Infocyph\Console\Scheduling\ScheduleManifestCompiler;
use Infocyph\Console\Scheduling\ScheduleRun;
use Infocyph\Console\Scheduling\ScheduleRunner;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;

final readonly class ScheduleManager
{
    public function __construct(private Application $application) {}

    public function clear(string $manifest = 'bootstrap/cache/console/schedule.php'): bool
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
    public function entries(
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/console/schedule.php',
    ): array {
        return $this->load($routes, $manifest)->entries();
    }

    /** @return list<ScheduleRun> */
    public function runDue(
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/console/schedule.php',
        ?\DateTimeInterface $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $schedule = $this->load($routes, $manifest);
        $mutex = $this->requiresMutex($schedule, $now)
            ? new CommandMutex($this->locks())
            : null;

        return new ScheduleRunner($mutex)->runDue(
            $schedule,
            fn(string $command, ScheduledCommand $entry, ?ScheduleLease $lease): int => $this->execute(
                $command,
                $entry,
                $lease,
            ),
            $now,
        );
    }

    public function write(
        string $routes = 'routes/schedule.php',
        string $manifest = 'bootstrap/cache/console/schedule.php',
    ): string {
        $path = $this->path($manifest);
        new ScheduleManifestCompiler()->write($this->load($routes, ''), $path);

        return $path;
    }

    private function executable(): string
    {
        $configured = $this->application->config()->get('console.executable');

        return is_string($configured) && $configured !== ''
            ? $this->path($configured)
            : $this->application->basePath('infbyte');
    }

    private function execute(string $command, ScheduledCommand $entry, ?ScheduleLease $lease): int
    {
        $process = [PHP_BINARY];
        if ($entry->memoryLimitMegabytes() !== null) {
            $process[] = '-d';
            $process[] = 'memory_limit=' . $entry->memoryLimitMegabytes() . 'M';
        }
        $process[] = $this->executable();
        $process[] = $command;
        array_push($process, ...$entry->commandArguments());

        $heartbeat = $lease === null ? null : $lease->heartbeat(...);

        return new ProcessRunner()->run(
            $process,
            new ProcessOptions(
                timeoutSeconds: $entry->timeoutSeconds(),
                idleTimeoutSeconds: $entry->idleTimeoutSeconds(),
                heartbeat: $heartbeat,
                passthrough: true,
                inheritInput: true,
                mode: ProcessMode::STREAM,
                terminationGraceSeconds: $entry->terminationGraceSeconds(),
            ),
        )->exitCode;
    }

    private function load(string $routes, string $manifest): Schedule
    {
        $manifestPath = $manifest === '' ? '' : $this->path($manifest);
        if ($manifestPath !== '' && is_file($manifestPath)) {
            return ScheduleManifest::load($manifestPath);
        }

        $routePath = $this->path($routes);
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

    private function locks(): \Infocyph\CacheLayer\Cache\Lock\LockProviderInterface
    {
        if (!class_exists(\Infocyph\CacheLayer\Cache\Cache::class)) {
            throw new \LogicException(
                'Scheduling requires infocyph/cachelayer; run "php infbyte module:install cache".',
            );
        }

        return $this->application->boot()->make(CacheLayerFactory::class)->lock();
    }

    private function path(string $path): string
    {
        if (preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    private function requiresMutex(Schedule $schedule, \DateTimeInterface $now): bool
    {
        return array_any($schedule->entries(), fn($entry) => $entry->due($now)
        && ($entry->preventsOverlap() || $entry->requiresSingleServer()));
    }
}
