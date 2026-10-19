<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\StepUp\StepUpManager;
use Infocyph\Foundation\Auth\Authentication\StepUp\StepUpMethod;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Exception\SessionException;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Support\ArrayTtlStore;
use Infocyph\Foundation\Foundation;

it('requires exact step-up methods, rejects invalid policy and expired sessions', function (): void {
    $clock = new class implements ClockInterface {
        public int $time = 1700000000;
        public function now(): int { return $this->time; }
    };
    $stepUp = new StepUpManager(new ArrayTtlStore($clock), $clock);
    $session = new AuthSession('session', 'owner', null, $clock->time - 10, $clock->time, $clock->time + 100, $clock->time);

    expect($stepUp->requiresStepUp($session, 'approve'))->toBeFalse()
        ->and($stepUp->requiresStepUp($session, 'approve', ['method' => 'mfa']))->toBeTrue()
        ->and($stepUp->requiresStepUp($session, 'approve', ['method' => 'passkey']))->toBeTrue()
        ->and($stepUp->requiresStepUp($session, 'approve', ['method' => 'password']))->toBeTrue();

    $stepUp->markSatisfied('owner', 'session', 'approve', StepUpMethod::MFA, 30);
    expect($stepUp->requiresStepUp($session, 'approve', ['method' => 'mfa']))->toBeFalse()
        ->and($stepUp->requiresStepUp($session, 'approve', ['method' => 'password']))->toBeTrue()
        ->and($stepUp->requiresStepUp($session, 'other', ['method' => 'mfa']))->toBeTrue();

    $clock->time += 31;
    expect($stepUp->requiresStepUp($session, 'approve', ['method' => 'mfa']))->toBeTrue()
        ->and(fn() => $stepUp->evaluate($session, 'approve', ['method' => 'unknown']))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $stepUp->evaluate($session, 'approve', ['max_age_seconds' => -1]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $stepUp->markSatisfied('owner', 'session', 'approve', ttlSeconds: 0))->toThrow(InvalidArgumentException::class);

    $clock->time += 100;
    expect($stepUp->requiresStepUp($session, 'approve'))->toBeTrue();
});

it('rejects expired session rotation and still rotates live sessions', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $sessions = $app->make(AuthServices::class)->sessions();
        $store = $app->make(SessionStoreInterface::class);
        $now = time();
        $expired = new AuthSession('expired-session', 'owner', null, $now - 500, $now - 400, $now - 1);
        $store->create($expired);

        expect(fn() => $sessions->rotate($expired->id))->toThrow(SessionException::class)
            ->and($store->find($expired->id))->toBe($expired);

        $live = $sessions->create('owner');
        $rotated = $sessions->rotate($live->id);
        expect($store->find($live->id))->toBeNull()
            ->and($store->find($rotated->id))->not->toBeNull();
    });
});

it('binds passkey revocation to account ownership and uses credential revision CAS', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $store = $app->make(PasskeyCredentialCompareAndSwapStoreInterface::class);
        $manager = $app->make(AuthServices::class)->passkeys();
        $one = new PasskeyCredential('stored-one', 'owner', 'credential-one', null, 'key', 0, [], time());
        $two = new PasskeyCredential('stored-two', 'owner', 'credential-two', null, 'key', 0, [], time());

        expect($store->compareAndSwap(null, $one))->toBeTrue()
            ->and($store->compareAndSwap(null, $two))->toBeTrue()
            ->and(fn() => $manager->revokeCredential('other', 'credential-one'))->toThrow(InvalidArgumentException::class)
            ->and($store->findByCredentialId('credential-one'))->not->toBeNull();

        $manager->revokeCredential('owner', 'credential-one');
        $manager->revokeCredential('owner', 'stored-two');
        expect($store->findByCredentialId('credential-one'))->toBeNull()
            ->and($store->findByCredentialId('credential-two'))->toBeNull()
            ->and(fn() => $manager->revokeCredential('other', 'stored-two'))->toThrow(InvalidArgumentException::class);
    });
});
