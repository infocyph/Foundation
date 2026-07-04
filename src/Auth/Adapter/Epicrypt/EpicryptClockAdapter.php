<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Internal\Clock\ClockInterface as EpicryptClockInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface as AuthClockInterface;

final readonly class EpicryptClockAdapter implements EpicryptClockInterface
{
    public function __construct(
        private AuthClockInterface $clock,
    ) {}

    public function now(): int
    {
        return $this->clock->now();
    }
}
