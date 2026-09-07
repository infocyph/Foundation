<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Mfa\MfaChallengePurpose;
use Infocyph\Foundation\Auth\Mfa\MfaChallengeResult;
use Infocyph\Foundation\Auth\Mfa\MfaEnrollmentResult;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaStatus;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\OTP\AOTP;
use Infocyph\OTP\GridOTP;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\ValueObjects\AotpChallenge;
use Infocyph\OTP\ValueObjects\AotpResponse;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;
use Infocyph\OTP\ValueObjects\GridChallenge;

/**
 * Foundation-owned OTP enrollment and application challenge workflow.
 *
 * OTP 6.1 owns algorithms, native challenge payloads, verification windows and
 * replay protection. Foundation owns account/factor persistence, activation,
 * recovery codes, audit, notifications and MFA satisfaction policy.
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
    public function completeAotpEnrollment(
        string $accountId,
        string $factorId,
        AotpChallenge $challenge,
        AotpResponse $response,
        array $context = [],
    ): OtpEnrollmentConfirmationResult {
        $factor = $this->findFactor($accountId, $factorId);
        if (!$factor instanceof MfaFactor) {
            return $this->missingFactorConfirmation($context);
        }
        if ($factor->type !== MfaFactorType::AOTP->value) {
            return $this->unsupportedFactorConfirmation($factor, $context);
        }

        $verification = $this->verifier->challengeFactors()->verifyAotpResponse(
            $factor,
            $challenge,
            $response,
        );

        return $this->activateVerifiedFactor($accountId, $factor, $verification, $context);
    }

    /** @param array<string, mixed> $context */
    public function completeEnrollment(
        string $accountId,
        string $factorId,
        string $code,
        array $context = [],
    ): OtpEnrollmentConfirmationResult {
        $factor = $this->findFactor($accountId, $factorId);
        if (!$factor instanceof MfaFactor) {
            return $this->missingFactorConfirmation($context);
        }
        if (!in_array($factor->type, [MfaFactorType::MOBILE_OTP->value, MfaFactorType::TOTP->value], true)) {
            return $this->unsupportedFactorConfirmation($factor, $context);
        }

        return $this->activateVerifiedFactor(
            $accountId,
            $factor,
            $this->verifyFactor($factor, $code),
            $context,
        );
    }

    /** @param array<string, mixed> $context */
    public function completeGridEnrollment(
        string $accountId,
        string $factorId,
        GridChallenge $challenge,
        string $response,
        array $context = [],
    ): OtpEnrollmentConfirmationResult {
        $factor = $this->findFactor($accountId, $factorId);
        if (!$factor instanceof MfaFactor) {
            return $this->missingFactorConfirmation($context);
        }
        if ($factor->type !== MfaFactorType::GRID_OTP->value) {
            return $this->unsupportedFactorConfirmation($factor, $context);
        }

        $verification = $this->verifier->challengeFactors()->verifyGridResponse(
            $factor,
            $challenge,
            $response,
        );

        return $this->activateVerifiedFactor($accountId, $factor, $verification, $context);
    }

    public function enrollAotp(
        string $accountId,
        string $publicKey,
        string $audience,
        ?string $label = null,
        int $recoveryCodeCount = 10,
    ): MfaEnrollmentResult {
        new AOTP($publicKey, $audience);

        return $this->mfa->enrollFactor(
            accountId: $accountId,
            type: MfaFactorType::AOTP,
            label: $this->label($accountId, $label, 'AOTP'),
            metadata: [
                'otp' => [
                    'audience' => $audience,
                    'public_key' => $publicKey,
                ],
            ],
            enabled: false,
            recoveryCodeCount: $recoveryCodeCount,
        );
    }

    public function enrollGridOtp(
        string $accountId,
        ?string $label = null,
        int $secretLength = 12,
        int $challengeSize = 6,
        int $ttlSeconds = 120,
        int $maxAttempts = 3,
        int $recoveryCodeCount = 10,
    ): GridOtpEnrollmentResult {
        $secret = GridOTP::generateSecret($secretLength);
        new GridOTP(
            $this->verifier->challengeFactors()->stateCache(),
            $secret,
            $challengeSize,
            $ttlSeconds,
            $maxAttempts,
        );

        $enrollment = $this->mfa->enrollFactor(
            accountId: $accountId,
            type: MfaFactorType::GRID_OTP,
            label: $this->label($accountId, $label, 'GridOTP'),
            metadata: [
                'otp' => [
                    'challenge_size' => $challengeSize,
                    'max_attempts' => $maxAttempts,
                    'secret' => $secret,
                    'ttl' => $ttlSeconds,
                ],
            ],
            enabled: false,
            recoveryCodeCount: $recoveryCodeCount,
        );

        return new GridOtpEnrollmentResult($enrollment, $secret);
    }

    public function importLegacyMobileOtp(
        string $accountId,
        #[\SensitiveParameter]
        string $secret,
        #[\SensitiveParameter]
        string $pin,
        ?string $label = null,
        int $pastWindows = 1,
        int $futureWindows = 1,
        int $offsetSteps = 0,
        int $recoveryCodeCount = 10,
    ): MfaEnrollmentResult {
        new MobileOTP($secret, $pin);

        return $this->mfa->enrollFactor(
            accountId: $accountId,
            type: MfaFactorType::MOBILE_OTP,
            label: $this->label($accountId, $label, 'MobileOTP legacy'),
            metadata: [
                'otp' => [
                    'future_windows' => $futureWindows,
                    'legacy' => true,
                    'offset_steps' => $offsetSteps,
                    'past_windows' => $pastWindows,
                    'pin' => $pin,
                    'secret' => $secret,
                ],
            ],
            enabled: false,
            recoveryCodeCount: $recoveryCodeCount,
        );
    }

    /** @param array<string, mixed> $context */
    public function issueAotpChallenge(
        string $accountId,
        string $factorId,
        string $operationContext,
        MfaChallengePurpose|string $purpose = MfaChallengePurpose::LOGIN,
        int $ttlSeconds = 120,
        array $context = [],
    ): MfaChallengeResult {
        $factor = $this->activeFactor($accountId, $factorId, MfaFactorType::AOTP);
        if (!$factor instanceof MfaFactor) {
            return $this->invalidChallenge($context);
        }

        $native = $this->verifier->challengeFactors()->issueAotp(
            $factor,
            $operationContext,
            $ttlSeconds,
        );
        $context['aotp_challenge'] = $native->toArray();

        return $this->mfa->issueChallenge($accountId, $purpose, $factorId, $context);
    }

    public function issueAotpEnrollmentChallenge(
        string $accountId,
        string $factorId,
        int $ttlSeconds = 120,
    ): AotpChallenge {
        $factor = $this->requireFactor($accountId, $factorId, MfaFactorType::AOTP);
        $context = 'foundation:aotp:enrollment:v1:' . hash('sha256', $accountId . "\0" . $factorId);

        return $this->verifier->challengeFactors()->issueAotp($factor, $context, $ttlSeconds);
    }

    /** @param array<string, mixed> $context */
    public function issueGridChallenge(
        string $accountId,
        string $factorId,
        MfaChallengePurpose|string $purpose = MfaChallengePurpose::LOGIN,
        array $context = [],
    ): MfaChallengeResult {
        $factor = $this->activeFactor($accountId, $factorId, MfaFactorType::GRID_OTP);
        if (!$factor instanceof MfaFactor) {
            return $this->invalidChallenge($context);
        }

        $context['grid_challenge'] = $this->verifier->challengeFactors()->issueGrid($factor)->toArray();

        return $this->mfa->issueChallenge($accountId, $purpose, $factorId, $context);
    }

    public function issueGridEnrollmentChallenge(string $accountId, string $factorId): GridChallenge
    {
        return $this->verifier->challengeFactors()->issueGrid(
            $this->requireFactor($accountId, $factorId, MfaFactorType::GRID_OTP),
        );
    }

    public function verifyFactor(MfaFactor $factor, string $code): MfaVerificationResult
    {
        return $this->verifier->verifyEnrollment($factor, $code);
    }

    /** @param array<string, mixed> $context */
    private function activateVerifiedFactor(
        string $accountId,
        MfaFactor $factor,
        MfaVerificationResult $verification,
        array $context,
    ): OtpEnrollmentConfirmationResult {
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

        $activation = $this->mfa->activateFactor($accountId, $factor->id, $context);

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

    private function activeFactor(string $accountId, string $factorId, MfaFactorType $type): ?MfaFactor
    {
        $factor = $this->findFactor($accountId, $factorId);

        return $factor instanceof MfaFactor && $factor->type === $type->value && $factor->enabled
            ? $factor
            : null;
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

    /** @param array<string, mixed> $context */
    private function invalidChallenge(array $context): MfaChallengeResult
    {
        return new MfaChallengeResult(
            MfaStatus::INVALID,
            code: 'mfa_factor_not_available',
            context: $context,
        );
    }

    private function label(string $accountId, ?string $label, string $fallback): string
    {
        if ($label !== null && trim($label) !== '') {
            return trim($label);
        }

        return $fallback . ' - ' . $accountId;
    }

    /** @param array<string, mixed> $context */
    private function missingFactorConfirmation(array $context): OtpEnrollmentConfirmationResult
    {
        return new OtpEnrollmentConfirmationResult(
            verified: false,
            activated: false,
            code: 'mfa_factor_not_found',
            context: $context,
        );
    }

    private function requireFactor(string $accountId, string $factorId, MfaFactorType $type): MfaFactor
    {
        $factor = $this->findFactor($accountId, $factorId);
        if (!$factor instanceof MfaFactor) {
            throw new \InvalidArgumentException('MFA factor was not found for the requested account.');
        }
        if ($factor->type !== $type->value) {
            throw new \InvalidArgumentException(sprintf(
                'MFA factor "%s" is not a %s factor.',
                $factorId,
                $type->value,
            ));
        }

        return $factor;
    }

    /** @param array<string, mixed> $context */
    private function unsupportedFactorConfirmation(
        MfaFactor $factor,
        array $context,
    ): OtpEnrollmentConfirmationResult {
        return new OtpEnrollmentConfirmationResult(
            verified: false,
            activated: false,
            factor: $factor,
            code: 'mfa_factor_unsupported',
            context: $context,
        );
    }
}
