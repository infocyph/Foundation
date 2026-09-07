<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

final class DBLayerFactory
{
    /** @var array<string, ConnectionConfig> */
    private array $configurations = [];

    private ?CacheInterface $queryCache = null;

    private bool $queryCacheResolved = false;

    private bool $resolvingQueryCache = false;

    public function __construct(
        private readonly DatabaseConnectionResolver $resolver,
        private readonly ContainerInterface $container,
    ) {}

    public function connection(?string $name = null, bool $fresh = false): Connection
    {
        $name = $this->resolver->connectionName($name);
        $config = $this->configurations[$name]
            ??= ConnectionConfig::fromArray($this->resolver->configuration($name));
        $state = $this->executionState();
        $connection = $fresh
            ? $state->freshConnection($name, $config)
            : $state->connection($name, $config);

        return $this->bindQueryCache($connection);
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

    private function executionState(): RuntimeExecutionState
    {
        $state = $this->container->get(RuntimeExecutionState::class);
        if (!$state instanceof RuntimeExecutionState) {
            throw new \LogicException('RuntimeExecutionState binding is invalid.');
        }

        return $state;
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
