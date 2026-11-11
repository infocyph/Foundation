<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpChallengeFactorService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpMfaVerifier;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeService;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Support\InMemoryMfaFactorStore;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Foundation;
use Infocyph\OTP\AOTP;
use Infocyph\OTP\GridOTP;
use Infocyph\OTP\HOTP;
use Infocyph\OTP\RecoveryCodes;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\OTP\MobileOTP;
use Infocyph\OTP\ValueObjects\AotpChallenge;
use Infocyph\OTP\ValueObjects\GridChallenge;

/**
 * @throws \Exception
 * @throws \Infocyph\Foundation\Exception\ConfigurationException
 * @throws \Infocyph\Foundation\Exception\ServiceResolutionException
 * @throws \InvalidArgumentException
 * @throws \LogicException
 * @throws \Psr\Cache\InvalidArgumentException
 */
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

        if (!$factor instanceof MfaFactor || !is_array($factor->metadata['otp'] ?? null)) {
            throw new \UnexpectedValueException('AOTP enrollment must produce a factor with OTP metadata.');
        }
        if (isset($enrollment->context['otp']) && !is_array($enrollment->context['otp'])) {
            throw new \UnexpectedValueException('AOTP enrollment must provide an OTP context.');
        }
        expect($factor)->toBeInstanceOf(MfaFactor::class)
            ->and($factor->metadata['otp']['public_key'] ?? null)->toBe($keys->publicKey)
            ->and($factor->metadata['otp'])->not->toHaveKey('private_key')
            ->and($enrollment->context['otp'] ?? [])->not->toHaveKey('private_key');

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

it('preserves HOTP counters across a cancelled borrowed request and forwards native verification state', function (): void {
    $secret = HOTP::generateSecret();
    $factor = new MfaFactor(
        id: 'factor-hotp-' . bin2hex(random_bytes(6)),
        accountId: 'account-hotp',
        type: MfaFactorType::HOTP->value,
        label: 'HOTP',
        enabled: true,
        createdAt: time(),
        metadata: ['otp' => [
            'secret' => $secret,
            'digits' => 6,
            'counter' => 0,
            'look_ahead' => 0,
            'algorithm' => 'sha1',
        ]],
    );
    $factors = new InMemoryMfaFactorStore();
    $factors->save($factor);
    $verifier = new OtpMfaVerifier(
        $factors,
        new OtpChallengeFactorService(Cache::memory('foundation-hotp-' . bin2hex(random_bytes(8)))),
    );
    $challenge = new MfaChallenge(
        id: 'challenge-hotp',
        accountId: $factor->accountId,
        factorId: $factor->id,
        purpose: 'login',
        issuedAt: time(),
        expiresAt: time() + 90,
    );
    $code = new HOTP($secret, 6, 'sha1')->generate(0);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);

    try {
        CacheRunwire::share(
            $request,
            null,
            static function () use ($verifier, $challenge, $code, $request): void {
                $request->cancel(CancellationReason::HOST_CANCELLED);
                expect(fn() => $verifier->verify($challenge, $code))->toThrow(CancelledException::class);
            },
            $runtime,
        );
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }

    expect($factors->findForAccount($factor->accountId)[0]->metadata['otp']['counter'])->toBe(0)
        ->and($verifier->verify($challenge, $code)->verified)->toBeTrue()
        ->and($factors->findForAccount($factor->accountId)[0]->metadata['otp']['counter'])->toBe(1)
        ->and($verifier->verify($challenge, $code)->verified)->toBeFalse();
});

it('keeps recovery-code issuance and consumption atomic across cancelled borrowed Runwire requests', function (): void {
    $accountId = 'acct-recovery-cancel-' . bin2hex(random_bytes(6));
    $factors = new InMemoryMfaFactorStore();
    $recovery = new OtpRecoveryCodeService(
        new RecoveryCodes(new OtpRecoveryCodeStore($factors), str_repeat('k', 32)),
        defaultCount: 3,
    );

    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);
    try {
        CacheRunwire::share($request, null, static function () use ($accountId, $recovery, $request): void {
            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $recovery->generate($accountId))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }

    expect($factors->findForAccount($accountId))->toBe([]);
    $codes = $recovery->generate($accountId);
    expect($codes)->toHaveCount(3);
    $code = $codes[0];
    $original = $factors->findForAccount($accountId)[0]->metadata['otp_recovery']['hashes'];

    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwire::bind($runtime);
    try {
        CacheRunwire::share($request, null, static function () use ($accountId, $code, $recovery, $request): void {
            $request->cancel(CancellationReason::HOST_CANCELLED);
            expect(fn() => $recovery->verify($accountId, $code))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }

    expect($factors->findForAccount($accountId)[0]->metadata['otp_recovery']['hashes'])->toBe($original)
        ->and($recovery->verify($accountId, $code)->verified)->toBeTrue()
        ->and($factors->findForAccount($accountId)[0]->metadata['otp_recovery']['hashes'])->toHaveCount(2)
        ->and($recovery->verify($accountId, $code)->verified)->toBeFalse();
});

it('rejects recovery consumption when cancellation arrives after state read but before CAS', function (): void {
    $accountId = 'acct-recovery-interleave-' . bin2hex(random_bytes(6));
    $backing = new InMemoryMfaFactorStore();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $store = new class ($backing, $request) implements MfaFactorCompareAndSwapStoreInterface {
        public bool $cancelOnFind = false;

        public int $writes = 0;

        public function __construct(
            private readonly InMemoryMfaFactorStore $backing,
            private readonly RequestContext $request,
        ) {}

        #[\Override]
        public function findForAccount(string $accountId): array
        {
            $found = $this->backing->findForAccount($accountId);
            if ($this->cancelOnFind) {
                $this->cancelOnFind = false;
                $this->request->cancel(CancellationReason::HOST_CANCELLED);
            }

            return $found;
        }

        #[\Override]
        public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool
        {
            $this->writes++;

            return $this->backing->compareAndSwap($expected, $updated);
        }

        #[\Override]
        public function remove(string $factorId): void
        {
            $this->backing->remove($factorId);
        }

        #[\Override]
        public function save(MfaFactor $factor): void
        {
            $this->backing->save($factor);
        }
    };
    $recovery = new OtpRecoveryCodeService(new RecoveryCodes(
        new OtpRecoveryCodeStore($store),
        str_repeat('k', 32),
    ));
    $code = $recovery->generate($accountId)[0];
    $initial = $backing->findForAccount($accountId)[0]->metadata['otp_recovery']['hashes'];
    expect($store->writes)->toBe(1);

    CacheRunwire::bind($runtime);
    try {
        CacheRunwire::share($request, null, static function () use ($accountId, $code, $recovery, $store): void {
            $store->cancelOnFind = true;
            expect(fn() => $recovery->verify($accountId, $code))->toThrow(CancelledException::class);
        }, $runtime);
    } finally {
        $request->complete();
        CacheRunwire::release($runtime);
    }

    expect($store->writes)->toBe(1)
        ->and($backing->findForAccount($accountId)[0]->metadata['otp_recovery']['hashes'])->toBe($initial)
        ->and($recovery->verify($accountId, $code)->verified)->toBeTrue()
        ->and($store->writes)->toBe(2);
});
