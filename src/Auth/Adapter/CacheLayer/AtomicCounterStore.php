<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\CacheLayer;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Cache\FoundationCacheKey;

final readonly class AtomicCounterStore implements CounterStoreInterface
{
    public function __construct(
        private AtomicCounterStoreInterface $counters,
        private string $domain = 'foundation.auth.counter.v1',
        private string $prefix = 'ac',
    ) {}

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        return $this->counters->increment($this->key($key), $by, $ttlSeconds)->value;
    }

    public function reset(string $key): void
    {
        $this->counters->delete($this->key($key));
    }

    private function key(string $key): string
    {
        return FoundationCacheKey::security($this->prefix, $this->domain, $key);
    }
}
