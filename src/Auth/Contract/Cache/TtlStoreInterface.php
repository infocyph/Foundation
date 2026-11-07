<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Cache;

interface TtlStoreInterface
{
    public function delete(string $key): void;

    public function get(string $key, mixed $default = null): mixed;

    /**
     * Atomically return and remove a live value; competing callers receive
     * the default after the winning consumption, within the store's scope.
     */
    public function pull(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;
}
