<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\Store\ArraySessionStore;
use Infocyph\Foundation\Session\Store\CacheSessionStore;
use Infocyph\Foundation\Session\Store\DatabaseSessionStore;
use Infocyph\Foundation\Session\Store\FileSessionStore;
use Psr\Container\ContainerInterface;

final class SessionStoreFactory
{
    private ?ArraySessionStore $arrayStore = null;

    public function __construct(
        private readonly SessionConfig $config,
        private readonly ContainerInterface $container,
    ) {}

    public function make(): SessionStoreInterface
    {
        return match ($this->config->driver) {
            'array' => $this->arrayStore ??= new ArraySessionStore(),
            'file' => new FileSessionStore($this->config->filePath),
            'cache' => $this->cacheStore(),
            'database' => $this->databaseStore(),
            default => throw new \LogicException(sprintf(
                'Unsupported browser session driver "%s".',
                $this->config->driver,
            )),
        };
    }

    private function cacheStore(): CacheSessionStore
    {
        $cache = $this->container->get(CacheManager::class);
        if (!$cache instanceof CacheManager) {
            throw new \LogicException('CacheManager binding is invalid.');
        }

        return new CacheSessionStore($cache->store($this->config->cacheStore));
    }

    private function databaseStore(): DatabaseSessionStore
    {
        $database = $this->container->get(DBLayerFactory::class);
        if (!$database instanceof DBLayerFactory) {
            throw new \LogicException('DBLayerFactory binding is invalid.');
        }

        return new DatabaseSessionStore(
            $database->connection($this->config->databaseConnection),
            $this->config->databaseTable,
        );
    }
}
