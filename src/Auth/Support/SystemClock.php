<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
