<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Certificate\KeyPairGenerator;
use Infocyph\Epicrypt\Token\Jwt\DpopProof;
use Infocyph\Epicrypt\Token\Jwt\Enum\AsymmetricJwtAlgorithm;
use Infocyph\Epicrypt\Token\Jwt\Jwks;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptClockAdapter;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('binds DPoP access tokens to the proof key and rejects replay or wrong-key resource proofs', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture(now: $now);
    $redirectUri = 'https://dpop-client.example.test/callback';
    $audience = 'https://api.example.test';
    $resourceUri = 'https://api.example.test/orders/42';
    $tokenUri = 'https://issuer.example.test/oauth/token';
    $verifier = str_repeat('d', 64);
    $pair = KeyPairGenerator::ec()->generate();
    $jwk = new Jwks()->exportPublicKeyToJwk(
        $pair['public'],
        'dpop-key',
        AsymmetricJwtAlgorithm::ES256,
    );
    $proofs = new DpopProof(new EpicryptClockAdapter($fixture->clock));

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['orders.read'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'orders.read',
            'audience' => $audience,
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $fixture->consents->grant($principal, $request);
        $code = $fixture->codes->issue($request, $principal);
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        );
        $tokenProof = $proofs->issue(
            'POST',
            $tokenUri,
            $pair['private'],
            $jwk,
            AsymmetricJwtAlgorithm::ES256,
            jwtId: 'dpop-token-proof',
        );
        $tokens = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], $authentication, $tokenProof);
        expect($tokens->tokenType)->toBe('DPoP');

        $resourceProof = $proofs->issue(
            'GET',
            $resourceUri,
            $pair['private'],
            $jwk,
            AsymmetricJwtAlgorithm::ES256,
            accessToken: $tokens->accessToken,
            jwtId: 'dpop-resource-proof',
        );
        $verified = $fixture->accessValidator->verifyResource(
            $tokens->accessToken,
            $audience,
            'GET',
            $resourceUri,
            $resourceProof,
        );
        expect($verified->claims->clientId)->toBe($registration->client->clientId)
            ->and($verified->claims->scopes)->toBe(['orders.read']);

        expect(fn() => $fixture->accessValidator->verifyResource(
            $tokens->accessToken,
            $audience,
            'GET',
            $resourceUri,
            $resourceProof,
        ))->toThrow(OAuthTokenException::class);

        $other = KeyPairGenerator::ec()->generate();
        $otherJwk = new Jwks()->exportPublicKeyToJwk(
            $other['public'],
            'other-dpop-key',
            AsymmetricJwtAlgorithm::ES256,
        );
        $wrongProof = $proofs->issue(
            'GET',
            $resourceUri,
            $other['private'],
            $otherJwk,
            AsymmetricJwtAlgorithm::ES256,
            accessToken: $tokens->accessToken,
            jwtId: 'dpop-wrong-key',
        );
        expect(fn() => $fixture->accessValidator->verifyResource(
            $tokens->accessToken,
            $audience,
            'GET',
            $resourceUri,
            $wrongProof,
        ))->toThrow(OAuthTokenException::class);
    } finally {
        $fixture->close();
    }
});
