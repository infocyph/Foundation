<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Exception\ConfigurationException;

/**
 * Classifies Foundation-managed state and coordination backends by visibility.
 *
 * Specialist libraries own storage and locking mechanics. Foundation only owns
 * the deployment policy that decides whether a configured backend is visible
 * to one process, one host, or the whole deployment.
 */
final readonly class SharedStateTopology
{
    public const string CLUSTER = 'cluster';

    public const string HOST = 'host';

    public const string NONE = 'none';

    public const string PROCESS = 'process';

    public function __construct(private ConfigRepository $config) {}

    public function assertCacheStore(
        ?string $name,
        string $purpose,
        ?string $requiredScope = null,
        bool $requireCoordination = false,
    ): void {
        $requiredScope ??= $this->requiredSecurityScope();
        $actual = $this->cacheStoreScope($name);
        if (!$this->satisfies($actual, $requiredScope)) {
            throw new ConfigurationException(sprintf(
                '%s requires %s-visible state; cache store "%s" is only %s-visible.',
                $purpose,
                $requiredScope,
                $name ?? ($this->string($this->config->get('cache.default')) ?? 'local'),
                $actual,
            ));
        }

        if (!$requireCoordination) {
            return;
        }

        $coordination = $this->cacheStoreCoordinationScope($name);
        if (!$this->satisfies($coordination, $requiredScope)) {
            throw new ConfigurationException(sprintf(
                '%s requires %s-visible atomic coordination; cache store "%s" provides %s-visible coordination.',
                $purpose,
                $requiredScope,
                $name ?? ($this->string($this->config->get('cache.default')) ?? 'local'),
                $coordination,
            ));
        }
    }

    public function cacheStoreCoordinationScope(?string $name = null): string
    {
        $globalLock = $this->array($this->config->get('cache.lock', []));
        $requestedStore = $name
            ?? $this->string($globalLock['store'] ?? null)
            ?? $this->string($this->config->get('cache.default'))
            ?? 'local';
        $store = $this->cacheStore($requestedStore);
        $localLock = $this->array($store['lock'] ?? []);
        $lock = array_replace($globalLock, $localLock);
        $driver = strtolower($this->string($lock['driver'] ?? null) ?? '');

        if ($driver !== '') {
            $lockStoreName = $this->string($localLock['store'] ?? null)
                ?? ($localLock === [] ? $this->string($globalLock['store'] ?? null) : null)
                ?? $requestedStore;
            $lockStore = $this->cacheStore($lockStoreName);

            return $this->lockDriverScope($driver, array_replace($lockStore, $lock));
        }

        return $this->nativeCacheLockScope($store, $requestedStore);
    }

    public function cacheStoreScope(?string $name = null): string
    {
        $name ??= $this->string($this->config->get('cache.default')) ?? 'local';
        $store = $this->cacheStore($name);

        return $this->cacheDefinitionScope($store, $name);
    }

    public function databaseConnectionScope(?string $name = null): string
    {
        $name ??= $this->string($this->config->get('database.default')) ?? 'sqlite';
        $definition = $this->array($this->config->get('database.connections.' . $name, []));
        $driver = $this->normalizeDatabaseDriver(
            $this->string($definition['driver'] ?? null) ?? $name,
        );

        return $driver === 'sqlite' ? self::HOST : match ($driver) {
            'mysql', 'mariadb', 'pgsql', 'mssql' => self::CLUSTER,
            default => self::NONE,
        };
    }

    public function requiredSecurityScope(): string
    {
        return DeploymentTopology::resolve($this->config) === DeploymentTopology::DISTRIBUTED
            ? self::CLUSTER
            : self::HOST;
    }

    public function satisfies(string $actual, string $required): bool
    {
        return $this->rank($actual) >= $this->rank($required);
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $definition */
    private function cacheDefinitionScope(array $definition, string $fallbackDriver): string
    {
        $driver = $this->normalizeCacheDriver(
            $this->string($definition['driver'] ?? null) ?? $fallbackDriver,
        );

        return match ($driver) {
            'memory', 'apcu', 'weak_map', 'local' => self::PROCESS,
            'file', 'php_files', 'sqlite', 'node', 'shared_memory' => self::HOST,
            'redis', 'redis_cluster', 'valkey', 'memcache', 'mongodb', 'scylladb' => self::CLUSTER,
            'pdo' => $this->pdoStateScope($definition),
            'tiered' => $this->tieredScope($definition),
            default => self::NONE,
        };
    }

    /** @return array<string, mixed> */
    private function cacheStore(string $name): array
    {
        $stores = $this->array($this->config->get('cache.stores', []));
        $definition = $stores[$name] ?? null;
        if (!is_array($definition)) {
            return ['driver' => $name];
        }

        return $this->array($definition);
    }

    /** @param array<string, mixed> $definition */
    private function lockDriverScope(string $driver, array $definition): string
    {
        return match ($this->normalizeCacheDriver($driver)) {
            'file' => self::HOST,
            'redis', 'valkey', 'memcache' => self::CLUSTER,
            'pdo' => $this->pdoNativeLockScope($definition),
            default => self::NONE,
        };
    }

    /** @param array<string, mixed> $definition */
    private function nativeCacheLockScope(array $definition, string $fallbackDriver): string
    {
        $driver = $this->normalizeCacheDriver(
            $this->string($definition['driver'] ?? null) ?? $fallbackDriver,
        );

        return match ($driver) {
            'memory', 'apcu', 'weak_map', 'local', 'file', 'php_files', 'sqlite', 'node', 'shared_memory' => self::HOST,
            'redis', 'valkey', 'memcache' => self::CLUSTER,
            'pdo' => $this->pdoNativeLockScope($definition),
            default => self::NONE,
        };
    }

    private function normalizeCacheDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'array' => 'memory',
            'memcached' => 'memcache',
            'null' => 'null_store',
            'scylla' => 'scylladb',
            default => strtolower($driver),
        };
    }

    private function normalizeDatabaseDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'pdo_mysql', 'mysqli' => 'mysql',
            'postgres', 'postgresql', 'psql', 'pdo_pgsql' => 'pgsql',
            'sqlsrv', 'sqlserver', 'pdo_sqlsrv' => 'mssql',
            'sqlite3', 'pdo_sqlite' => 'sqlite',
            default => strtolower($driver),
        };
    }

    /** @param array<string, mixed> $definition */
    private function pdoDriver(array $definition): string
    {
        $client = $definition['client'] ?? null;
        if ($client instanceof \PDO) {
            $driver = $client->getAttribute(\PDO::ATTR_DRIVER_NAME);

            return is_string($driver) ? $this->normalizeDatabaseDriver($driver) : '';
        }

        $connection = $this->string($definition['connection'] ?? null);
        if ($connection !== null) {
            $database = $this->array($this->config->get('database.connections.' . $connection, []));

            return $this->normalizeDatabaseDriver(
                $this->string($database['driver'] ?? null) ?? $connection,
            );
        }

        $dsn = $this->string($definition['dsn'] ?? null);
        if ($dsn !== null && str_contains($dsn, ':')) {
            return $this->normalizeDatabaseDriver(strstr($dsn, ':', true) ?: '');
        }

        return '';
    }

    /** @param array<string, mixed> $definition */
    private function pdoNativeLockScope(array $definition): string
    {
        return match ($this->pdoDriver($definition)) {
            'mysql', 'mariadb', 'pgsql' => self::CLUSTER,
            default => self::NONE,
        };
    }

    /** @param array<string, mixed> $definition */
    private function pdoStateScope(array $definition): string
    {
        return match ($this->pdoDriver($definition)) {
            'sqlite' => self::HOST,
            'mysql', 'mariadb', 'pgsql', 'mssql' => self::CLUSTER,
            default => self::NONE,
        };
    }

    private function rank(string $scope): int
    {
        return match ($scope) {
            self::PROCESS => 1,
            self::HOST => 2,
            self::CLUSTER => 3,
            default => 0,
        };
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param array<string, mixed> $definition */
    private function tieredScope(array $definition): string
    {
        $tiers = $definition['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === []) {
            return self::NONE;
        }

        $scope = self::CLUSTER;
        foreach ($tiers as $tier) {
            if (!is_array($tier)) {
                return self::NONE;
            }
            $candidate = $this->cacheDefinitionScope($this->array($tier), '');
            if ($this->rank($candidate) < $this->rank($scope)) {
                $scope = $candidate;
            }
        }

        return $scope;
    }
}
