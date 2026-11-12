<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Cache\TtlStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class ArrayTtlStore implements TtlStoreInterface
{
    /**
     * @var array<string, array{value: mixed, expires_at: int}>
     */
    private array $items = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    #[\Override]
    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    /** @param array<array-key, mixed>|string|float|int|bool|null $default */
    #[\Override]
    public function get(string $key, array|string|float|int|bool|null $default = null): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null || $this->expired($key, $item['expires_at'])) {
            return $default;
        }

        return $item['value'];
    }

    #[\Override]
    public function pull(string $key, ?string $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($this->items[$key]);

        return $value;
    }

    /** @param array<array-key, mixed>|string|float|int|bool|null $value */
    #[\Override]
    public function put(string $key, array|string|float|int|bool|null $value, int $ttlSeconds): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expires_at' => $this->clock->now() + $ttlSeconds,
        ];
    }

    private function expired(string $key, int $expiresAt): bool
    {
        if ($expiresAt >= $this->clock->now()) {
            return false;
        }

        unset($this->items[$key]);

        return true;
    }
}
