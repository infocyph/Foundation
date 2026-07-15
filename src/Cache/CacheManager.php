<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cluster\ClusterRuntime;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatus;
use Infocyph\CacheLayer\Cluster\Outbox\ClusterOutbox;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Support\HasConfigSection;

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
        private DatabaseManager $database,
    ) {}

    public function checkpointNode(string $name): void
    {
        $this->factory->nodeMaintenance($name)->checkpoint();
    }

    public function cluster(string $name): ClusterRuntime
    {
        return $this->clusters[$name] ??= $this->factory->cluster($name);
    }

    public function clusterStatus(string $name): ClusterStatus
    {
        return $this->cluster($name)->status();
    }

    public function consumeCluster(string $name, ?int $limit = null): int
    {
        return $this->cluster($name)->consume($limit);
    }

    public function counters(string $name): AtomicCounterStoreInterface
    {
        return $this->counters[$name] ??= $this->factory->counters($name);
    }

    public function drainCluster(string $name, ?int $limit = null, int $maxBatches = 100): int
    {
        return $this->cluster($name)->drain($limit, $maxBatches);
    }

    public function maintainNode(string $name, int $limit = 5_000): int
    {
        return $this->factory->maintainNode($name, $limit);
    }

    public function memoizer(): Memoizer
    {
        return Memoizer::instance();
    }

    public function once(): OnceMemoizer
    {
        return OnceMemoizer::instance();
    }

    public function optimizeNode(string $name): void
    {
        $this->factory->optimizeNode($name);
    }

    public function pruneCluster(string $name, int $retentionSeconds, int $limit = 5_000): int
    {
        return $this->factory->pruneCluster($name, $retentionSeconds, $limit);
    }

    public function store(?string $name = null): CacheInterface
    {
        $key = $name ?? '__default__';

        return $this->stores[$key] ??= $this->factory->make($name);
    }

    /**
     * @param callable(Connection, ClusterOutbox):mixed $callback
     */
    public function transactionalInvalidation(
        string $cluster,
        callable $callback,
        ?string $connection = null,
        int $attempts = 1,
    ): mixed {
        $runtime = $this->cluster($cluster);

        return $this->database->transaction(
            function (Connection $databaseConnection) use ($callback, $runtime): mixed {
                $outbox = $runtime->outbox($databaseConnection->getPdo());
                $result = $callback($databaseConnection, $outbox);
                $databaseConnection->afterCommit($outbox->applyLocally(...));

                return $result;
            },
            $connection,
            $attempts,
        );
    }

    protected function configSection(): string
    {
        return 'cache';
    }
}
