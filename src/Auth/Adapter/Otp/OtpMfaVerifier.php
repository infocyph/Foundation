<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\Contracts\ReplayStoreInterface;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class OtpMfaVerifier implements MfaVerifierInterface
{
    public function __construct(
        private MfaFactorStoreInterface $factors,
        private ?ReplayStoreInterface $replayStore = null,
        private int $window = 1,
    ) {}

    public function verify(MfaChallenge $challenge, string $code): MfaVerificationResult
    {
        if ($challenge->factorId === null || $challenge->factorId === '') {
            return new MfaVerificationResult(false, reason: 'mfa_factor_missing');
        }

        $factor = $this->findFactor($challenge->accountId, $challenge->factorId);
        if ($factor === null) {
            return new MfaVerificationResult(false, factorId: $challenge->factorId, reason: 'mfa_factor_not_found');
        }

        if (!$factor->enabled) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_disabled');
        }

        if ($factor->type !== MfaFactorType::TOTP->value) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_unsupported');
        }

        $config = $this->factorConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

        try {
            $totp = new TOTP($config['secret'], $config['digits'], $config['period'])
                ->setAlgorithm($config['algorithm']);
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        $result = $totp->verifyWithWindow(
            $code,
            window: VerificationWindow::symmetric($config['window']),
            replayStore: $this->replayStore,
            binding: $factor->id,
        );

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
            'algorithm' => is_string($otp['algorithm'] ?? null) && $otp['algorithm'] !== ''
                ? $otp['algorithm']
                : 'sha1',
            'digits' => max(4, $this->integerOption($otp, 'digits', 6)),
            'period' => max(1, $this->integerOption($otp, 'period', 30)),
            'secret' => is_string($otp['secret'] ?? null) && $otp['secret'] !== ''
                ? $otp['secret']
                : null,
            'window' => max(0, $this->integerOption($otp, 'window', $this->window)),
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

    /**
     * @param array<string, mixed> $config
     */
    private function integerOption(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
