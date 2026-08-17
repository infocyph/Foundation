<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\AtomicCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerCounterStore;
use Infocyph\Foundation\Auth\Adapter\CacheLayer\CacheLayerTtlStore;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Driver\AuthCacheDriver;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Auth\Support\InMemoryCounterStore;
use Infocyph\Foundation\Cache\CacheManager;

final readonly class AuthCacheRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->cache() === AuthCacheDriver::CACHE) {
            $this->requirePackage(Cache::class, 'infocyph/cachelayer', 'cache');
            $this->singleton(CacheInterface::class, fn() => $this->app->make(CacheManager::class)->store());
            $counter = $this->stringConfig('cache.default_counter', '');
            $this->singleton(CounterStoreInterface::class, $counter === ''
                ? fn() => new CacheLayerCounterStore($this->app->make(CacheInterface::class))
                : fn() => new AtomicCounterStore($this->app->make(CacheManager::class)->counters($counter)));
            $this->singleton(TtlStoreInterface::class, fn() => new CacheLayerTtlStore(
                $this->app->make(CacheInterface::class),
            ));

            return;
        }

        $this->singleton(CounterStoreInterface::class, fn() => new InMemoryCounterStore(
            $this->app->make(ClockInterface::class),
        ));
        $this->singleton(TtlStoreInterface::class, fn() => new ArrayTtlStore(
            $this->app->make(ClockInterface::class),
        ));
    }
}
