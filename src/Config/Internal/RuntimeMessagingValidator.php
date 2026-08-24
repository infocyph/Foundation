<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class RuntimeMessagingValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        return [
            ...$this->routes(),
            ...$this->callableMaps(),
            ...$this->middleware(),
            ...$this->listeners(),
            ...$this->settings(),
            ...$this->retry(),
            ...$this->workers(),
        ];
    }

    private function callableDefinition(mixed $definition): bool
    {
        if (is_string($definition)) {
            return trim($definition) !== '';
        }
        if ($definition instanceof \Closure) {
            return true;
        }
        if (!is_array($definition) || count($definition) !== 2) {
            return false;
        }

        $target = $definition[0] ?? null;
        $method = $definition[1] ?? null;

        return (is_string($target) || is_object($target))
            && is_string($method)
            && trim($method) !== '';
    }

    /** @return list<ConfigIssue> */
    private function callableMaps(): array
    {
        $issues = [];
        foreach (['handlers', 'scheduled_messages'] as $map) {
            $key = 'messaging.' . $map;
            $value = $this->config->get($key, []);
            if (!is_array($value)) {
                $issues[] = new ConfigIssue(sprintf('%s must be an explicit map.', $key), $key);

                continue;
            }
            if (!$this->validCallableMap($value)) {
                $issues[] = new ConfigIssue(
                    sprintf('%s must map non-empty keys to callable definitions.', $key),
                    $key,
                );
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function finiteNumber(string $key, float $minimum, ?float $maximum = null): array
    {
        $value = $this->config->get($key);
        if (!is_int($value) && !is_float($value)) {
            return [new ConfigIssue($key . ' must be a finite number.', $key)];
        }

        $number = (float) $value;

        return is_finite($number) && $number >= $minimum && ($maximum === null || $number <= $maximum)
            ? []
            : [new ConfigIssue($key . ' is outside its supported range.', $key)];
    }

    private function forkUnsafePath(mixed $value, string $path): ?string
    {
        if ($value === null || is_scalar($value)) {
            return null;
        }
        if (!is_array($value)) {
            return $path . ' (' . get_debug_type($value) . ')';
        }

        foreach ($value as $key => $child) {
            $unsafe = $this->forkUnsafePath($child, $path . '.' . $key);
            if ($unsafe !== null) {
                return $unsafe;
            }
        }

        return null;
    }

    /** @return list<ConfigIssue> */
    private function listeners(): array
    {
        $listeners = $this->config->get('messaging.listeners', []);
        if (!is_array($listeners)) {
            return [new ConfigIssue(
                'messaging.listeners must be an explicit event-class map.',
                'messaging.listeners',
            )];
        }

        foreach ($listeners as $event => $definitions) {
            if (!$this->validListenerEntry($event, $definitions)) {
                return [new ConfigIssue(
                    'messaging.listeners must map non-empty event class names to callable definition lists.',
                    'messaging.listeners',
                )];
            }
        }

        return [];
    }

    /** @return list<ConfigIssue> */
    private function middleware(): array
    {
        $issues = [];
        foreach (['handler_middleware', 'job_middleware'] as $surface) {
            $key = 'messaging.' . $surface;
            $definitions = $this->config->get($key, []);
            if (!is_array($definitions) || !array_is_list($definitions)) {
                $issues[] = new ConfigIssue($key . ' must be an ordered middleware list.', $key);

                continue;
            }
            if (array_any(
                $definitions,
                static fn(mixed $definition): bool
                => (!is_string($definition) || trim($definition) === '') && !is_object($definition),
            )) {
                $issues[] = new ConfigIssue(
                    $key . ' entries must be non-empty service class names or middleware instances.',
                    $key,
                );
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function positiveInteger(string $key, int $minimum): array
    {
        $value = $this->config->get($key);

        return is_int($value) && $value >= $minimum
            ? []
            : [new ConfigIssue(
                sprintf('%s must be an integer of at least %d.', $key, $minimum),
                $key,
            )];
    }

    /** @return list<ConfigIssue> */
    private function retry(): array
    {
        return [
            ...$this->positiveInteger('messaging.retry.maximum_attempts', 1),
            ...$this->finiteNumber('messaging.retry.initial_delay_seconds', 0.0),
            ...$this->finiteNumber('messaging.retry.multiplier', 1.0),
            ...$this->finiteNumber('messaging.retry.maximum_delay_seconds', 0.0),
            ...$this->finiteNumber('messaging.retry.jitter_ratio', 0.0, 1.0),
        ];
    }

    /** @return list<ConfigIssue> */
    private function route(string $key, mixed $route): array
    {
        if (!is_array($route)) {
            return [new ConfigIssue($key . ' must be a route array.', $key)];
        }

        return [
            ...$this->routeString($key, $route, 'transport'),
            ...$this->routeString($key, $route, 'queue'),
            ...$this->routeDelay($key, $route['delay_seconds'] ?? null),
        ];
    }

    /** @return list<ConfigIssue> */
    private function routeDelay(string $key, mixed $delay): array
    {
        return (is_int($delay) || is_float($delay)) && is_finite((float) $delay) && $delay >= 0
            ? []
            : [new ConfigIssue(
                $key . '.delay_seconds must be a finite non-negative number.',
                $key . '.delay_seconds',
            )];
    }

    /** @return list<ConfigIssue> */
    private function routes(): array
    {
        $issues = $this->route('messaging.default_route', $this->config->get('messaging.default_route'));
        $routes = $this->config->get('messaging.routes', []);
        if (!is_array($routes)) {
            return [...$issues, new ConfigIssue(
                'messaging.routes must be an explicit message-class map.',
                'messaging.routes',
            )];
        }

        foreach ($routes as $message => $route) {
            if (!is_string($message) || trim($message) === '') {
                $issues[] = new ConfigIssue(
                    'messaging.routes keys must be non-empty message class names.',
                    'messaging.routes',
                );

                break;
            }
            array_push($issues, ...$this->route('messaging.routes.' . $message, $route));
        }

        return $issues;
    }

    /**
     * @param array<int|string,mixed> $route
     * @return list<ConfigIssue>
     */
    private function routeString(string $key, array $route, string $field): array
    {
        return is_string($route[$field] ?? null) && $route[$field] !== ''
            ? []
            : [new ConfigIssue(
                $key . '.' . $field . ' must be a non-empty string.',
                $key . '.' . $field,
            )];
    }

    /** @return list<ConfigIssue> */
    private function settings(): array
    {
        $issues = [];
        $transport = $this->config->get('messaging.consumer.transport');
        if (!is_string($transport) || $transport === '') {
            $issues[] = new ConfigIssue(
                'messaging.consumer.transport must be a non-empty receiver name.',
                'messaging.consumer.transport',
            );
        }
        if (!is_bool($this->config->get('messaging.forward_auth_events', false))) {
            $issues[] = new ConfigIssue(
                'messaging.forward_auth_events must be true or false.',
                'messaging.forward_auth_events',
            );
        }

        return $issues;
    }

    /** @param array<int|string,mixed> $value */
    private function validCallableMap(array $value): bool
    {
        return array_all($value, fn($definition, $name) => !(!is_string($name) || $name === '' || !$this->callableDefinition($definition)));
    }

    private function validListenerEntry(mixed $event, mixed $definitions): bool
    {
        if (!is_string($event) || trim($event) === '' || !is_array($definitions)) {
            return false;
        }

        return !array_any($definitions, fn(mixed $definition): bool => !$this->callableDefinition($definition));
    }

    /**
     * @param array<int|string,mixed> $definition
     * @return array{issues:list<ConfigIssue>,pooled:?string}
     */
    private function worker(string $name, array $definition): array
    {
        $key = 'messaging.workers.' . $name;
        $issues = $this->workerEndpoints($key, $definition);
        $pool = $definition['pool'] ?? [];
        if (!is_array($pool)) {
            $issues[] = new ConfigIssue($key . '.pool must be an array.', $key . '.pool');

            return ['issues' => $issues, 'pooled' => null];
        }

        $enabled = $pool['enabled'] ?? false;
        if (!is_bool($enabled)) {
            $issues[] = new ConfigIssue($key . '.pool.enabled must be true or false.', $key . '.pool.enabled');

            return ['issues' => $issues, 'pooled' => null];
        }
        if (!$enabled) {
            return ['issues' => $issues, 'pooled' => null];
        }

        array_push($issues, ...$this->workerPoolTransport($key, $definition));

        return ['issues' => $issues, 'pooled' => $key];
    }

    /**
     * @param array<int|string,mixed> $definition
     * @return list<ConfigIssue>
     */
    private function workerEndpoints(string $key, array $definition): array
    {
        $issues = [];
        foreach (['transport', 'queue'] as $field) {
            $value = $definition[$field] ?? ($field === 'transport'
                ? $this->config->get('messaging.consumer.transport')
                : 'default');
            if (!is_string($value) || $value === '') {
                $issues[] = new ConfigIssue(
                    $key . '.' . $field . ' must be a non-empty string.',
                    $key . '.' . $field,
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<string> $pooled
     * @return list<ConfigIssue>
     */
    private function workerForkSafety(array $pooled): array
    {
        if ($pooled === []) {
            return [];
        }

        $unsafe = $this->forkUnsafePath($this->config->all(), 'config');
        if ($unsafe === null) {
            return [];
        }

        return [new ConfigIssue(
            sprintf(
                'Pooled messaging workers require scalar/array declarative configuration; %s contains runtime state.',
                $unsafe,
            ),
            $pooled[0] . '.pool',
        )];
    }

    /**
     * @param array<int|string,mixed> $definition
     * @return list<ConfigIssue>
     */
    private function workerPoolTransport(string $key, array $definition): array
    {
        $transport = $definition['transport'] ?? $this->config->get('messaging.consumer.transport');

        return match ($transport) {
            'memory' => [new ConfigIssue(
                $key . '.pool cannot use the process-local memory transport.',
                $key . '.transport',
            )],
            'sync' => [new ConfigIssue(
                $key . '.pool requires a receiving transport; sync cannot receive messages.',
                $key . '.transport',
            )],
            default => [],
        };
    }

    /** @return list<ConfigIssue> */
    private function workers(): array
    {
        $workers = $this->config->get('messaging.workers', []);
        if (!is_array($workers)) {
            return [new ConfigIssue(
                'messaging.workers must be an associative worker map.',
                'messaging.workers',
            )];
        }

        $issues = [];
        $pooled = [];
        foreach ($workers as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                $issues[] = new ConfigIssue(
                    'messaging.workers must map non-empty worker names to configuration arrays.',
                    'messaging.workers',
                );

                continue;
            }

            $result = $this->worker($name, $definition);
            array_push($issues, ...$result['issues']);
            if ($result['pooled'] !== null) {
                $pooled[] = $result['pooled'];
            }
        }

        return [...$issues, ...$this->workerForkSafety($pooled)];
    }
}
