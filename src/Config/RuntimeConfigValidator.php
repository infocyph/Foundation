<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Psr\Log\LogLevel;

final readonly class RuntimeConfigValidator
{
    public function __construct(private ConfigRepository $config) {}

    /**
     * @return list<ConfigIssue>
     */
    public function validate(): array
    {
        return [
            ...$this->container(),
            ...$this->logging(),
            ...$this->migrations(),
            ...$this->messageRoutes(),
            ...$this->messageCallableMaps(),
            ...$this->messageListeners(),
            ...$this->messageSettings(),
            ...$this->messageRetry(),
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

    /**
     * @return list<ConfigIssue>
     */
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

        return $issues;
    }

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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
            || array_any($ignored, static fn(mixed $class): bool => !is_string($class)
                || !is_a($class, \Throwable::class, true))
        ) {
            $issues[] = new ConfigIssue(
                'logging.exceptions.ignore must be a list of available Throwable classes.',
                'logging.exceptions.ignore',
            );
        }

        return $issues;
    }

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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
            if (array_any(
                $value,
                static fn(mixed $definition, mixed $name): bool => !is_string($name)
                    || $name === ''
                    || (!is_string($definition) && !is_callable($definition)),
            )) {
                $issues[] = new ConfigIssue(
                    sprintf('%s must map non-empty keys to callable definitions.', $key),
                    $key,
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<ConfigIssue>
     */
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
            if (is_string($event)
                && (class_exists($event) || interface_exists($event))
                && is_array($definitions)
                && !array_any(
                    $definitions,
                    static fn(mixed $definition): bool => !is_string($definition) && !is_callable($definition),
                )
            ) {
                continue;
            }

            return [new ConfigIssue(
                'messaging.listeners must map available event classes to callable definition lists.',
                'messaging.listeners',
            )];
        }

        return [];
    }

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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
            if (!is_string($message) || (!class_exists($message) && !interface_exists($message))) {
                $issues[] = new ConfigIssue(
                    'messaging.routes keys must be available message classes.',
                    'messaging.routes',
                );

                break;
            }
            $issues = [...$issues, ...$this->messageRoute('messaging.routes.' . $message, $route)];
        }

        return $issues;
    }

    /**
     * @return list<ConfigIssue>
     */
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
     * @return list<ConfigIssue>
     */
    private function migrations(): array
    {
        $issues = [];
        foreach (['database.migrations.classes', 'database.seeders'] as $key) {
            $definitions = $this->config->get($key, []);
            if (!is_array($definitions)
                || array_any($definitions, static fn(mixed $definition): bool => !is_string($definition)
                    || (!class_exists($definition) && !interface_exists($definition)))
            ) {
                $issues[] = new ConfigIssue($key . ' must be a list of available classes.', $key);
            }
        }

        return [...$issues, ...$this->migrationSettings()];
    }

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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

    /**
     * @return list<ConfigIssue>
     */
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
}
