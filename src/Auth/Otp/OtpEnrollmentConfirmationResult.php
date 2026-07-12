<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\Foundation\Auth\Mfa\MfaEnrollmentResult;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;

final readonly class OtpEnrollmentConfirmationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $verified,
        public bool $activated,
        public ?MfaFactor $factor = null,
        public ?MfaVerificationResult $verification = null,
        public ?MfaEnrollmentResult $activation = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function successful(): bool
    {
        return $this->verified && $this->activated;
    }
}
