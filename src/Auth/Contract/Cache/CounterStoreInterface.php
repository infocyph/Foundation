<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Cache;

interface CounterStoreInterface
{
    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int;

    public function reset(string $key): void;
}
