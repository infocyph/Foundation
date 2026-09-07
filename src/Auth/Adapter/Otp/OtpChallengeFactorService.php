<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\CacheLayer\Cache\AuthenticationStateCacheInterface;
use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\AOTP;
use Infocyph\OTP\GridOTP;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\Result\VerificationResult;
use Infocyph\OTP\ValueObjects\AotpChallenge;
use Infocyph\OTP\ValueObjects\AotpResponse;
use Infocyph\OTP\ValueObjects\GridChallenge;
use Infocyph\OTP\ValueObjects\VerificationWindow;

final readonly class OtpChallengeFactorService
{
    public function __construct(private AuthenticationStateCacheInterface $stateCache) {}

    public function issueAotp(MfaFactor $factor, string $context, int $ttlSeconds = 120): AotpChallenge
    {
        $config = $this->otpMetadata($factor);

        return new AOTP(
            $this->requiredString($config, 'public_key'),
            $this->requiredString($config, 'audience'),
        )->issue(
            $this->stateCache,
            $factor->id,
            $context,
            $ttlSeconds,
        );
    }

    public function issueGrid(MfaFactor $factor): GridChallenge
    {
        return $this->grid($factor)->issue($factor->id);
    }

    public function stateCache(): AuthenticationStateCacheInterface
    {
        return $this->stateCache;
    }

    public function verifyAotp(MfaFactor $factor, MfaChallenge $challenge, string $responseJson): MfaVerificationResult
    {
        $payload = ValueNormalizer::associativeArray($challenge->metadata['aotp_challenge'] ?? null);
        if ($payload === []) {
            return $this->invalid($factor, 'mfa_factor_invalid_configuration');
        }

        $response = ValueNormalizer::associativeArray(
            json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR),
        );
        if ($response === []) {
            return $this->invalid($factor, 'mfa_code_invalid');
        }

        return $this->verifyAotpResponse(
            $factor,
            AotpChallenge::fromArray($payload),
            AotpResponse::fromArray($response),
        );
    }

    public function verifyAotpResponse(
        MfaFactor $factor,
        AotpChallenge $challenge,
        AotpResponse $response,
    ): MfaVerificationResult {
        $config = $this->otpMetadata($factor);
        $result = new AOTP(
            $this->requiredString($config, 'public_key'),
            $this->requiredString($config, 'audience'),
        )->verifyWithResult(
            $this->stateCache,
            $factor->id,
            $challenge,
            $response,
        );

        return $this->verification($factor, $result);
    }

    public function verifyGrid(MfaFactor $factor, MfaChallenge $challenge, string $response): MfaVerificationResult
    {
        $payload = ValueNormalizer::associativeArray($challenge->metadata['grid_challenge'] ?? null);
        if ($payload === []) {
            return $this->invalid($factor, 'mfa_factor_invalid_configuration');
        }

        return $this->verifyGridResponse($factor, GridChallenge::fromArray($payload), $response);
    }

    public function verifyGridResponse(
        MfaFactor $factor,
        GridChallenge $challenge,
        string $response,
    ): MfaVerificationResult {
        return $this->verification(
            $factor,
            $this->grid($factor)->verifyWithResult($factor->id, $challenge, $response),
        );
    }

    public function verifyLegacyMobile(MfaFactor $factor, string $code): MfaVerificationResult
    {
        $config = $this->otpMetadata($factor);
        if (($config['legacy'] ?? null) !== true) {
            return $this->invalid($factor, 'mfa_factor_invalid_configuration');
        }

        $result = new MobileOTP(
            $this->requiredString($config, 'secret'),
            $this->requiredString($config, 'pin'),
        )->verifyWithWindow(
            $code,
            window: new VerificationWindow(
                $this->integer($config, 'past_windows', 1),
                $this->integer($config, 'future_windows', 1),
            ),
            offsetSteps: $this->integer($config, 'offset_steps', 0),
            cache: $this->stateCache,
            factorId: hash(
                'sha3-256',
                "foundation:otp-mobile:v1\0" . $factor->id . "\0" . $this->requiredString($config, 'secret'),
            ),
        );

        return $this->verification($factor, $result);
    }

    private function grid(MfaFactor $factor): GridOTP
    {
        $config = $this->otpMetadata($factor);

        return new GridOTP(
            $this->stateCache,
            $this->requiredString($config, 'secret'),
            $this->integer($config, 'challenge_size', 6),
            $this->integer($config, 'ttl', 120),
            $this->integer($config, 'max_attempts', 3),
        );
    }

    /** @param array<string, mixed> $config */
    private function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/D', $value) === 1)) {
            throw new \InvalidArgumentException(sprintf('OTP factor option "%s" must be an integer.', $key));
        }

        return (int) $value;
    }

    private function invalid(MfaFactor $factor, string $reason): MfaVerificationResult
    {
        return new MfaVerificationResult(false, factorId: $factor->id, reason: $reason);
    }

    /** @return array<string, mixed> */
    private function otpMetadata(MfaFactor $factor): array
    {
        $config = ValueNormalizer::associativeArray($factor->metadata['otp'] ?? null);
        if ($config === []) {
            throw new \InvalidArgumentException('OTP factors require nested otp metadata.');
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('OTP factor option "%s" must be a non-empty string.', $key));
        }

        return trim($value);
    }

    private function verification(MfaFactor $factor, VerificationResult $result): MfaVerificationResult
    {
        return new MfaVerificationResult(
            verified: $result->matched,
            factorId: $factor->id,
            reason: $result->matched
                ? null
                : ($result->replayDetected ? 'mfa_code_replayed' : 'mfa_code_invalid'),
            context: [
                'otp_reason' => $result->reason->value,
                'drift_offset' => $result->driftOffset,
                'matched_counter' => $result->matchedCounter,
                'matched_timestep' => $result->matchedTimestep,
                'replay_detected' => $result->replayDetected,
            ],
        );
    }
}
