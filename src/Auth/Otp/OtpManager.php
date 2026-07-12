<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Otp;

use DateTimeImmutable;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaManager;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\Result\StepUpResult;
use Infocyph\OTP\Support\SecretUtility;
use Infocyph\OTP\Support\StepUp;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;
use Infocyph\OTP\ValueObjects\ParsedOtpAuthUri;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class OtpManager
{
    public function __construct(
        private ConfigRepository $config,
        private MfaManager $mfa,
        private MfaFactorStoreInterface $factors,
        private OtpProvisioningService $provisioning,
    ) {}

    public function assessFreshness(
        ?DateTimeImmutable $verifiedAt,
        ?int $seconds = null,
        ?DateTimeImmutable $now = null,
    ): StepUpResult {
        return StepUp::assess($verifiedAt, $seconds ?? $this->freshnessWindow(), $now);
    }

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

    public function buildPayload(
        string $secret,
        string $label,
        ?string $issuer = null,
        bool $withQrSvg = false,
    ): EnrollmentPayload {
        return $this->totp($secret)->getEnrollmentPayload(
            $label,
            $issuer ?? $this->issuer(),
            ['algorithm', 'digits', 'period'],
            [],
            $withQrSvg,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
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

    /**
     * @throws \Exception
     */
    public function generateSecret(?int $bytes = null): string
    {
        return TOTP::generateSecret($bytes ?? $this->secretBytes());
    }

    public function hotp(string $secret, int $digits = 6): HOTP
    {
        return new HOTP($secret, $digits);
    }

    public function isValidSecret(string $secret): bool
    {
        return SecretUtility::isValidBase32($secret);
    }

    public function normalizeSecret(string $secret): string
    {
        return SecretUtility::normalizeBase32($secret);
    }

    public function ocra(string $suite, string $sharedKey): OCRA
    {
        return new OCRA($suite, $sharedKey);
    }

    public function parseProvisioningUri(string $uri): ParsedOtpAuthUri
    {
        return TOTP::parseProvisioningUri($uri);
    }

    public function verifyFactor(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->factorConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

        try {
            $totp = new TOTP($config['secret'], $config['digits'], $config['period']);
            $totp->setAlgorithm($config['algorithm']);
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        $result = $totp->verifyWithWindow(
            $code,
            window: VerificationWindow::symmetric($config['window']),
        );

        if (!$result->matched) {
            return new MfaVerificationResult(
                verified: false,
                factorId: $factor->id,
                reason: 'mfa_code_invalid',
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

    /**
     * @return array{algorithm: string, digits: int, period: int, secret: ?string, window: int}
     */
    private function factorConfig(MfaFactor $factor): array
    {
        $otp = ValueNormalizer::associativeArray($factor->metadata['otp'] ?? null);
        if ($otp === []) {
            $otp = $factor->metadata;
        }

        return [
            'algorithm' => $this->stringValue($otp['algorithm'] ?? null, $this->algorithm()),
            'digits' => max(4, $this->intValue($otp['digits'] ?? null, $this->digits())),
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

    private function freshnessWindow(): int
    {
        return $this->intValue(
            $this->config->get('auth.otp.freshness_window'),
            $this->intValue($this->config->get('auth.mfa_satisfied_ttl'), 900),
        );
    }

    private function intValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function issuer(): string
    {
        return $this->stringValue($this->config->get('auth.otp.issuer'), 'Foundation');
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function period(): int
    {
        return $this->intValue($this->config->get('auth.otp.totp.period'), 30);
    }

    private function secretBytes(): int
    {
        return $this->intValue($this->config->get('auth.otp.totp.secret_bytes'), 64);
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function totp(string $secret): TOTP
    {
        $totp = new TOTP($secret, $this->digits(), $this->period());
        $totp->setAlgorithm($this->algorithm());

        return $totp;
    }

    private function window(): int
    {
        return max(0, $this->intValue($this->config->get('auth.otp.totp.window'), 1));
    }
}
