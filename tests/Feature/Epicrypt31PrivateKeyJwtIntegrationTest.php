<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Token\Jwt\AsymmetricJwt;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('authenticates registered private_key_jwt clients and rejects assertion replay', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now);
    $pair = KeyPairGenerator::ec()->generate();
    $algorithm = AsymmetricJwtAlgorithm::ES256;
    $keyId = 'client-assertion-key';
    $jwk = new Jwks()->exportPublicKeyToJwk($pair['public'], $keyId, $algorithm);
    $tokenEndpoint = 'https://issuer.example.test/oauth/token';
    $audience = 'https://service-api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['service.read'],
            [$audience],
            ['assertion_jwks' => ['keys' => [$jwk]]],
            OAuthClientAuthenticationMethod::PrivateKeyJwt,
        );
        expect($registration->secret)->toBeNull();

        $claims = JwtClaims::issue(
            issuer: $registration->client->clientId,
            subject: $registration->client->clientId,
            audiences: [$tokenEndpoint],
            ttlSeconds: 120,
            clock: new EpicryptClockAdapter($fixture->clock),
        );
        $assertion = AsymmetricJwt::issuer(
            $pair['private'],
            'JWT',
            $keyId,
            $algorithm,
            clock: new EpicryptClockAdapter($fixture->clock),
        )->issue($claims);
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::PrivateKeyJwt,
            $registration->client->clientId,
            assertion: $assertion,
        );

        $response = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'service.read',
        ], $authentication);
        expect($fixture->accessTokens->verify($response->accessToken, $audience)->clientId)
            ->toBe($registration->client->clientId);

        try {
            $fixture->tokens->exchange([
                'grant_type' => OAuthGrantType::ClientCredentials->value,
                'scope' => 'service.read',
            ], $authentication);
            throw new RuntimeException('Expected private_key_jwt replay rejection.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_client');
        }
    } finally {
        $fixture->close();
    }
});
