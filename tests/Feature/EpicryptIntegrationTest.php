<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Security\SecurityManager;

it('uses Epicrypt for configured Foundation password security', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'passwords' => 'security',
            ],
        ],
    ]);

    $services = $app->auth();
    $hash = $services->passwordHasher()->hash('MyStrongPassword!2026');
    $verification = $services->passwordVerifier()->verify('MyStrongPassword!2026', $hash);

    expect($hash)->not->toBe('')
        ->and($verification->verified)->toBeTrue();
});

it('uses Epicrypt for configured Foundation token security', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'tokens' => 'security',
            ],
            'token_secret' => str_repeat('k', 32),
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

    $services = $app->auth();
    $now = time();
    $issued = $services->tokens()->issueAccessToken(new AccessTokenClaims(
        subjectId: 'account-1',
        actorId: null,
        issuedAt: $now,
        expiresAt: $now + 300,
        scopes: ['profile.read'],
    ));

    expect($issued->token)->not->toBeNull()
        ->and($services->tokens()->verifyAccessToken($issued->token ?? '')->successful())->toBeTrue();
});

it('keeps the Foundation security manager focused on auth security policy', function (): void {
    $app = Foundation::web();
    $app->auth();
    $security = $app->make(SecurityManager::class);

    expect($security->passwordHasher())->toBe($app->auth()->passwordHasher())
        ->and($security->passwordVerifier())->toBe($app->auth()->passwordVerifier())
        ->and($security->accessTokens())->toBeObject()
        ->and($security->refreshTokens())->toBeObject();
});
