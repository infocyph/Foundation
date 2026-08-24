<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache\Internal;

use Closure;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Cache\CacheNamespace;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class CacheTierDescriptorResolver
{
    public function __construct(
        private ConfigRepository $config,
        /** @var Closure(?string):Connection */
        private Closure $database,
    ) {}

    /**
     * @param array<string,mixed> $store
     * @return list<array<string,mixed>>
     */
    public function resolve(string $name, array $store): array
    {
        $tiers = $store['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === []) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" must define CacheLayer-native tier descriptors.',
                $name,
            ));
        }

        $resolved = [];
        foreach ($tiers as $index => $tier) {
            $resolved[] = $this->resolveTier($name, $index, $tier);
        }

        return $resolved;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private function fileDescriptor(array $descriptor): array
    {
        foreach (['dir', 'base_dir'] as $key) {
            $path = $descriptor[$key] ?? null;
            if (is_string($path)) {
                $descriptor[$key] = $this->resolvePath($path);
            }
        }

        return $descriptor;
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private function pdoDescriptor(array $descriptor): array
    {
        $connection = $descriptor['connection'] ?? null;
        if (!is_string($connection) || $connection === '') {
            return $descriptor;
        }

        $descriptor['client'] = ($this->database)($connection)->getPdo();
        unset($descriptor['connection']);

        return $descriptor;
    }

    /**
     * @param array<string,mixed> $definition
     * @return array{client:?\Redis,dsn:string}
     */
    private function redisConnection(array $definition, string $driver): array
    {
        $name = $this->stringOrNull($definition['connection'] ?? null);
        $configured = $name === null
            ? []
            : ValueNormalizer::associativeArray($this->config->get('cache.connections.' . $name, []));
        $resolved = array_replace($configured, $definition);
        $client = $resolved['client'] ?? null;
        $resolvedDriver = strtolower(ValueNormalizer::string($resolved['driver'] ?? null, $driver));

        return [
            'client' => $client instanceof \Redis ? $client : null,
            'dsn' => ValueNormalizer::string(
                $resolved['dsn'] ?? null,
                $resolvedDriver === 'valkey' ? 'valkey://127.0.0.1:6379' : 'redis://127.0.0.1:6379',
            ),
        ];
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private function redisDescriptor(array $descriptor, string $driver): array
    {
        $connection = $this->redisConnection($descriptor, $driver);
        $descriptor['dsn'] = $connection['dsn'];
        if ($connection['client'] instanceof \Redis) {
            $descriptor['client'] = $connection['client'];
        }
        unset($descriptor['connection']);

        return $descriptor;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $this->stringConfig('app.base_path', getcwd() ?: '.')
            . DIRECTORY_SEPARATOR
            . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /** @return array<string,mixed> */
    private function resolveTier(string $name, int|string $index, mixed $tier): array
    {
        if (!is_array($tier)) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" tier %s must be a CacheLayer descriptor array.',
                $name,
                (string) $index,
            ));
        }

        $descriptor = $this->stringKeyed($tier);
        $driver = strtolower(ValueNormalizer::string($descriptor['driver'] ?? null));
        if ($driver === '') {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" tier %s requires driver.',
                $name,
                (string) $index,
            ));
        }

        $descriptor['namespace'] ??= CacheNamespace::derive(
            $this->stringConfig('cache.prefix', 'foundation:'),
            $name . '.tier.' . $index,
        );

        return match (true) {
            in_array($driver, ['file', 'php_files'], true) => $this->fileDescriptor($descriptor),
            $driver === 'sqlite' => $this->sqliteDescriptor($descriptor),
            in_array($driver, ['redis', 'valkey'], true) => $this->redisDescriptor($descriptor, $driver),
            $driver === 'pdo' => $this->pdoDescriptor($descriptor),
            default => $descriptor,
        };
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    private function sqliteDescriptor(array $descriptor): array
    {
        $file = $descriptor['file'] ?? null;
        if (is_string($file)) {
            $descriptor['file'] = $this->resolvePath($file);
        }

        return $descriptor;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /** @param array<array-key,mixed> $value @return array<string,mixed> */
    private function stringKeyed(array $value): array
    {
        $resolved = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $resolved[$key] = $item;
            }
        }

        return $resolved;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
