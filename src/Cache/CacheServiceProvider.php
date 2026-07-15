<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class CacheServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(CacheLayerFactory::class, fn() => new CacheLayerFactory(
            config: $app->config(),
            database: $app->make(DatabaseManager::class),
            paths: $app->make(PathManager::class),
            redis: new RedisConnectionFactory($app->config()),
        ), LifetimeEnum::Singleton);

        $container->bind(CacheManager::class, fn() => new CacheManager(
            config: $app->config(),
            factory: $app->make(CacheLayerFactory::class),
            database: $app->make(DatabaseManager::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.cache', fn() => $container->get(CacheManager::class), LifetimeEnum::Singleton);
    }
}
