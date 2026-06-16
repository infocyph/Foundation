<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\PasswordReset;

final readonly class PasswordResetResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasswordResetStatus $status,
        public ?PasswordResetRequest $request = null,
        public ?string $token = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function completed(): bool
    {
        return $this->status === PasswordResetStatus::COMPLETED;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === PasswordResetStatus::REQUESTED
            || $this->status === PasswordResetStatus::COMPLETED;
    }
}
