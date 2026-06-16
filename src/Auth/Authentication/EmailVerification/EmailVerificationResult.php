<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\EmailVerification;

final readonly class EmailVerificationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public EmailVerificationStatus $status,
        public ?EmailVerificationRequest $request = null,
        public ?string $token = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function email(): ?string
    {
        return $this->request?->email;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === EmailVerificationStatus::ISSUED
            || $this->status === EmailVerificationStatus::VERIFIED;
    }

    public function verified(): bool
    {
        return $this->status === EmailVerificationStatus::VERIFIED;
    }
}
