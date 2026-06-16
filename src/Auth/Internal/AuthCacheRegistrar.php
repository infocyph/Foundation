<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Auth\Support\InMemoryCounterStore;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthCacheRegistrar
{
    public function __construct(
        private Application $app,
        private Container $container,
    ) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->cache() === AuthCacheDriver::CACHELAYER) {
            $this->container->bind(CacheInterface::class, fn() => $this->app->cache()->store(
                (string) $this->app->config()->get('auth.cachelayer.store', (string) $this->app->config()->get('cache.default', 'memory')),
            ), LifetimeEnum::Singleton);
            $this->container->bind(CounterStoreInterface::class, fn() => new CacheLayerCounterStore(
                $this->container->get(CacheInterface::class),
            ), LifetimeEnum::Singleton);
            $this->container->bind(TtlStoreInterface::class, fn() => new CacheLayerTtlStore(
                $this->container->get(CacheInterface::class),
            ), LifetimeEnum::Singleton);

            return;
        }

        $this->container->bind(CounterStoreInterface::class, fn() => new InMemoryCounterStore(
            $this->container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $this->container->bind(TtlStoreInterface::class, fn() => new ArrayTtlStore(
            $this->container->get(ClockInterface::class),
        ), LifetimeEnum::Singleton);
    }
}
