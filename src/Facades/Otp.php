<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use DateTimeImmutable;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaVerificationResult;
use Infocyph\Foundation\Auth\Otp\OtpEnrollmentConfirmationResult;
use Infocyph\Foundation\Auth\Otp\OtpEnrollmentResult;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\OTP\Result\StepUpResult;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;
use Infocyph\OTP\ValueObjects\ParsedOtpAuthUri;

final class Otp extends Facade
{
    public static function assessFreshness(
        ?DateTimeImmutable $verifiedAt,
        ?int $seconds = null,
        ?DateTimeImmutable $now = null,
    ): StepUpResult {
        return self::manager()->assessFreshness($verifiedAt, $seconds, $now);
    }

    public static function beginEnrollment(
        string $accountId,
        ?string $label = null,
        bool $withQrSvg = false,
        int $recoveryCodeCount = 10,
    ): OtpEnrollmentResult {
        return self::manager()->beginEnrollment($accountId, $label, $withQrSvg, $recoveryCodeCount);
    }

    public static function buildPayload(
        string $secret,
        string $label,
        ?string $issuer = null,
        bool $withQrSvg = false,
    ): EnrollmentPayload {
        return self::manager()->buildPayload($secret, $label, $issuer, $withQrSvg);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function completeEnrollment(
        string $accountId,
        string $factorId,
        string $code,
        array $context = [],
    ): OtpEnrollmentConfirmationResult {
        return self::manager()->completeEnrollment($accountId, $factorId, $code, $context);
    }

    /**
     * @throws \Exception
     */
    public static function generateSecret(?int $bytes = null): string
    {
        return self::manager()->generateSecret($bytes);
    }

    public static function isValidSecret(string $secret): bool
    {
        return self::manager()->isValidSecret($secret);
    }

    public static function manager(): OtpManager
    {
        return self::app()->otp();
    }

    public static function normalizeSecret(string $secret): string
    {
        return self::manager()->normalizeSecret($secret);
    }

    public static function parseProvisioningUri(string $uri): ParsedOtpAuthUri
    {
        return self::manager()->parseProvisioningUri($uri);
    }

    public static function verifyFactor(MfaFactor $factor, string $code): MfaVerificationResult
    {
        return self::manager()->verifyFactor($factor, $code);
    }
}
