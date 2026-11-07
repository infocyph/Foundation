<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Certificate\Enum\OpenSslCurveName;
use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Exception\SigningKeyReadinessException;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAccessTokenService;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

it('round trips RFC 9068 OAuth access-token claims through the current signing key set contract', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $tokens = oauth21AccessTokenService($keys, $issuer);
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

    $token = $tokens->issue($claims);
    $verified = $tokens->verify($token, $audience);

    expect($verified)->toEqual($claims)
        ->and($verified->tokenUse)->toBe(OAuthAccessTokenClaims::TOKEN_USE)
        ->and(fn() => $tokens->verify($token, 'https://other-api.example.test'))
        ->toThrow(OAuthTokenException::class);
});

it('rejects an at+jwt token that lacks the Foundation OAuth profile discriminator', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $service = oauth21AccessTokenService($keys, $issuer);
    $token = AsymmetricJwt::issuer(
        $keys['private'],
        'at+jwt',
        'oauth-key-1',
        AsymmetricJwtAlgorithm::ES256,
    )->issue(JwtClaims::issue(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        ttlSeconds: 300,
        custom: [
            'client_id' => 'client-1',
            'scope' => 'profile.read',
        ],
    ));

    expect(fn() => $service->verify($token, $audience))->toThrow(OAuthTokenException::class);
});

it('rejects a JWT with a non OAuth access-token type before claim resolution', function (): void {
    $keys = KeyPairGenerator::ec()->generate();
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $service = oauth21AccessTokenService($keys, $issuer);
    $token = AsymmetricJwt::issuer(
        $keys['private'],
        'application+jwt',
        'oauth-key-1',
        AsymmetricJwtAlgorithm::ES256,
    )->issue(JwtClaims::issue(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        ttlSeconds: 300,
        custom: [
            'client_id' => 'client-1',
            'token_use' => OAuthAccessTokenClaims::TOKEN_USE,
        ],
    ));

    expect(fn() => $service->verify($token, $audience))->toThrow(OAuthTokenException::class);
});

it('issues and verifies OAuth access tokens only with their exact JOSE EC curve', function (OpenSslCurveName $curve, AsymmetricJwtAlgorithm $algorithm): void {
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $keys = KeyPairGenerator::ec($curve)->generate();
    $service = oauth21AccessTokenService($keys, $issuer, $algorithm);
    $now = time();
    $claims = new OAuthAccessTokenClaims(
        issuer: $issuer,
        subject: 'account-curve-check',
        audiences: [$audience],
        expiresAt: $now + 180,
        issuedAt: $now,
        tokenId: 'token-' . $algorithm->value,
        clientId: 'client-curve-check',
        scopes: ['profile.read'],
        authorizationId: 'authorization-curve-check',
    );

    expect($service->verify($service->issue($claims), $audience))->toEqual($claims);
})->with([
    'ES256 P-256' => [OpenSslCurveName::PRIME256V1, AsymmetricJwtAlgorithm::ES256],
    'ES384 P-384' => [OpenSslCurveName::SECP384R1, AsymmetricJwtAlgorithm::ES384],
    'ES512 P-521' => [OpenSslCurveName::SECP521R1, AsymmetricJwtAlgorithm::ES512],
]);

it('rejects OAuth signing keys whose EC curve does not match the selected JOSE algorithm', function (OpenSslCurveName $curve, AsymmetricJwtAlgorithm $algorithm): void {
    $keys = KeyPairGenerator::ec($curve)->generate();

    expect(fn() => oauth21AccessTokenService($keys, 'https://issuer.example.test', $algorithm))
        ->toThrow(SigningKeyReadinessException::class);
})->with([
    'P-256 used for ES384' => [OpenSslCurveName::PRIME256V1, AsymmetricJwtAlgorithm::ES384],
    'P-256 used for ES512' => [OpenSslCurveName::PRIME256V1, AsymmetricJwtAlgorithm::ES512],
    'P-384 used for ES256' => [OpenSslCurveName::SECP384R1, AsymmetricJwtAlgorithm::ES256],
    'P-384 used for ES512' => [OpenSslCurveName::SECP384R1, AsymmetricJwtAlgorithm::ES512],
    'P-521 used for ES256' => [OpenSslCurveName::SECP521R1, AsymmetricJwtAlgorithm::ES256],
    'P-521 used for ES384' => [OpenSslCurveName::SECP521R1, AsymmetricJwtAlgorithm::ES384],
]);

/** @param array{private:string,public:string} $keys */
function oauth21AccessTokenService(array $keys, string $issuer, AsymmetricJwtAlgorithm $algorithm = AsymmetricJwtAlgorithm::ES256): EpicryptOAuthAccessTokenService
{
    $keyId = 'oauth-key-1';
    $ring = new KeyRing([
        new KeyRingEntry(
            id: $keyId,
            key: $keys['public'],
            status: KeyStatus::ACTIVE,
            purpose: KeyPurpose::OAUTH_ACCESS_TOKEN_SIGNING,
            algorithm: $algorithm->value,
            issuer: $issuer,
        ),
    ]);

    return new EpicryptOAuthAccessTokenService(new OAuthSigningKeySet(
        issuer: $issuer,
        activeKeyId: $keyId,
        privateKey: $keys['private'],
        publicKeys: $ring,
        algorithm: $algorithm,
    ));
}
