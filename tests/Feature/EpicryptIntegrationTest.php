<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Foundation;

it('uses Epicrypt for configured Foundation password security', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'passwords' => 'security',
            ],
        ],
    ]);

    $services = $app->make(AuthServices::class);
    $hash = $services->passwordHasher()->hash('MyStrongPassword!2026');
    $verification = $app->make(PasswordVerifierInterface::class)->verify('MyStrongPassword!2026', $hash);

    expect($hash)->not->toBe('')
        ->and($services->passwordHasher())->toBe($app->make(PasswordHasherInterface::class))
        ->and($verification->verified)->toBeTrue();
});

it('uses Epicrypt for configured Foundation token security', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'tokens' => 'security',
            ],
            'token_secret' => str_repeat('k', 64),
        ],
        'security' => [
            'jwt' => [
                'issuer' => 'foundation-test',
                'audience' => 'foundation-test-api',
                'maximum_lifetime_seconds' => 3600,
                'leeway_seconds' => 0,
            ],
        ],
    ]);

    $services = $app->make(AuthServices::class);
    $now = time();
    $issued = $services->tokens()->issueAccessToken(new AccessTokenClaims(
        subjectId: 'account-1',
        actorId: null,
        issuedAt: $now,
        expiresAt: $now + 300,
        scopes: ['profile.read'],
    ));

    expect($services->tokens())->toBe($app->make(AccessTokenServiceInterface::class))
        ->and($issued->token)->not->toBeNull()
        ->and($services->tokens()->verifyAccessToken($issued->token ?? '')->successful())->toBeTrue();
});
