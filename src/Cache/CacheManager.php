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
 * CacheLayer APIs. Database query-cache selection is owned separately by
 * DBLayerFactory so resolving the application default store never mutates the
 * process-static DBLayer facade.
 */
final class CacheManager
{
    /** @var array<string, CacheInterface> */
    private array $stores = [];

    public function __construct(
        private readonly CacheLayerFactory $factory,
        /** @var Closure(?string):Connection */
        private readonly Closure $database,
        private readonly ?CacheLayerFactory $transactionalFactory = null,
    ) {}

    public function store(?string $name = null): CacheInterface
    {
        $key = $name ?? '__default__';
        if (isset($this->stores[$key])) {
            return $this->stores[$key];
        }

        return $this->stores[$key] = $this->factory->make($name);
    }

    /**
     * Couple a DB transaction with CacheLayer's cluster invalidation outbox.
     *
     * The ordinary factory is infrastructure-owned because its products may
     * retain native clients beyond one execution. Transactional invalidation is
     * different: CacheLayer's PDO outbox must bind to the exact PDO that owns
     * the application transaction, so this path uses the execution-bound
     * factory supplied by CacheGraphFactory.
     *
     * @param callable(Connection, ClusterOutbox):mixed $callback
     */
    public function transactionalInvalidation(
        string $cluster,
        callable $callback,
        ?string $connection = null,
        int $attempts = 1,
    ): mixed {
        $database = ($this->database)($connection);
        $runtime = ($this->transactionalFactory ?? $this->factory)->cluster($cluster);

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

    public function useStore(CacheInterface $store, ?string $name = null): CacheInterface
    {
        $this->stores[$name ?? '__default__'] = $store;

        return $store;
    }
}
