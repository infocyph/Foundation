<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

use Infocyph\Foundation\Application\Application;
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
        private readonly Application $application,
        private readonly SessionConfig $config,
    ) {}

    public function make(): SessionStoreInterface
    {
        return match ($this->config->driver) {
            'array' => $this->arrayStore ??= new ArraySessionStore(),
            'file' => new FileSessionStore($this->config->filePath),
            'cache' => new CacheSessionStore(
                $this->application->make(CacheManager::class)->store($this->config->cacheStore),
            ),
            'database' => new DatabaseSessionStore(
                $this->application->make(DBLayerFactory::class)->connection($this->config->databaseConnection),
                $this->config->databaseTable,
            ),
            default => throw new \LogicException(sprintf(
                'Unsupported browser session driver "%s".',
                $this->config->driver,
            )),
        };
    }
}
