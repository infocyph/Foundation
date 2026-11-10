<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwire;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Support\InMemoryMfaFactorStore;
use Infocyph\Foundation\Foundation;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Runtime\Http\RuntimeRequestContext;

it('preserves recovery-code tombstones and winner-only CAS across competing consumers', function (): void {
    $factors = new InMemoryMfaFactorStore();
    $racing = new class($factors) implements MfaFactorCompareAndSwapStoreInterface {
        public ?Closure $beforeUpdate = null;

        public function __construct(private InMemoryMfaFactorStore $factors) {}

        #[\Override]
        public function compareAndSwap(?MfaFactor $expected, MfaFactor $updated): bool
        {
            if ($expected !== null && $this->beforeUpdate !== null) {
                $action = $this->beforeUpdate;
                $this->beforeUpdate = null;
                $action();
            }

            return $this->factors->compareAndSwap($expected, $updated);
        }

        #[\Override]
        public function findForAccount(string $accountId): array
        {
            return $this->factors->findForAccount($accountId);
        }

        #[\Override]
        public function remove(string $factorId): void
        {
            $this->factors->remove($factorId);
        }

        #[\Override]
        public function save(MfaFactor $factor): void
        {
            $this->factors->save($factor);
        }
    };

    $codes = new OtpRecoveryCodeStore($racing);
    $issuedAt = new DateTimeImmutable('@1700000000');
    $first = hash('sha256', 'recovery-first');
    $second = hash('sha256', 'recovery-second');
    $replacement = hash('sha256', 'replacement-generation');
    expect($codes->replace('account:alpha', [$first, $second], $issuedAt))->toMatchArray([
        'total' => 2, 'remaining' => 2,
    ]);
    expect($codes->replace('account:beta', [$first], $issuedAt))->toMatchArray([
        'total' => 1, 'remaining' => 1,
    ]);

    $racing->beforeUpdate = static function () use ($codes, $first, $issuedAt): void {
        expect($codes->consume('account:alpha', $first, $issuedAt))->toMatchArray([
            'consumed' => true, 'total' => 2, 'remaining' => 1,
        ]);
    };
    // The interleaved winner commits first. The stale loser must retry, see
    // the consumed digest and report failure without losing the other code.
    expect($codes->consume('account:alpha', $first, $issuedAt))->toMatchArray([
        'consumed' => false, 'total' => 2, 'remaining' => 1,
    ]);
    expect($codes->consume('account:alpha', $second, $issuedAt))->toMatchArray([
        'consumed' => true, 'total' => 2, 'remaining' => 0,
    ]);
    expect($codes->consume('account:alpha', $second, $issuedAt))->toMatchArray([
        'consumed' => false, 'total' => 2, 'remaining' => 0,
    ]);
    expect($codes->metadata('account:alpha'))->toMatchArray(['total' => 2, 'remaining' => 0])
        ->and($factors->findForAccount('alpha'))->toHaveCount(1)
        ->and($codes->metadata('account:beta'))->toMatchArray(['total' => 1, 'remaining' => 1]);

    $codes->replace('account:alpha', [$replacement], $issuedAt);
    expect($codes->consume('account:alpha', $first, $issuedAt)['consumed'])->toBeFalse()
        ->and($codes->consume('account:alpha', $second, $issuedAt)['consumed'])->toBeFalse()
        ->and($codes->consume('account:beta', $first, $issuedAt)['consumed'])->toBeTrue();

    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    try {
        $committed = CacheRunwire::share(
            $request,
            null,
            static function () use ($codes, $replacement, $issuedAt, $request): array {
                $result = $codes->consume('account:alpha', $replacement, $issuedAt);
                $request->cancel(CancellationReason::HOST_CANCELLED);

                return $result;
            },
            $runtime,
        );
        expect($committed)->toMatchArray(['consumed' => true, 'remaining' => 0]);
    } finally {
        $request->complete();
    }

    expect($codes->consume('account:alpha', $replacement, $issuedAt))->toMatchArray([
        'consumed' => false, 'total' => 1, 'remaining' => 0,
    ]);
    expect($codes->metadata('account:alpha'))->toMatchArray(['total' => 1, 'remaining' => 0])
        ->and(CacheRunwire::current())->toBeNull();
});

it('preserves single-use recovery-code semantics through the selected Foundation MFA service', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => ['mfa' => 'otp', 'storage' => 'memory'],
            'otp' => ['replay' => ['store' => 'recovery-state']],
        ],
        'cache' => [
            'default' => 'recovery-state',
            'stores' => [
                'recovery-state' => [
                    'driver' => 'memory',
                    'namespace' => 'foundation-recovery-' . bin2hex(random_bytes(8)),
                    'fail_open' => false,
                    'security' => ['integrity_key' => 'foundation-recovery-test-key'],
                ],
            ],
        ],
    ])->boot();

    $app->container()->withinScope(RuntimeRequestContext::REQUEST_SCOPE, static function () use ($app): void {
        $mfa = $app->make(AuthServices::class)->mfa();
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $enrolled = $mfa->enrollFactor(
            'recovery-owner',
            MfaFactorType::HOTP,
            'Primary token',
            ['otp' => ['secret' => $secret, 'counter' => 0, 'digits' => 6]],
            enabled: true,
            recoveryCodeCount: 2,
        );
        expect($enrolled->successful())->toBeTrue()
            ->and($enrolled->recoveryCodes)->toHaveCount(2);

        [$first, $second] = $enrolled->recoveryCodes;
        expect($mfa->verifyRecoveryCode('recovery-owner', $first)->successful())->toBeTrue()
            ->and($mfa->verifyRecoveryCode('recovery-owner', $first)->successful())->toBeFalse()
            ->and($mfa->verifyRecoveryCode('another-account', $second)->successful())->toBeFalse()
            ->and($mfa->verifyRecoveryCode('recovery-owner', $second)->successful())->toBeTrue()
            ->and($mfa->verifyRecoveryCode('recovery-owner', $second)->successful())->toBeFalse();

        $regenerated = $mfa->enrollFactor(
            'recovery-owner',
            MfaFactorType::HOTP,
            'Replacement token',
            ['otp' => ['secret' => $secret, 'counter' => 0, 'digits' => 6]],
            enabled: true,
            recoveryCodeCount: 1,
        );
        expect($regenerated->recoveryCodes)->toHaveCount(1)
            ->and($mfa->verifyRecoveryCode('recovery-owner', $first)->successful())->toBeFalse()
            ->and($mfa->verifyRecoveryCode('recovery-owner', $regenerated->recoveryCodes[0])->successful())->toBeTrue();
    });
});
