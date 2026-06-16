<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

final readonly class MfaChallengeResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public MfaStatus $status,
        public ?MfaChallenge $challenge = null,
        public ?MfaVerificationResult $verification = null,
        public ?MfaFactor $factor = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === MfaStatus::CHALLENGE_ISSUED
            || $this->status === MfaStatus::VERIFIED
            || $this->status === MfaStatus::RECOVERY_CODE_VERIFIED;
    }
}
