<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Auth\Mfa\MfaVerifierInterface;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\Result\VerificationResult;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class OtpMfaVerifier implements MfaVerifierInterface
{
    public function __construct(
        private MfaFactorCompareAndSwapStoreInterface $factors,
        private AuthenticationStateCacheInterface $stateCache,
        private int $window = 1,
        private int $ocraReplayTtl = 90,
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

        try {
            return match ($factor->type) {
                MfaFactorType::TOTP->value => $this->verifyTotp($factor, $code),
                MfaFactorType::HOTP->value => $this->verifyHotp($factor, $code),
                MfaFactorType::OCRA->value => $this->verifyOcra($challenge, $factor, $code),
                default => new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_unsupported'),
            };
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }
    }

    /**
     * Verify the initial code for a disabled TOTP factor before activation.
     *
     * Enrollment verification deliberately uses the same canonical parser,
     * validation window and replay protection as normal OTP verification.
     */
    public function verifyEnrollment(MfaFactor $factor, string $code): MfaVerificationResult
    {
        if ($factor->type !== MfaFactorType::TOTP->value) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_unsupported');
        }

        try {
            return $this->verifyTotp($factor, $code);
        } catch (\Throwable) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }
    }

    private function advanceCounter(MfaFactor $factor, int $counter): bool
    {
        $metadata = $factor->metadata;
        $otp = ValueNormalizer::associativeArray($metadata['otp'] ?? null);
        if ($otp === []) {
            return false;
        }

        $otp['counter'] = $counter;
        $metadata['otp'] = $otp;

        return $this->factors->compareAndSwap($factor, $factor->withMetadata($metadata));
    }

    private function factorBinding(MfaFactor $factor, string $generationSecret): string
    {
        return hash('sha256', "foundation:otp-factor:v1\0{$factor->id}\0{$generationSecret}");
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

    /** @param array<string, mixed> $config */
    private function integerOption(array $config, string $key, int $default): int
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/D', $value) === 1) {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($validated)) {
                return $validated;
            }
        }

        throw new \InvalidArgumentException(sprintf('OTP factor option "%s" must be an integer.', $key));
    }

    /** @return array{algorithm:string,counter:int,digits:int,look_ahead:int,period:int,secret:?string,suite:?string,window:int} */
    private function otpConfig(MfaFactor $factor): array
    {
        $otp = ValueNormalizer::associativeArray($factor->metadata['otp'] ?? null);
        if ($otp === []) {
            throw new \InvalidArgumentException('OTP factors require nested otp metadata.');
        }

        return [
            'algorithm' => $this->stringOption($otp, 'algorithm') ?? 'sha1',
            'counter' => $this->integerOption($otp, 'counter', 0),
            'digits' => $this->integerOption($otp, 'digits', 6),
            'look_ahead' => $this->integerOption($otp, 'look_ahead', 5),
            'period' => $this->integerOption($otp, 'period', 30),
            'secret' => $this->stringOption($otp, 'secret'),
            'suite' => $this->stringOption($otp, 'suite'),
            'window' => $this->integerOption($otp, 'window', $this->window),
        ];
    }

    private function resultFailure(MfaFactor $factor, VerificationResult $result): MfaVerificationResult
    {
        return new MfaVerificationResult(
            verified: false,
            factorId: $factor->id,
            reason: $result->replayDetected ? 'mfa_code_replayed' : 'mfa_code_invalid',
            context: [
                'otp_reason' => $result->reason->value,
                'drift_offset' => $result->driftOffset,
                'matched_counter' => $result->matchedCounter,
                'matched_timestep' => $result->matchedTimestep,
                'replay_detected' => $result->replayDetected,
            ],
        );
    }

    /** @param array<string, mixed> $config */
    private function stringOption(array $config, string $key): ?string
    {
        if (!array_key_exists($key, $config) || $config[$key] === null) {
            return null;
        }

        $value = $config[$key];
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('OTP factor option "%s" must be a non-empty string.', $key));
        }

        return trim($value);
    }

    private function verifyHotp(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->otpConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

        $result = new HOTP(
            $config['secret'],
            $config['digits'],
            $config['algorithm'],
        )->verifyWithResult(
            $code,
            $config['counter'],
            $config['look_ahead'],
        );

        if (!$result->matched) {
            return $this->resultFailure($factor, $result);
        }
        if ($result->nextCounter === null || !$this->advanceCounter($factor, $result->nextCounter)) {
            return new MfaVerificationResult(
                false,
                factorId: $factor->id,
                reason: 'mfa_code_replayed',
                context: ['otp_reason' => $result->reason->value],
            );
        }

        return new MfaVerificationResult(true, factorId: $factor->id, context: [
            'otp_reason' => $result->reason->value,
            'drift_offset' => $result->driftOffset,
            'matched_counter' => $result->matchedCounter,
            'next_counter' => $result->nextCounter,
        ]);
    }

    private function verifyOcra(MfaChallenge $challenge, MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->otpConfig($factor);
        $challengeValue = $this->stringOption($challenge->metadata, 'ocra_challenge');
        if ($challengeValue === null || $config['suite'] === null || $config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_factor_invalid_configuration');
        }

        $ocra = OCRA::fromBase32($config['suite'], $config['secret']);
        $suite = $ocra->getSuite();
        $pin = $this->stringOption($challenge->metadata, 'ocra_pin');
        $session = $this->stringOption($challenge->metadata, 'ocra_session');
        $counter = $suite->counterEnabled ? $config['counter'] : null;
        $timestamp = $suite->usesTime() ? time() : null;
        $window = $suite->usesTime() && !$suite->counterEnabled
            ? VerificationWindow::symmetric($config['window'])
            : new VerificationWindow();

        $result = $ocra->verifyWithResult(
            $code,
            $challengeValue,
            $counter,
            $pin,
            $session,
            $timestamp,
            $window,
            cache: $suite->counterEnabled ? null : $this->stateCache,
            factorId: $suite->counterEnabled ? null : $this->factorBinding($factor, $config['secret']),
            replayTtl: $suite->counterEnabled ? null : $this->ocraReplayTtl,
        );

        if (!$result->matched) {
            return $this->resultFailure($factor, $result);
        }
        if ($suite->counterEnabled) {
            if ($result->nextCounter === null || !$this->advanceCounter($factor, $result->nextCounter)) {
                return new MfaVerificationResult(
                    false,
                    factorId: $factor->id,
                    reason: 'mfa_code_replayed',
                    context: ['otp_reason' => $result->reason->value],
                );
            }
        }

        return new MfaVerificationResult(true, factorId: $factor->id, context: [
            'otp_reason' => $result->reason->value,
            'drift_offset' => $result->driftOffset,
            'matched_counter' => $result->matchedCounter,
            'matched_timestep' => $result->matchedTimestep,
            'next_counter' => $result->nextCounter,
        ]);
    }

    private function verifyTotp(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->otpConfig($factor);
        if ($config['secret'] === null) {
            return new MfaVerificationResult(false, factorId: $factor->id, reason: 'mfa_secret_missing');
        }

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

        if (!$result->matched) {
            return $this->resultFailure($factor, $result);
        }

        return new MfaVerificationResult(verified: true, factorId: $factor->id, context: [
            'otp_reason' => $result->reason->value,
            'drift_offset' => $result->driftOffset,
            'matched_timestep' => $result->matchedTimestep,
        ]);
    }
}
