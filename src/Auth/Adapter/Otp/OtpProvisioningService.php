<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;

/**
 * Translate Foundation enrollment policy into native OTP enrollment payloads
 * and canonical MFA factor metadata.
 */
final readonly class OtpProvisioningService
{
    public function __construct(
        private string $issuer = 'Foundation',
        private string $algorithm = 'sha1',
        private int $digits = 6,
        private int $period = 30,
        private int $secretBytes = 20,
        private int $hotpLookAhead = 5,
    ) {}

    /**
     * Backward-compatible TOTP metadata helper used by application enrollment
     * workflows that already have a generated secret.
     *
     * @return array<string, mixed>
     */
    public function factorMetadata(string $secret, string $label): array
    {
        return $this->totpMetadata($secret, $label);
    }

    /**
     * Default Foundation OTP enrollment is TOTP.
     *
     * @return array{payload: EnrollmentPayload, factor_metadata: array<string, mixed>}
     */
    public function provision(string $accountId, ?string $label = null, bool $withQrSvg = false): array
    {
        return $this->provisionTotp($accountId, $label, $withQrSvg);
    }

    /**
     * @return array{payload: EnrollmentPayload, factor_metadata: array<string, mixed>}
     */
    public function provisionHotp(
        string $accountId,
        ?string $label = null,
        int $counter = 0,
        ?int $lookAhead = null,
        bool $withQrSvg = false,
    ): array {
        $resolvedLabel = $this->label($accountId, $label);
        $secret = HOTP::generateSecret($this->secretBytes);
        $hotp = new HOTP($secret, $this->digits, $this->algorithm);
        $lookAhead ??= $this->hotpLookAhead;

        return [
            'payload' => $hotp->getEnrollmentPayload(
                $resolvedLabel,
                $this->issuer,
                $counter,
                [],
                $withQrSvg,
            ),
            'factor_metadata' => [
                'otp' => [
                    'algorithm' => $this->algorithm,
                    'counter' => $counter,
                    'digits' => $this->digits,
                    'issuer' => $this->issuer,
                    'label' => $resolvedLabel,
                    'look_ahead' => $lookAhead,
                    'secret' => $secret,
                ],
            ],
        ];
    }

    /**
     * OCRA suites define their own algorithm/digit policy. The generated secret
     * is stored as canonical Base32 so application persistence never contains
     * raw binary key material.
     *
     * @return array{payload: EnrollmentPayload, factor_metadata: array<string, mixed>}
     */
    public function provisionOcra(
        string $accountId,
        string $suite,
        ?string $label = null,
        int $counter = 0,
        bool $withQrSvg = false,
    ): array {
        $resolvedLabel = $this->label($accountId, $label);
        $secret = OCRA::generateSecret($this->secretBytes);
        $ocra = OCRA::fromBase32($suite, $secret);
        $otp = [
            'issuer' => $this->issuer,
            'label' => $resolvedLabel,
            'secret' => $secret,
            'suite' => $suite,
        ];
        if ($ocra->getSuite()->counterEnabled) {
            $otp['counter'] = $counter;
        }

        return [
            'payload' => $ocra->getEnrollmentPayload(
                $resolvedLabel,
                $this->issuer,
                [],
                $withQrSvg,
            ),
            'factor_metadata' => ['otp' => $otp],
        ];
    }

    /**
     * @return array{payload: EnrollmentPayload, factor_metadata: array<string, mixed>}
     */
    public function provisionTotp(string $accountId, ?string $label = null, bool $withQrSvg = false): array
    {
        $resolvedLabel = $this->label($accountId, $label);
        $secret = TOTP::generateSecret($this->secretBytes);
        $totp = new TOTP($secret, $this->digits, $this->period, $this->algorithm);

        return [
            'payload' => $totp->getEnrollmentPayload(
                $resolvedLabel,
                $this->issuer,
                [],
                $withQrSvg,
            ),
            'factor_metadata' => $this->totpMetadata($secret, $resolvedLabel),
        ];
    }

    private function label(string $accountId, ?string $label): string
    {
        return $label !== null && trim($label) !== '' ? trim($label) : $accountId;
    }

    /** @return array<string, mixed> */
    private function totpMetadata(string $secret, string $label): array
    {
        return [
            'otp' => [
                'algorithm' => $this->algorithm,
                'digits' => $this->digits,
                'issuer' => $this->issuer,
                'label' => $label,
                'period' => $this->period,
                'secret' => $secret,
            ],
        ];
    }
}
