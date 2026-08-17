<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\TOTP;

it('exposes the OTP enrollment lifecycle through Foundation auth services', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-otp-' . uniqid('', true);
    mkdir($basePath . '/cache', 0775, true);

    $app = Foundation::web([
        'app' => [
            'base_path' => $basePath,
        ],
        'paths' => [
            'cache' => 'cache',
        ],
        'auth' => [
            'drivers' => [
                'mfa' => 'otp',
                'storage' => 'memory',
            ],
            'token_secret' => str_repeat('r', 64),
            'otp' => [
                'issuer' => 'Infbyte Test',
                'replay' => [
                    'store' => 'auth-state',
                ],
                'totp' => [
                    'algorithm' => 'sha256',
                    'digits' => 6,
                    'period' => 30,
                    'secret_bytes' => 32,
                    'window' => 1,
                ],
                'recovery_codes' => [
                    'count' => 8,
                    'length' => 12,
                ],
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'stores' => [
                'auth-state' => [
                    'driver' => 'memory',
                    'namespace' => 'foundation-test-otp-state',
                    'fail_open' => false,
                    'security' => [
                        'integrity_key' => 'foundation-test-otp-state-integrity-key',
                    ],
                ],
            ],
        ],
    ])->boot();

    $otp = $app->make(OtpManager::class);
    $enrollment = $otp->beginEnrollment(
        accountId: 'acct-otp-1',
        label: 'ada@example.com',
        withQrSvg: true,
        recoveryCodeCount: 8,
    );

    $factor = $enrollment->factor();
    if (!$factor instanceof MfaFactor) {
        throw new \RuntimeException('OTP enrollment did not create a factor.');
    }

    expect($enrollment->successful())->toBeTrue()
        ->and($factor->enabled)->toBeFalse()
        ->and($enrollment->payload->issuer)->toBe('Infbyte Test')
        ->and($enrollment->payload->label)->toBe('ada@example.com')
        ->and($enrollment->payload->qrSvg)->not->toBeNull()
        ->and($enrollment->recoveryCodes())->toHaveCount(8)
        ->and($enrollment->factorMetadata['otp']['secret'] ?? null)->toBe($enrollment->payload->secret);

    $code = new TOTP(
        $enrollment->payload->secret,
        6,
        30,
        'sha256',
    )->generate();

    $confirmation = $otp->completeEnrollment('acct-otp-1', $factor->id, $code);

    expect($confirmation->successful())->toBeTrue()
        ->and($confirmation->factor?->enabled)->toBeTrue()
        ->and($confirmation->verification?->verified)->toBeTrue();
});
