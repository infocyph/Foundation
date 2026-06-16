<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;

final class NullTtlStore implements TtlStoreInterface
{
    public function delete(string $key): void {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void {}
}
