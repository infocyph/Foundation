<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class CacheServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(Cache::class)) {
            throw new \LogicException(
                'Foundation cache services require infocyph/cachelayer; run "php infbyte module:install cache".',
            );
        }

        $container = $app->container();

        $this->bindFactory($container, CacheLayerFactory::class, fn() => new CacheLayerFactory(
            config: $app->config(),
            paths: $app->make(PathManager::class),
            redis: new RedisConnectionFactory($app->config()),
            database: fn(): DatabaseManager => $app->make(DatabaseManager::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, CacheManager::class, fn() => new CacheManager(
            config: $app->config(),
            factory: $app->make(CacheLayerFactory::class),
            database: fn(): DatabaseManager => $app->make(DatabaseManager::class),
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, 'foundation.cache', fn() => $container->get(CacheManager::class), LifetimeEnum::Singleton);
    }
}
