<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\Foundation\Auth\Mfa\MfaEnrollmentResult;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;

final readonly class OtpEnrollmentResult
{
    /**
     * @param array<string, mixed> $factorMetadata
     */
    public function __construct(
        public MfaEnrollmentResult $enrollment,
        public EnrollmentPayload $payload,
        public array $factorMetadata,
    ) {}

    public function factor(): ?MfaFactor
    {
        return $this->enrollment->factor;
    }

    /**
     * @return list<string>
     */
    public function recoveryCodes(): array
    {
        return $this->enrollment->recoveryCodes;
    }

    public function successful(): bool
    {
        return $this->enrollment->successful();
    }
}
