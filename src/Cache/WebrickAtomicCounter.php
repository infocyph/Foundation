<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Counter\AtomicCounterStoreInterface;
use Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface;

final readonly class WebrickAtomicCounter implements AtomicCounterInterface
{
    public function __construct(private AtomicCounterStoreInterface $store) {}

    public function increment(string $key, int $delta, int $ttlSeconds): int
    {
        return $this->store->increment($key, $delta, $ttlSeconds)->value;
    }
}
