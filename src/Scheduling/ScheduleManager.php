<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessRunner;

final readonly class ScheduleManager
{
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
            sleep($sleepSeconds);
        }

        return 0;
    }

    public function write(string $routes = 'routes/schedule.php', string $manifest = 'bootstrap/cache/schedule.php'): string
    {
        $path = $this->path($manifest);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create schedule cache directory "%s".', $directory));
        }

        $payload = array_map(
            static fn(ScheduledCommand $entry): array => $entry->toManifest(),
            $this->load($routes, '')->entries(),
        );
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
        $manifestPath = $manifest === '' ? '' : $this->path($manifest);
        if ($manifestPath !== '' && is_file($manifestPath)) {
            $payload = require $manifestPath;
            if (!is_array($payload)) {
                throw new \UnexpectedValueException(sprintf('Schedule manifest "%s" is invalid.', $manifestPath));
            }

            $schedule = new Schedule();
            foreach ($payload as $entry) {
                if (!is_array($entry)) {
                    throw new \UnexpectedValueException('Schedule manifest entries must be arrays.');
                }
                $schedule->add(ScheduledCommand::fromManifest($entry));
            }

            return $schedule;
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

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    private function runEntry(ScheduledCommand $entry): ScheduleRun
    {
        $lock = null;
        $handle = null;
        if ($entry->preventsOverlap() || $entry->requiresSingleServer()) {
            $lock = $this->application->make(CacheLayerFactory::class)->lock();
            $handle = $lock->acquire(
                'foundation:schedule:' . $entry->identity(),
                $entry->overlapWaitSeconds(),
                $entry->overlapLeaseSeconds(),
            );
            if ($handle === null) {
                return new ScheduleRun($entry, 0, locked: true);
            }
        }

        try {
            $process = [PHP_BINARY];
            if ($entry->memoryLimitMegabytes() !== null) {
                $process[] = '-d';
                $process[] = 'memory_limit=' . $entry->memoryLimitMegabytes() . 'M';
            }
            $process[] = $this->executable();
            $process[] = $entry->command();
            array_push($process, ...$entry->commandArguments());

            $exitCode = new SchedulerRuntime($this->application)->execute(
                fn() => new ProcessRunner()->run(
                    $process,
                    new ProcessOptions(
                        cwd: $this->application->basePath(),
                        timeoutSeconds: $entry->timeoutSeconds(),
                        interactive: true,
                    ),
                )->exitCode,
                [ScheduledCommand::class => $entry],
            );

            return new ScheduleRun($entry, $exitCode);
        } finally {
            $lock?->release($handle);
        }
    }
}
