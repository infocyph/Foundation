<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\Foundation\Auth\Mfa\RecoveryCodeServiceInterface;
use Infocyph\Foundation\Auth\Mfa\RecoveryCodeVerificationResult;
use Infocyph\OTP\RecoveryCodes;

final readonly class OtpRecoveryCodeService implements RecoveryCodeServiceInterface
{
    public function __construct(
        private RecoveryCodes $recoveryCodes,
        private int $defaultCount = 10,
        private int $codeLength = 10,
    ) {}

    public function generate(string $accountId, int $count = 10): array
    {
        $result = $this->recoveryCodes->generate(
            $this->binding($accountId),
            $count > 0 ? $count : $this->defaultCount,
            $this->codeLength,
        );

        return $result->plainCodes;
    }

    public function verify(string $accountId, string $code): RecoveryCodeVerificationResult
    {
        $result = $this->recoveryCodes->consume($this->binding($accountId), $code);

        return new RecoveryCodeVerificationResult(
            verified: $result->consumed,
            reason: $result->consumed ? null : 'recovery_code_invalid',
        );
    }

    private function binding(string $accountId): string
    {
        return 'account:' . $accountId;
    }
}
