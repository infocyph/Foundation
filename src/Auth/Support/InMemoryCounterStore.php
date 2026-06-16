<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Cache\CounterStoreInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class InMemoryCounterStore implements CounterStoreInterface
{
    /**
     * @var array<string, array{value: int, expires_at: int|null}>
     */
    private array $values = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $current = $this->values[$key] ?? null;

        if ($current !== null && $current['expires_at'] !== null && $current['expires_at'] <= $this->clock->now()) {
            unset($this->values[$key]);
            $current = null;
        }

        $value = ($current['value'] ?? 0) + $by;

        $this->values[$key] = [
            'value' => $value,
            'expires_at' => $ttlSeconds !== null ? $this->clock->now() + $ttlSeconds : ($current['expires_at'] ?? null),
        ];

        return $value;
    }

    public function reset(string $key): void
    {
        unset($this->values[$key]);
    }
}
