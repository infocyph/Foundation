<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\CacheLayer;

use Infocyph\AuthLayer\Contract\Cache\TtlStoreInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;

final readonly class CacheLayerTtlStore implements TtlStoreInterface
{
    public function __construct(
        private CacheInterface $cache,
        private string $prefix = 'foundation:auth:ttl:',
    ) {}

    public function delete(string $key): void
    {
        $this->cache->delete($this->key($key));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->key($key);
        $value = $this->cache->get($cacheKey, $default);
        $this->cache->delete($cacheKey);

        return $value;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->set($this->key($key), $value, $ttlSeconds);
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }
}
