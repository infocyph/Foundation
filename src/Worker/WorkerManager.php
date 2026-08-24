<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Messaging\OmnibusWorkerFactory;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerPool;

final readonly class WorkerManager
{
    public function __construct(private Application $application) {}

    /** @return array<string, array<string, mixed>> */
    public function all(string $routes = 'routes/workers.php'): array
    {
        $providers = $this->providerDefinitions($routes);
        $messaging = $this->messagingDefinitions();
        $this->assertDistinctNames($providers, $messaging);

        $workers = [];
        foreach ($providers as $name => $definition) {
            $workers[$name] = [
                'type' => 'provider',
                'provider' => $definition['provider'],
                'singleton' => $definition['singleton'],
                'lock_lease_seconds' => $definition['lock_lease_seconds'],
            ];
        }
        foreach ($messaging as $name => $definition) {
            $pool = ValueNormalizer::associativeArray($definition['pool'] ?? []);
            $workers[$name] = [
                'type' => 'messaging',
                'transport' => ValueNormalizer::string(
                    $definition['transport'] ?? null,
                    ValueNormalizer::string(
                        $this->application->config()->get('messaging.consumer.transport'),
                        'memory',
                    ),
                ),
                'queue' => ValueNormalizer::string($definition['queue'] ?? null, 'default'),
                'pool' => ValueNormalizer::bool($pool['enabled'] ?? null, false),
                'concurrency' => ValueNormalizer::int($pool['concurrency'] ?? null, 2),
            ];
        }

        return $workers;
    }

    public function run(string $name, string $routes = 'routes/workers.php'): ?int
    {
        if (!$this->application->runningInWorker()) {
            throw new \LogicException('Workers must run from a Foundation worker runtime.');
        }

        $providers = $this->providerDefinitions($routes);
        $messaging = $this->messagingDefinitions();
        $this->assertDistinctNames($providers, $messaging);
        if (!isset($providers[$name]) && !isset($messaging[$name])) {
            throw new \InvalidArgumentException(sprintf('Worker "%s" is not defined.', $name));
        }

        $control = new RuntimeControl($this->application);
        $runtimeToken = $control->token('runtime');
        $workerToken = $control->token('worker');
        $namedToken = $control->token('worker', $name);
        $stopRequested = static fn(): bool => $control->changed('runtime', null, $runtimeToken)
            || $control->changed('worker', null, $workerToken)
            || $control->changed('worker', $name, $namedToken);

        $registry = new RuntimeProcessRegistry($this->application);
        $process = $registry->register('worker', $name);
        $heartbeat = static function () use ($registry, &$process): void {
            $process = $registry->heartbeat($process);
        };

        try {
            if (isset($providers[$name])) {
                return $this->runProvider($name, $providers[$name], $stopRequested, $heartbeat);
            }

            return $this->runMessaging($name, $stopRequested, $heartbeat);
        } catch (WorkerRestartRequested) {
            return 0;
        } finally {
            $registry->unregister($process);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $providers
     * @param array<string, array<string, mixed>> $messaging
     */
    private function assertDistinctNames(array $providers, array $messaging): void
    {
        $collision = array_key_first(array_intersect_key($providers, $messaging));
        if (is_string($collision)) {
            throw new \UnexpectedValueException(sprintf(
                'Worker "%s" is defined as both a provider worker and a messaging worker.',
                $collision,
            ));
        }
    }

    /** @param array<string, mixed> $config */
    private function assertForkSafeConfig(array $config): void
    {
        $this->assertForkSafeValue($config, 'config');
    }

    private function assertForkSafeValue(mixed $value, string $path): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->assertForkSafeValue($child, $path . '.' . $key);
            }

            return;
        }

        throw new \LogicException(sprintf(
            'Pooled workers require scalar/array configuration; %s contains %s. Use class names and declarative configuration before forking.',
            $path,
            get_debug_type($value),
        ));
    }

    private function assertPoolParentClean(): void
    {
        if ($this->application->booted()) {
            throw new \LogicException('Pooled workers must fork before booting the parent Foundation application.');
        }

        $container = $this->application->container();
        foreach ([
            \Infocyph\Foundation\Cache\CacheManager::class,
            \Infocyph\CacheLayer\Cache\CacheInterface::class,
            \Infocyph\CacheLayer\Cache\Cache::class,
            \Infocyph\CacheLayer\Cache\Lock\LockProviderInterface::class,
            \Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface::class,
            \Infocyph\DBLayer\Connection\Connection::class,
            \Infocyph\Omnibus\Consumer\Consumer::class,
            \Infocyph\Omnibus\Failure\FailureStore::class,
            \Infocyph\Omnibus\Transport\TransportRegistry::class,
            \Infocyph\TalkingBytes\Http\HttpClient::class,
        ] as $service) {
            if ($container->isResolved($service)) {
                throw new \LogicException(sprintf(
                    'Pooled workers must fork before resolving process-bound service "%s".',
                    $service,
                ));
            }
        }

        $db = \Infocyph\DBLayer\DB::class;
        if (class_exists($db, false) && $db::getConnections() !== []) {
            throw new \LogicException(
                'Pooled workers must fork before opening DBLayer connections in the parent process.',
            );
        }
    }

    private function floatValue(mixed $value, float $default, string $key): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new \UnexpectedValueException(sprintf('Worker %s must be numeric.', $key));
    }

    /** @return array<string, array<string, mixed>> */
    private function messagingDefinitions(): array
    {
        $configured = $this->application->config()->get('messaging.workers', []);
        if (!is_array($configured)) {
            throw new \UnexpectedValueException('messaging.workers must be an associative worker map.');
        }

        $workers = [];
        foreach ($configured as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                throw new \UnexpectedValueException(
                    'messaging.workers must map non-empty worker names to configuration arrays.',
                );
            }
            $workers[$name] = ValueNormalizer::associativeArray($definition);
        }

        return $workers;
    }

    private function nonNegativeFloat(mixed $value, float $default, string $key): float
    {
        $resolved = $this->floatValue($value, $default, $key);
        if (!is_finite($resolved) || $resolved < 0.0) {
            throw new \UnexpectedValueException(sprintf('Worker %s must be finite and non-negative.', $key));
        }

        return $resolved;
    }

    private function path(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    private function positiveFloat(mixed $value, float $default, string $key): float
    {
        $resolved = $this->floatValue($value, $default, $key);
        if (!is_finite($resolved) || $resolved <= 0.0) {
            throw new \UnexpectedValueException(sprintf('Worker %s must be positive and finite.', $key));
        }

        return $resolved;
    }

    /**
     * @return array<string, array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float}>
     */
    private function providerDefinitions(string $routes): array
    {
        $path = $this->path($routes);
        if (!is_file($path)) {
            return [];
        }

        $configured = require $path;
        if (!is_array($configured)) {
            throw new \UnexpectedValueException(sprintf('Worker route file "%s" must return a worker map.', $path));
        }

        $workers = [];
        foreach ($configured as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Worker route names must be non-empty strings.');
            }

            $options = is_array($definition) ? $definition : ['provider' => $definition];
            $provider = $options['provider'] ?? null;
            if (!is_string($provider) || $provider === '' || !is_a($provider, WorkerProvider::class, true)) {
                throw new \UnexpectedValueException(sprintf(
                    'Worker "%s" must define a %s provider.',
                    $name,
                    WorkerProvider::class,
                ));
            }

            /** @var class-string<WorkerProvider> $provider */
            $workers[$name] = [
                'provider' => $provider,
                'singleton' => ValueNormalizer::bool($options['singleton'] ?? null, false),
                'lock_wait_seconds' => $this->nonNegativeFloat(
                    $options['lock_wait_seconds'] ?? $this->application->config()->get('worker.lock_wait_seconds'),
                    0.0,
                    'lock_wait_seconds',
                ),
                'lock_lease_seconds' => $this->positiveFloat(
                    $options['lock_lease_seconds'] ?? $this->application->config()->get('worker.lock_lease_seconds'),
                    300.0,
                    'lock_lease_seconds',
                ),
            ];
        }

        return $workers;
    }

    /**
     * @param callable():bool $stopRequested
     * @param callable():void $processHeartbeat
     */
    private function runMessaging(string $name, callable $stopRequested, callable $processHeartbeat): int
    {
        if (!class_exists(Worker::class) || !interface_exists(WorkerLifecycle::class)) {
            throw new \LogicException('Messaging workers require infocyph/omnibus ^2.4.');
        }

        /** @var OmnibusWorkerFactory $factory */
        $factory = $this->application->make(OmnibusWorkerFactory::class);
        $factory->options($name);
        $pool = $factory->pool($name);

        if (!$pool['enabled']) {
            $this->application->boot();
            $lifecycle = new readonly class ($processHeartbeat, $stopRequested) implements WorkerLifecycle {
                private \Closure $heartbeatCallback;

                private \Closure $stopCallback;

                public function __construct(callable $heartbeat, callable $stopRequested)
                {
                    $this->heartbeatCallback = \Closure::fromCallable($heartbeat);
                    $this->stopCallback = \Closure::fromCallable($stopRequested);
                }

                public function heartbeat(): void
                {
                    ($this->heartbeatCallback)();
                }

                public function stopRequested(): bool
                {
                    return (bool) ($this->stopCallback)();
                }
            };

            $factory->make($name, $lifecycle)->run();

            return 0;
        }

        if (!class_exists(WorkerPool::class)) {
            throw new \LogicException('Pooled messaging workers require an Omnibus release that provides WorkerPool.');
        }

        $transport = $factory->transport($name);
        if ($transport === 'memory') {
            throw new \LogicException('Pooled workers cannot use Omnibus memory transport because it is process-local.');
        }
        if ($transport === 'sync') {
            throw new \LogicException('Pooled workers require a receiving transport; sync cannot receive messages.');
        }

        $this->assertPoolParentClean();
        $config = $this->application->config()->all();
        $this->assertForkSafeConfig($config);

        $workerPool = new WorkerPool(
            workerFactory: static function (int $_slot) use ($config, $name): Worker {
                unset($_slot);
                $child = Foundation::worker($config);
                $child->boot();

                return $child->make(OmnibusWorkerFactory::class)->make($name);
            },
            concurrency: $pool['concurrency'],
            maximumRestarts: $pool['maximum_restarts'],
            restartBackoffSeconds: $pool['restart_backoff_seconds'],
            shutdownGraceSeconds: $pool['shutdown_grace_seconds'],
        );
        $this->watchPool(
            $workerPool,
            $stopRequested,
            $processHeartbeat,
            static fn() => $workerPool->run(),
        );

        return 0;
    }

    /**
     * @param array{provider:class-string<WorkerProvider>,singleton:bool,lock_wait_seconds:float,lock_lease_seconds:float} $definition
     * @param callable():bool $stopRequested
     * @param callable():void $processHeartbeat
     */
    private function runProvider(
        string $name,
        array $definition,
        callable $stopRequested,
        callable $processHeartbeat,
    ): ?int {
        $app = $this->application->boot();
        $provider = $app->make($definition['provider']);

        if (!$definition['singleton']) {
            return $provider->run(new WorkerRuntime(
                $app,
                \Closure::fromCallable($processHeartbeat),
                \Closure::fromCallable($stopRequested),
            ));
        }

        $lock = $app->make(CacheLayerFactory::class)->lock();
        $handle = $lock->acquire(
            'foundation:worker:' . $name,
            $definition['lock_wait_seconds'],
            $definition['lock_lease_seconds'],
        );
        if ($handle === null) {
            return null;
        }

        $heartbeat = static function () use ($lock, $handle, $definition, $name, $processHeartbeat): void {
            $processHeartbeat();
            if (!$lock->refresh($handle, $definition['lock_lease_seconds'])) {
                throw new \RuntimeException(sprintf(
                    'Singleton worker "%s" lost its CacheLayer lock lease.',
                    $name,
                ));
            }
        };

        try {
            return $provider->run(new WorkerRuntime(
                $app,
                $heartbeat(...),
                \Closure::fromCallable($stopRequested),
            ));
        } finally {
            $lock->release($handle);
        }
    }

    /**
     * WorkerPool is Unix/pcntl-only upstream, so its parent process still uses
     * a small signal watchdog. Single Omnibus workers use WorkerLifecycle and
     * therefore need no Foundation signal polling.
     *
     * @param callable():bool $stopRequested
     * @param callable():void $heartbeat
     * @param callable():void $run
     */
    private function watchPool(
        WorkerPool $target,
        callable $stopRequested,
        callable $heartbeat,
        callable $run,
    ): void {
        if (!defined('SIGALRM')
            || !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
        ) {
            $run();

            return;
        }

        $signal = constant('SIGALRM');
        $previousHandler = pcntl_signal_get_handler($signal);
        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        pcntl_signal($signal, static function () use ($target, $stopRequested, $heartbeat): void {
            $heartbeat();
            if ($stopRequested()) {
                $target->requestStop();

                return;
            }
            pcntl_alarm(1);
        });
        pcntl_alarm(1);

        try {
            $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal($signal, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }
}
