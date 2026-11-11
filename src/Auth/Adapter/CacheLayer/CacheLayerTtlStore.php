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

    /**
     * @throws \LogicException
     */
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

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    #[\Override]
    public function delete(string $key): void
    {
        if (!$this->cache->delete($this->key($key))) {
            throw new \RuntimeException('Foundation auth TTL state could not be deleted.');
        }
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     *
     * @param null|string $default
     *
     * @psalm-param '__expired__'|null $default
     */
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    #[\Override]
    public function pull(string $key, ?string $default = null): mixed
    {
        return $this->atomic->getAndDelete($this->key($key), $default);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \RuntimeException
     * @throws \LogicException
     *
     * @param int|string|string[] $value
     *
     * @psalm-param 'payload'|'short-lived'|array{id: 'one'}|int<0, max> $value
     */
    #[\Override]
    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Foundation auth TTL must be at least one second.');
        }
        if (!$this->cache->set($this->key($key), $value, $ttlSeconds)) {
            throw new \RuntimeException('Foundation auth TTL state could not be persisted.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function key(string $key): string
    {
        return FoundationCacheKey::security($this->prefix, $this->domain, $key);
    }
}
