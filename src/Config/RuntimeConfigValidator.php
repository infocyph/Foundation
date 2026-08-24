<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Exception\ConfigurationException;
use Psr\Log\LogLevel;

final readonly class RuntimeConfigValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        return [
            ...$this->topology(),
            ...$this->container(),
            ...$this->logging(),
            ...$this->notifications(),
            ...$this->operations(),
            ...$this->migrations(),
            ...$this->messageRoutes(),
            ...$this->messageCallableMaps(),
            ...$this->messageMiddleware(),
            ...$this->messageListeners(),
            ...$this->messageSettings(),
            ...$this->messageRetry(),
            ...$this->messageWorkers(),
            ...$this->responses(),
        ];
    }

    /**
     * @param list<string> $allowed
     * @return list<ConfigIssue>
     */
    private function allowedString(string $key, mixed $value, array $allowed): array
    {
        return is_string($value) && in_array($value, $allowed, true)
            ? []
            : [new ConfigIssue(
                sprintf('%s must be one of: %s.', $key, implode(', ', $allowed)),
                $key,
            )];
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
    private function container(): array
    {
        $issues = $this->allowedString(
            'app.container.compiled_activation',
            $this->config->get('app.container.compiled_activation', 'off'),
            ['off', 'always'],
        );
        $path = $this->config->get('app.container.compiled', 'bootstrap/cache/container.php');
        if (!is_string($path) || trim($path) === '') {
            $issues[] = new ConfigIssue(
                'app.container.compiled must be a non-empty application-owned path.',
                'app.container.compiled',
            );
        }
        if (!is_bool($this->config->get('app.container.lazy_loading', true))) {
            $issues[] = new ConfigIssue(
                'app.container.lazy_loading must be true or false.',
                'app.container.lazy_loading',
            );
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
    private function logging(): array
    {
        $issues = [
            ...$this->allowedString(
                'logging.driver',
                $this->config->get('logging.driver', 'null'),
                ['null', 'file', 'error_log'],
            ),
            ...$this->allowedString(
                'logging.level',
                $this->config->get('logging.level', LogLevel::WARNING),
                [
                    LogLevel::DEBUG,
                    LogLevel::INFO,
                    LogLevel::NOTICE,
                    LogLevel::WARNING,
                    LogLevel::ERROR,
                    LogLevel::CRITICAL,
                    LogLevel::ALERT,
                    LogLevel::EMERGENCY,
                ],
            ),
        ];

        foreach (['include_message', 'include_trace'] as $option) {
            $key = 'logging.exceptions.' . $option;
            if (!is_bool($this->config->get($key, false))) {
                $issues[] = new ConfigIssue($key . ' must be true or false.', $key);
            }
        }

        return [...$issues, ...$this->loggingCollections(), ...$this->loggingLimits()];
    }

    /** @return list<ConfigIssue> */
    private function loggingCollections(): array
    {
        $issues = [];
        $redact = $this->config->get('logging.redact', []);
        if (!is_array($redact)
            || array_any($redact, static fn(mixed $key): bool => !is_string($key) || trim($key) === '')
        ) {
            $issues[] = new ConfigIssue(
                'logging.redact must be a list of non-empty key fragments.',
                'logging.redact',
            );
        }

        $ignored = $this->config->get('logging.exceptions.ignore', []);
        if (!is_array($ignored)
            || array_any($ignored, static fn(mixed $class): bool => !is_string($class) || trim($class) === '')
        ) {
            $issues[] = new ConfigIssue(
                'logging.exceptions.ignore must be a list of non-empty Throwable class names.',
                'logging.exceptions.ignore',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function loggingLimits(): array
    {
        $issues = [
            ...$this->finiteNumber('logging.exceptions.sample_rate', 0.0, 1.0),
            ...$this->positiveInteger('logging.exceptions.throttle_seconds', 0),
            ...$this->positiveInteger('logging.exceptions.throttle_limit', 1),
        ];
        $path = $this->config->get('logging.path');
        if ($this->config->get('logging.driver') === 'file'
            && $path !== null
            && (!is_string($path) || trim($path) === '')
        ) {
            $issues[] = new ConfigIssue(
                'logging.path must be null or a non-empty filename for the file driver.',
                'logging.path',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function messageCallableMaps(): array
    {
        $issues = [];
        foreach (['handlers', 'scheduled_messages'] as $map) {
            $key = 'messaging.' . $map;
            $value = $this->config->get($key, []);
            if (!is_array($value)) {
                $issues[] = new ConfigIssue(sprintf('%s must be an explicit map.', $key), $key);

                continue;
            }
            foreach ($value as $name => $definition) {
                if (!is_string($name) || $name === '' || !$this->callableDefinition($definition)) {
                    $issues[] = new ConfigIssue(
                        sprintf('%s must map non-empty keys to callable definitions.', $key),
                        $key,
                    );

                    break;
                }
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function messageListeners(): array
    {
        $listeners = $this->config->get('messaging.listeners', []);
        if (!is_array($listeners)) {
            return [new ConfigIssue(
                'messaging.listeners must be an explicit event-class map.',
                'messaging.listeners',
            )];
        }

        foreach ($listeners as $event => $definitions) {
            if (!is_string($event) || trim($event) === '' || !is_array($definitions)) {
                return [new ConfigIssue(
                    'messaging.listeners must map non-empty event class names to callable definition lists.',
                    'messaging.listeners',
                )];
            }
            foreach ($definitions as $definition) {
                if (!$this->callableDefinition($definition)) {
                    return [new ConfigIssue(
                        'messaging.listeners must map non-empty event class names to callable definition lists.',
                        'messaging.listeners',
                    )];
                }
            }
        }

        return [];
    }

    /** @return list<ConfigIssue> */
    private function messageMiddleware(): array
    {
        $issues = [];
        foreach (['handler_middleware', 'job_middleware'] as $surface) {
            $key = 'messaging.' . $surface;
            $definitions = $this->config->get($key, []);
            if (!is_array($definitions) || !array_is_list($definitions)) {
                $issues[] = new ConfigIssue($key . ' must be an ordered middleware list.', $key);

                continue;
            }
            foreach ($definitions as $definition) {
                if ((!is_string($definition) || trim($definition) === '') && !is_object($definition)) {
                    $issues[] = new ConfigIssue(
                        $key . ' entries must be non-empty service class names or middleware instances.',
                        $key,
                    );

                    break;
                }
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function messageRetry(): array
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
    private function messageRoute(string $key, mixed $route): array
    {
        if (!is_array($route)) {
            return [new ConfigIssue($key . ' must be a route array.', $key)];
        }

        $issues = [];
        foreach (['transport', 'queue'] as $field) {
            if (!is_string($route[$field] ?? null) || $route[$field] === '') {
                $issues[] = new ConfigIssue(
                    $key . '.' . $field . ' must be a non-empty string.',
                    $key . '.' . $field,
                );
            }
        }
        $delay = $route['delay_seconds'] ?? null;
        if ((!is_int($delay) && !is_float($delay)) || !is_finite((float) $delay) || $delay < 0) {
            $issues[] = new ConfigIssue(
                $key . '.delay_seconds must be a finite non-negative number.',
                $key . '.delay_seconds',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function messageRoutes(): array
    {
        $issues = $this->messageRoute(
            'messaging.default_route',
            $this->config->get('messaging.default_route'),
        );
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
            $issues = [...$issues, ...$this->messageRoute('messaging.routes.' . $message, $route)];
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function messageSettings(): array
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

    /**
     * Validate only Foundation-owned worker topology. Omnibus validates worker
     * lifecycle and pool numeric bounds when those objects are constructed.
     *
     * @return list<ConfigIssue>
     */
    private function messageWorkers(): array
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

            $key = 'messaging.workers.' . $name;
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

            $pool = $definition['pool'] ?? [];
            if (!is_array($pool)) {
                $issues[] = new ConfigIssue($key . '.pool must be an array.', $key . '.pool');

                continue;
            }
            $enabled = $pool['enabled'] ?? false;
            if (!is_bool($enabled)) {
                $issues[] = new ConfigIssue(
                    $key . '.pool.enabled must be true or false.',
                    $key . '.pool.enabled',
                );

                continue;
            }
            if (!$enabled) {
                continue;
            }

            $pooled[] = $key;
            $transport = $definition['transport'] ?? $this->config->get('messaging.consumer.transport');
            if ($transport === 'memory') {
                $issues[] = new ConfigIssue(
                    $key . '.pool cannot use the process-local memory transport.',
                    $key . '.transport',
                );
            } elseif ($transport === 'sync') {
                $issues[] = new ConfigIssue(
                    $key . '.pool requires a receiving transport; sync cannot receive messages.',
                    $key . '.transport',
                );
            }
        }

        if ($pooled !== []) {
            $unsafe = $this->forkUnsafePath($this->config->all(), 'config');
            if ($unsafe !== null) {
                $issues[] = new ConfigIssue(
                    sprintf(
                        'Pooled messaging workers require scalar/array declarative configuration; %s contains runtime state.',
                        $unsafe,
                    ),
                    $pooled[0] . '.pool',
                );
            }
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function migrations(): array
    {
        $issues = [];
        foreach (['database.migrations.classes', 'database.seeders'] as $key) {
            $definitions = $this->config->get($key, []);
            if (!is_array($definitions)
                || array_any($definitions, static fn(mixed $definition): bool => !is_string($definition)
                    || trim($definition) === '')
            ) {
                $issues[] = new ConfigIssue($key . ' must be a list of non-empty class names.', $key);
            }
        }

        return [...$issues, ...$this->migrationSettings()];
    }

    /** @return list<ConfigIssue> */
    private function migrationSettings(): array
    {
        $issues = [];
        $table = $this->config->get('database.migrations.table', 'migrations');
        if (!is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            $issues[] = new ConfigIssue(
                'database.migrations.table must be a safe SQL identifier.',
                'database.migrations.table',
            );
        }
        $lockStore = $this->config->get('database.migrations.lock_store');
        if ($lockStore !== null && (!is_string($lockStore) || $lockStore === '')) {
            $issues[] = new ConfigIssue(
                'database.migrations.lock_store must be null or a configured cache store name.',
                'database.migrations.lock_store',
            );
        }

        return [
            ...$issues,
            ...$this->finiteNumber('database.migrations.lock_wait_seconds', 0.0),
            ...$this->finiteNumber('database.migrations.lock_lease_seconds', PHP_FLOAT_MIN),
        ];
    }

    /** @return list<ConfigIssue> */
    private function notifications(): array
    {
        $issues = [];
        $channels = $this->config->get('notifications.channels', []);
        if (!is_array($channels)) {
            $issues[] = new ConfigIssue(
                'notifications.channels must be a channel service map.',
                'notifications.channels',
            );
        } else {
            foreach ($channels as $name => $definition) {
                if (!is_string($name)
                    || trim($name) === ''
                    || ((!is_string($definition) || trim($definition) === '') && !is_object($definition))
                ) {
                    $issues[] = new ConfigIssue(
                        'notifications.channels must map non-empty names to service class names or channel instances.',
                        'notifications.channels',
                    );

                    break;
                }
            }
        }

        $sender = $this->config->get('notifications.email.default_sender', 'default');
        if (!is_string($sender) || trim($sender) === '') {
            $issues[] = new ConfigIssue(
                'notifications.email.default_sender must be a non-empty sender profile name.',
                'notifications.email.default_sender',
            );
        }

        $from = $this->config->get('notifications.email.default_from');
        if ($from !== null && (!is_string($from) || trim($from) === '')) {
            $issues[] = new ConfigIssue(
                'notifications.email.default_from must be null or a non-empty mailbox string.',
                'notifications.email.default_from',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function operationalCacheState(string $surface, mixed $configuredStore): array
    {
        $store = is_string($configuredStore) && trim($configuredStore) !== ''
            ? trim($configuredStore)
            : $this->config->get('cache.default');
        $key = 'operations.' . $surface . '.store';
        if (!is_string($store) || trim($store) === '') {
            return [new ConfigIssue(
                sprintf('%s requires a configured cache store.', 'operations.' . $surface),
                $key,
            )];
        }
        $store = trim($store);
        if (!$this->config->has('cache.stores.' . $store)) {
            return [new ConfigIssue(
                sprintf('cache.stores.%s must exist for cache-backed %s.', $store, str_replace('_', ' ', $surface)),
                'cache.stores.' . $store,
            )];
        }

        $topology = new SharedStateTopology($this->config);
        $required = DeploymentTopology::resolve($this->config) === DeploymentTopology::DISTRIBUTED
            ? SharedStateTopology::CLUSTER
            : SharedStateTopology::HOST;

        try {
            $topology->assertCacheStore(
                $store,
                $surface === 'runtime_control' ? 'Runtime control' : 'Maintenance state',
                $required,
                $surface === 'runtime_control',
            );
        } catch (ConfigurationException $exception) {
            return [new ConfigIssue($exception->getMessage(), 'cache.stores.' . $store)];
        }

        return [];
    }

    /** @return list<ConfigIssue> */
    private function operations(): array
    {
        $issues = [];
        if (!is_bool($this->config->get('operations.history.enabled', false))) {
            $issues[] = new ConfigIssue(
                'operations.history.enabled must be true or false.',
                'operations.history.enabled',
            );
        }

        foreach ([
            'operations.history.path' => 'storage/logs/executions.jsonl',
            'operations.maintenance.path' => 'storage/framework/maintenance.json',
            'operations.runtime_control.path' => 'storage/framework/runtime-control.json',
            'operations.runtime_registry.path' => 'storage/framework/runtime',
        ] as $key => $default) {
            $value = $this->config->get($key, $default);
            if (!is_string($value) || trim($value) === '') {
                $issues[] = new ConfigIssue($key . ' must be a non-empty application path.', $key);
            }
        }

        foreach (['maintenance', 'runtime_control'] as $surface) {
            $prefix = 'operations.' . $surface;
            $issues = [
                ...$issues,
                ...$this->allowedString(
                    $prefix . '.driver',
                    $this->config->get($prefix . '.driver', 'file'),
                    ['file', 'cache'],
                ),
            ];

            $key = $this->config->get(
                $prefix . '.key',
                $surface === 'maintenance' ? 'foundation:maintenance' : 'foundation:runtime-control',
            );
            if (!is_string($key) || trim($key) === '') {
                $issues[] = new ConfigIssue($prefix . '.key must be a non-empty cache key.', $prefix . '.key');
            }

            $store = $this->config->get($prefix . '.store');
            if ($store !== null && (!is_string($store) || trim($store) === '')) {
                $issues[] = new ConfigIssue(
                    $prefix . '.store must be null or a non-empty configured cache store name.',
                    $prefix . '.store',
                );
            }

            if ($this->config->get($prefix . '.driver', 'file') === 'cache') {
                array_push($issues, ...$this->operationalCacheState($surface, $store));
            }
        }

        array_push(
            $issues,
            ...$this->allowedString(
                'operations.runtime_registry.visibility',
                $this->config->get('operations.runtime_registry.visibility', 'host'),
                ['host', 'shared'],
            ),
        );

        $maxBytes = $this->config->get('operations.history.max_bytes', 16_777_216);
        if (!is_int($maxBytes) || $maxBytes < 1) {
            $issues[] = new ConfigIssue(
                'operations.history.max_bytes must be an integer of at least 1.',
                'operations.history.max_bytes',
            );
        }

        $retained = $this->config->get('operations.history.retained_files', 7);
        if (!is_int($retained) || $retained < 0 || $retained > 100) {
            $issues[] = new ConfigIssue(
                'operations.history.retained_files must be an integer between 0 and 100.',
                'operations.history.retained_files',
            );
        }

        $stale = $this->config->get('operations.runtime_registry.stale_seconds', 15);
        if (!is_int($stale) || $stale < 1 || $stale > 3_600) {
            $issues[] = new ConfigIssue(
                'operations.runtime_registry.stale_seconds must be an integer between 1 and 3600.',
                'operations.runtime_registry.stale_seconds',
            );
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
    private function responses(): array
    {
        $issues = [];
        $vendor = $this->config->get('responses.json_dispatch.vendor', 'infocyph');
        if (!is_string($vendor) || preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $vendor) !== 1) {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.vendor must be a lowercase media-type token.',
                'responses.json_dispatch.vendor',
            );
        }
        $version = $this->config->get('responses.json_dispatch.application_version', '1.0.0');
        if (!is_string($version) || $version === '') {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.application_version must be a non-empty string.',
                'responses.json_dispatch.application_version',
            );
        }
        if (!is_bool($this->config->get('responses.json_dispatch.tunnel_errors', false))) {
            $issues[] = new ConfigIssue(
                'responses.json_dispatch.tunnel_errors must be true or false.',
                'responses.json_dispatch.tunnel_errors',
            );
        }

        return $issues;
    }

    /** @return list<ConfigIssue> */
    private function topology(): array
    {
        return $this->allowedString(
            'app.topology',
            $this->config->get('app.topology', DeploymentTopology::SINGLE_NODE->value),
            array_map(static fn(DeploymentTopology $topology): string => $topology->value, DeploymentTopology::cases()),
        );
    }
}
