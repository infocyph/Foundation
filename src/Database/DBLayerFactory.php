<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

final class DBLayerFactory
{
    /** @var array<string, ConnectionConfig> */
    private array $configurations = [];

    /**
     * Generation/process-owned connections used only by infrastructure objects
     * that intentionally retain a raw PDO beyond one execution (for example a
     * PDO-backed CacheLayer store or invalidation transport).
     *
     * @var array<string, Connection>
     */
    private array $infrastructureConnections = [];

    /** @var array<string, true> */
    private array $pooledConfigurations = [];

    private ?PoolManager $poolManager = null;

    private ?CacheInterface $queryCache = null;

    private bool $queryCacheResolved = false;

    private bool $resolvingQueryCache = false;

    public function __construct(
        private readonly DatabaseConnectionResolver $resolver,
        private readonly ContainerInterface $container,
    ) {}

    public function __destruct()
    {
        try {
            $this->poolManager?->getPool()->closeAll();
        } catch (\Throwable) {
            // Destructors must not surface shutdown failures.
        }

        foreach ($this->infrastructureConnections as $connection) {
            try {
                $connection->disconnect();
            } catch (\Throwable) {
                // Destructors must not surface shutdown failures.
            }
        }
    }

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = $this->resolver->connectionName($name);
        $config = $this->configuration($name);
        $state = $this->executionState();

        if ($fresh) {
            return $this->bindQueryCache($state->freshConnection($name, $config));
        }

        $connection = $this->resolver->poolEnabled()
            ? $this->pooledConnection($state, $name, $config)
            : $state->connection($name, $config);

        return $this->bindQueryCache($connection);
    }

    /**
     * Resolve a generation/process-owned DBLayer connection for infrastructure
     * that retains its PDO independently of an execution scope.
     *
     * Application repositories and request/job work must use connection().
     */
    public function infrastructureConnection(?string $name = null): Connection
    {
        $name = $this->resolver->connectionName($name);

        return $this->infrastructureConnections[$name]
            ??= new Connection($this->configuration($name), $name);
    }

    public function resolver(): DatabaseConnectionResolver
    {
        return $this->resolver;
    }

    private function bindQueryCache(Connection $connection): Connection
    {
        if (!$this->resolver->queryCacheEnabled() || $this->resolvingQueryCache) {
            return $connection;
        }

        $connection->setQueryCache($this->queryCache());

        return $connection;
    }

    private function configuration(string $name): ConnectionConfig
    {
        return $this->configurations[$name]
            ??= ConnectionConfig::fromArray($this->resolver->configuration($name));
    }

    private function executionState(): RuntimeExecutionState
    {
        $state = $this->container->get(RuntimeExecutionState::class);
        if (!$state instanceof RuntimeExecutionState) {
            throw new \LogicException('RuntimeExecutionState binding is invalid.');
        }

        return $state;
    }

    private function pooledConnection(
        RuntimeExecutionState $state,
        string $name,
        ConnectionConfig $config,
    ): Connection {
        $pool = $this->poolManager();
        if (!isset($this->pooledConfigurations[$name])) {
            $pool->getPool()->addConfig($name, $config);
            $this->pooledConfigurations[$name] = true;
        }

        return $state->leasedConnection($name, $pool);
    }

    private function poolManager(): PoolManager
    {
        return $this->poolManager ??= new PoolManager(new Pool($this->resolver->poolOptions()));
    }

    private function queryCache(): CacheInterface
    {
        if ($this->queryCacheResolved && $this->queryCache instanceof CacheInterface) {
            return $this->queryCache;
        }

        if (!$this->container->has(CacheLayerFactory::class)) {
            throw new ConfigurationException(
                'Database query caching requires the Foundation cache capability and an explicit database.query_cache.store.',
            );
        }

        $this->resolvingQueryCache = true;

        try {
            $factory = $this->container->get(CacheLayerFactory::class);
            if (!$factory instanceof CacheLayerFactory) {
                throw new ConfigurationException('CacheLayerFactory binding is invalid.');
            }

            $this->queryCache = $factory->make($this->resolver->queryCacheStore());
            $this->queryCacheResolved = true;

            return $this->queryCache;
        } finally {
            $this->resolvingQueryCache = false;
        }
    }
}
