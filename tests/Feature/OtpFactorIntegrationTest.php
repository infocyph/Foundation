<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\AOTP;
use Infocyph\OTP\GridOTP;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\ValueObjects\AotpChallenge;
use Infocyph\OTP\ValueObjects\GridChallenge;

function foundationOtpApp(): \Infocyph\Foundation\Application\Application
{
    $basePath = sys_get_temp_dir() . '/foundation-otp61-' . bin2hex(random_bytes(8));
    mkdir($basePath . '/cache', 0775, true);

    return Foundation::web([
        'app' => ['base_path' => $basePath],
        'paths' => ['cache' => 'cache'],
        'auth' => [
            'drivers' => [
                'mfa' => 'otp',
                'storage' => 'memory',
            ],
            'otp' => [
                'replay' => ['store' => 'auth-state'],
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'stores' => [
                'auth-state' => [
                    'driver' => 'memory',
                    'namespace' => 'foundation-test-otp61-state-' . bin2hex(random_bytes(8)),
                    'fail_open' => false,
                    'security' => [
                        'integrity_key' => 'foundation-test-otp61-state-integrity-key',
                    ],
                ],
            ],
        ],
    ])->boot();
}

it('keeps AOTP private keys device-owned and composes native challenges', function (): void {
    expect(AOTP::isAvailable())->toBeTrue();

    $app = foundationOtpApp();
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $otp = $app->make(OtpManager::class);
        $keys = AOTP::generateKeyPair();
        $enrollment = $otp->enrollAotp(
            accountId: 'acct-aotp-1',
            publicKey: $keys->publicKey,
            audience: 'foundation.test',
            label: 'Device AOTP',
        );
        $factor = $enrollment->factor;

        expect($factor)->toBeInstanceOf(MfaFactor::class)
            ->and($factor?->metadata['otp']['public_key'] ?? null)->toBe($keys->publicKey)
            ->and($factor?->metadata['otp'])->not->toHaveKey('private_key')
            ->and($enrollment->context['otp'] ?? [])->not->toHaveKey('private_key');

        if (!$factor instanceof MfaFactor) {
            throw new RuntimeException('AOTP enrollment did not create a factor.');
        }

        $enrollmentChallenge = $otp->issueAotpEnrollmentChallenge('acct-aotp-1', $factor->id);
        $enrollmentResponse = AOTP::respond(
            $keys->privateKey,
            $enrollmentChallenge,
            $enrollmentChallenge->audience,
            $enrollmentChallenge->context,
        );
        $confirmed = $otp->completeAotpEnrollment(
            'acct-aotp-1',
            $factor->id,
            $enrollmentChallenge,
            $enrollmentResponse,
        );

        expect($confirmed->successful())->toBeTrue();

        $challenge = $otp->issueAotpChallenge(
            'acct-aotp-1',
            $factor->id,
            'approve:transfer:42',
        );
        $payload = $challenge->challenge?->metadata['aotp_challenge'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('AOTP challenge metadata was not emitted.');
        }
        $native = AotpChallenge::fromArray($payload);
        $response = AOTP::respond($keys->privateKey, $native, $native->audience, $native->context);
        $verified = $app->make(\Infocyph\Foundation\Auth\AuthServices::class)
            ->mfa()
            ->verifyChallenge(
                (string) $challenge->challenge?->id,
                json_encode($response->toArray(), JSON_THROW_ON_ERROR),
            );

        expect($verified->successful())->toBeTrue();
    });
});

it('enrolls GridOTP without exposing its secret through generic enrollment context', function (): void {
    $app = foundationOtpApp();
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $otp = $app->make(OtpManager::class);
        $enrollment = $otp->enrollGridOtp('acct-grid-1');
        $factor = $enrollment->factor();

        expect($factor)->toBeInstanceOf(MfaFactor::class)
            ->and($enrollment->secret)->not->toBeEmpty()
            ->and($factor?->metadata['otp']['secret'] ?? null)->toBe($enrollment->secret)
            ->and($enrollment->enrollment->context['otp']['secret'] ?? null)->toBe('[redacted]');

        if (!$factor instanceof MfaFactor) {
            throw new RuntimeException('GridOTP enrollment did not create a factor.');
        }

        $enrollmentChallenge = $otp->issueGridEnrollmentChallenge('acct-grid-1', $factor->id);
        $confirmed = $otp->completeGridEnrollment(
            'acct-grid-1',
            $factor->id,
            $enrollmentChallenge,
            GridOTP::respond($enrollmentChallenge, $enrollment->secret),
        );
        expect($confirmed->successful())->toBeTrue();

        $challenge = $otp->issueGridChallenge('acct-grid-1', $factor->id);
        $payload = $challenge->challenge?->metadata['grid_challenge'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('GridOTP challenge metadata was not emitted.');
        }
        $native = GridChallenge::fromArray($payload);
        $verified = $app->make(\Infocyph\Foundation\Auth\AuthServices::class)
            ->mfa()
            ->verifyChallenge(
                (string) $challenge->challenge?->id,
                GridOTP::respond($native, $enrollment->secret),
            );

        expect($verified->successful())->toBeTrue();
    });
});

it('imports MobileOTP only through the explicit legacy workflow', function (): void {
    $app = foundationOtpApp();
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $otp = $app->make(OtpManager::class);
        $secret = MobileOTP::generateSecret();
        $pin = '1234';
        $enrollment = $otp->importLegacyMobileOtp(
            accountId: 'acct-mobile-1',
            secret: $secret,
            pin: $pin,
        );
        $factor = $enrollment->factor;

        expect($factor)->toBeInstanceOf(MfaFactor::class)
            ->and($factor?->metadata['otp']['legacy'] ?? null)->toBeTrue()
            ->and($enrollment->context['otp']['secret'] ?? null)->toBe('[redacted]')
            ->and($enrollment->context['otp']['pin'] ?? null)->toBe('[redacted]');

        if (!$factor instanceof MfaFactor) {
            throw new RuntimeException('MobileOTP enrollment did not create a factor.');
        }

        $code = new MobileOTP($secret, $pin)->generate();
        $confirmed = $otp->completeEnrollment('acct-mobile-1', $factor->id, $code);

        expect($confirmed->successful())->toBeTrue();
    });
});

it('preserves GridOTP state after cancellation of a borrowed Runwire operation', function (): void {
    $app = foundationOtpApp();
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $otp = $app->make(OtpManager::class);
        $enrollment = $otp->enrollGridOtp('acct-grid-cancelled');
        $factor = $enrollment->factor();
        expect($factor)->toBeInstanceOf(MfaFactor::class);
        if (!$factor instanceof MfaFactor) {
            throw new RuntimeException('GridOTP test factor was not created.');
        }

        $challenge = $otp->issueGridEnrollmentChallenge('acct-grid-cancelled', $factor->id);
        $response = GridOTP::respond($challenge, $enrollment->secret);
        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $request = \Infocyph\Runwire\RequestContext::create($runtime);
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);

        try {
            \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
                $request,
                null,
                static function () use ($request, $otp, $factor, $challenge, $response): void {
                    $request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
                    expect(fn() => $otp->completeGridEnrollment(
                        'acct-grid-cancelled',
                        $factor->id,
                        $challenge,
                        $response,
                    ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
                },
                $runtime,
            );
        } finally {
            $request->complete();
            \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        }

        expect($otp->completeGridEnrollment(
            'acct-grid-cancelled',
            $factor->id,
            $challenge,
            $response,
        )->successful())->toBeTrue();
    });
});
