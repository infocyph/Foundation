<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Closure;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cluster\Outbox\ClusterOutbox;
use Infocyph\DBLayer\Connection\Connection;

/**
 * Foundation application topology for named CacheLayer stores.
 *
 * Generic cache, locking, counter, node and cluster operations remain native
 * CacheLayer APIs. This component keeps application store identity, wires the
 * default store into an already-active DBLayer runtime, and owns the DB/cache
 * invalidation workflow.
 */
final class CacheManager
{
    /** @var array<string, CacheInterface> */
    private array $stores = [];

    public function __construct(
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
        $runtime = $this->factory->cluster($cluster);
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

    private function wireDatabaseCache(CacheInterface $store): void
    {
        $db = 'Infocyph\\DBLayer\\DB';
        if (class_exists($db, false)) {
            $db::setCache($store);
        }
    }
}
