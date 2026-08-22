<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class MiddlewareConfigValidator
{
    /** @var array<string, true> */
    private const array BUILTIN_DRIVERS = [
        'cache_validators' => true,
        'compression' => true,
        'cookie_encryption' => true,
        'cors' => true,
        'gateway_hardening' => true,
        'input_sanitizer' => true,
        'maintenance_mode' => true,
        'negotiation' => true,
        'normalize_method' => true,
        'request_limits' => true,
        'response_cache' => true,
        'response_linter' => true,
        'telemetry' => true,
        'throttle' => true,
        'vary' => true,
        'verify_signed_url' => true,
    ];

    public function __construct(private ConfigRepository $config) {}

    public function validate(): void
    {
        $middleware = $this->config->get('router.middleware', []);
        if (!is_array($middleware)) {
            throw new ConfigurationException('router.middleware must be an associative array.');
        }

        $definitions = $middleware['definitions'] ?? [];
        if (!is_array($definitions)) {
            throw new ConfigurationException('router.middleware.definitions must be an associative middleware map.');
        }
        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || trim($name) === '' || !is_array($definition)) {
                throw new ConfigurationException(
                    'router.middleware.definitions must map non-empty middleware names to configuration arrays.',
                );
            }
        }

        $aliases = $middleware['aliases'] ?? [];
        if (!is_array($aliases)) {
            throw new ConfigurationException('router.middleware.aliases must be an associative middleware map.');
        }
        foreach ($aliases as $alias => $definition) {
            if (!is_string($alias) || trim($alias) === '') {
                throw new ConfigurationException('router.middleware.aliases keys must be non-empty strings.');
            }
            $this->definition($definition, 'router.middleware.aliases.' . $alias, $definitions);
        }

        $globals = $middleware['globals'] ?? [];
        if (!is_array($globals)) {
            throw new ConfigurationException('router.middleware.globals must define pre and post middleware lists.');
        }
        foreach (['pre', 'post'] as $phase) {
            $entries = $globals[$phase] ?? [];
            if (!is_array($entries)) {
                throw new ConfigurationException(sprintf(
                    'router.middleware.globals.%s must be a middleware list.',
                    $phase,
                ));
            }
            foreach ($entries as $index => $entry) {
                $this->definition(
                    $entry,
                    sprintf('router.middleware.globals.%s.%s', $phase, (string) $index),
                    $definitions,
                );
            }
        }
    }

    /** @param array<array-key, mixed> $presets */
    private function definition(mixed $definition, string $key, array $presets): void
    {
        if (is_string($definition)) {
            $driver = trim($definition);
            if ($driver === '') {
                throw new ConfigurationException($key . ' must name a middleware driver or class.');
            }
            $this->driver($driver, $key);

            return;
        }

        if (!is_array($definition)) {
            throw new ConfigurationException($key . ' must be a middleware driver string or configuration array.');
        }

        $driver = $definition['driver'] ?? $definition['class'] ?? null;
        if (!is_string($driver) || trim($driver) === '') {
            throw new ConfigurationException($key . ' must define a non-empty driver or class.');
        }
        if (array_key_exists('enabled', $definition) && !is_bool($definition['enabled'])) {
            throw new ConfigurationException($key . '.enabled must be true or false.');
        }

        $driver = trim($driver);
        $this->driver($driver, $key);

        if ($driver === 'cookie_encryption' && ($definition['enabled'] ?? true) === true) {
            $keyValue = $definition['key'] ?? null;
            $keys = $definition['keys'] ?? null;
            $hasKey = is_string($keyValue) && $keyValue !== '';
            $hasKeys = is_array($keys) && $keys !== []
                && !array_any($keys, static fn(mixed $value): bool => !is_string($value) || $value === '');
            $preset = is_array($presets[$driver] ?? null) ? $presets[$driver] : [];
            $presetKey = $preset['key'] ?? null;
            $presetKeys = $preset['keys'] ?? null;
            $hasPresetKey = is_string($presetKey) && $presetKey !== '';
            $hasPresetKeys = is_array($presetKeys) && $presetKeys !== []
                && !array_any($presetKeys, static fn(mixed $value): bool => !is_string($value) || $value === '');

            if (!$hasKey && !$hasKeys && !$hasPresetKey && !$hasPresetKeys) {
                throw new ConfigurationException(
                    $key . ' enables cookie_encryption but no encryption key is configured.',
                );
            }
        }
    }

    private function driver(string $driver, string $key): void
    {
        if (isset(self::BUILTIN_DRIVERS[$driver]) || class_exists($driver)) {
            return;
        }

        throw new ConfigurationException(sprintf(
            '%s references unknown middleware driver or class "%s".',
            $key,
            $driver,
        ));
    }
}
