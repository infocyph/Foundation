<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Session\Store\ArraySessionStore;
use Infocyph\Foundation\Session\Store\CacheSessionStore;
use Infocyph\Foundation\Session\Store\DatabaseSessionStore;
use Infocyph\Foundation\Session\Store\FileSessionStore;

final class SessionStoreFactory
{
    private ?ArraySessionStore $arrayStore = null;

    public function __construct(
        private readonly SessionConfig $config,
        private readonly ?CacheManager $cache = null,
        private readonly ?DBLayerFactory $database = null,
    ) {}

    public function make(): SessionStoreInterface
    {
        return match ($this->config->driver) {
            'array' => $this->arrayStore ??= new ArraySessionStore(),
            'file' => new FileSessionStore($this->config->filePath),
            'cache' => new CacheSessionStore(
                $this->cache?->store($this->config->cacheStore)
                    ?? throw new \LogicException('Cache-backed sessions require the Foundation cache capability.'),
            ),
            'database' => new DatabaseSessionStore(
                $this->database?->connection($this->config->databaseConnection)
                    ?? throw new \LogicException('Database-backed sessions require the Foundation database capability.'),
                $this->config->databaseTable,
            ),
            default => throw new \LogicException(sprintf(
                'Unsupported browser session driver "%s".',
                $this->config->driver,
            )),
        };
    }
}
