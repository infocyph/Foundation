<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class OtpManager
{
    public function __construct(
        private ConfigRepository $config,
        private MfaManager $mfa,
        private MfaFactorStoreInterface $factors,
        private OtpProvisioningService $provisioning,
        private AuthenticationStateCacheInterface $stateCache,
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
        $config = $this->factorConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

        try {
            $result = new TOTP(
                $config['secret'],
                $config['digits'],
                $config['period'],
                $config['algorithm'],
            )->verifyWithWindow(
                $code,
                window: VerificationWindow::symmetric($config['window']),
                cache: $this->stateCache,
                factorId: $this->factorBinding($factor, $config['secret']),
            );
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        if (!$result->matched) {
            return new MfaVerificationResult(
                verified: false,
                factorId: $factor->id,
                reason: $result->replayDetected ? 'mfa_code_replayed' : 'mfa_code_invalid',
                context: [
                    'drift_offset' => $result->driftOffset,
                    'matched_timestep' => $result->matchedTimestep,
                    'replay_detected' => $result->replayDetected,
                ],
            );
        }

        return new MfaVerificationResult(
            verified: true,
            factorId: $factor->id,
            context: [
                'drift_offset' => $result->driftOffset,
                'matched_timestep' => $result->matchedTimestep,
                'verified_at' => $result->verifiedAt?->getTimestamp(),
            ],
        );
    }

    private function algorithm(): string
    {
        return $this->stringValue($this->config->get('auth.otp.totp.algorithm'), 'sha1');
    }

    private function digits(): int
    {
        return $this->intValue($this->config->get('auth.otp.totp.digits'), 6);
    }

    private function factorBinding(MfaFactor $factor, string $secret): string
    {
        return hash('sha256', "foundation:otp-factor:v1\0{$factor->id}\0{$secret}");
    }

    /** @return array{algorithm:string,digits:int,period:int,secret:?string,window:int} */
    private function factorConfig(MfaFactor $factor): array
    {
        $otp = ValueNormalizer::associativeArray($factor->metadata['otp'] ?? null);
        if ($otp === []) {
            $otp = $factor->metadata;
        }

        return [
            'algorithm' => $this->stringValue($otp['algorithm'] ?? null, $this->algorithm()),
            'digits' => max(6, $this->intValue($otp['digits'] ?? null, $this->digits())),
            'period' => max(1, $this->intValue($otp['period'] ?? null, $this->period())),
            'secret' => $this->nonEmptyString($otp['secret'] ?? null),
            'window' => max(0, $this->intValue($otp['window'] ?? null, $this->window())),
        ];
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

    private function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function period(): int
    {
        return $this->intValue($this->config->get('auth.otp.totp.period'), 30);
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function window(): int
    {
        return max(0, $this->intValue($this->config->get('auth.otp.totp.window'), 1));
    }
}
