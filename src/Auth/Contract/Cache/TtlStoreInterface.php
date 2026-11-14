<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Cache;

interface TtlStoreInterface
{
    public function delete(string $key): void;

    /** @param array<array-key, mixed>|string|float|int|bool|null $default */
    public function get(string $key, array|string|float|int|bool|null $default = null): mixed;

    /**
     * Atomically return and remove a live value; competing callers receive
     * the nullable string default after the winning consumption, within the store's scope.
     */
    public function pull(string $key, ?string $default = null): mixed;

    /** @param array<array-key, mixed>|string|float|int|bool|null $value */
    public function put(string $key, array|string|float|int|bool|null $value, int $ttlSeconds): void;
}
