<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;

final class NullCounterStore implements CounterStoreInterface
{
    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        return 0;
    }

    public function reset(string $key): void {}
}
