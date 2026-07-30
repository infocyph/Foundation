<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Testing;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
    public function __construct(private int $timestamp) {}

    public function advance(int $seconds): self
    {
        $this->timestamp += $seconds;

        return $this;
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    public function set(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }
}
