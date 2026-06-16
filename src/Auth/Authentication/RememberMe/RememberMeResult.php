<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

final readonly class RememberMeResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public RememberTokenStatus $status,
        public ?RememberToken $token = null,
        public ?RememberTokenRecord $record = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === RememberTokenStatus::ISSUED
            || $this->status === RememberTokenStatus::ROTATED
            || $this->status === RememberTokenStatus::VERIFIED
            || $this->status === RememberTokenStatus::REVOKED;
    }

    public function verified(): bool
    {
        return $this->status === RememberTokenStatus::VERIFIED;
    }
}
