<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class CacheLayerFactory
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function make(?string $name = null): CacheInterface
    {
        $name ??= $this->stringConfig('cache.default', 'memory');
        $stores = $this->stores();

        if (isset($stores[$name])) {
            return $this->makeFromStoreConfig($name, $stores[$name]);
        }

        return $this->makeFromStoreConfig($name, ['driver' => $name]);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function applyCacheConfiguration(CacheInterface $cache, array $store, CacheDriver $driver): CacheInterface
    {
        $compression = $this->compressionConfig($store);
        $cache->configurePayloadCompression(
            $compression['threshold_bytes'],
            $compression['level'],
        );

        $security = $this->securityConfig($store);
        $cache->configurePayloadSecurity(
            $security['integrity_key'],
            $security['max_payload_bytes'],
        );

        $serialization = $this->serializationConfig($store);
        $cache->configureSerializationSecurity(
            $serialization['allow_closure_payloads'],
            $serialization['allow_object_payloads'],
        );

        return $this->applyLockConfiguration($cache, $store, $driver);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function applyLockConfiguration(CacheInterface $cache, array $store, CacheDriver $driver): CacheInterface
    {
        $configured = ValueNormalizer::associativeArray($this->config->get('cache.lock', []));
        $lock = array_replace($configured, ValueNormalizer::associativeArray($store['lock'] ?? []));
        $lockDriver = $this->stringOrNull($lock['driver'] ?? null);

        if ($lockDriver === null || $lockDriver === '') {
            return $cache;
        }

        $prefix = ValueNormalizer::string($lock['prefix'] ?? null, 'cachelayer:lock:');
        $retrySleepMicros = ValueNormalizer::int($lock['retry_sleep_micros'] ?? null, 50_000);

        return match ($this->normalizeLockDriver($lockDriver)) {
            'file' => $cache->setLockProvider(
                new FileLockProvider(
                    $this->directoryFrom($lock, 'path', 'dir', 'directory'),
                    $retrySleepMicros,
                ),
            ),
            'memcache' => $cache->useMemcachedLock(
                $this->memcachedClientFromConfig($store, $lock),
                $prefix,
            ),
            'pdo' => $cache->setLockProvider(
                new PdoLockProvider(
                    $this->pdoClientFromConfig($store, $lock, $driver),
                    $prefix,
                    $retrySleepMicros,
                    new FileLockProvider(
                        $this->directoryFrom(
                            ValueNormalizer::associativeArray($lock['fallback'] ?? []),
                            'path',
                            'dir',
                            'directory',
                        ),
                        ValueNormalizer::int(
                            ValueNormalizer::associativeArray($lock['fallback'] ?? [])['retry_sleep_micros'] ?? null,
                            $retrySleepMicros,
                        ),
                    ),
                ),
            ),
            'redis' => $cache->useRedisLock(
                $this->redisClientFromConfig($store, $lock, 'redis'),
                $prefix,
            ),
            'valkey' => $cache->useValkeyLock(
                $this->redisClientFromConfig($store, $lock, 'valkey'),
                $prefix,
            ),
            default => throw new ConfigurationException(sprintf(
                'Unsupported cache lock driver "%s".',
                $lockDriver,
            )),
        };
    }

    private function basePath(): string
    {
        return $this->stringConfig('app.base_path', getcwd() ?: '.');
    }

    /**
     * @param array<string, mixed> $store
     * @return array{threshold_bytes:?int,level:int}
     */
    private function compressionConfig(array $store): array
    {
        $configured = ValueNormalizer::associativeArray($this->config->get('cache.compression', []));
        $compression = array_replace($configured, ValueNormalizer::associativeArray($store['compression'] ?? []));
        $threshold = $compression['threshold_bytes'] ?? $compression['threshold'] ?? null;
        $level = $compression['level'] ?? null;

        return [
            'threshold_bytes' => is_numeric($threshold) ? max(1, (int) $threshold) : null,
            'level' => max(1, min(9, ValueNormalizer::int($level, 6))),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{dsn:string,username:?string,password:?string}
     */
    private function connectionDsn(array $config): array
    {
        $driver = strtolower(ValueNormalizer::string($config['driver'] ?? null));
        $host = ValueNormalizer::string($config['host'] ?? null, '127.0.0.1');
        $port = ValueNormalizer::int($config['port'] ?? null, 0);
        $database = ValueNormalizer::string($config['database'] ?? null);
        $username = $this->stringOrNull($config['username'] ?? null);
        $password = $this->stringOrNull($config['password'] ?? null);
        $charset = $this->stringOrNull($config['charset'] ?? null);

        return match ($driver) {
            'mariadb', 'mysql' => [
                'dsn' => sprintf(
                    'mysql:host=%s;%sdbname=%s%s',
                    $host,
                    $port > 0 ? 'port=' . $port . ';' : '',
                    $database,
                    $charset !== null ? ';charset=' . $charset : '',
                ),
                'username' => $username,
                'password' => $password,
            ],
            'pgsql', 'postgres', 'postgresql' => [
                'dsn' => sprintf(
                    'pgsql:host=%s;%sdbname=%s%s',
                    $host,
                    $port > 0 ? 'port=' . $port . ';' : '',
                    $database,
                    $charset !== null ? ';options=--client_encoding=' . $charset : '',
                ),
                'username' => $username,
                'password' => $password,
            ],
            'sqlite' => [
                'dsn' => $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:' . $this->resolvePath($database),
                'username' => null,
                'password' => null,
            ],
            default => throw new ConfigurationException(sprintf(
                'Unsupported database connection driver "%s" for cache store resolution.',
                $driver,
            )),
        };
    }

    /**
     * @param array<string, mixed> $store
     */
    private function createCache(string $name, array $store, CacheDriver $driver): CacheInterface
    {
        $namespace = $this->namespace($name, $store);

        return match ($driver) {
            CacheDriver::APCU => Cache::apcu($namespace),
            CacheDriver::FILE => Cache::file($namespace, $this->directoryFrom($store, 'path', 'dir', 'directory')),
            CacheDriver::LOCAL => Cache::local($namespace, $this->directoryFrom($store, 'path', 'dir', 'directory')),
            CacheDriver::MEMCACHE => Cache::memcache($namespace, $this->servers($store['servers'] ?? null)),
            CacheDriver::MEMORY => Cache::memory($namespace),
            CacheDriver::MONGODB => Cache::mongodb(
                namespace: $namespace,
                collection: is_object($store['collection'] ?? null) ? $store['collection'] : null,
                client: is_object($store['client'] ?? null) ? $store['client'] : null,
                database: ValueNormalizer::string($store['database'] ?? null, 'cachelayer'),
                collectionName: ValueNormalizer::string($store['collection_name'] ?? null, 'entries'),
                uri: ValueNormalizer::string($store['uri'] ?? null, 'mongodb://127.0.0.1:27017'),
            ),
            CacheDriver::NULL_STORE => Cache::nullStore(),
            CacheDriver::PDO => $this->pdoCache($namespace, $store),
            CacheDriver::PHP_FILES => Cache::phpFiles($namespace, $this->directoryFrom($store, 'path', 'dir', 'directory')),
            CacheDriver::REDIS => Cache::redis(
                $namespace,
                ValueNormalizer::string($store['dsn'] ?? $store['connection'] ?? null, 'redis://127.0.0.1:6379'),
            ),
            CacheDriver::REDIS_CLUSTER => Cache::redisCluster(
                namespace: $namespace,
                seeds: $this->seeds($store['seeds'] ?? null),
                timeout: $this->floatValue($store['timeout'] ?? null, 1.0),
                readTimeout: $this->floatValue($store['read_timeout'] ?? null, 1.0),
                persistent: ValueNormalizer::bool($store['persistent'] ?? null, false),
                client: is_object($store['client'] ?? null) ? $store['client'] : null,
            ),
            CacheDriver::SCYLLADB => Cache::scyllaDb(
                namespace: $namespace,
                session: $this->scyllaSession($store),
                keyspace: ValueNormalizer::string($store['keyspace'] ?? null, 'cachelayer'),
                table: ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
            ),
            CacheDriver::SHARED_MEMORY => Cache::sharedMemory(
                $namespace,
                ValueNormalizer::int($store['segment_size'] ?? null, 16_777_216),
            ),
            CacheDriver::SQLITE => Cache::sqlite(
                $namespace,
                $this->sqliteFile($store),
            ),
            CacheDriver::TIERED => Cache::tiered(
                $this->tiers($name, $store),
                ValueNormalizer::bool($store['write_to_l1'] ?? null, true),
            ),
            CacheDriver::VALKEY => Cache::valkey(
                $namespace,
                ValueNormalizer::string($store['dsn'] ?? $store['connection'] ?? null, 'valkey://127.0.0.1:6379'),
            ),
            CacheDriver::WEAK_MAP => Cache::weakMap($namespace),
        };
    }

    private function databaseResolver(): DatabaseConnectionResolver
    {
        return new DatabaseConnectionResolver($this->config);
    }

    /**
     * @param array<string, array<string, mixed>> $stores
     * @return array<string, mixed>
     */
    private function descriptorFromNamedStore(string $name, array $stores): array
    {
        $store = $stores[$name] ?? null;
        if (!is_array($store)) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store references undefined store "%s".',
                $name,
            ));
        }

        return $this->descriptorFromStore($name, $store);
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function descriptorFromStore(string $name, array $store): array
    {
        $driver = $this->resolveDriver($name, $store);
        if ($driver === CacheDriver::TIERED) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" cannot be nested inside another tiered definition.',
                $name,
            ));
        }

        $namespace = $this->namespace($name, $store);

        return match ($driver) {
            CacheDriver::APCU => ['driver' => 'apcu', 'namespace' => $namespace],
            CacheDriver::FILE => ['driver' => 'file', 'namespace' => $namespace, 'dir' => $this->directoryFrom($store, 'path', 'dir', 'directory')],
            CacheDriver::LOCAL => extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()
                ? ['driver' => 'apcu', 'namespace' => $namespace]
                : ['driver' => 'file', 'namespace' => $namespace, 'dir' => $this->directoryFrom($store, 'path', 'dir', 'directory')],
            CacheDriver::MEMCACHE => ['driver' => 'memcache', 'namespace' => $namespace, 'servers' => $this->servers($store['servers'] ?? null)],
            CacheDriver::MEMORY => ['driver' => 'memory', 'namespace' => $namespace],
            CacheDriver::MONGODB => [
                'driver' => 'mongodb',
                'namespace' => $namespace,
                'collection' => $store['collection'] ?? null,
                'client' => $store['client'] ?? null,
                'database' => ValueNormalizer::string($store['database'] ?? null, 'cachelayer'),
                'collection_name' => ValueNormalizer::string($store['collection_name'] ?? null, 'entries'),
            ],
            CacheDriver::NULL_STORE => ['driver' => 'null'],
            CacheDriver::PDO => $this->pdoDescriptor($namespace, $store),
            CacheDriver::PHP_FILES => ['driver' => 'php_files', 'namespace' => $namespace, 'dir' => $this->directoryFrom($store, 'path', 'dir', 'directory')],
            CacheDriver::REDIS => [
                'driver' => 'redis',
                'namespace' => $namespace,
                'dsn' => ValueNormalizer::string($store['dsn'] ?? $store['connection'] ?? null, 'redis://127.0.0.1:6379'),
                'client' => $store['client'] ?? null,
            ],
            CacheDriver::REDIS_CLUSTER => [
                'driver' => 'redis_cluster',
                'namespace' => $namespace,
                'seeds' => $this->seeds($store['seeds'] ?? null),
                'timeout' => $this->floatValue($store['timeout'] ?? null, 1.0),
                'read_timeout' => $this->floatValue($store['read_timeout'] ?? null, 1.0),
                'persistent' => ValueNormalizer::bool($store['persistent'] ?? null, false),
                'client' => $store['client'] ?? null,
            ],
            CacheDriver::SCYLLADB => [
                'driver' => 'scylladb',
                'namespace' => $namespace,
                'session' => $store['session'] ?? $store['client'] ?? null,
                'keyspace' => ValueNormalizer::string($store['keyspace'] ?? null, 'cachelayer'),
                'table' => ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
            ],
            CacheDriver::SHARED_MEMORY => [
                'driver' => 'shared_memory',
                'namespace' => $namespace,
                'segment_size' => ValueNormalizer::int($store['segment_size'] ?? null, 16_777_216),
            ],
            CacheDriver::SQLITE => [
                'driver' => 'sqlite',
                'namespace' => $namespace,
                'file' => $this->sqliteFile($store),
                'table' => ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
            ],
            CacheDriver::VALKEY => [
                'driver' => 'valkey',
                'namespace' => $namespace,
                'dsn' => ValueNormalizer::string($store['dsn'] ?? $store['connection'] ?? null, 'valkey://127.0.0.1:6379'),
                'client' => $store['client'] ?? null,
            ],
            CacheDriver::WEAK_MAP => ['driver' => 'weak_map', 'namespace' => $namespace],
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function directoryFrom(array $config, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $directory = $this->stringOrNull($config[$key] ?? null);
            if ($directory !== null) {
                return $this->resolvePath($directory);
            }
        }

        return null;
    }

    private function floatValue(mixed $value, float $default): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function makeFromStoreConfig(string $name, array $store): CacheInterface
    {
        $driver = $this->resolveDriver($name, $store);

        return $this->applyCacheConfiguration(
            $this->createCache($name, $store, $driver),
            $store,
            $driver,
        );
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $lock
     */
    private function memcachedClientFromConfig(array $store, array $lock): \Memcached
    {
        $client = $lock['client'] ?? $store['client'] ?? null;
        if ($client instanceof \Memcached) {
            return $client;
        }

        throw new ConfigurationException('Memcached lock provider requires a Memcached client instance.');
    }

    /**
     * @param array<string, mixed> $store
     */
    private function namespace(string $name, array $store): string
    {
        $configured = $this->stringOrNull($store['namespace'] ?? null);
        if ($configured !== null) {
            return $configured;
        }

        return $this->stringConfig('cache.prefix', 'foundation:') . $name;
    }

    private function normalizeDriverName(string $driver): string
    {
        return match (strtolower($driver)) {
            'array' => 'memory',
            'memcached' => 'memcache',
            'null' => 'null_store',
            'scylla' => 'scylladb',
            default => strtolower($driver),
        };
    }

    private function normalizeLockDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'memcached' => 'memcache',
            default => strtolower($driver),
        };
    }

    /**
     * @param array<string, mixed> $store
     */
    private function pdoCache(string $namespace, array $store): CacheInterface
    {
        $runtime = $this->pdoRuntimeConfig($store);

        return Cache::pdo(
            namespace: $namespace,
            dsn: $runtime['dsn'],
            username: $runtime['username'],
            password: $runtime['password'],
            pdo: $runtime['client'],
            table: ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
        );
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $lock
     */
    private function pdoClientFromConfig(array $store, array $lock, CacheDriver $driver): \PDO
    {
        $client = $lock['client'] ?? $store['client'] ?? null;
        if ($client instanceof \PDO) {
            return $client;
        }

        if ($driver !== CacheDriver::PDO && $driver !== CacheDriver::SQLITE) {
            throw new ConfigurationException('PDO lock provider requires a PDO-backed cache store or PDO client.');
        }

        $runtime = $this->pdoRuntimeConfig($store);
        if ($runtime['client'] instanceof \PDO) {
            return $runtime['client'];
        }

        return new \PDO(
            $runtime['dsn'] ?? throw new ConfigurationException('PDO cache store could not resolve a DSN for lock provider.'),
            $runtime['username'],
            $runtime['password'],
        );
    }

    /**
     * @param array<string, mixed> $store
     * @return array<string, mixed>
     */
    private function pdoDescriptor(string $namespace, array $store): array
    {
        $runtime = $this->pdoRuntimeConfig($store);

        /** @var array<string, mixed> $descriptor */
        $descriptor = array_filter([
            'driver' => 'pdo',
            'namespace' => $namespace,
            'dsn' => $runtime['dsn'],
            'username' => $runtime['username'],
            'password' => $runtime['password'],
            'table' => ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
            'client' => $runtime['client'],
        ], static fn(mixed $value): bool => $value !== null);

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $store
     * @return array{dsn:?string,username:?string,password:?string,client:?\PDO}
     */
    private function pdoRuntimeConfig(array $store): array
    {
        $client = $store['client'] ?? null;
        if ($client instanceof \PDO) {
            return [
                'dsn' => null,
                'username' => null,
                'password' => null,
                'client' => $client,
            ];
        }

        $dsn = $this->stringOrNull($store['dsn'] ?? null);
        $username = $this->stringOrNull($store['username'] ?? null);
        $password = $this->stringOrNull($store['password'] ?? null);
        $connection = $this->stringOrNull($store['connection'] ?? null);

        if ($connection !== null) {
            $resolved = $this->connectionDsn($this->databaseResolver()->configuration($connection));

            return [
                'dsn' => $resolved['dsn'],
                'username' => $resolved['username'],
                'password' => $resolved['password'],
                'client' => null,
            ];
        }

        return [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
            'client' => null,
        ];
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $lock
     */
    private function redisClientFromConfig(array $store, array $lock, string $driver): \Redis
    {
        $client = $lock['client'] ?? $store['client'] ?? null;
        if ($client instanceof \Redis) {
            return $client;
        }

        throw new ConfigurationException(sprintf(
            '%s lock provider requires a Redis client instance.',
            ucfirst($driver),
        ));
    }

    /**
     * @param array<string, mixed> $store
     */
    private function resolveDriver(string $name, array $store): CacheDriver
    {
        $driverName = isset($store['driver']) && is_string($store['driver'])
            ? $store['driver']
            : $name;
        $driver = CacheDriver::tryFrom($this->normalizeDriverName($driverName));

        if ($driver !== null) {
            return $driver;
        }

        throw new ConfigurationException(sprintf(
            'Invalid cache store "%s" driver "%s".',
            $name,
            $driverName,
        ));
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || $this->absolute($path)) {
            return $path;
        }

        return $this->basePath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function scyllaSession(array $store): ?object
    {
        $session = $store['session'] ?? $store['client'] ?? null;

        return is_object($session) ? $session : null;
    }

    /**
     * @param array<string, mixed> $store
     * @return array{integrity_key:?string,max_payload_bytes:?int}
     */
    private function securityConfig(array $store): array
    {
        $configured = ValueNormalizer::associativeArray($this->config->get('cache.security', []));
        $security = array_replace($configured, ValueNormalizer::associativeArray($store['security'] ?? []));

        return [
            'integrity_key' => $this->stringOrNull($security['integrity_key'] ?? null),
            'max_payload_bytes' => is_numeric($security['max_payload_bytes'] ?? null)
                ? max(1, (int) $security['max_payload_bytes'])
                : 8_388_608,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function seeds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return ['127.0.0.1:6379'];
        }

        return ValueNormalizer::stringList($value);
    }

    /**
     * @param array<string, mixed> $store
     * @return array{allow_closure_payloads:bool,allow_object_payloads:bool}
     */
    private function serializationConfig(array $store): array
    {
        $configured = ValueNormalizer::associativeArray($this->config->get('cache.serialization', []));
        $serialization = array_replace($configured, ValueNormalizer::associativeArray($store['serialization'] ?? []));

        return [
            'allow_closure_payloads' => ValueNormalizer::bool(
                $serialization['allow_closure_payloads'] ?? null,
                true,
            ),
            'allow_object_payloads' => ValueNormalizer::bool(
                $serialization['allow_object_payloads'] ?? null,
                true,
            ),
        ];
    }

    /**
     * @return array<int, array{0:string,1:int,2:int}>
     */
    private function servers(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [['127.0.0.1', 11211, 0]];
        }

        $servers = [];

        foreach ($value as $server) {
            if (is_array($server) && isset($server[0], $server[1]) && is_string($server[0])) {
                $servers[] = [
                    $server[0],
                    ValueNormalizer::int($server[1], 11211),
                    ValueNormalizer::int($server[2] ?? null, 0),
                ];

                continue;
            }

            $descriptor = ValueNormalizer::associativeArray($server);
            $servers[] = [
                ValueNormalizer::string($descriptor['host'] ?? null, '127.0.0.1'),
                ValueNormalizer::int($descriptor['port'] ?? null, 11211),
                ValueNormalizer::int($descriptor['weight'] ?? null, 0),
            ];
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $store
     */
    private function sqliteFile(array $store): ?string
    {
        $path = $this->stringOrNull($store['file'] ?? $store['path'] ?? $store['database'] ?? null);

        return $path === null
            ? null
            : $this->resolvePath($path);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function stores(): array
    {
        $stores = $this->config->get('cache.stores', []);
        if (!is_array($stores)) {
            return [];
        }

        $resolved = [];
        foreach ($stores as $name => $store) {
            if (!is_string($name) || !is_array($store)) {
                continue;
            }

            $resolved[$name] = ValueNormalizer::associativeArray($store);
        }

        return $resolved;
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<int, array<string, mixed>>
     */
    private function tiers(string $name, array $store): array
    {
        $tiers = $store['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === []) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" must define a non-empty tiers array.',
                $name,
            ));
        }

        $stores = $this->stores();
        $descriptors = [];

        foreach ($tiers as $index => $tier) {
            if (is_string($tier) && $tier !== '') {
                $descriptors[] = $this->descriptorFromNamedStore($tier, $stores);

                continue;
            }

            if (!is_array($tier)) {
                throw new ConfigurationException(sprintf(
                    'Tiered cache store "%s" tier %s must be a string store name or descriptor array.',
                    $name,
                    (string) $index,
                ));
            }

            $descriptor = ValueNormalizer::associativeArray($tier);
            $storeName = $this->stringOrNull($descriptor['store'] ?? null);
            unset($descriptor['store']);

            if ($storeName !== null) {
                $descriptors[] = array_replace(
                    $this->descriptorFromNamedStore($storeName, $stores),
                    $descriptor,
                );

                continue;
            }

            $descriptors[] = $this->descriptorFromStore(
                $name . '.tiers.' . $index,
                $descriptor,
            );
        }

        return $descriptors;
    }
}
