<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\CacheLayer;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Cache\FoundationCacheKey;

final readonly class CacheLayerCounterStore implements CounterStoreInterface
{
    /**
     * This adapter preserves TTL semantics but is not guaranteed atomic unless
     * the underlying CacheLayer store itself provides atomic mutation. Production
     * authentication lockouts use AtomicCounterStore instead.
     */
    public function __construct(
        private CacheInterface $cache,
        private string $domain = 'foundation.auth.counter.v1',
        private string $prefix = 'ac',
    ) {}

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $cacheKey = $this->key($key);
        $current = $this->cache->get($cacheKey, 0);
        $value = (is_int($current) ? $current : 0) + $by;

        $this->cache->set($cacheKey, $value, $ttlSeconds);

        return $value;
    }

    public function reset(string $key): void
    {
        $this->cache->delete($this->key($key));
    }

    private function key(string $key): string
    {
        return FoundationCacheKey::security($this->prefix, $this->domain, $key);
    }
}
