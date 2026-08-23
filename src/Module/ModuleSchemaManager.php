<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheSchema;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationSchema;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\SessionDatabaseSchema;
use PDO;
use PDOException;

final readonly class ModuleSchemaManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
    ) {}

    /**
     * Provision every schema currently required by configured application capabilities.
     *
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function installApplicable(?string $connection = null): array
    {
        $results = [];

        foreach (array_keys($this->catalog->all()) as $module) {
            array_push($results, ...$this->install($module, $connection, true));
        }

        return $results;
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function install(string $module, ?string $connection = null, bool $applicableOnly = false): array
    {
        $definition = $this->catalog->resolve($module);
        $results = [];

        foreach ($definition['schemas'] as $schema) {
            $before = $this->schemaStatuses($definition['name'], $schema, $connection);
            $applicable = array_any($before, static fn(array $status): bool => $status['applicable']);
            $available = array_any($before, static fn(array $status): bool => $status['state'] !== 'unavailable');

            if (($applicableOnly && !$applicable) || !$available) {
                array_push($results, ...$before);

                continue;
            }

            $this->installSchema($schema, $connection);
            array_push(
                $results,
                ...$this->schemaStatuses($definition['name'], $schema, $connection, true),
            );
        }

        return $results;
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    public function status(string $module, ?string $connection = null): array
    {
        $definition = $this->catalog->resolve($module);
        $results = [];

        foreach ($definition['schemas'] as $schema) {
            array_push($results, ...$this->schemaStatuses($definition['name'], $schema, $connection));
        }

        return $results;
    }

    private function authApplicable(): bool
    {
        return $this->application->config()->get('auth.drivers.storage', 'memory') === 'database';
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    private function cacheStatuses(string $module, ?string $connection, bool $afterInstall = false): array
    {
        $applicable = $this->cacheDatabaseConfigured();
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

        $resources = $this->activeCacheDatabaseResources($connection);
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

        $results = [];
        foreach ($resources as $resource) {
            if (!$resource['pdo'] instanceof PDO) {
                $results[] = $this->result(
                    $resource['name'],
                    $module,
                    true,
                    false,
                    'unavailable',
                    $resource['detail'],
                );

                continue;
            }

            $installed = $this->tableExists($resource['pdo'], $resource['table']);
            $results[] = $this->result(
                $resource['name'],
                $module,
                true,
                $installed,
                $installed ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
                $resource['detail'],
            );
        }

        return $results;
    }

    private function cacheDatabaseConfigured(): bool
    {
        $stores = $this->associative($this->application->config()->get('cache.stores', []));
        foreach ($this->activeCacheStoreNames() as $name) {
            $store = $this->associative($stores[$name] ?? []);
            $driver = strtolower(is_string($store['driver'] ?? null) ? $store['driver'] : $name);
            if (in_array($driver, ['pdo', 'sqlite'], true)) {
                return true;
            }
        }

        return $this->activePdoTransportNames() !== [];
    }

    /**
     * @return list<array{name:string,table:string,pdo:?PDO,detail:string,type:string,allow_sqlite_for_testing?:bool}>
     */
    private function activeCacheDatabaseResources(?string $connection): array
    {
        $stores = $this->associative($this->application->config()->get('cache.stores', []));
        $resources = [];

        foreach ($this->activeCacheStoreNames() as $name) {
            $store = $this->associative($stores[$name] ?? []);
            $driver = strtolower(is_string($store['driver'] ?? null) ? $store['driver'] : $name);
            if (!in_array($driver, ['pdo', 'sqlite'], true)) {
                continue;
            }

            $table = is_string($store['table'] ?? null) && $store['table'] !== ''
                ? $store['table']
                : 'cachelayer_entries';
            $resolved = $this->cachePdo($store, $driver, $connection);
            $resources[] = [
                'name' => 'cache:store:' . $name,
                'table' => $table,
                'pdo' => $resolved['pdo'],
                'detail' => $resolved['detail'],
                'type' => 'cache',
            ];
        }

        $transports = $this->associative($this->application->config()->get('cache.transports', []));
        foreach ($this->activePdoTransportNames() as $name) {
            $transport = $this->associative($transports[$name] ?? []);
            $resolved = $this->databasePdo(
                $connection ?? $this->nullableString($transport['connection'] ?? null),
            );
            $resources[] = [
                'name' => 'cache:transport:' . $name,
                'table' => 'cachelayer_invalidation_events',
                'pdo' => $resolved['pdo'],
                'detail' => $resolved['detail'],
                'type' => 'invalidation',
                'allow_sqlite_for_testing' => ($transport['allow_sqlite_for_testing'] ?? false) === true,
            ];
        }

        return $resources;
    }

    /** @return list<string> */
    private function activeCacheStoreNames(): array
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
    private function activePdoTransportNames(): array
    {
        $clusters = $this->associative($this->application->config()->get('cache.clusters', []));
        $transports = $this->associative($this->application->config()->get('cache.transports', []));
        $active = [];

        foreach ($clusters as $cluster) {
            if (!is_array($cluster)) {
                continue;
            }
            $name = $cluster['transport'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            $transport = $this->associative($transports[$name] ?? []);
            if (strtolower(is_string($transport['driver'] ?? null) ? $transport['driver'] : '') === 'pdo') {
                $active[$name] = true;
            }
        }

        return array_keys($active);
    }

    /** @param array<string,mixed> $store @return array{pdo:?PDO,detail:string} */
    private function cachePdo(array $store, string $driver, ?string $connection): array
    {
        if (($store['client'] ?? null) instanceof PDO) {
            return ['pdo' => $store['client'], 'detail' => 'Configured PDO client.'];
        }

        $named = $connection ?? $this->nullableString($store['connection'] ?? null);
        if ($named !== null) {
            return $this->databasePdo($named);
        }

        if ($driver === 'sqlite') {
            $path = $this->nullableString($store['path'] ?? $store['file'] ?? $store['sqlite_file'] ?? null);
            if ($path === null) {
                return ['pdo' => null, 'detail' => 'SQLite cache store has no configured path.'];
            }
            $path = $this->absolute($path) ? $path : $this->application->basePath($path);
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create cache schema directory "%s".', $directory));
            }

            return ['pdo' => new PDO('sqlite:' . $path), 'detail' => 'SQLite cache database ' . $path . '.'];
        }

        $dsn = $this->nullableString($store['dsn'] ?? null);
        if ($dsn === null) {
            return ['pdo' => null, 'detail' => 'PDO cache store requires a DBLayer connection or DSN.'];
        }

        return [
            'pdo' => new PDO(
                $dsn,
                $this->nullableString($store['username'] ?? null),
                $this->nullableString($store['password'] ?? null),
            ),
            'detail' => 'Configured PDO cache DSN.',
        ];
    }

    /** @return array{pdo:?PDO,detail:string} */
    private function databasePdo(?string $connection): array
    {
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return [
                'pdo' => null,
                'detail' => 'Requires the database module; run "php infbyte module:install database".',
            ];
        }

        try {
            $pdo = $this->application->make(DBLayerFactory::class)->connection($connection)->getPdo();
        } catch (\Throwable $failure) {
            return ['pdo' => null, 'detail' => $failure->getMessage()];
        }

        return [
            'pdo' => $pdo,
            'detail' => 'DBLayer connection ' . ($connection ?? 'default') . '.',
        ];
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
        return compact('name', 'module', 'applicable', 'installed', 'state', 'detail');
    }

    /**
     * @return list<array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}>
     */
    private function schemaStatuses(
        string $module,
        string $schema,
        ?string $connection,
        bool $afterInstall = false,
    ): array {
        return match ($schema) {
            'auth' => [$this->authStatus($module, $connection, $afterInstall)],
            'cache' => $this->cacheStatuses($module, $connection, $afterInstall),
            'session' => [$this->sessionStatus($module, $connection, $afterInstall)],
            default => [$this->result($schema, $module, false, true, 'not-applicable', 'No schema provisioner is registered.')],
        };
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function authStatus(string $module, ?string $connection, bool $afterInstall): array
    {
        $applicable = $this->authApplicable();
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return $this->result(
                'auth',
                $module,
                $applicable,
                false,
                'unavailable',
                'Requires the database module; run "php infbyte module:install database".',
            );
        }

        try {
            $status = $this->application->make(AuthSchemaInstaller::class)->readiness($connection);
        } catch (\Throwable $failure) {
            return $this->result('auth', $module, $applicable, false, 'unavailable', $failure->getMessage());
        }

        return $this->result(
            'auth',
            $module,
            $applicable,
            $status['installed'],
            $status['installed'] ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
            $status['installed']
                ? 'Authentication tables are installed.'
                : 'Missing: ' . implode(', ', [...$status['missing_tables'], ...$status['missing_columns']]),
        );
    }

    private function installSchema(string $schema, ?string $connection): void
    {
        switch ($schema) {
            case 'auth':
                $this->application->make(AuthSchemaInstaller::class)->install($connection);
                break;
            case 'cache':
                $this->installCacheSchemas($connection);
                break;
            case 'session':
                $this->application->make(SessionDatabaseSchema::class)->install($connection);
                break;
        }
    }

    private function installCacheSchemas(?string $connection): void
    {
        foreach ($this->activeCacheDatabaseResources($connection) as $resource) {
            $pdo = $resource['pdo'];
            if (!$pdo instanceof PDO) {
                continue;
            }

            if ($resource['type'] === 'invalidation') {
                PdoInvalidationSchema::install(
                    $pdo,
                    ($resource['allow_sqlite_for_testing'] ?? false) === true,
                );

                continue;
            }

            PdoCacheSchema::install($pdo, $resource['table']);
        }
    }

    private function sessionApplicable(): bool
    {
        return $this->application->config()->get('session.driver', 'file') === 'database';
    }

    /**
     * @return array{name:string,module:string,applicable:bool,installed:bool,state:string,detail:string}
     */
    private function sessionStatus(string $module, ?string $connection, bool $afterInstall): array
    {
        $applicable = $this->sessionApplicable();
        if (!class_exists(\Infocyph\DBLayer\Connection\Connection::class)) {
            return $this->result(
                'session',
                $module,
                $applicable,
                false,
                'unavailable',
                'Requires the database module; run "php infbyte module:install database".',
            );
        }

        try {
            $status = $this->application->make(SessionDatabaseSchema::class)->readiness($connection);
        } catch (\Throwable $failure) {
            return $this->result('session', $module, $applicable, false, 'unavailable', $failure->getMessage());
        }

        return $this->result(
            'session',
            $module,
            $applicable,
            $status['installed'],
            $status['installed'] ? 'installed' : ($afterInstall ? 'missing' : 'pending'),
            $status['installed'] ? 'Session table is installed.' : 'Missing session table ' . $status['table'] . '.',
        );
    }

    /** @return array<string,mixed> */
    private function associative(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
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
}
