<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Psr\Clock\ClockInterface as PsrClockInterface;

/** Adapt the execution-owned Foundation clock to Epicrypt's PSR clock contract. */
final readonly class RememberTokenPsrClock implements PsrClockInterface
{
    public function __construct(private ClockInterface $clock) {}

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->clock->now());
    }
}
