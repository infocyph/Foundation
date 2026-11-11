<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\WebAuthn\WebAuthnConfig;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Mfa\MfaFactorStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\OCRA;
use Infocyph\OTP\TOTP;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;

it('supports secure HOTP and OCRA MFA workflows', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'mfa' => 'otp',
                'storage' => 'memory',
            ],
            'otp' => [
                'replay' => [
                    'store' => 'auth-state',
                ],
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'stores' => [
                'auth-state' => [
                    'driver' => 'memory',
                    'namespace' => 'foundation-test-auth-state',
                    'fail_open' => false,
                    'security' => [
                        'integrity_key' => 'foundation-test-auth-state-integrity-key',
                    ],
                ],
            ],
        ],
    ])->boot();

    $app->container()->withinScope(RuntimeRequestContext::REQUEST_SCOPE, static function () use ($app): void {
            $mfa = $app->make(AuthServices::class)->mfa();
        $hotpSecret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $hotp = new HOTP($hotpSecret);
        $hotpEnrollment = $mfa->enrollFactor(
            'account-hotp',
            MfaFactorType::HOTP,
            'Hardware token',
            ['otp' => ['algorithm' => 'sha1', 'counter' => 0, 'digits' => 6, 'secret' => $hotpSecret]],
            enabled: true,
        );

        $hotpFactorId = $hotpEnrollment->factor?->id;
        expect($hotpFactorId)->toBeString()->not->toBeEmpty();

        $firstChallenge = $mfa->issueChallenge('account-hotp', factorId: $hotpFactorId);
        $firstVerification = $mfa->verifyChallenge((string) $firstChallenge->challenge?->id, $hotp->generate(0));
        expect($firstVerification->successful())->toBeTrue();
        $stepUp = $app->make(AuthServices::class)->stepUp();
        $now = time();
        $session = new \Infocyph\Foundation\Auth\Authentication\Session\AuthSession(
            'verified-hotp-session', 'account-hotp', null, $now, $now, $now + 300, $now,
        );
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'mfa']))->toBeTrue();
        $stepUp->markSatisfied($session->accountId, $session->id, 'payment', \Infocyph\Foundation\Auth\Authentication\StepUp\StepUpMethod::MFA);
        expect($stepUp->requiresStepUp($session, 'payment', ['method' => 'mfa']))->toBeFalse()
            ->and($stepUp->requiresStepUp($session, 'payment', ['method' => 'passkey']))->toBeTrue()
            ->and($stepUp->requiresStepUp($session, 'other-payment', ['method' => 'mfa']))->toBeTrue();
        $replayedChallenge = $mfa->issueChallenge('account-hotp', factorId: $hotpFactorId);
        $replayedVerification = $mfa->verifyChallenge((string) $replayedChallenge->challenge?->id, $hotp->generate(0));
        $secondVerification = $mfa->verifyChallenge((string) $replayedChallenge->challenge?->id, $hotp->generate(1));

        expect($firstVerification->successful())->toBeTrue()
            ->and($replayedVerification->successful())->toBeFalse()
            ->and($replayedVerification->code)->toBe('mfa_code_invalid')
            ->and($secondVerification->successful())->toBeTrue();

        $totpSecret = TOTP::generateSecret(20);
        $totp = new TOTP($totpSecret);
        $totpFactor = $mfa->enrollFactor(
            'account-canonical-totp',
            MfaFactorType::TOTP,
            'Canonical TOTP',
            ['otp' => ['secret' => $totpSecret, 'digits' => 6, 'period' => 30]],
            enabled: true,
        )->factor;
        $totpChallenge = $mfa->issueChallenge('account-canonical-totp', factorId: $totpFactor?->id);
        expect($mfa->verifyChallenge((string) $totpChallenge->challenge?->id, $totp->generate())->successful())
            ->toBeTrue();

        // Invalid characters and noncanonical unused trailing bits must fail closed.
        $invalidBase32 = [$totpSecret . chr(36), str_repeat('A', 25) . 'B'];
        foreach ($invalidBase32 as $index => $malformedSecret) {
            expect(fn() => new TOTP($malformedSecret))->toThrow(InvalidArgumentException::class);
            $account = 'account-invalid-totp-' . $index;
            $badFactor = $mfa->enrollFactor(
                $account,
                MfaFactorType::TOTP,
                'Invalid Base32 TOTP',
                ['otp' => ['secret' => $malformedSecret]],
                enabled: true,
            )->factor;
            $badChallenge = $mfa->issueChallenge($account, factorId: $badFactor?->id);
            $failure = $mfa->verifyChallenge((string) $badChallenge->challenge?->id, '000000');
            expect($failure->successful())->toBeFalse()
                ->and($failure->code)->toBe('mfa_factor_invalid_configuration');
        }

        $ocraSecret = OCRA::generateSecret(20);
        $ocra = OCRA::fromBase32('OCRA-1:HOTP-SHA1-6:QN08', $ocraSecret);
        $ocraEnrollment = $mfa->enrollFactor(
            'account-ocra',
            MfaFactorType::OCRA,
            'Challenge token',
            ['otp' => ['secret' => $ocraSecret, 'suite' => 'OCRA-1:HOTP-SHA1-6:QN08']],
            enabled: true,
        );
        $ocraFactorId = $ocraEnrollment->factor?->id;
        $challengeValue = '12345678';
        $ocraChallenge = $mfa->issueChallenge(
            'account-ocra',
            factorId: $ocraFactorId,
            context: ['ocra_challenge' => $challengeValue],
        );
        $ocraVerification = $mfa->verifyChallenge((string) $ocraChallenge->challenge?->id, $ocra->generate($challengeValue));
        $replayedOcraChallenge = $mfa->issueChallenge(
            'account-ocra',
            factorId: $ocraFactorId,
            context: ['ocra_challenge' => $challengeValue],
        );
        $replayedOcraVerification = $mfa->verifyChallenge((string) $replayedOcraChallenge->challenge?->id, $ocra->generate($challengeValue));

        expect($ocraVerification->successful())->toBeTrue()
            ->and($replayedOcraVerification->successful())->toBeFalse()
            ->and($replayedOcraVerification->code)->toBe('mfa_code_replayed');
    });
});


it('rejects host-cancelled native HOTP mutation and retains the unconsumed counter for ordinary execution', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => ['mfa' => 'otp', 'storage' => 'memory'],
            'otp' => ['replay' => ['store' => 'otp-cancellation-state']],
        ],
        'cache' => [
            'default' => 'otp-cancellation-state',
            'stores' => [
                'otp-cancellation-state' => [
                    'driver' => 'memory',
                    'namespace' => 'foundation-otp-cancel-' . bin2hex(random_bytes(8)),
                    'fail_open' => false,
                    'security' => ['integrity_key' => 'foundation-otp-cancellation-test-key'],
                ],
            ],
        ],
    ])->boot();

    $app->container()->withinScope(RuntimeRequestContext::REQUEST_SCOPE, static function () use ($app): void {
        $mfa = $app->make(AuthServices::class)->mfa();
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $factor = $mfa->enrollFactor(
            'account-cancelled-hotp',
            MfaFactorType::HOTP,
            'Cancelled native HOTP',
            ['otp' => ['secret' => $secret, 'counter' => 0, 'digits' => 6]],
            enabled: true,
        )->factor;
        if ($factor === null) {
            throw new \UnexpectedValueException('Expected native HOTP enrollment factor.');
        }

        $challenge = $mfa->issueChallenge('account-cancelled-hotp', factorId: $factor->id)->challenge;
        if ($challenge === null) {
            throw new \UnexpectedValueException('Expected native HOTP challenge.');
        }

        $verifier = $app->make(OtpMfaVerifier::class);
        $store = $app->make(MfaFactorStoreInterface::class);
        $code = new HOTP($secret)->generate(0);
        $runtime = RuntimeContext::standalone();
        $request = RequestContext::create($runtime);

        try {
            CacheRunwire::share($request, null, static function () use ($verifier, $challenge, $request, $code): void {
                expect(CacheRunwire::current()?->request)->toBe($request);
                $request->cancel(CancellationReason::HOST_CANCELLED);

                expect(fn() => $verifier->verify($challenge, $code))->toThrow(CancelledException::class);
            }, $runtime);
        } finally {
            $request->complete();
        }

        $before = $store->findForAccount('account-cancelled-hotp')[0] ?? null;
        if ($before === null || !is_array($before->metadata['otp'] ?? null)) {
            throw new \UnexpectedValueException('Cancelled HOTP verification must preserve a factor record.');
        }
        expect($before?->revision)->toBe(0)
            ->and($before?->metadata['otp']['counter'] ?? null)->toBe(0)
            ->and(CacheRunwire::current())->toBeNull();

        expect($verifier->verify($challenge, $code)->verified)->toBeTrue();
        $after = $store->findForAccount('account-cancelled-hotp')[0] ?? null;
        expect($after?->revision)->toBe(1)
            ->and($after?->metadata['otp']['counter'] ?? null)->toBe(1)
            ->and($verifier->verify($challenge, $code)->verified)->toBeFalse();

        // A late host cancellation must not retroactively negate a committed
        // native factor CAS. Later attempted reuse remains rejected.
        $committedFactor = $mfa->enrollFactor(
            'account-committed-hotp',
            MfaFactorType::HOTP,
            'Committed native HOTP',
            ['otp' => ['secret' => $secret, 'counter' => 0, 'digits' => 6]],
            enabled: true,
        )->factor;
        expect($committedFactor)->not->toBeNull();
        $committedChallenge = $mfa->issueChallenge('account-committed-hotp', factorId: $committedFactor->id)->challenge;
        expect($committedChallenge)->not->toBeNull();

        $committedRuntime = RuntimeContext::standalone();
        $committedRequest = RequestContext::create($committedRuntime);

        try {
            $committed = CacheRunwire::share(
                $committedRequest,
                null,
                static function () use ($verifier, $committedChallenge, $committedRequest, $code): bool {
                    $result = $verifier->verify($committedChallenge, $code);
                    $committedRequest->cancel(CancellationReason::HOST_CANCELLED);

                    return $result->verified;
                },
                $committedRuntime,
            );
            expect($committed)->toBeTrue();
        } finally {
            $committedRequest->complete();
        }

        $persisted = $store->findForAccount('account-committed-hotp')[0] ?? null;
        expect($persisted?->revision)->toBe(1)
            ->and($persisted?->metadata['otp']['counter'] ?? null)->toBe(1)
            ->and($verifier->verify($committedChallenge, $code)->verified)->toBeFalse()
            ->and(CacheRunwire::current())->toBeNull();
    });
});

it('keeps WebAuthn policy limited to OTP passkey inputs', function (): void {
    $config = WebAuthnConfig::fromArray([
        'rp_id' => 'example.test',
        'origin' => 'https://example.test',
        'challenge_ttl' => 240,
        'allow_subdomains' => true,
    ]);

    expect($config->rpId)->toBe('example.test')
        ->and($config->origin)->toBe('https://example.test')
        ->and($config->challengeTtl)->toBe(240)
        ->and($config->allowSubdomains)->toBeTrue();
});
