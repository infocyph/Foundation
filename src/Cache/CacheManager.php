<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Closure;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cluster\ClusterRuntime;
use Infocyph\CacheLayer\Cluster\Outbox\ClusterOutbox;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\HasConfigSection;

/**
 * Foundation application topology for named CacheLayer resources.
 *
 * Cache operations themselves remain native CacheLayer APIs. This manager only
 * resolves named application resources and coordinates cross-package workflows.
 */
final class CacheManager
{
    use HasConfigSection;

    /** @var array<string, ClusterRuntime> */
    private array $clusters = [];

    /** @var array<string, AtomicCounterStoreInterface> */
    private array $counters = [];

    /** @var array<string, CacheInterface> */
    private array $stores = [];

    public function __construct(
        private ConfigRepository $config,
        private CacheLayerFactory $factory,
        /** @var Closure(?string):Connection */
        private Closure $database,
    ) {}

    public function store(?string $name = null): CacheInterface
    {
        $key = $name ?? '__default__';
        if (isset($this->stores[$key])) {
            return $this->stores[$key];
        }

        $store = $this->factory->make($name);
        $this->stores[$key] = $store;

        if ($name === null) {
            $this->wireDatabaseCache($store);
        }

        return $store;
    }

    public function useStore(CacheInterface $store, ?string $name = null): CacheInterface
    {
        $this->stores[$name ?? '__default__'] = $store;

        if ($name === null) {
            $this->wireDatabaseCache($store);
        }

        return $store;
    }

    public function lock(?string $store = null): LockProviderInterface
    {
        return $this->factory->lock($store);
    }

    public function counters(string $name): AtomicCounterStoreInterface
    {
        return $this->counters[$name] ??= $this->factory->counters($name);
    }

    public function cluster(string $name): ClusterRuntime
    {
        return $this->clusters[$name] ??= $this->factory->cluster($name);
    }

    public function nodeMaintenance(string $name): NodeCacheMaintenance
    {
        return $this->factory->nodeMaintenance($name);
    }

    public function pruneCluster(string $name, int $retentionSeconds, int $limit = 5_000): int
    {
        return $this->factory->pruneCluster($name, $retentionSeconds, $limit);
    }

    /**
     * Couple a DB transaction with CacheLayer's cluster invalidation outbox.
     *
     * @param callable(Connection, ClusterOutbox):mixed $callback
     */
    public function transactionalInvalidation(
        string $cluster,
        callable $callback,
        ?string $connection = null,
        int $attempts = 1,
    ): mixed {
        $runtime = $this->cluster($cluster);
        $database = ($this->database)($connection);

        return $database->transaction(
            function (Connection $connection) use ($callback, $runtime): mixed {
                $outbox = $runtime->outbox($connection->getPdo());
                $result = $callback($connection, $outbox);
                $connection->afterCommit($outbox->applyLocally(...));

                return $result;
            },
            $attempts,
        );
    }

    protected function configSection(): string
    {
        return 'cache';
    }

    private function wireDatabaseCache(CacheInterface $store): void
    {
        if (class_exists(DB::class)) {
            DB::setCache($store);
        }
    }
}
