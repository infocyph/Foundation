<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\DeploymentTopology;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class SessionTopologyGuard
{
    /** @var list<string> */
    private const array DISTRIBUTED_CACHE_DRIVERS = [
        'memcache',
        'mongodb',
        'pdo',
        'redis',
        'redis_cluster',
        'scylladb',
        'valkey',
    ];

    /** @var list<string> */
    private const array DISTRIBUTED_LOCK_DRIVERS = ['memcache', 'pdo', 'redis', 'valkey'];

    public function __construct(private ConfigRepository $config) {}

    public function assert(SessionConfig $session): void
    {
        if (!$this->config->isProduction()) {
            return;
        }
        if ($session->driver === 'array') {
            throw new ConfigurationException('Production browser sessions must not use the process-local array store.');
        }
        if (!$session->lockEnabled) {
            throw new ConfigurationException('Production browser sessions require session.lock.enabled=true.');
        }

        $topology = DeploymentTopology::resolve($this->config);
        if ($topology !== DeploymentTopology::DISTRIBUTED) {
            return;
        }

        match ($session->driver) {
            'file' => throw new ConfigurationException('Distributed browser sessions cannot use the node-local file store.'),
            'cache' => $this->assertDistributedCacheStore($session->cacheStore),
            'database' => $this->assertDistributedDatabase($session->databaseConnection),
            default => null,
        };

        $lockStore = $session->lockStore
            ?? $this->string($this->config->get('cache.lock.store'))
            ?? $this->string($this->config->get('cache.default'));
        $lockDriver = $this->normalizeDriver($this->config->get('cache.lock.driver'));
        if ($lockDriver === null) {
            if ($lockStore === null) {
                throw new ConfigurationException(
                    'Distributed browser sessions require a shared CacheLayer lock store.',
                );
            }
            $this->assertDistributedCacheStore($lockStore);
            $lockDriver = $this->nativeLockDriver($lockStore);
        } elseif ($lockStore !== null) {
            $this->assertDistributedCacheStore($lockStore);
        }

        if (!in_array($lockDriver, self::DISTRIBUTED_LOCK_DRIVERS, true)) {
            throw new ConfigurationException(
                'Distributed browser sessions require a Redis, Valkey, Memcached, or shared PDO coordination lock.',
            );
        }
    }

    private function assertDistributedCacheStore(?string $configured): void
    {
        $store = $configured;
        if ($store === null || $store === '') {
            $default = $this->config->get('cache.default');
            $store = is_string($default) && trim($default) !== '' ? trim($default) : null;
        }
        if ($store === null) {
            throw new ConfigurationException('Distributed session cache/lock state requires a configured shared CacheLayer store.');
        }

        $driver = $this->storeDriver($store);
        if (!in_array($driver, self::DISTRIBUTED_CACHE_DRIVERS, true)) {
            throw new ConfigurationException(sprintf(
                'Session cache store "%s" is not shared across distributed nodes.',
                $store,
            ));
        }
    }

    private function assertDistributedDatabase(?string $configured): void
    {
        $connection = $configured;
        if ($connection === null || $connection === '') {
            $default = $this->config->get('database.default');
            $connection = is_string($default) && trim($default) !== '' ? trim($default) : null;
        }
        if ($connection === null) {
            throw new ConfigurationException('Distributed database sessions require a configured database connection.');
        }

        $driver = $this->config->get('database.connections.' . $connection . '.driver');
        if (!is_string($driver) || in_array(strtolower($driver), ['sqlite', 'sqlite3'], true)) {
            throw new ConfigurationException(sprintf(
                'Session database connection "%s" must use a shared database in distributed topology.',
                $connection,
            ));
        }
    }

    private function nativeLockDriver(string $store): ?string
    {
        return match ($this->storeDriver($store)) {
            'memcache' => 'memcache',
            'pdo' => 'pdo',
            'redis' => 'redis',
            'valkey' => 'valkey',
            default => null,
        };
    }

    private function storeDriver(string $store): ?string
    {
        $definition = $this->config->get('cache.stores.' . $store);

        return is_array($definition)
            ? $this->normalizeDriver($definition['driver'] ?? $store)
            : null;
    }

    private function normalizeDriver(mixed $driver): ?string
    {
        if (!is_string($driver) || trim($driver) === '') {
            return null;
        }

        return match (strtolower(trim($driver))) {
            'array' => 'memory',
            'memcached' => 'memcache',
            'null' => 'null_store',
            'scylla' => 'scylladb',
            default => strtolower(trim($driver)),
        };
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
