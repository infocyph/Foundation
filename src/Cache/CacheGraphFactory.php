<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\PathManager;

final class CacheGraphFactory
{
    public static function counters(CacheLayerFactory $factory, string $name): AtomicCounterStoreInterface
    {
        return $factory->counters($name);
    }

    public static function layerFactory(
        ConfigRepository $config,
        PathManager $paths,
        DBLayerFactory $database,
    ): CacheLayerFactory {
        return new CacheLayerFactory(
            config: $config,
            paths: $paths,
            database: static fn(?string $name = null) => $database->connection($name),
        );
    }

    public static function layerFactoryWithoutDatabase(
        ConfigRepository $config,
        PathManager $paths,
    ): CacheLayerFactory {
        return new CacheLayerFactory(
            config: $config,
            paths: $paths,
            database: static function (?string $name = null): never {
                unset($name);

                throw new \LogicException(
                    'The selected cache topology requires the Foundation database capability.',
                );
            },
        );
    }

    public static function lock(CacheLayerFactory $factory): LockProviderInterface
    {
        return $factory->lock();
    }

    public static function manager(CacheLayerFactory $factory, DBLayerFactory $database): CacheManager
    {
        return new CacheManager(
            factory: $factory,
            database: static fn(?string $name = null) => $database->connection($name),
        );
    }

    public static function managerWithoutDatabase(CacheLayerFactory $factory): CacheManager
    {
        return new CacheManager(
            factory: $factory,
            database: static function (?string $name = null): never {
                unset($name);

                throw new \LogicException(
                    'Transactional cache invalidation requires the Foundation database capability.',
                );
            },
        );
    }

    public static function store(CacheManager $manager): CacheInterface
    {
        return $manager->store();
    }
}
