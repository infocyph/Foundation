<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\CacheLayer;

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\AtomicCacheProviderInterface;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Cache\FoundationCacheKey;

final readonly class CacheLayerTtlStore implements TtlStoreInterface
{
    private AtomicCacheInterface $atomic;

    public function __construct(
        private CacheInterface $cache,
        private string $domain = 'foundation.auth.ttl.v1',
        private string $prefix = 'at',
    ) {
        if (!$cache instanceof AtomicCacheProviderInterface || ($atomic = $cache->atomic()) === null) {
            throw new \LogicException(
                'Foundation auth TTL state requires a CacheLayer store with atomic cache capability.',
            );
        }

        $this->atomic = $atomic;
    }

    public function delete(string $key): void
    {
        if (!$this->cache->delete($this->key($key))) {
            throw new \RuntimeException('Foundation auth TTL state could not be deleted.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->atomic->getAndDelete($this->key($key), $default);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Foundation auth TTL must be at least one second.');
        }
        if (!$this->cache->set($this->key($key), $value, $ttlSeconds)) {
            throw new \RuntimeException('Foundation auth TTL state could not be persisted.');
        }
    }

    private function key(string $key): string
    {
        return FoundationCacheKey::security($this->prefix, $this->domain, $key);
    }
}
