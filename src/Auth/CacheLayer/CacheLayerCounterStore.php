<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\CacheLayer;

use Infocyph\AuthLayer\Contract\Cache\CounterStoreInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;

final readonly class CacheLayerCounterStore implements CounterStoreInterface
{
    /**
     * Note: this adapter preserves TTL semantics but is not guaranteed atomic
     * unless the underlying CacheLayer store itself provides atomic mutation.
     */
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'foundation:auth:counter:',
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
        return $this->prefix . $key;
    }
}
