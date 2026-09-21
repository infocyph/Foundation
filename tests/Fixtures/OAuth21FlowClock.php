<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class OAuth21FlowClock implements ClockInterface
{
    public function __construct(private int $now) {}

    public function advance(int $seconds): void
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('OAuth fixture time cannot move backwards.');
        }

        $this->now += $seconds;
    }

    public function now(): int
    {
        return $this->now;
    }
}
