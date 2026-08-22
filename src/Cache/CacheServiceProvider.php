<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

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
        $database = fn(?string $name = null) => $app->make(DBLayerFactory::class)->connection($name);

        $this->bindFactory($container, CacheLayerFactory::class, fn() => new CacheLayerFactory(
            config: $app->config(),
            paths: $app->make(PathManager::class),
            database: $database,
        ), LifetimeEnum::Singleton);

        $this->bindFactory($container, CacheManager::class, fn() => new CacheManager(
            config: $app->config(),
            factory: $app->make(CacheLayerFactory::class),
            database: $database,
        ), LifetimeEnum::Singleton);

        $this->bindFactory(
            $container,
            CacheInterface::class,
            fn() => $app->make(CacheManager::class)->store(),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            SimpleCacheInterface::class,
            fn() => $app->make(CacheInterface::class),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            CacheItemPoolInterface::class,
            fn() => $app->make(CacheInterface::class),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            LockProviderInterface::class,
            fn() => $app->make(CacheManager::class)->lock(),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            Memoizer::class,
            Memoizer::instance(...),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            OnceMemoizer::class,
            OnceMemoizer::instance(...),
            LifetimeEnum::Singleton,
        );

        $counter = $app->config()->get('cache.default_counter');
        if (is_string($counter) && $counter !== '') {
            $this->bindFactory(
                $container,
                AtomicCounterStoreInterface::class,
                fn() => $app->make(CacheManager::class)->counters($counter),
                LifetimeEnum::Singleton,
            );
        }

        $this->bindFactory(
            $container,
            'foundation.cache',
            fn() => $app->make(CacheInterface::class),
            LifetimeEnum::Singleton,
        );
    }
}
