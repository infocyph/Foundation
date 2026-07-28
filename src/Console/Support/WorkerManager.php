<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Console\Cache\CommandMutex;
use Infocyph\Console\Worker\WorkerRunSummary;
use Infocyph\Console\Worker\WorkerSupervisor;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Console\WorkerProvider;

final readonly class WorkerManager
{
    public function __construct(private Application $application) {}

    /** @return array<string, string> */
    public function all(string $routes = 'routes/workers.php'): array
    {
        $path = $this->path($routes);
        if (!is_file($path)) {
            return [];
        }
        $definitions = require $path;
        if (!is_array($definitions)) {
            throw new \UnexpectedValueException(sprintf('Worker route file "%s" must return a class map.', $path));
        }

        $workers = [];
        foreach ($definitions as $name => $provider) {
            if (
                !is_string($name)
                || $name === ''
                || !is_string($provider)
                || $provider === ''
            ) {
                throw new \UnexpectedValueException('Worker definitions must map non-empty names to provider class strings.');
            }
            $workers[$name] = $provider;
        }

        return $workers;
    }

    public function run(string $name, string $routes = 'routes/workers.php'): ?WorkerRunSummary
    {
        $providerClass = $this->all($routes)[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Worker "%s" is not defined.',
            $name,
        ));
        if (!is_a($providerClass, WorkerProvider::class, true)) {
            throw new \UnexpectedValueException(sprintf(
                'Worker "%s" provider "%s" must implement %s.',
                $name,
                $providerClass,
                WorkerProvider::class,
            ));
        }
        $provider = $this->application->boot()->make($providerClass);

        $options = $provider->options();
        $leaseSeconds = max(30.0, ($options->processMaxSeconds ?? 300.0) + $options->terminationGraceSeconds);
        $mutex = new CommandMutex($this->application->make(CacheLayerFactory::class)->lock());
        $handle = $mutex->acquire('worker:' . $name, 0.0, $leaseSeconds);
        if ($handle === null) {
            return null;
        }
        $nextRefresh = microtime(true) + $leaseSeconds / 3;

        try {
            return new WorkerSupervisor()->run(
                $provider->command(),
                $provider->workload(),
                $options,
                static function () use ($handle, $leaseSeconds, $mutex, &$nextRefresh): bool {
                    if (microtime(true) < $nextRefresh) {
                        return true;
                    }
                    $nextRefresh = microtime(true) + $leaseSeconds / 3;

                    return $mutex->refresh($handle, $leaseSeconds);
                },
            );
        } finally {
            $mutex->release($handle);
        }
    }

    private function path(string $path): string
    {
        if (preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }
}
