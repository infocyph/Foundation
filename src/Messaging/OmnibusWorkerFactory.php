<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

use Closure;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Omnibus\Consumer\Worker;
use Infocyph\Omnibus\Consumer\WorkerLifecycle;
use Infocyph\Omnibus\Consumer\WorkerOptions;

final readonly class OmnibusWorkerFactory
{
    /** @param Closure():ConsumerFactory $consumers */
    public function __construct(
        private ConfigRepository $config,
        private Closure $consumers,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $configured = $this->config->get('messaging.workers', []);
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

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function make(string $name, ?WorkerLifecycle $lifecycle = null): Worker
    {
        return new Worker(
            ($this->consumers)()->make($this->transport($name)),
            $this->options($name),
            $lifecycle,
        );
    }

    public function options(string $name): WorkerOptions
    {
        $definition = $this->definition($name);

        return new WorkerOptions(
            queue: ValueNormalizer::string($definition['queue'] ?? null, 'default'),
            prefetch: $this->intValue($definition['prefetch'] ?? null, 1, $name . '.prefetch'),
            visibilitySeconds: $this->floatValue(
                $definition['visibility_seconds'] ?? null,
                60.0,
                $name . '.visibility_seconds',
            ),
            idleSleepSeconds: $this->floatValue(
                $definition['idle_sleep_seconds'] ?? null,
                0.05,
                $name . '.idle_sleep_seconds',
            ),
            maxIdleSleepSeconds: $this->floatValue(
                $definition['max_idle_sleep_seconds'] ?? null,
                1.0,
                $name . '.max_idle_sleep_seconds',
            ),
            idleJitterRatio: $this->floatValue(
                $definition['idle_jitter_ratio'] ?? null,
                0.20,
                $name . '.idle_jitter_ratio',
            ),
            maxMessages: $this->nullableInt($definition['max_messages'] ?? null, $name . '.max_messages'),
            maxRuntimeSeconds: $this->nullableFloat(
                $definition['max_runtime_seconds'] ?? null,
                $name . '.max_runtime_seconds',
            ),
            memoryLimitBytes: $this->nullableInt(
                $definition['memory_limit_bytes'] ?? null,
                $name . '.memory_limit_bytes',
            ),
            maxMemoryGrowthBytes: $this->nullableInt(
                $definition['max_memory_growth_bytes'] ?? null,
                $name . '.max_memory_growth_bytes',
            ),
            handleSignals: ValueNormalizer::bool($definition['handle_signals'] ?? null, true),
        );
    }

    /**
     * @return array{
     *   enabled:bool,
     *   concurrency:int,
     *   maximum_restarts:int,
     *   restart_backoff_seconds:float,
     *   shutdown_grace_seconds:float
     * }
     */
    public function pool(string $name): array
    {
        $definition = $this->definition($name);
        $pool = ValueNormalizer::associativeArray($definition['pool'] ?? []);

        return [
            'enabled' => ValueNormalizer::bool($pool['enabled'] ?? null, false),
            'concurrency' => $this->intValue($pool['concurrency'] ?? null, 2, $name . '.pool.concurrency'),
            'maximum_restarts' => $this->intValue(
                $pool['maximum_restarts'] ?? null,
                5,
                $name . '.pool.maximum_restarts',
            ),
            'restart_backoff_seconds' => $this->floatValue(
                $pool['restart_backoff_seconds'] ?? null,
                0.25,
                $name . '.pool.restart_backoff_seconds',
            ),
            'shutdown_grace_seconds' => $this->floatValue(
                $pool['shutdown_grace_seconds'] ?? null,
                30.0,
                $name . '.pool.shutdown_grace_seconds',
            ),
        ];
    }

    public function transport(string $name): string
    {
        $definition = $this->definition($name);

        return ValueNormalizer::string(
            $definition['transport'] ?? null,
            ValueNormalizer::string($this->config->get('messaging.consumer.transport'), 'memory'),
        );
    }

    /** @return array<string, mixed> */
    private function definition(string $name): array
    {
        return $this->all()[$name] ?? throw new \InvalidArgumentException(sprintf(
            'Messaging worker "%s" is not configured.',
            $name,
        ));
    }

    private function intValue(mixed $value, int $default, string $key): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(sprintf('messaging.workers.%s must be an integer.', $key));
    }

    private function floatValue(mixed $value, float $default, string $key): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new \InvalidArgumentException(sprintf('messaging.workers.%s must be numeric.', $key));
    }

    private function nullableInt(mixed $value, string $key): ?int
    {
        return $value === null || $value === '' ? null : $this->intValue($value, 0, $key);
    }

    private function nullableFloat(mixed $value, string $key): ?float
    {
        return $value === null || $value === '' ? null : $this->floatValue($value, 0.0, $key);
    }
}
