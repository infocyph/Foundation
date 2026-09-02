<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
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

final readonly class AuthCacheRegistrar extends AbstractAuthRegistrar
{
    public function register(AuthDriverResolver $drivers): void
    {
        if ($drivers->cache() === AuthCacheDriver::CACHE) {
            $this->requirePackage(Cache::class, 'infocyph/cachelayer', 'cache');
            $counter = $this->stringConfig('cache.default_counter', '');

            $this->recipe(
                CounterStoreInterface::class,
                $counter === '' ? CacheLayerCounterStore::class : AtomicCounterStore::class,
                [$this->ref($counter === '' ? CacheInterface::class : AtomicCounterStoreInterface::class)],
            );
            $this->recipe(TtlStoreInterface::class, CacheLayerTtlStore::class, [
                $this->ref(CacheInterface::class),
            ]);

            return;
        }

        $this->recipe(CounterStoreInterface::class, InMemoryCounterStore::class, [
            $this->ref(ClockInterface::class),
        ]);
        $this->recipe(TtlStoreInterface::class, ArrayTtlStore::class, [
            $this->ref(ClockInterface::class),
        ]);
    }
}
