<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Lockout;

final readonly class LockoutConfig
{
    public function __construct(
        public int $maxLoginFailures = 5,
        public int $maxMfaFailures = 5,
        public int $maxPasskeyFailures = 5,
        public int $windowSeconds = 900,
        public int $lockSeconds = 900,
    ) {}
}
