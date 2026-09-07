<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class DatabaseConnectionResolver
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /** @return array<string, mixed> */
    public function configuration(?string $name = null): array
    {
        $name = $this->connectionName($name);
        $config = $this->connections()[$name] ?? null;

        if (!is_array($config) || $config === []) {
            throw new ConfigurationException(sprintf(
                'Database connection "%s" is not configured.',
                $name,
            ));
        }

        return $this->normalizeConfiguration($config);
    }

    public function connectionName(?string $name = null): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $default = $this->config->get('database.default');
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $first = array_key_first($this->connections());
        if (is_string($first) && $first !== '') {
            return $first;
        }

        throw new ConfigurationException('No database.default connection has been configured.');
    }

    /** @return array<string, array<string, mixed>> */
    public function connections(): array
    {
        $connections = $this->config->get('database.connections', []);

        if (!is_array($connections)) {
            return [];
        }

        $resolved = [];
        foreach ($connections as $name => $connection) {
            if (!is_string($name) || !is_array($connection)) {
                continue;
            }

            $resolved[$name] = $this->map($connection);
        }

        return $resolved;
    }

    public function poolEnabled(): bool
    {
        return $this->config->get('database.pool.enabled', false) === true;
    }

    /**
     * @return array{
     *   min_connections:int,
     *   max_connections:int,
     *   idle_timeout:int,
     *   max_lifetime:int,
     *   health_check_interval:int
     * }
     */
    public function poolOptions(): array
    {
        return [
            'min_connections' => $this->poolInt('min_connections', 0),
            'max_connections' => $this->poolInt('max_connections', 10),
            'idle_timeout' => $this->poolInt('idle_timeout', 60),
            'max_lifetime' => $this->poolInt('max_lifetime', 3_600),
            'health_check_interval' => $this->poolInt('health_check_interval', 30),
        ];
    }

    public function queryCacheEnabled(): bool
    {
        return $this->config->get('database.query_cache.enabled', false) === true;
    }

    public function queryCacheStore(): string
    {
        $store = $this->config->get('database.query_cache.store');
        if (!is_string($store) || trim($store) === '') {
            throw new ConfigurationException(
                'database.query_cache.store must name an explicit CacheLayer store when database query caching is enabled.',
            );
        }

        return trim($store);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function basePath(): string
    {
        $configured = $this->config->get('app.base_path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : (getcwd() ?: '.');
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function map(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfiguration(array $config): array
    {
        $driver = $config['driver'] ?? null;
        $database = $config['database'] ?? null;
        $sqlite = is_string($driver)
            && in_array(strtolower(trim($driver)), ['sqlite', 'sqlite3', 'pdo_sqlite'], true);

        if (
            $sqlite
            && is_string($database)
            && $database !== ''
            && $database !== ':memory:'
            && !$this->absolute($database)
        ) {
            $config['database'] = $this->basePath() . DIRECTORY_SEPARATOR . ltrim($database, DIRECTORY_SEPARATOR);
        }

        return $config;
    }

    private function poolInt(string $key, int $default): int
    {
        $value = $this->config->get('database.pool.' . $key, $default);

        return is_int($value) || is_numeric($value) ? (int) $value : $default;
    }
}
