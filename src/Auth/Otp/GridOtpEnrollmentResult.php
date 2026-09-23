<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\Foundation\Auth\Mfa\MfaEnrollmentResult;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;

final readonly class GridOtpEnrollmentResult
{
    public function __construct(
        public MfaEnrollmentResult $enrollment,
        #[\SensitiveParameter]
        public string $secret,
    ) {}

    /** @return array{enrollment:MfaEnrollmentResult,secret:string} */
    public function __debugInfo(): array
    {
        return [
            'enrollment' => $this->enrollment,
            'secret' => '[redacted]',
        ];
    }

    public function factor(): ?MfaFactor
    {
        return $this->enrollment->factor;
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        return $this->enrollment->recoveryCodes;
    }

    public function successful(): bool
    {
        return $this->enrollment->successful();
    }
}
