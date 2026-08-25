<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;

/**
 * Foundation-owned OTP enrollment workflow.
 *
 * OTP algorithms, factor parsing, verification windows and replay protection are
 * delegated to OtpProvisioningService/OtpMfaVerifier and ultimately OTP 6.0.
 */
final readonly class OtpManager
{
    public function __construct(
        private MfaManager $mfa,
        private MfaFactorStoreInterface $factors,
        private OtpProvisioningService $provisioning,
        private OtpMfaVerifier $verifier,
    ) {}

    public function beginEnrollment(
        string $accountId,
        ?string $label = null,
        bool $withQrSvg = false,
        int $recoveryCodeCount = 10,
    ): OtpEnrollmentResult {
        $provisioned = $this->provisioning->provision($accountId, $label, $withQrSvg);
        /** @var EnrollmentPayload $payload */
        $payload = $provisioned['payload'];
        /** @var array<string, mixed> $factorMetadata */
        $factorMetadata = $provisioned['factor_metadata'];

        $enrollment = $this->mfa->enrollFactor(
            accountId: $accountId,
            type: MfaFactorType::TOTP,
            label: $payload->label,
            metadata: $factorMetadata,
            enabled: false,
            recoveryCodeCount: $recoveryCodeCount,
        );

        return new OtpEnrollmentResult($enrollment, $payload, $factorMetadata);
    }

    /** @param array<string, mixed> $context */
    public function completeEnrollment(
        string $accountId,
        string $factorId,
        string $code,
        array $context = [],
    ): OtpEnrollmentConfirmationResult {
        $factor = $this->findFactor($accountId, $factorId);
        if ($factor === null) {
            return new OtpEnrollmentConfirmationResult(
                verified: false,
                activated: false,
                code: 'mfa_factor_not_found',
                context: $context,
            );
        }
        if ($factor->type !== MfaFactorType::TOTP->value) {
            return new OtpEnrollmentConfirmationResult(
                verified: false,
                activated: false,
                factor: $factor,
                code: 'mfa_factor_unsupported',
                context: $context,
            );
        }

        $verification = $this->verifyFactor($factor, $code);
        if (!$verification->verified) {
            return new OtpEnrollmentConfirmationResult(
                verified: false,
                activated: false,
                factor: $factor,
                verification: $verification,
                code: $verification->reason ?? 'mfa_code_invalid',
                context: $context,
            );
        }
        if ($factor->enabled) {
            return new OtpEnrollmentConfirmationResult(
                verified: true,
                activated: true,
                factor: $factor,
                verification: $verification,
                code: 'mfa_factor_already_active',
                context: $context,
            );
        }

        $activation = $this->mfa->activateFactor($accountId, $factorId, $context);

        return new OtpEnrollmentConfirmationResult(
            verified: true,
            activated: $activation->successful(),
            factor: $activation->factor,
            verification: $verification,
            activation: $activation,
            code: $activation->code ?? 'mfa_factor_activated',
            context: $context,
        );
    }

    public function verifyFactor(MfaFactor $factor, string $code): MfaVerificationResult
    {
        return $this->verifier->verifyEnrollment($factor, $code);
    }

    private function findFactor(string $accountId, string $factorId): ?MfaFactor
    {
        foreach ($this->factors->findForAccount($accountId) as $factor) {
            if ($factor->id === $factorId) {
                return $factor;
            }
        }

        return null;
    }
}
