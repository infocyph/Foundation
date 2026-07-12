<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Support\Logger;
use Infocyph\DBLayer\Support\Profiler;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DatabaseManager;
use PDO;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

final class DB extends Facade
{
    public static function authSchema(): AuthSchemaInstaller
    {
        return self::manager()->authSchema();
    }

    public static function beginTransaction(?string $name = null): void
    {
        self::manager()->beginTransaction($name);
    }

    public static function cache(?CacheInterface $cache = null): CacheInterface
    {
        return self::manager()->cache($cache);
    }

    public static function capabilities(?string $name = null): Capabilities
    {
        return self::manager()->capabilities($name);
    }

    public static function commit(?string $name = null): void
    {
        self::manager()->commit($name);
    }

    public static function connection(?string $name = null, bool $fresh = false): Connection
    {
        return self::manager()->connection($name, $fresh);
    }

    public static function databaseName(?string $name = null): string
    {
        return self::manager()->databaseName($name);
    }

    public static function disableLogger(): void
    {
        self::manager()->disableLogger();
    }

    public static function disableProfiler(): void
    {
        self::manager()->disableProfiler();
    }

    public static function disableQueryLog(): void
    {
        self::manager()->disableQueryLog();
    }

    public static function disableTelemetry(): void
    {
        self::manager()->disableTelemetry();
    }

    public static function disconnect(?string $name = null): void
    {
        self::manager()->disconnect($name);
    }

    public static function driverName(?string $name = null): string
    {
        return self::manager()->driverName($name);
    }

    public static function enableLogger(?string $logFile = null, ?PsrLoggerInterface $psrLogger = null): void
    {
        self::manager()->enableLogger($logFile, $psrLogger);
    }

    public static function enableProfiler(): void
    {
        self::manager()->enableProfiler();
    }

    public static function enableQueryLog(): void
    {
        self::manager()->enableQueryLog();
    }

    public static function enableTelemetry(): void
    {
        self::manager()->enableTelemetry();
    }

    public static function flushQueryLog(): void
    {
        self::manager()->flushQueryLog();
    }

    /**
     * @param null|callable(array<string, mixed>):void $exporter
     * @return array<string, mixed>
     */
    public static function flushTelemetry(?callable $exporter = null): array
    {
        return self::manager()->flushTelemetry($exporter);
    }

    public static function freshConnection(?string $name = null): Connection
    {
        return self::manager()->freshConnection($name);
    }

    /**
     * @param array<string, mixed> $securityOverrides
     */
    public static function hardenProduction(array $securityOverrides = [], bool $refreshExisting = true): void
    {
        self::manager()->hardenProduction($securityOverrides, $refreshExisting);
    }

    /**
     * @return array<string, mixed>
     */
    public static function health(?string $name = null): array
    {
        return self::manager()->health($name);
    }

    public static function listen(callable $callback): void
    {
        self::manager()->listen($callback);
    }

    public static function logger(?string $logFile = null): Logger
    {
        return self::manager()->logger($logFile);
    }

    public static function logging(): bool
    {
        return self::manager()->logging();
    }

    public static function manager(): DatabaseManager
    {
        return self::app()->db();
    }

    public static function pdo(?string $name = null): PDO
    {
        return self::manager()->pdo($name);
    }

    public static function ping(?string $name = null): bool
    {
        return self::manager()->ping($name);
    }

    /**
     * @param array<string, int> $poolConfig
     */
    public static function pool(array $poolConfig = []): Pool
    {
        return self::manager()->pool($poolConfig);
    }

    /**
     * @param array<string, int> $poolConfig
     */
    public static function poolManager(array $poolConfig = []): PoolManager
    {
        return self::manager()->poolManager($poolConfig);
    }

    public static function profiler(): Profiler
    {
        return self::manager()->profiler();
    }

    public static function purge(): void
    {
        self::manager()->purge();
    }

    public static function query(?string $name = null): QueryBuilder
    {
        return self::manager()->query($name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function queryLog(): array
    {
        return self::manager()->queryLog();
    }

    public static function readOnlyTransaction(callable $callback, ?string $name = null, int $attempts = 1): mixed
    {
        return self::manager()->readOnlyTransaction($callback, $name, $attempts);
    }

    public static function reconnect(?string $name = null): Connection
    {
        return self::manager()->reconnect($name);
    }

    public static function repository(string $table, ?string $name = null): Repository
    {
        return self::manager()->repository($table, $name);
    }

    public static function resetRuntimeState(bool $disconnectConnections = true): void
    {
        self::manager()->resetRuntimeState($disconnectConnections);
    }

    public static function rollback(?string $name = null): void
    {
        self::manager()->rollback($name);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public static function scalar(string $query, array $bindings = [], ?string $name = null): mixed
    {
        return self::manager()->scalar($query, $bindings, $name);
    }

    /**
     * @param array<int, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public static function select(string $query, array $bindings = [], ?string $name = null): array
    {
        return self::manager()->select($query, $bindings, $name);
    }

    /**
     * @param array<string, mixed> $security
     */
    public static function setSecurityDefaults(array $security, bool $refreshExisting = true): void
    {
        self::manager()->setSecurityDefaults($security, $refreshExisting);
    }

    public static function setTelemetryBufferLimits(?int $queryEvents = null, ?int $transactionEvents = null): void
    {
        self::manager()->setTelemetryBufferLimits($queryEvents, $transactionEvents);
    }

    /**
     * @param list<int|float> $percentiles
     * @return array<string, mixed>
     */
    public static function slowQueryReport(array $percentiles = [50, 90, 95, 99], ?float $minimumMs = null): array
    {
        return self::manager()->slowQueryReport($percentiles, $minimumMs);
    }

    /**
     * @param array<int, mixed> $bindings
     */
    public static function statement(string $query, array $bindings = [], ?string $name = null): bool
    {
        return self::manager()->statement($query, $bindings, $name);
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
    public static function stats(?string $name = null): array
    {
        return self::manager()->stats($name);
    }

    public static function supportsJson(?string $name = null): bool
    {
        return self::manager()->supportsJson($name);
    }

    public static function supportsReturning(?string $name = null): bool
    {
        return self::manager()->supportsReturning($name);
    }

    public static function supportsWindowFunctions(?string $name = null): bool
    {
        return self::manager()->supportsWindowFunctions($name);
    }

    public static function table(string $table, ?string $name = null): QueryBuilder
    {
        return self::manager()->table($table, $name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function telemetry(): array
    {
        return self::manager()->telemetry();
    }

    /**
     * @return array<string, mixed>
     */
    public static function telemetryOtel(string $serviceName = 'dblayer'): array
    {
        return self::manager()->telemetryOtel($serviceName);
    }

    public static function transaction(callable $callback, ?string $name = null, int $attempts = 1): mixed
    {
        return self::manager()->transaction($callback, $name, $attempts);
    }

    public static function transactionLevel(?string $name = null): int
    {
        return self::manager()->transactionLevel($name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function transactionStats(?string $name = null): array
    {
        return self::manager()->transactionStats($name);
    }

    public static function version(?string $name = null): string
    {
        return self::manager()->version($name);
    }

    public static function whenQueryingForLongerThan(float $milliseconds, callable $callback): void
    {
        self::manager()->whenQueryingForLongerThan($milliseconds, $callback);
    }

    public static function withPooledConnection(callable $callback, ?string $name = null): mixed
    {
        return self::manager()->withPooledConnection($callback, $name);
    }

    public static function withQueryCancellation(callable $checker, callable $callback, ?string $name = null): mixed
    {
        return self::manager()->withQueryCancellation($checker, $callback, $name);
    }

    public static function withQueryDeadline(float $seconds, callable $callback, ?string $name = null): mixed
    {
        return self::manager()->withQueryDeadline($seconds, $callback, $name);
    }

    public static function withQueryTimeout(?int $milliseconds, callable $callback, ?string $name = null): mixed
    {
        return self::manager()->withQueryTimeout($milliseconds, $callback, $name);
    }
}
