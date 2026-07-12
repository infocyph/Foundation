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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, array<string, mixed>>
     */
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

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
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
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
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

        if (
            $driver === 'sqlite'
            && is_string($database)
            && $database !== ''
            && $database !== ':memory:'
            && !$this->absolute($database)
        ) {
            $config['database'] = $this->basePath() . DIRECTORY_SEPARATOR . ltrim($database, DIRECTORY_SEPARATOR);
        }

        return $config;
    }
}
