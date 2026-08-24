<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config\Internal;

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class CacheTopologyValidator
{
    public function __construct(private ConfigRepository $config) {}

    /** @return list<ConfigIssue> */
    public function validate(): array
    {
        $stores = $this->definitions('cache.stores');
        $clusters = $this->definitions('cache.clusters');
        $transports = $this->definitions('cache.transports');

        $issues = $this->validateNodeStores($stores);
        array_push($issues, ...$this->validateClusters($clusters, $stores, $transports));
        array_push($issues, ...$this->validateTransports($transports));
        array_push($issues, ...$this->validateCounters($this->definitions('cache.counters')));

        return $issues;
    }

    /** @return array<string,mixed> */
    private function definitions(string $key): array
    {
        $definitions = $this->config->get($key, []);
        if (!is_array($definitions)) {
            return [];
        }

        $named = [];
        foreach ($definitions as $name => $definition) {
            if (is_string($name)) {
                $named[$name] = $definition;
            }
        }

        return $named;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function isNodeCachePath(string $path): bool
    {
        $base = $this->stringConfig('app.base_path', getcwd() ?: '.');
        $cache = $this->config->get('paths.cache', 'storage/cache');
        $cache = is_string($cache) && $cache !== '' ? $cache : 'storage/cache';
        $directory = $this->isAbsolutePath($cache) ? $cache : $base . DIRECTORY_SEPARATOR . $cache;
        $file = $this->isAbsolutePath($path) ? $path : $base . DIRECTORY_SEPARATOR . $path;
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $file = str_replace('\\', '/', $file);

        return str_starts_with($file, $directory . '/');
    }

    private function reservedPurpose(mixed $value): bool
    {
        return is_string($value)
            && in_array(strtolower($value), ['auth', 'session', 'security', 'idempotency'], true);
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string,mixed> $clusters
     * @param array<string,mixed> $stores
     * @param array<string,mixed> $transports
     * @return list<ConfigIssue>
     */
    private function validateClusters(array $clusters, array $stores, array $transports): array
    {
        $issues = [];
        foreach ($clusters as $name => $cluster) {
            if (!is_array($cluster)) {
                continue;
            }

            $key = 'cache.clusters.' . $name;
            $store = $cluster['store'] ?? null;
            $transport = $cluster['transport'] ?? null;
            if (!is_string($cluster['node_id'] ?? null) || $cluster['node_id'] === '') {
                $issues[] = new ConfigIssue($key . '.node_id must be a stable explicit instance identity.', $key . '.node_id');
            }
            if (!is_string($store)
                || !isset($stores[$store])
                || !is_array($stores[$store])
                || ($stores[$store]['driver'] ?? null) !== 'node'
            ) {
                $issues[] = new ConfigIssue($key . '.store must reference a node cache store.', $key . '.store');
            }
            if (!is_string($transport)
                || !isset($transports[$transport])
                || !is_array($transports[$transport])
            ) {
                $issues[] = new ConfigIssue($key . '.transport must reference a configured shared transport.', $key . '.transport');
            }
            if ($this->reservedPurpose($name) || $this->reservedPurpose($cluster['purpose'] ?? null)) {
                $issues[] = new ConfigIssue(
                    $key . ' cannot be used for auth, session, security, or idempotency state.',
                    $key,
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $counters
     * @return list<ConfigIssue>
     */
    private function validateCounters(array $counters): array
    {
        $issues = [];
        foreach ($counters as $name => $counter) {
            if (!is_array($counter) || !in_array($counter['driver'] ?? null, ['redis', 'valkey'], true)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.counters.%s must use Redis or Valkey for atomic increments.', $name),
                    'cache.counters.' . $name,
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $stores
     * @return list<ConfigIssue>
     */
    private function validateNodeStores(array $stores): array
    {
        $issues = [];
        foreach ($stores as $name => $store) {
            if (!is_array($store) || ($store['driver'] ?? null) !== 'node') {
                continue;
            }

            $file = $store['sqlite_file'] ?? $store['file'] ?? $store['path'] ?? null;
            if (!is_string($file) || $file === '' || !$this->isNodeCachePath($file)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.stores.%s node SQLite files must be inside the configured cache directory.', $name),
                    'cache.stores.' . $name,
                );
            }
        }

        return $issues;
    }

    /**
     * @param array<string,mixed> $transports
     * @return list<ConfigIssue>
     */
    private function validateTransports(array $transports): array
    {
        $issues = [];
        foreach ($transports as $name => $transport) {
            if (!is_array($transport) || ($transport['driver'] ?? null) !== 'pdo') {
                continue;
            }

            $connection = $transport['connection'] ?? null;
            $driver = is_string($connection)
                ? $this->config->get('database.connections.' . $connection . '.driver')
                : null;
            if (!in_array($driver, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true)) {
                $issues[] = new ConfigIssue(
                    sprintf('cache.transports.%s PDO transport must use a shared MySQL or PostgreSQL connection.', $name),
                    'cache.transports.' . $name,
                );
            }
        }

        return $issues;
    }
}
