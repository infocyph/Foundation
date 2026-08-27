<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthAccessTokenService;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthJwkSetProvider;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenClaims;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;

it('publishes bounded authorization server metadata', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth']['issuer'] = 'https://issuer.example.test';
    $metadata = new AuthorizationServerMetadata(new ConfigRepository($config));

    expect($metadata->toArray())->toMatchArray([
        'issuer' => 'https://issuer.example.test',
        'authorization_endpoint' => 'https://issuer.example.test/oauth/authorize',
        'token_endpoint' => 'https://issuer.example.test/oauth/token',
        'jwks_uri' => 'https://issuer.example.test/.well-known/jwks.json',
        'revocation_endpoint' => 'https://issuer.example.test/oauth/revoke',
        'introspection_endpoint' => 'https://issuer.example.test/oauth/introspect',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'client_credentials', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic'],
        'code_challenge_methods_supported' => ['S256'],
    ]);
});

it('keeps old access tokens verifiable through fallback keys and rejects unknown kid values', function (): void {
    $issuer = 'https://issuer.example.test';
    $audience = 'https://api.example.test';
    $algorithm = AsymmetricJwtAlgorithm::ES256;
    $old = KeyPairGenerator::ec()->generate();
    $new = KeyPairGenerator::ec()->generate();
    $unknown = KeyPairGenerator::ec()->generate();
    $now = time();

    $oldService = new EpicryptOAuthAccessTokenService(oauth21SigningKeySet(
        $issuer,
        'old-key',
        $old['private'],
        [['old-key', $old['public'], KeyStatus::ACTIVE]],
    ));
    $claims = new OAuthAccessTokenClaims(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        expiresAt: $now + 300,
        issuedAt: $now,
        tokenId: 'old-token',
        clientId: 'client-1',
        scopes: ['profile.read'],
        authorizationId: 'authorization-1',
    );
    $oldToken = $oldService->issue($claims);

    $rotatedKeys = oauth21SigningKeySet(
        $issuer,
        'new-key',
        $new['private'],
        [
            ['new-key', $new['public'], KeyStatus::ACTIVE],
            ['old-key', $old['public'], KeyStatus::FALLBACK],
        ],
    );
    $rotatedService = new EpicryptOAuthAccessTokenService($rotatedKeys);
    expect($rotatedService->verify($oldToken, $audience))->toEqual($claims);

    $newClaims = new OAuthAccessTokenClaims(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        expiresAt: $now + 300,
        issuedAt: $now,
        tokenId: 'new-token',
        clientId: 'client-1',
        scopes: ['profile.read'],
        authorizationId: 'authorization-1',
    );
    expect($rotatedService->verify($rotatedService->issue($newClaims), $audience))->toEqual($newClaims);

    $jwks = new EpicryptOAuthJwkSetProvider($rotatedKeys)->jwks();
    $kids = array_column($jwks['keys'], 'kid');
    sort($kids, SORT_STRING);
    expect($kids)->toBe(['new-key', 'old-key']);
    foreach ($jwks['keys'] as $jwk) {
        expect($jwk)->not->toHaveKey('d');
    }

    $unknownToken = AsymmetricJwt::issuer(
        $unknown['private'],
        'at+jwt',
        'unknown-key',
        $algorithm,
    )->issue(JwtClaims::issue(
        issuer: $issuer,
        subject: 'account-1',
        audiences: [$audience],
        ttlSeconds: 300,
        custom: [
            'client_id' => 'client-1',
            'token_use' => OAuthAccessTokenClaims::TOKEN_USE,
            'scope' => 'profile.read',
        ],
    ));
    expect(fn() => $rotatedService->verify($unknownToken, $audience))
        ->toThrow(OAuthTokenException::class);
});

/**
 * @param list<array{0:string,1:string,2:KeyStatus}> $publicKeys
 */
function oauth21SigningKeySet(string $issuer, string $activeId, string $privateKey, array $publicKeys): OAuthSigningKeySet
{
    $algorithm = AsymmetricJwtAlgorithm::ES256;
    $entries = array_map(
        static fn(array $entry): KeyRingEntry => new KeyRingEntry(
            id: $entry[0],
            key: $entry[1],
            status: $entry[2],
            purpose: KeyPurpose::JWT_SIGNING,
            algorithm: $algorithm->value,
            issuer: $issuer,
        ),
        $publicKeys,
    );

    return new OAuthSigningKeySet(
        issuer: $issuer,
        activeKeyId: $activeId,
        privateKey: $privateKey,
        publicKeys: new KeyRing($entries),
        algorithm: $algorithm,
    );
}
