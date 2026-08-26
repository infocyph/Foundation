<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtPolicy;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAccessTokenService;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;

it('round trips distinct RFC 9068 OAuth access-token claims through Epicrypt', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $tokens = new EpicryptOAuthAccessTokenService(
        AsymmetricJwt::issuer($keys['private'], 'at+jwt', keyId: 'oauth-key-1'),
        AsymmetricJwt::verifier($keys['public'], JwtPolicy::oauthAccessToken($issuer, $audience)),
    );
    $now = time();
    $claims = new OAuthAccessTokenClaims(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        expiresAt: $now + 300,
        issuedAt: $now,
        tokenId: 'oauth-token-1',
        clientId: 'client-1',
        scopes: ['profile.read', 'orders.read'],
        authorizationId: 'authorization-1',
    );

    $verified = $tokens->verify($tokens->issue($claims));

    expect($verified)->toEqual($claims)
        ->and($verified->tokenUse)->toBe(OAuthAccessTokenClaims::TOKEN_USE);
});

it('rejects an at+jwt token that lacks the Foundation OAuth profile discriminator', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $service = new EpicryptOAuthAccessTokenService(
        AsymmetricJwt::issuer($keys['private'], 'at+jwt', keyId: 'oauth-key-1'),
        AsymmetricJwt::verifier($keys['public'], JwtPolicy::oauthAccessToken($issuer, $audience)),
    );
    $token = AsymmetricJwt::issuer($keys['private'], 'at+jwt', keyId: 'oauth-key-1')->issue(
        JwtClaims::issue(
            issuer: $issuer,
            subject: 'account-1',
            audiences: [$audience],
            ttlSeconds: 300,
            custom: [
                'client_id' => 'client-1',
                'scope' => ['profile.read'],
            ],
        ),
    );

    expect(fn() => $service->verify($token))->toThrow(OAuthTokenException::class);
});

it('rejects a JWT with a non OAuth access-token type before claim resolution', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $service = new EpicryptOAuthAccessTokenService(
        AsymmetricJwt::issuer($keys['private'], 'at+jwt', keyId: 'oauth-key-1'),
        AsymmetricJwt::verifier($keys['public'], JwtPolicy::oauthAccessToken($issuer, $audience)),
    );
    $token = AsymmetricJwt::issuer($keys['private'], 'application+jwt')->issue(
        JwtClaims::issue(
            issuer: $issuer,
            subject: 'account-1',
            audiences: [$audience],
            ttlSeconds: 300,
            custom: [
                'client_id' => 'client-1',
                'token_use' => OAuthAccessTokenClaims::TOKEN_USE,
            ],
        ),
    );

    expect(fn() => $service->verify($token))->toThrow(OAuthTokenException::class);
});
