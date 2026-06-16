<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Lockout;

use Infocyph\Foundation\Auth\Contract\Storage\LockoutReason;

final readonly class LockoutResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public LockoutStatus $status,
        public string $accountId,
        public ?LockoutReason $reason = null,
        public ?int $lockedUntil = null,
        public ?int $attempts = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return match ($this->status) {
            LockoutStatus::FAILURE_RECORDED,
            LockoutStatus::LOCKED,
            LockoutStatus::UNLOCKED,
            LockoutStatus::CLEAR => true,
        };
    }
}
