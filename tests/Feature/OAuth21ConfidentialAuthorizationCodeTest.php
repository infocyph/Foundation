<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('completes the confidential authorization code flow with PKCE S256 and client_secret_basic', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://confidential.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('c', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        expect($registration->secret)->not->toBeNull();

        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $fixture->consents->grant($principal, $request);
        $code = $fixture->codes->issue($request, $principal);
        $response = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $registration->client->clientId,
            $registration->secret,
        ));
        $claims = $fixture->accessTokens->verify($response->accessToken, $audience);

        expect($response->tokenType)->toBe('Bearer')
            ->and($response->refreshToken)->toBeNull()
            ->and($claims->subject)->toBe('account-1')
            ->and($claims->clientId)->toBe($registration->client->clientId)
            ->and($claims->authorizationId)->toBe($code->authorization->id);
    } finally {
        $fixture->close();
    }
});
