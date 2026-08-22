<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;

/**
 * Foundation-owned database composition and application policy.
 *
 * Query building, repositories, transactions, observability, capabilities and
 * runtime state remain DBLayer APIs and are intentionally not mirrored here.
 */
final readonly class DatabaseManager
{
    public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $factory,
        private AuthSchemaInstaller $authSchemaInstaller,
        private DatabaseMigrationManager $migrations,
    ) {}

    public function authSchema(): AuthSchemaInstaller
    {
        return $this->authSchemaInstaller;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('database', []);
        }

        return $this->config->get('database.' . $key, $default);
    }

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        return $this->factory->connection($name, $fresh);
    }

    public function freshConnection(?string $name = null): Connection
    {
        return $this->connection($name, true);
    }

    public function migrations(): DatabaseMigrationManager
    {
        return $this->migrations;
    }

    /** @param array<string, int>|null $poolConfig */
    public function pool(?array $poolConfig = null): Pool
    {
        return DB::pool($poolConfig ?? $this->poolConfiguration());
    }

    /** @param array<string, int>|null $poolConfig */
    public function poolManager(?array $poolConfig = null): PoolManager
    {
        return DB::poolManager($poolConfig ?? $this->poolConfiguration());
    }

    public function resetRuntimeState(bool $disconnectConnections = true): void
    {
        DB::resetRuntimeState($disconnectConnections);
    }

    /** Transitional composition helper for ReqShield integration. */
    public function query(?string $name = null): QueryBuilder
    {
        return $this->connection($name)->query();
    }

    /**
     * Transitional composition helper for ReqShield integration.
     *
     * @param array<int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $query, array $bindings = [], ?string $name = null): array
    {
        return $this->connection($name)->select($query, $bindings);
    }

    public function withPooledConnection(callable $callback, ?string $name = null): mixed
    {
        $this->pool();
        $resolved = $this->factory->resolver()->connectionName($name);
        $this->connection($resolved);

        return DB::withPooledConnection($callback, $resolved);
    }

    /** @return array<string, int> */
    private function poolConfiguration(): array
    {
        $configured = $this->config->get('database.pool', []);
        if (!is_array($configured)) {
            return [];
        }

        $pool = [];
        foreach ($configured as $key => $value) {
            if (is_string($key) && (is_int($value) || is_numeric($value))) {
                $pool[$key] = (int) $value;
            }
        }

        return $pool;
    }
}
