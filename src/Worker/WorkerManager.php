<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;

final readonly class WorkerManager
{
    public function __construct(private Application $application) {}

    /** @return array<string, class-string<WorkerProvider>> */
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
            if (!is_string($name)
                || $name === ''
                || !is_string($provider)
                || $provider === ''
                || !is_a($provider, WorkerProvider::class, true)
            ) {
                throw new \UnexpectedValueException(sprintf(
                    'Worker definitions must map non-empty names to %s implementations.',
                    WorkerProvider::class,
                ));
            }
            /** @var class-string<WorkerProvider> $provider */
            $workers[$name] = $provider;
        }

        return $workers;
    }

    public function run(string $name, string $routes = 'routes/workers.php'): ?int
    {
        $providerClass = $this->all($routes)[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Worker "%s" is not defined.',
            $name,
        ));
        $provider = $this->application->boot()->make($providerClass);
        $runtime = new WorkerRuntime($this->application);
        $leaseSeconds = max(30.0, $this->leaseSeconds());
        $lock = $this->application->make(CacheLayerFactory::class)->lock();
        $handle = $lock->acquire('foundation:worker:' . $name, 0.0, $leaseSeconds);
        if ($handle === null) {
            return null;
        }

        try {
            return $provider->run($runtime);
        } finally {
            $lock->release($handle);
        }
    }

    private function leaseSeconds(): float
    {
        $value = $this->application->config()->get('worker.lock_lease_seconds', 300.0);
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 300.0;
    }

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }
}
