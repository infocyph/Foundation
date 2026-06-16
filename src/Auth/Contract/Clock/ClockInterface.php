<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Clock;

interface ClockInterface
{
    public function now(): int;
}
