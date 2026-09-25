<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter;
use Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface;

/**
 * @deprecated Use Webrick's native CacheLayer AtomicCounterAdapter.
 */
final readonly class WebrickAtomicCounter implements AtomicCounterInterface
{
    private AtomicCounterAdapter $adapter;

    public function __construct(AtomicCounterStoreInterface $store)
    {
        $this->adapter = new AtomicCounterAdapter($store);
    }

    public function increment(string $key, int $delta, int $ttlSeconds): int
    {
        return $this->adapter->increment($key, $delta, $ttlSeconds);
    }
}
