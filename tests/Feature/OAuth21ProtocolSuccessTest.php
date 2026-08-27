<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('completes the public authorization code flow with PKCE S256', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://public.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('v', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        expect($registration->secret)->toBeNull();

        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
            'state' => 'opaque-state',
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $consent = $fixture->consents->grant($principal, $request);
        $code = $fixture->codes->issue($request, $principal);

        expect($consent->active())->toBeTrue()
            ->and($request->state)->toBe('opaque-state')
            ->and($code->code)->not->toBe('');

        $response = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        ));
        $claims = $fixture->accessTokens->verify($response->accessToken, $audience);

        expect($response->tokenType)->toBe('Bearer')
            ->and($response->scopes)->toBe(['profile.read'])
            ->and($response->refreshToken)->toBeNull()
            ->and($claims->subject)->toBe('account-1')
            ->and($claims->clientId)->toBe($registration->client->clientId)
            ->and($claims->scopes)->toBe(['profile.read'])
            ->and($claims->audiences)->toBe([$audience])
            ->and($claims->authorizationId)->toBe($code->authorization->id);
    } finally {
        $fixture->close();
    }
});
