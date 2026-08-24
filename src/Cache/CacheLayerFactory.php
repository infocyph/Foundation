<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Closure;
use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\ClusterRuntime;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Support\RedisConnection;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;

/**
 * Translate Foundation application configuration into native CacheLayer objects.
 *
 * Cache semantics, backend behavior, tier validation, locking, counters, node
 * cache and cluster behavior remain CacheLayer responsibilities.
 */
final readonly class CacheLayerFactory
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
        /** @var Closure(?string):Connection */
        private Closure $database,
    ) {}

    public function cluster(string $name): ClusterRuntime
    {
        $cluster = $this->named('cache.clusters')[$name] ?? null;
        if ($cluster === null) {
            throw new ConfigurationException(sprintf('Cache cluster "%s" is not configured.', $name));
        }

        $storeName = $this->requiredString($cluster, 'store', 'cache.clusters.' . $name);
        $store = $this->stores()[$storeName] ?? null;
        if ($store === null || $this->driver($storeName, $store) !== CacheDriver::NODE) {
            throw new ConfigurationException(sprintf(
                'Cache cluster "%s" must reference a configured node cache store.',
                $name,
            ));
        }

        return ClusterCache::create(
            $this->nodeConfig($storeName, $store),
            new ClusterCacheConfig(
                cluster: $this->requiredString($cluster, 'cluster', 'cache.clusters.' . $name),
                nodeId: $this->requiredString($cluster, 'node_id', 'cache.clusters.' . $name),
                consumerBatchSize: max(1, ValueNormalizer::int($cluster['consumer_batch_size'] ?? null, 1_000)),
                invalidateLocallyFirst: ValueNormalizer::bool($cluster['invalidate_locally_first'] ?? null, true),
            ),
            $this->transport($this->requiredString($cluster, 'transport', 'cache.clusters.' . $name)),
        );
    }

    public function counters(string $name): AtomicCounterStoreInterface
    {
        $counter = $this->named('cache.counters')[$name] ?? null;
        if ($counter === null) {
            throw new ConfigurationException(sprintf('Cache counter "%s" is not configured.', $name));
        }

        $driver = strtolower($this->requiredString($counter, 'driver', 'cache.counters.' . $name));
        $connection = $this->redisConnection($counter, $driver);
        $namespace = ValueNormalizer::string($counter['namespace'] ?? null, $name);

        return match ($driver) {
            'redis' => AtomicCounters::redis($namespace, $connection['dsn'], $connection['client']),
            'valkey' => AtomicCounters::valkey($namespace, $connection['dsn'], $connection['client']),
            default => throw new ConfigurationException(sprintf(
                'Cache counter "%s" must use Redis or Valkey.',
                $name,
            )),
        };
    }

    public function lock(?string $storeName = null): LockProviderInterface
    {
        $lock = ValueNormalizer::associativeArray($this->config->get('cache.lock', []));
        $storeName ??= $this->stringOrNull($lock['store'] ?? null)
            ?? $this->stringConfig('cache.default', 'memory');
        $store = $this->stores()[$storeName] ?? ['driver' => $storeName];
        $driver = $this->driver($storeName, $store);

        if ($this->stringOrNull($lock['driver'] ?? null) !== null) {
            return $this->lockProvider($store, $lock, $driver);
        }

        $cache = $this->make($storeName);
        if ($cache instanceof AuthenticationStateCacheInterface) {
            $native = $cache->authenticationStateLock();
            if ($native instanceof LockProviderInterface) {
                return $native;
            }
        }

        throw new ConfigurationException(sprintf(
            'Cache store "%s" does not expose a native coordination lock; configure cache.lock.driver explicitly.',
            $storeName,
        ));
    }

    public function make(?string $name = null): CacheInterface
    {
        $name ??= $this->stringConfig('cache.default', 'memory');
        $store = $this->stores()[$name] ?? ['driver' => $name];
        $driver = $this->driver($name, $store);

        return $this->applyLock(
            $this->createCache($name, $store, $driver),
            $store,
            $driver,
        );
    }

    public function nodeMaintenance(string $name): NodeCacheMaintenance
    {
        $store = $this->stores()[$name] ?? null;
        if ($store === null || $this->driver($name, $store) !== CacheDriver::NODE) {
            throw new ConfigurationException(sprintf('Cache node store "%s" is not configured.', $name));
        }

        return NodeCache::maintenance($this->nodeConfig($name, $store));
    }

    public function pruneCluster(string $name, int $retentionSeconds, int $limit = 5_000): int
    {
        if ($retentionSeconds < 0) {
            throw new ConfigurationException('Cluster event retention cannot be negative.');
        }

        $cluster = $this->named('cache.clusters')[$name] ?? null;
        if ($cluster === null) {
            throw new ConfigurationException(sprintf('Cache cluster "%s" is not configured.', $name));
        }

        $transport = $this->transport($this->requiredString($cluster, 'transport', 'cache.clusters.' . $name));
        if (!$transport instanceof PdoInvalidationTransport) {
            throw new ConfigurationException('Only PDO cluster transports support retention pruning.');
        }

        return $transport->pruneBefore(time() - $retentionSeconds, max(1, $limit));
    }

    /** @param array<string, mixed> $store */
    private function applyLock(CacheInterface $cache, array $store, CacheDriver $driver): CacheInterface
    {
        $global = ValueNormalizer::associativeArray($this->config->get('cache.lock', []));
        $local = ValueNormalizer::associativeArray($store['lock'] ?? []);
        $lock = array_replace($global, $local);

        if ($this->stringOrNull($lock['driver'] ?? null) === null) {
            return $cache;
        }

        [$lockStore, $lockDriver] = $this->resolveLockStore($store, $driver, $global, $local);

        return $cache->setLockProvider($this->lockProvider($lockStore, $lock, $lockDriver));
    }

    /**  */
    private function assertNodePath(string $file, string $name): void
    {
        $directory = rtrim(str_replace('\\', '/', $this->paths->cache()), '/');
        $candidate = str_replace('\\', '/', $file);
        if ($candidate !== $directory && !str_starts_with($candidate, $directory . '/')) {
            throw new ConfigurationException(sprintf(
                'Node cache store "%s" must keep its SQLite file inside the configured cache directory.',
                $name,
            ));
        }
    }

    private function basePath(): string
    {
        return $this->stringConfig('app.base_path', getcwd() ?: '.');
    }

    /** @param array<string, mixed> $store */
    private function cacheOptions(array $store): CacheOptions
    {
        $compression = array_replace(
            ValueNormalizer::associativeArray($this->config->get('cache.compression', [])),
            ValueNormalizer::associativeArray($store['compression'] ?? []),
        );
        $security = array_replace(
            ValueNormalizer::associativeArray($this->config->get('cache.security', [])),
            ValueNormalizer::associativeArray($store['security'] ?? []),
        );
        $serialization = array_replace(
            ValueNormalizer::associativeArray($this->config->get('cache.serialization', [])),
            ValueNormalizer::associativeArray($store['serialization'] ?? []),
        );

        $threshold = $compression['threshold_bytes'] ?? $compression['threshold'] ?? null;
        $maxPayload = $security['max_payload_bytes'] ?? null;

        return new CacheOptions(
            integrityKey: $this->stringOrNull($security['integrity_key'] ?? null),
            maxPayloadBytes: is_numeric($maxPayload) ? max(1, (int) $maxPayload) : 8_388_608,
            compressionThreshold: is_numeric($threshold) && (int) $threshold > 0 ? (int) $threshold : null,
            compressionLevel: max(1, min(9, ValueNormalizer::int($compression['level'] ?? null, 6))),
            allowClosures: ValueNormalizer::bool($serialization['allow_closure_payloads'] ?? null, true),
            allowObjects: ValueNormalizer::bool($serialization['allow_object_payloads'] ?? null, true),
            failOpen: ValueNormalizer::bool($store['fail_open'] ?? null, true),
        );
    }

    /** @param array<string, mixed> $store */
    private function createCache(string $name, array $store, CacheDriver $driver): CacheInterface
    {
        $namespace = $this->namespace($name, $store);
        $options = $this->cacheOptions($store);

        return match ($driver) {
            CacheDriver::APCU => Cache::apcu($namespace, $options),
            CacheDriver::FILE => Cache::file($namespace, $this->directory($store), $options),
            CacheDriver::LOCAL => $this->localCache($namespace, $store, $options),
            CacheDriver::MEMCACHE => Cache::memcached(
                namespace: $namespace,
                servers: $this->servers($store['servers'] ?? null),
                client: ($store['client'] ?? null) instanceof \Memcached ? $store['client'] : null,
                options: $options,
            ),
            CacheDriver::MEMORY => Cache::memory($namespace, $options),
            CacheDriver::MONGODB => Cache::mongodb(
                namespace: $namespace,
                collection: is_object($store['collection'] ?? null) ? $store['collection'] : null,
                client: is_object($store['client'] ?? null) ? $store['client'] : null,
                database: ValueNormalizer::string($store['database'] ?? null, 'cachelayer'),
                collectionName: ValueNormalizer::string($store['collection_name'] ?? null, 'entries'),
                uri: ValueNormalizer::string($store['uri'] ?? null, 'mongodb://127.0.0.1:27017'),
                options: $options,
            ),
            CacheDriver::NODE => NodeCache::create($this->nodeConfig($name, $store)),
            CacheDriver::NULL_STORE => Cache::nullStore($options),
            CacheDriver::PDO => $this->pdoCache($namespace, $store, $options),
            CacheDriver::PHP_FILES => Cache::phpFiles($namespace, $this->directory($store), $options),
            CacheDriver::REDIS => $this->redisCache($namespace, $store, 'redis', $options),
            CacheDriver::REDIS_CLUSTER => Cache::redisCluster(
                namespace: $namespace,
                seeds: $this->seeds($store['seeds'] ?? null),
                timeout: $this->floatValue($store['timeout'] ?? null, 1.0),
                readTimeout: $this->floatValue($store['read_timeout'] ?? null, 1.0),
                persistent: ValueNormalizer::bool($store['persistent'] ?? null, false),
                client: is_object($store['client'] ?? null) ? $store['client'] : null,
                options: $options,
            ),
            CacheDriver::SCYLLADB => Cache::scylla(
                namespace: $namespace,
                session: is_object($store['session'] ?? $store['client'] ?? null)
                    ? ($store['session'] ?? $store['client'])
                    : null,
                keyspace: ValueNormalizer::string($store['keyspace'] ?? null, 'cachelayer'),
                table: ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
                bucketCount: ValueNormalizer::int($store['bucket_count'] ?? null, 128),
                options: $options,
            ),
            CacheDriver::SHARED_MEMORY => Cache::sharedMemory(
                $namespace,
                ValueNormalizer::int($store['segment_size'] ?? null, 16_777_216),
                $options,
            ),
            CacheDriver::SQLITE => Cache::sqlite($namespace, $this->sqliteFile($store), $options),
            CacheDriver::TIERED => Cache::tiered(
                tiers: $this->tierDescriptors($name, $store),
                writeToL1: ValueNormalizer::bool($store['write_to_l1'] ?? null, true),
                options: $options,
                namespace: $namespace,
            ),
            CacheDriver::VALKEY => $this->redisCache($namespace, $store, 'valkey', $options),
            CacheDriver::WEAK_MAP => Cache::weakMap($namespace, $options),
        };
    }

    /** @param array<string, mixed> $config */
    private function directory(array $config): ?string
    {
        return $this->directoryFrom($config, 'path', 'dir', 'directory');
    }

    /** @param array<string, mixed> $config */
    private function directoryFrom(array $config, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $path = $this->stringOrNull($config[$key] ?? null);
            if ($path !== null) {
                return $this->resolvePath($path);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $store */
    private function driver(string $name, array $store): CacheDriver
    {
        $raw = isset($store['driver']) && is_string($store['driver']) ? $store['driver'] : $name;
        $normalized = match (strtolower($raw)) {
            'array' => 'memory',
            'memcached' => 'memcache',
            'null' => 'null_store',
            'scylla' => 'scylladb',
            default => strtolower($raw),
        };

        return CacheDriver::tryFrom($normalized)
            ?? throw new ConfigurationException(sprintf('Invalid cache store "%s" driver "%s".', $name, $raw));
    }

    private function floatValue(mixed $value, float $default): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $default;
    }

    /** @param array<string, mixed> $store */
    private function localCache(string $namespace, array $store, CacheOptions $options): CacheInterface
    {
        if (extension_loaded('apcu') && function_exists('apcu_enabled') && apcu_enabled()) {
            return Cache::apcu($namespace, $options);
        }

        return Cache::file($namespace, $this->directory($store), $options);
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $lock
     */
    private function lockProvider(array $store, array $lock, CacheDriver $storeDriver): LockProviderInterface
    {
        $driver = strtolower($this->requiredString($lock, 'driver', 'cache.lock'));
        $prefix = ValueNormalizer::string($lock['prefix'] ?? null, 'cachelayer:lock:');
        $retry = ValueNormalizer::int($lock['retry_sleep_micros'] ?? null, 50_000);

        return match ($driver) {
            'file' => new FileLockProvider($this->directory($lock), $retry),
            'memcache', 'memcached' => new MemcachedLockProvider(
                $this->memcachedClient($store, $lock),
                $prefix,
                $retry,
            ),
            'pdo' => PdoLockProvider::strict(
                $this->pdoClient(array_replace($store, $lock), $storeDriver),
                $prefix,
                $retry,
            ),
            'redis', 'valkey' => new RedisLockProvider(
                $this->redisClient(array_replace($store, $lock), $driver),
                $prefix,
                $retry,
            ),
            default => throw new ConfigurationException(sprintf('Unsupported cache lock driver "%s".', $driver)),
        };
    }

    /**
     * @param array<string, mixed> $store
     * @param array<string, mixed> $lock
     */
    private function memcachedClient(array $store, array $lock): \Memcached
    {
        $client = $lock['client'] ?? $store['client'] ?? null;
        if ($client instanceof \Memcached) {
            return $client;
        }
        if (!class_exists(\Memcached::class)) {
            throw new ConfigurationException('Memcached lock provider requires the Memcached extension.');
        }

        $client = new \Memcached();
        if (!$client->addServers($this->servers($lock['servers'] ?? $store['servers'] ?? null))) {
            throw new ConfigurationException('Memcached lock provider could not configure its servers.');
        }

        return $client;
    }

    /** @return array<string, array<string, mixed>> */
    private function named(string $key): array
    {
        $configured = $this->config->get($key, []);
        if (!is_array($configured)) {
            return [];
        }

        $resolved = [];
        foreach ($configured as $name => $definition) {
            if (is_string($name) && is_array($definition)) {
                $resolved[$name] = ValueNormalizer::associativeArray($definition);
            }
        }

        return $resolved;
    }

    /** @param array<string, mixed> $store */
    private function namespace(string $name, array $store): string
    {
        return $this->stringOrNull($store['namespace'] ?? null)
            ?? CacheNamespace::derive($this->stringConfig('cache.prefix', 'foundation:'), $name);
    }

    /** @param array<string, mixed> $store */
    private function nodeConfig(string $name, array $store): NodeCacheConfig
    {
        $file = $this->sqliteFile($store);
        if ($file === null) {
            throw new ConfigurationException(sprintf('Node cache store "%s" requires sqlite_file.', $name));
        }

        $this->assertNodePath($file, $name);

        return new NodeCacheConfig(
            sqliteFile: $file,
            namespace: $this->namespace($name, $store),
            lockDirectory: $this->directoryFrom($store, 'lock_directory'),
            busyTimeoutMs: max(0, ValueNormalizer::int($store['busy_timeout_ms'] ?? null, 1_000)),
            apcuEnabled: ValueNormalizer::bool($store['apcu_enabled'] ?? null, true),
            failOpen: ValueNormalizer::bool($store['fail_open'] ?? null, true),
            lockProvider: $this->nodeLockProvider($store),
        );
    }

    /** @param array<string, mixed> $store */
    private function nodeLockProvider(array $store): ?LockProviderInterface
    {
        $global = ValueNormalizer::associativeArray($this->config->get('cache.lock', []));
        $local = ValueNormalizer::associativeArray($store['lock'] ?? []);
        $lock = array_replace($global, $local);
        if ($this->stringOrNull($lock['driver'] ?? null) === null) {
            return null;
        }

        [$lockStore, $driver] = $this->resolveLockStore($store, CacheDriver::NODE, $global, $local);

        return $this->lockProvider($lockStore, $lock, $driver);
    }

    /** @param array<string, mixed> $store */
    private function pdoCache(string $namespace, array $store, CacheOptions $options): CacheInterface
    {
        $runtime = $this->pdoRuntime($store);

        return Cache::pdo(
            namespace: $namespace,
            dsn: $runtime['dsn'],
            username: $runtime['username'],
            password: $runtime['password'],
            pdo: $runtime['client'],
            table: ValueNormalizer::string($store['table'] ?? null, 'cachelayer_entries'),
            options: $options,
        );
    }

    /** @param array<string, mixed> $store */
    private function pdoClient(array $store, CacheDriver $driver): \PDO
    {
        if (($store['client'] ?? null) instanceof \PDO) {
            return $store['client'];
        }

        if (!in_array($driver, [CacheDriver::NODE, CacheDriver::PDO, CacheDriver::SQLITE], true)) {
            throw new ConfigurationException('PDO lock provider requires a PDO-backed cache store or PDO client.');
        }

        $runtime = $this->pdoRuntime($store);
        if ($runtime['client'] instanceof \PDO) {
            return $runtime['client'];
        }

        return new \PDO(
            $runtime['dsn'] ?? throw new ConfigurationException('PDO cache store could not resolve a DSN.'),
            $runtime['username'],
            $runtime['password'],
        );
    }

    /**
     * @param array<string, mixed> $store
     * @return array{dsn:?string,username:?string,password:?string,client:?\PDO}
     */
    private function pdoRuntime(array $store): array
    {
        if (($store['client'] ?? null) instanceof \PDO) {
            return ['dsn' => null, 'username' => null, 'password' => null, 'client' => $store['client']];
        }

        $connection = $this->stringOrNull($store['connection'] ?? null);
        if ($connection !== null) {
            return [
                'dsn' => null,
                'username' => null,
                'password' => null,
                'client' => ($this->database)($connection)->getPdo(),
            ];
        }

        return [
            'dsn' => $this->stringOrNull($store['dsn'] ?? null),
            'username' => $this->stringOrNull($store['username'] ?? null),
            'password' => $this->stringOrNull($store['password'] ?? null),
            'client' => null,
        ];
    }

    /** @param array<string, mixed> $store */
    private function redisCache(string $namespace, array $store, string $driver, CacheOptions $options): CacheInterface
    {
        $connection = $this->redisConnection($store, $driver);

        return $driver === 'valkey'
            ? Cache::valkey($namespace, $connection['dsn'], $connection['client'], $options)
            : Cache::redis($namespace, $connection['dsn'], $connection['client'], $options);
    }

    /** @param array<string, mixed> $definition */
    private function redisClient(array $definition, string $driver): \Redis
    {
        $connection = $this->redisConnection($definition, $driver);
        if ($connection['client'] instanceof \Redis) {
            return $connection['client'];
        }
        if (!class_exists(\Redis::class)) {
            throw new ConfigurationException(sprintf('%s requires the phpredis extension.', ucfirst($driver)));
        }

        return RedisConnection::connect($connection['dsn']);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{client:?\Redis,dsn:string}
     */
    private function redisConnection(array $definition, string $driver): array
    {
        $name = $this->stringOrNull($definition['connection'] ?? null);
        $configured = $name === null
            ? []
            : ValueNormalizer::associativeArray($this->config->get('cache.connections.' . $name, []));
        $resolved = array_replace($configured, $definition);
        $client = $resolved['client'] ?? null;
        $resolvedDriver = strtolower(ValueNormalizer::string($resolved['driver'] ?? null, $driver));

        return [
            'client' => $client instanceof \Redis ? $client : null,
            'dsn' => ValueNormalizer::string(
                $resolved['dsn'] ?? null,
                $resolvedDriver === 'valkey' ? 'valkey://127.0.0.1:6379' : 'redis://127.0.0.1:6379',
            ),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function requiredString(array $definition, string $key, string $context): string
    {
        return $this->stringOrNull($definition[$key] ?? null)
            ?? throw new ConfigurationException(sprintf('%s.%s must be configured.', $context, $key));
    }

    /**
     * @param array<string, mixed> $fallbackStore
     * @param array<string, mixed> $global
     * @param array<string, mixed> $local
     * @return array{array<string, mixed>, CacheDriver}
     */
    private function resolveLockStore(
        array $fallbackStore,
        CacheDriver $fallbackDriver,
        array $global,
        array $local,
    ): array {
        $name = $this->stringOrNull($local['store'] ?? null);
        if ($name === null && $local === []) {
            $name = $this->stringOrNull($global['store'] ?? null);
        }
        if ($name === null) {
            return [$fallbackStore, $fallbackDriver];
        }

        $store = $this->stores()[$name] ?? null;
        if ($store === null) {
            throw new ConfigurationException(sprintf('Cache lock references undefined store "%s".', $name));
        }

        return [$store, $this->driver($name, $store)];
    }

    private function resolvePath(string $path): string
    {
        if ($path === '' || preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $this->basePath() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /** @return list<string> */
    private function seeds(mixed $value): array
    {
        return is_array($value) && $value !== []
            ? ValueNormalizer::stringList($value)
            : ['127.0.0.1:6379'];
    }

    /** @return list<array{0:string,1:int,2:int}> */
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

            $server = ValueNormalizer::associativeArray($server);
            $servers[] = [
                ValueNormalizer::string($server['host'] ?? null, '127.0.0.1'),
                ValueNormalizer::int($server['port'] ?? null, 11211),
                ValueNormalizer::int($server['weight'] ?? null, 0),
            ];
        }

        return $servers;
    }

    /** @param array<string, mixed> $store */
    private function sqliteFile(array $store): ?string
    {
        $path = $this->stringOrNull(
            $store['sqlite_file'] ?? $store['file'] ?? $store['path'] ?? $store['database'] ?? null,
        );

        return $path === null ? null : $this->resolvePath($path);
    }

    /** @return array<string, array<string, mixed>> */
    private function stores(): array
    {
        return $this->named('cache.stores');
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $store */
    private function tierDescriptors(string $name, array $store): array
    {
        $tiers = $store['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === []) {
            throw new ConfigurationException(sprintf(
                'Tiered cache store "%s" must define CacheLayer-native tier descriptors.',
                $name,
            ));
        }

        $resolved = [];
        foreach ($tiers as $index => $tier) {
            if (!is_array($tier)) {
                throw new ConfigurationException(sprintf(
                    'Tiered cache store "%s" tier %s must be a CacheLayer descriptor array.',
                    $name,
                    (string) $index,
                ));
            }

            $descriptor = ValueNormalizer::associativeArray($tier);
            $driver = strtolower(ValueNormalizer::string($descriptor['driver'] ?? null));
            if ($driver === '') {
                throw new ConfigurationException(sprintf(
                    'Tiered cache store "%s" tier %s requires driver.',
                    $name,
                    (string) $index,
                ));
            }

            $descriptor['namespace'] ??= $this->namespace($name . '.tier.' . $index, []);

            if (in_array($driver, ['file', 'php_files'], true)) {
                foreach (['dir', 'base_dir'] as $key) {
                    if (is_string($descriptor[$key] ?? null)) {
                        $descriptor[$key] = $this->resolvePath($descriptor[$key]);
                    }
                }
            } elseif ($driver === 'sqlite' && is_string($descriptor['file'] ?? null)) {
                $descriptor['file'] = $this->resolvePath($descriptor['file']);
            } elseif (in_array($driver, ['redis', 'valkey'], true)) {
                $connection = $this->redisConnection($descriptor, $driver);
                $descriptor['dsn'] = $connection['dsn'];
                if ($connection['client'] instanceof \Redis) {
                    $descriptor['client'] = $connection['client'];
                }
                unset($descriptor['connection']);
            } elseif ($driver === 'pdo' && $this->stringOrNull($descriptor['connection'] ?? null) !== null) {
                $descriptor['client'] = ($this->database)($descriptor['connection'])->getPdo();
                unset($descriptor['connection']);
            }

            $resolved[] = $descriptor;
        }

        return $resolved;
    }

    private function transport(string $name): InvalidationTransportInterface
    {
        $transport = $this->named('cache.transports')[$name] ?? null;
        if ($transport === null) {
            throw new ConfigurationException(sprintf('Cache transport "%s" is not configured.', $name));
        }

        $driver = strtolower($this->requiredString($transport, 'driver', 'cache.transports.' . $name));

        return match ($driver) {
            'pdo' => new PdoInvalidationTransport(
                ($this->database)($this->requiredString($transport, 'connection', 'cache.transports.' . $name))->getPdo(),
                ValueNormalizer::bool($transport['allow_sqlite_for_testing'] ?? null, false),
            ),
            'redis_stream', 'redis-stream', 'stream', 'valkey_stream', 'valkey-stream' => new RedisStreamInvalidationTransport(
                $this->redisClient($transport, str_contains($driver, 'valkey') ? 'valkey' : 'redis'),
                ValueNormalizer::string($transport['prefix'] ?? null, 'cachelayer:invalidation:'),
                max(1, ValueNormalizer::int($transport['max_length'] ?? null, 100_000)),
            ),
            default => throw new ConfigurationException(sprintf(
                'Unsupported cache transport "%s" driver "%s".',
                $name,
                $driver,
            )),
        };
    }
}
