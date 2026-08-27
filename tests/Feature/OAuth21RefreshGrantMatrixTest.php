<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('supports equal and narrowed refresh scopes, rejects widening, and revokes the family on rotated-token reuse', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://refresh.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('r', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
            [$redirectUri],
            ['profile.read', 'orders.read'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read orders.read',
            'audience' => $audience,
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $fixture->consents->grant($principal, $request);
        $code = $fixture->codes->issue($request, $principal);
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        );
        $initial = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], $authentication);
        expect($initial->refreshToken)->not->toBeNull();

        $equal = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::RefreshToken->value,
            'refresh_token' => $initial->refreshToken,
        ], $authentication);
        expect($equal->scopes)->toBe(['profile.read', 'orders.read'])
            ->and($equal->refreshToken)->not->toBeNull();

        $narrowed = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::RefreshToken->value,
            'refresh_token' => $equal->refreshToken,
            'scope' => 'profile.read',
        ], $authentication);
        expect($narrowed->scopes)->toBe(['profile.read'])
            ->and($narrowed->refreshToken)->not->toBeNull();

        try {
            $fixture->tokens->exchange([
                'grant_type' => OAuthGrantType::RefreshToken->value,
                'refresh_token' => $narrowed->refreshToken,
                'scope' => 'profile.read orders.read',
            ], $authentication);
            throw new RuntimeException('Expected widened refresh scope to be rejected.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_grant');
        }

        try {
            $fixture->tokens->exchange([
                'grant_type' => OAuthGrantType::RefreshToken->value,
                'refresh_token' => $equal->refreshToken,
            ], $authentication);
            throw new RuntimeException('Expected rotated refresh token reuse to be rejected.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_grant');
        }

        $latest = $fixture->refreshStore->findByHash(new OpaqueToken()->hash((string) $narrowed->refreshToken));
        expect($latest)->not->toBeNull()
            ->and($latest?->revokedAt)->not->toBeNull();
    } finally {
        $fixture->close();
    }
});
