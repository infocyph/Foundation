<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class PasswordlessResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasswordlessStatus $status,
        public ?string $token = null,
        public ?TokenVerificationResult $verification = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === PasswordlessStatus::ISSUED
            || $this->status === PasswordlessStatus::VERIFIED;
    }
}
