<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheSchema;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationSchema;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Support\ValueNormalizer;
use PDO;
use PDOException;

final readonly class CacheSchemaManager
{
    public function __construct(private Application $application) {}

    public function install(?string $connection): void
    {
        foreach ($this->activeResources($connection, true) as $resource) {
            $pdo = $resource['pdo'];
            if (!$pdo instanceof PDO) {
                continue;
            }

            if ($resource['type'] === 'invalidation') {
                PdoInvalidationSchema::install($pdo, $resource['allow_sqlite_for_testing']);

                continue;
            }

            PdoCacheSchema::install($pdo, $resource['table']);
        }
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function statuses(string $module, ?string $connection, bool $afterInstall = false): array
    {
        $applicable = $this->configured();
        if (!class_exists(PdoCacheSchema::class)) {
            return [$this->result(
                'cache',
                $module,
                $applicable,
                false,
                'unavailable',
                'Requires the cache module; run "php infbyte module:install cache".',
            )];
        }

        $resources = $this->activeResources($connection);
        if ($resources === []) {
            return [$this->result(
                'cache',
                $module,
                false,
                true,
                'not-applicable',
                'No active database-backed cache store or PDO invalidation transport.',
            )];
        }

        return array_map(
            fn(array $resource): array => $this->resourceStatus($resource, $module, $afterInstall),
            $resources,
        );
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @return list<array{name:string,table:string,pdo:?PDO,detail:string,type:'cache'|'invalidation',state:string,allow_sqlite_for_testing:bool}>
     */
    private function activeResources(?string $connection, bool $forInstall = false): array
    {
        return [
            ...$this->storeResources($connection, $forInstall),
            ...$this->transportResources($connection),
        ];
    }

    /** @return list<string> */
    private function activeStoreNames(): array
    {
        $config = $this->application->config();
        $default = $config->get('cache.default', 'memory');
        $default = is_string($default) && $default !== '' ? $default : 'memory';
        $names = [$default => true];

        foreach (['cache.lock.store', 'database.migrations.lock_store'] as $key) {
            $name = $config->get($key);
            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        if ($config->get('session.driver', 'file') === 'cache') {
            $name = $config->get('session.stores.cache.store');
            $names[is_string($name) && $name !== '' ? $name : $default] = true;
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private function activeTransportNames(): array
    {
        $clusters = ValueNormalizer::associativeArray($this->application->config()->get('cache.clusters', []));
        $transports = ValueNormalizer::associativeArray($this->application->config()->get('cache.transports', []));
        $active = [];

        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $name = $cluster['transport'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $transport = ValueNormalizer::associativeArray($transports[$name] ?? []);
            $driver = $transport['driver'] ?? null;
            if (is_string($driver) && strtolower($driver) === 'pdo') {
                $active[$name] = true;
            }
        }

        return array_keys($active);
    }

    /**
     * @param array<string,mixed> $store
     * @return array{pdo:?PDO,detail:string,state:string}
     */
    private function cachePdo(array $store, string $driver, ?string $connection, bool $forInstall): array
    {
        $client = $store['client'] ?? null;
        if ($client instanceof PDO) {
            return ['pdo' => $client, 'detail' => 'Configured PDO client.', 'state' => 'ready'];
        }

        $named = $this->nullableString($store['connection'] ?? null) ?? $connection;
        if ($named !== null) {
            return $this->databasePdo($named);
        }

        return $driver === 'sqlite'
            ? $this->sqlitePdo($store, $forInstall)
            : $this->dsnPdo($store);
    }

    private function configured(): bool
    {
        $stores = ValueNormalizer::associativeArray($this->application->config()->get('cache.stores', []));
        foreach ($this->activeStoreNames() as $name) {
            $store = ValueNormalizer::associativeArray($stores[$name] ?? []);
            $configuredDriver = $store['driver'] ?? null;
            $driver = strtolower(is_string($configuredDriver) ? $configuredDriver : $name);
            if (in_array($driver, ['pdo', 'sqlite'], true)) {
                return true;
            }
        }

        return $this->activeTransportNames() !== [];
    }

    /** @return array{pdo:?PDO,detail:string,state:string} */
    private function databasePdo(?string $connection): array
    {
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return [
                'pdo' => null,
                'detail' => 'Requires the database module; run "php infbyte module:install database".',
                'state' => 'unavailable',
            ];
        }

        try {
            $pdo = $this->application->make(DBLayerFactory::class)->connection($connection)->getPdo();
        } catch (\Throwable $failure) {
            return ['pdo' => null, 'detail' => $failure->getMessage(), 'state' => 'unavailable'];
        }

        return [
            'pdo' => $pdo,
            'detail' => 'DBLayer connection ' . ($connection ?? 'default') . '.',
            'state' => 'ready',
        ];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{pdo:?PDO,detail:string,state:string}
     */
    private function dsnPdo(array $store): array
    {
        $dsn = $this->nullableString($store['dsn'] ?? null);
        if ($dsn === null) {
            return [
                'pdo' => null,
                'detail' => 'PDO cache store requires a DBLayer connection or DSN.',
                'state' => 'unavailable',
            ];
        }

        return [
            'pdo' => new PDO(
                $dsn,
                $this->nullableString($store['username'] ?? null),
                $this->nullableString($store['password'] ?? null),
            ),
            'detail' => 'Configured PDO cache DSN.',
            'state' => 'ready',
        ];
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create cache schema directory "%s".', $directory));
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array{name:string,table:string,pdo:?PDO,detail:string,type:'cache'|'invalidation',state:string,allow_sqlite_for_testing:bool} $resource
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function resourceStatus(array $resource, string $module, bool $afterInstall): array
    {
        $pdo = $resource['pdo'];
        if (!$pdo instanceof PDO) {
            $state = $resource['state'];

            return $this->result(
                $resource['name'],
                $module,
                true,
                false,
                $afterInstall && $state === 'pending' ? 'missing' : $state,
                $resource['detail'],
            );
        }

        $installed = $this->tableExists($pdo, $resource['table']);

        return $this->result(
            $resource['name'],
            $module,
            true,
            $installed,
            $installed ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
            $resource['detail'],
        );
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function result(
        string $name,
        string $module,
        bool $applicable,
        bool $installed,
        string $state,
        string $detail,
    ): array {
        return [
            'name' => $name,
            'module' => $module,
            'applicable' => $applicable,
            'installed' => $installed,
            'state' => $state,
            'detail' => $detail,
        ];
    }

    /**
     * @param array<string,mixed> $store
     * @return array{pdo:?PDO,detail:string,state:string}
     */
    private function sqlitePdo(array $store, bool $forInstall): array
    {
        $path = $this->nullableString($store['path'] ?? $store['file'] ?? $store['sqlite_file'] ?? null);
        if ($path === null) {
            return ['pdo' => null, 'detail' => 'SQLite cache store has no configured path.', 'state' => 'unavailable'];
        }

        $path = $this->absolute($path) ? $path : $this->application->basePath($path);
        if (!$forInstall && !is_file($path)) {
            return [
                'pdo' => null,
                'detail' => 'SQLite cache database ' . $path . ' does not exist.',
                'state' => 'pending',
            ];
        }

        if ($forInstall) {
            $this->ensureDirectory(dirname($path));
        }

        return [
            'pdo' => new PDO('sqlite:' . $path),
            'detail' => 'SQLite cache database ' . $path . '.',
            'state' => 'ready',
        ];
    }

    /**
     * @return list<array{name:string,table:string,pdo:?PDO,detail:string,type:'cache'|'invalidation',state:string,allow_sqlite_for_testing:bool}>
     */
    private function storeResources(?string $connection, bool $forInstall): array
    {
        $stores = ValueNormalizer::associativeArray($this->application->config()->get('cache.stores', []));
        $resources = [];

        foreach ($this->activeStoreNames() as $name) {
            $store = ValueNormalizer::associativeArray($stores[$name] ?? []);
            $configuredDriver = $store['driver'] ?? null;
            $driver = strtolower(is_string($configuredDriver) ? $configuredDriver : $name);
            if (!in_array($driver, ['pdo', 'sqlite'], true)) {
                continue;
            }

            $configuredTable = $store['table'] ?? null;
            $table = is_string($configuredTable) && $configuredTable !== '' ? $configuredTable : 'cachelayer_entries';
            $resolved = $this->cachePdo($store, $driver, $connection, $forInstall);
            $resources[] = [
                'name' => 'cache:store:' . $name,
                'table' => $table,
                'pdo' => $resolved['pdo'],
                'detail' => $resolved['detail'],
                'type' => 'cache',
                'state' => $resolved['state'],
                'allow_sqlite_for_testing' => false,
            ];
        }

        return $resources;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return false;
        }

        try {
            return $pdo->query('SELECT 1 FROM ' . $table . ' WHERE 1 = 0') !== false;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return list<array{name:string,table:string,pdo:?PDO,detail:string,type:'cache'|'invalidation',state:string,allow_sqlite_for_testing:bool}>
     */
    private function transportResources(?string $connection): array
    {
        $transports = ValueNormalizer::associativeArray($this->application->config()->get('cache.transports', []));
        $resources = [];

        foreach ($this->activeTransportNames() as $name) {
            $transport = ValueNormalizer::associativeArray($transports[$name] ?? []);
            $resolved = $this->databasePdo($this->nullableString($transport['connection'] ?? null) ?? $connection);
            $resources[] = [
                'name' => 'cache:transport:' . $name,
                'table' => 'cachelayer_invalidation_events',
                'pdo' => $resolved['pdo'],
                'detail' => $resolved['detail'],
                'type' => 'invalidation',
                'state' => $resolved['state'],
                'allow_sqlite_for_testing' => ($transport['allow_sqlite_for_testing'] ?? false) === true,
            ];
        }

        return $resources;
    }
}
