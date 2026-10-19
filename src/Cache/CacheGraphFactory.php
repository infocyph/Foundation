<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\PathManager;
use Psr\Container\ContainerInterface;

final class CacheGraphFactory
{
    public static function counters(CacheLayerFactory $factory, string $name): AtomicCounterStoreInterface
    {
        return $factory->counters($name);
    }

    public static function layerFactory(
        ConfigRepository $config,
        PathManager $paths,
        ContainerInterface $container,
    ): CacheLayerFactory {
        return new CacheLayerFactory(
            config: $config,
            paths: $paths,
            database: static fn(?string $name = null) => self::database($container)->infrastructureConnection($name),
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

    public static function lock(CacheManager $manager): LockProviderInterface
    {
        return $manager->lock();
    }

    public static function manager(
        CacheLayerFactory $factory,
        ContainerInterface $container,
        ConfigRepository $config,
        PathManager $paths,
    ): CacheManager {
        return new CacheManager(
            factory: $factory,
            database: static fn(?string $name = null) => self::database($container)->connection($name),
            transactionalFactory: new CacheLayerFactory(
                config: $config,
                paths: $paths,
                database: static fn(?string $name = null) => self::database($container)->connection($name),
            ),
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

    private static function database(ContainerInterface $container): DBLayerFactory
    {
        $database = $container->get(DBLayerFactory::class);
        if (!$database instanceof DBLayerFactory) {
            throw new \LogicException('DBLayerFactory binding is invalid.');
        }

        return $database;
    }
}
