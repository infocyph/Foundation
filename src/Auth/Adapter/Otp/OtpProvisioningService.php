<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\OTP\TOTP;
use Infocyph\OTP\ValueObjects\EnrollmentPayload;

final readonly class OtpProvisioningService
{
    public function __construct(
        private string $issuer = 'Foundation',
        private string $algorithm = 'sha1',
        private int $digits = 6,
        private int $period = 30,
        private int $secretBytes = 64,
    ) {}

    /**
     * @return array{payload: EnrollmentPayload, factor_metadata: array<string, mixed>}
     */
    public function provision(string $accountId, ?string $label = null, bool $withQrSvg = false): array
    {
        $resolvedLabel = $label !== null && $label !== ''
            ? $label
            : $accountId;

        $secret = TOTP::generateSecret($this->secretBytes);
        $totp = $this->totp($secret);

        return [
            'payload' => $totp->getEnrollmentPayload(
                $resolvedLabel,
                $this->issuer,
                ['algorithm', 'digits', 'period'],
                [],
                $withQrSvg,
            ),
            'factor_metadata' => $this->factorMetadata($secret, $resolvedLabel),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function factorMetadata(string $secret, string $label): array
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

    private function totp(string $secret): TOTP
    {
        return (new TOTP($secret, $this->digits, $this->period))
            ->setAlgorithm($this->algorithm);
    }
}
