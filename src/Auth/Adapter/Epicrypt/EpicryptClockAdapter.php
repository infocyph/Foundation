<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use DateTimeImmutable;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface as AuthClockInterface;
use Psr\Clock\ClockInterface;

final readonly class EpicryptClockAdapter implements ClockInterface
{
    public function __construct(
        private AuthClockInterface $clock,
    ) {}

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->clock->now());
    }
}
