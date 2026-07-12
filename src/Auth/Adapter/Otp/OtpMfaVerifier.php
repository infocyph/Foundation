<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\Contracts\ReplayStoreInterface;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
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

        return match ($factor->type) {
            MfaFactorType::TOTP->value => $this->verifyTotp($factor, $code),
            MfaFactorType::HOTP->value => $this->verifyHotp($factor, $code),
            MfaFactorType::OCRA->value => $this->verifyOcra($challenge, $factor, $code),
            default => new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_unsupported'),
        };
    }

    private function advanceCounter(MfaFactor $factor, int $counter): bool
    {
        if (!$this->factors instanceof MfaFactorCompareAndSwapStoreInterface) {
            return false;
        }

        $metadata = $factor->metadata;
        $otp = ValueNormalizer::associativeArray($metadata['otp'] ?? null);
        if ($otp === []) {
            $otp = $metadata;
            $otp['counter'] = $counter;

            return $this->factors->compareAndSwap($factor, $factor->withMetadata($otp));
        }

        $otp['counter'] = $counter;
        $metadata['otp'] = $otp;

        return $this->factors->compareAndSwap($factor, $factor->withMetadata($metadata));
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

    /** @param array<string, mixed> $context */
    private function ocraAuthenticator(string $suite, string $sharedKey, array $context): ?OCRA
    {
        try {
            $ocra = new OCRA($suite, $sharedKey);
            $pin = $this->stringOption($context, 'ocra_pin');
            $session = $this->stringOption($context, 'ocra_session');
            if ($pin !== null) {
                $ocra->setPin($pin);
            }
            if ($session !== null) {
                $ocra->setSession($session);
            }

            return $ocra;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{challenge: string, counter: int, shared_key: string, suite: string}|null
     */
    private function ocraInputs(MfaChallenge $challenge, MfaFactor $factor): ?array
    {
        $config = $this->otpConfig($factor);
        $challengeValue = $this->stringOption($challenge->metadata, 'ocra_challenge');
        if ($challengeValue === null || $config['suite'] === null || $config['shared_key'] === null) {
            return null;
        }

        return [
            'challenge' => $challengeValue,
            'counter' => $config['counter'],
            'shared_key' => $config['shared_key'],
            'suite' => $config['suite'],
        ];
    }

    /**
     * @return array{algorithm: string, counter: int, digits: int, look_ahead: int, secret: ?string, shared_key: ?string, suite: ?string, window: int}
     */
    private function otpConfig(MfaFactor $factor): array
    {
        $otp = ValueNormalizer::associativeArray($factor->metadata['otp'] ?? null);
        if ($otp === []) {
            $otp = $factor->metadata;
        }

        return [
            'algorithm' => is_string($otp['algorithm'] ?? null) && $otp['algorithm'] !== '' ? $otp['algorithm'] : 'sha1',
            'counter' => max(0, $this->integerOption($otp, 'counter', 0)),
            'digits' => max(4, $this->integerOption($otp, 'digits', 6)),
            'look_ahead' => max(0, $this->integerOption($otp, 'look_ahead', 5)),
            'period' => max(1, $this->integerOption($otp, 'period', 30)),
            'secret' => $this->stringOption($otp, 'secret'),
            'shared_key' => $this->stringOption($otp, 'shared_key'),
            'suite' => $this->stringOption($otp, 'suite'),
            'window' => max(0, $this->integerOption($otp, 'window', $this->window)),
        ];
    }

    /** @param array<string, mixed> $config */
    private function stringOption(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{algorithm: string, digits: int, period: int, secret: ?string, window: int}
     */
    private function totpConfig(MfaFactor $factor): array
    {
        $otp = $this->otpConfig($factor);

        return [
            'algorithm' => $otp['algorithm'],
            'digits' => max(4, $this->integerOption($otp, 'digits', 6)),
            'period' => max(1, $this->integerOption($otp, 'period', 30)),
            'secret' => $otp['secret'],
            'window' => max(0, $this->integerOption($otp, 'window', $this->window)),
        ];
    }

    private function verifyHotp(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->otpConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

        if (!$this->factors instanceof MfaFactorCompareAndSwapStoreInterface) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_atomic_counter_store_required');
        }

        try {
            $result = new HOTP($config['secret'], $config['digits'])
                ->setAlgorithm($config['algorithm'])
                ->verifyWithResult(
                    $code,
                    $config['counter'],
                    $config['look_ahead'],
                    $this->replayStore,
                    $factor->id,
                );
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        if (!$result->matched || !is_int($result->matchedCounter)) {
            return new MfaVerificationResult(
                false,
                factorId: $factor->id,
                reason: $result->replayDetected ? 'mfa_code_replayed' : 'mfa_code_invalid',
            );
        }

        if (!$this->advanceCounter($factor, $result->matchedCounter + 1)) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_code_replayed');
        }

        return new MfaVerificationResult(
            true,
            factorId: $factor->id,
            context: [
                'drift_offset' => $result->driftOffset,
                'matched_counter' => $result->matchedCounter,
                'verified_at' => $result->verifiedAt?->getTimestamp(),
            ],
        );
    }

    private function verifyOcra(MfaChallenge $challenge, MfaFactor $factor, string $code): MfaVerificationResult
    {
        $inputs = $this->ocraInputs($challenge, $factor);
        if ($inputs === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        if (!$this->replayStore instanceof ReplayStoreInterface) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_replay_store_required');
        }

        $ocra = $this->ocraAuthenticator($inputs['suite'], $inputs['shared_key'], $challenge->metadata);
        if ($ocra === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        if ($ocra->getSuite()->counterEnabled && !$this->factors instanceof MfaFactorCompareAndSwapStoreInterface) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_atomic_counter_store_required');
        }

        $result = $ocra->verifyWithResult($code, $inputs['challenge'], $inputs['counter'], $this->replayStore, $factor->id);

        if (!$result->matched) {
            return new MfaVerificationResult(
                false,
                factorId: $factor->id,
                reason: $result->replayDetected ? 'mfa_code_replayed' : 'mfa_code_invalid',
            );
        }

        if ($ocra->getSuite()->counterEnabled && !$this->advanceCounter($factor, $inputs['counter'] + 1)) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_code_replayed');
        }

        return new MfaVerificationResult(
            true,
            factorId: $factor->id,
            context: [
                'matched_counter' => $result->matchedCounter,
                'verified_at' => $result->verifiedAt?->getTimestamp(),
            ],
        );
    }

    private function verifyTotp(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->totpConfig($factor);

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
}
