<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(
        private int $now,
    ) {}

    public function freezeAt(int $now): self
    {
        $this->now = $now;

        return $this;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function tick(int $seconds = 1): self
    {
        $this->now += $seconds;

        return $this;
    }
}
