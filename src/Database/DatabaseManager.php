<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\DB as DBLayer;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Support\Logger;
use Infocyph\DBLayer\Support\Profiler;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use PDO;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

final readonly class DatabaseManager
{
    public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $factory,
        private AuthSchemaInstaller $authSchemaInstaller,
    ) {}

    /**
     * @param callable():void $callback
     */
    public function afterCommit(callable $callback, ?string $name = null): void
    {
        $this->connection($name)->afterCommit($callback);
    }

    public function authSchema(): AuthSchemaInstaller
    {
        return $this->authSchemaInstaller;
    }

    public function beginTransaction(?string $name = null): void
    {
        DBLayer::beginTransaction($this->ensureRegistered($name));
    }

    public function cache(?CacheInterface $cache = null): CacheInterface
    {
        return DBLayer::cache($cache);
    }

    public function capabilities(?string $name = null): Capabilities
    {
        return DBLayer::capabilities($this->ensureRegistered($name));
    }

    public function commit(?string $name = null): void
    {
        DBLayer::commit($this->ensureRegistered($name));
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

    public function databaseName(?string $name = null): string
    {
        return DBLayer::getDatabaseName($this->ensureRegistered($name));
    }

    public function disableLogger(): void
    {
        DBLayer::disableLogger();
    }

    public function disableProfiler(): void
    {
        DBLayer::disableProfiler();
    }

    public function disableQueryLog(): void
    {
        DBLayer::disableQueryLog();
    }

    public function disableTelemetry(): void
    {
        DBLayer::disableTelemetry();
    }

    public function disconnect(?string $name = null): void
    {
        DBLayer::disconnect($this->ensureRegistered($name));
    }

    public function driverName(?string $name = null): string
    {
        return DBLayer::getDriverName($this->ensureRegistered($name));
    }

    public function enableLogger(?string $logFile = null, ?PsrLoggerInterface $psrLogger = null): void
    {
        DBLayer::enableLogger($logFile, $psrLogger);
    }

    public function enableProfiler(): void
    {
        DBLayer::enableProfiler();
    }

    public function enableQueryLog(): void
    {
        DBLayer::enableQueryLog();
    }

    public function enableTelemetry(): void
    {
        DBLayer::enableTelemetry();
    }

    public function flushQueryLog(): void
    {
        DBLayer::flushQueryLog();
    }

    /**
     * @param null|callable(array<string, mixed>):void $exporter
     * @return array<string, mixed>
     */
    public function flushTelemetry(?callable $exporter = null): array
    {
        return DBLayer::flushTelemetry($exporter);
    }

    public function freshConnection(?string $name = null): Connection
    {
        return $this->connection($name, true);
    }

    /**
     * @param array<string, mixed> $securityOverrides
     */
    public function hardenProduction(array $securityOverrides = [], bool $refreshExisting = true): void
    {
        DBLayer::hardenProduction($securityOverrides, $refreshExisting);
    }

    /**
     * @return array<string, mixed>
     */
    public function health(?string $name = null): array
    {
        return DBLayer::health($this->ensureRegistered($name));
    }

    public function listen(callable $callback): void
    {
        DBLayer::listen($callback);
    }

    public function logger(?string $logFile = null): Logger
    {
        return DBLayer::logger($logFile);
    }

    public function logging(): bool
    {
        return DBLayer::logging();
    }

    public function pdo(?string $name = null): PDO
    {
        return DBLayer::getPdo($this->ensureRegistered($name));
    }

    public function ping(?string $name = null): bool
    {
        return DBLayer::ping($this->ensureRegistered($name));
    }

    /**
     * @param array<string, int> $poolConfig
     */
    public function pool(array $poolConfig = []): Pool
    {
        return DBLayer::pool($poolConfig);
    }

    /**
     * @param array<string, int> $poolConfig
     */
    public function poolManager(array $poolConfig = []): PoolManager
    {
        return DBLayer::poolManager($poolConfig);
    }

    public function profiler(): Profiler
    {
        return DBLayer::profiler();
    }

    public function purge(): void
    {
        DBLayer::purge();
    }

    public function query(?string $name = null): QueryBuilder
    {
        return $this->connection($name)->query();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queryLog(): array
    {
        return DBLayer::getQueryLog();
    }

    public function readOnlyTransaction(callable $callback, ?string $name = null, int $attempts = 1): mixed
    {
        return DBLayer::readOnlyTransaction($callback, $attempts, $this->ensureRegistered($name));
    }

    public function reconnect(?string $name = null): Connection
    {
        return DBLayer::reconnect($this->ensureRegistered($name));
    }

    public function repository(string $table, ?string $name = null): Repository
    {
        return DBLayer::repository($table, $this->ensureRegistered($name));
    }

    public function resetRuntimeState(bool $disconnectConnections = true): void
    {
        DBLayer::resetRuntimeState($disconnectConnections);
    }

    public function rollback(?string $name = null): void
    {
        DBLayer::rollBack($this->ensureRegistered($name));
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function scalar(string $query, array $bindings = [], ?string $name = null): mixed
    {
        return DBLayer::scalar($query, $bindings, $this->ensureRegistered($name));
    }

    /**
     * @param array<int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $query, array $bindings = [], ?string $name = null): array
    {
        return DBLayer::select($query, $bindings, $this->ensureRegistered($name));
    }

    /**
     * @param array<string, mixed> $security
     */
    public function setSecurityDefaults(array $security, bool $refreshExisting = true): void
    {
        DBLayer::setSecurityDefaults($security, $refreshExisting);
    }

    public function setTelemetryBufferLimits(?int $queryEvents = null, ?int $transactionEvents = null): void
    {
        DBLayer::setTelemetryBufferLimits($queryEvents, $transactionEvents);
    }

    /**
     * @param list<int|float> $percentiles
     * @return array<string, mixed>
     */
    public function slowQueryReport(array $percentiles = [50, 90, 95, 99], ?float $minimumMs = null): array
    {
        return DBLayer::slowQueryReport($percentiles, $minimumMs);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public function statement(string $query, array $bindings = [], ?string $name = null): bool
    {
        return DBLayer::statement($query, $bindings, $this->ensureRegistered($name));
    }

    /**
     * @return array{
     *   driver:string,
     *   database:string,
     *   prefix:string,
     *   transaction_level:int,
     *   total_queries:int
     * }
     */
    public function stats(?string $name = null): array
    {
        return DBLayer::stats($this->ensureRegistered($name));
    }

    public function supportsJson(?string $name = null): bool
    {
        return DBLayer::supportsJson($this->ensureRegistered($name));
    }

    public function supportsReturning(?string $name = null): bool
    {
        return DBLayer::supportsReturning($this->ensureRegistered($name));
    }

    public function supportsWindowFunctions(?string $name = null): bool
    {
        return DBLayer::supportsWindowFunctions($this->ensureRegistered($name));
    }

    public function table(string $table, ?string $name = null): QueryBuilder
    {
        return $this->connection($name)->table($table);
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetry(): array
    {
        return DBLayer::telemetry();
    }

    /**
     * @return array<string, mixed>
     */
    public function telemetryOtel(string $serviceName = 'dblayer'): array
    {
        return DBLayer::telemetryOtel($serviceName);
    }

    public function transaction(callable $callback, ?string $name = null, int $attempts = 1): mixed
    {
        return $this->connection($name)->transaction($callback, $attempts);
    }

    public function transactionLevel(?string $name = null): int
    {
        return DBLayer::transactionLevel($this->ensureRegistered($name));
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionStats(?string $name = null): array
    {
        return DBLayer::transactionStats($this->ensureRegistered($name));
    }

    public function version(?string $name = null): string
    {
        return DBLayer::version($this->ensureRegistered($name));
    }

    public function whenQueryingForLongerThan(float $milliseconds, callable $callback): void
    {
        DBLayer::whenQueryingForLongerThan($milliseconds, $callback);
    }

    public function withPooledConnection(callable $callback, ?string $name = null): mixed
    {
        return DBLayer::withPooledConnection($callback, $this->ensureRegistered($name));
    }

    public function withQueryCancellation(callable $checker, callable $callback, ?string $name = null): mixed
    {
        return DBLayer::withQueryCancellation($checker, $callback, $this->ensureRegistered($name));
    }

    public function withQueryDeadline(float $seconds, callable $callback, ?string $name = null): mixed
    {
        return DBLayer::withQueryDeadline($seconds, $callback, $this->ensureRegistered($name));
    }

    public function withQueryTimeout(?int $milliseconds, callable $callback, ?string $name = null): mixed
    {
        return DBLayer::withQueryTimeout($milliseconds, $callback, $this->ensureRegistered($name));
    }

    private function ensureRegistered(?string $name = null): string
    {
        $resolved = $this->factory->resolver()->connectionName($name);
        $this->connection($resolved);

        return $resolved;
    }
}
