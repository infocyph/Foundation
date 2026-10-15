<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenStatus;
use Infocyph\Foundation\Auth\Contract\Storage\RefreshTokenStoreInterface;
use Infocyph\Foundation\Auth\Support\InMemoryEmailVerificationStore;
use Infocyph\Foundation\Auth\Support\InMemoryPasswordResetStore;
use Infocyph\Foundation\Foundation;

it('atomically consumes a password reset once and restores it when account mutation fails', function (): void {
    $store = new InMemoryPasswordResetStore();
    $now = time();
    $store->save(new PasswordResetRequest('reset', 'account', $now, $now + 300));
    $count = 0;

    expect($store->consumeAndApply('reset', static function () use (&$count): void {
        $count++;
    }))->toBeTrue()
        ->and($store->consumeAndApply('reset', static function (): void {}))->toBeFalse()
        ->and($count)->toBe(1);

    $store->save(new PasswordResetRequest('retry', 'account', $now, $now + 300));
    expect(fn() => $store->consumeAndApply('retry', static function (): void {
        throw new RuntimeException('account persistence failed');
    }))->toThrow(RuntimeException::class, 'account persistence failed')
        ->and($store->wasConsumed('retry'))->toBeFalse()
        ->and($store->consumeAndApply('retry', static function (): void {}))->toBeTrue();
});

it('prevents duplicate email verification consumption and rolls back failed mutation', function (): void {
    $store = new InMemoryEmailVerificationStore();
    $now = time();
    $store->save(new EmailVerificationRequest('email', 'account', 'a@example.test', $now, $now + 300));

    expect(fn() => $store->consumeAndApply('email', static function (): void {
        throw new RuntimeException('account update failed');
    }))->toThrow(RuntimeException::class)
        ->and($store->find('email')?->isConsumed())->toBeFalse()
        ->and($store->consumeAndApply('email', static function (): void {}))->toBeTrue()
        ->and($store->consumeAndApply('email', static function (): void {}))->toBeFalse();
});

it('rejects application refresh replay after an atomic rotation', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $tokens = $app->make(AuthServices::class)->tokens();
        $issued = $tokens->issueRefreshToken('account');
        $old = $issued->refreshToken;
        expect($old)->not->toBeNull();
        if ($old === null) {
            return;
        }

        $first = $tokens->rotateRefreshToken($old);
        expect($first->successful())->toBeTrue();
        $stored = $app->make(RefreshTokenStoreInterface::class)->find($old->id);
        expect($stored?->rotatedAt)->not->toBeNull()
            ->and($tokens->rotateRefreshToken($old)->failed())->toBeTrue()
            ->and($tokens->rotateRefreshToken($stored ?? $old)->failed())->toBeTrue();
    });
});

it('invalidates a rotated remember-me credential and denies replay', function (): void {
    $app = Foundation::web(['app' => ['base_path' => sys_get_temp_dir()], 'router' => ['files' => []]]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $remember = $app->make(AuthServices::class)->rememberMe();
        $issued = $remember->issue('account', 'device');
        $old = $issued->record;
        expect($old)->not->toBeNull();
        if ($old === null || $issued->token === null) {
            return;
        }

        $rotated = $remember->rotate($old);
        expect($rotated->status)->toBe(RememberTokenStatus::ROTATED)
            ->and($remember->verify($issued->token->value)->status)->toBe(RememberTokenStatus::REUSED)
            ->and($remember->rotate($old)->status)->toBe(RememberTokenStatus::REUSED);
    });
});
