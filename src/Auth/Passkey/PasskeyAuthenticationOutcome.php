<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyAuthenticationOutcome
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasskeyAuthenticationStatus $status,
        public ?PasskeyChallenge $challenge = null,
        public ?PasskeyVerificationResult $verification = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === PasskeyAuthenticationStatus::STARTED
            || $this->status === PasskeyAuthenticationStatus::VERIFIED;
    }
}
