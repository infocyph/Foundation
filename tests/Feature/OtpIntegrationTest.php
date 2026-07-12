<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Facades\Auth;
use Infocyph\Foundation\Facades\Otp;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\TOTP;

it('exposes otp enrollment lifecycle and helpers through foundation', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-otp-' . uniqid('', true);
    mkdir($basePath . '/cache', 0775, true);

    $app = Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'paths' => [
            'cache' => 'cache',
        ],
        'auth' => [
            'drivers' => [
                'mfa' => 'simple',
            ],
            'otp' => [
                'issuer' => 'Infbyte Test',
                'freshness_window' => 120,
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
    ])->boot();

    $enrollment = $app->otp()->beginEnrollment(
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
        ->and($factor)->toBeInstanceOf(MfaFactor::class)
        ->and($factor->enabled)->toBeFalse()
        ->and($enrollment->payload->issuer)->toBe('Infbyte Test')
        ->and($enrollment->payload->label)->toBe('ada@example.com')
        ->and($enrollment->payload->qrSvg)->not->toBeNull()
        ->and($enrollment->recoveryCodes())->toHaveCount(8)
        ->and($enrollment->factorMetadata['otp']['secret'] ?? null)->toBe($enrollment->payload->secret);

    $code = (new TOTP($enrollment->payload->secret, 6, 30))
        ->setAlgorithm('sha256')
        ->getOTP();

    $verification = Otp::verifyFactor($factor, $code);
    $confirmation = Auth::otp()->completeEnrollment('acct-otp-1', $factor->id, $code);
    $parsed = $app->otp()->parseProvisioningUri($enrollment->payload->uri);
    $fresh = $app->otp()->assessFreshness(new \DateTimeImmutable());
    $stale = $app->otp()->assessFreshness(
        (new \DateTimeImmutable())->modify('-5 minutes'),
        now: new \DateTimeImmutable(),
    );

    expect($verification->verified)->toBeTrue()
        ->and($confirmation->successful())->toBeTrue()
        ->and($confirmation->factor?->enabled)->toBeTrue()
        ->and($parsed->issuer)->toBe('Infbyte Test')
        ->and($parsed->label)->toBe('ada@example.com')
        ->and($fresh->isFresh())->toBeTrue()
        ->and($stale->requiresFreshOtp)->toBeTrue();
});
