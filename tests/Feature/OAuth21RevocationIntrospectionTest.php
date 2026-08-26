<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Opaque\OpaqueToken;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthAccessRevocationStore;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('transitions access and refresh introspection from active to inactive through durable revocation', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://introspection.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('i', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
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
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $registration->client->clientId,
            $registration->secret,
        );
        $issued = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], $authentication);
        expect($issued->refreshToken)->not->toBeNull();

        $accessRevocations = new DBLayerOAuthAccessRevocationStore($fixture->factory, $fixture->tables);
        $validator = new OAuthAccessTokenValidator(
            $fixture->accessTokens,
            $fixture->clients,
            $fixture->authorizationStore,
            $accessRevocations,
            $fixture->scopes,
            $fixture->accounts,
            $fixture->clock,
        );
        $introspection = new OAuthIntrospectionManager(
            $fixture->clients,
            $validator,
            $fixture->refreshStore,
            $fixture->authorizationStore,
            $fixture->scopes,
            $fixture->accounts,
            $fixture->clock,
            new OpaqueToken(),
        );
        $revocation = new OAuthRevocationManager(
            $fixture->clients,
            $fixture->accessTokens,
            $accessRevocations,
            $fixture->refreshTokens,
            $fixture->clock,
        );

        $activeAccess = $introspection->introspect($issued->accessToken, $authentication);
        $activeRefresh = $introspection->introspect((string) $issued->refreshToken, $authentication);
        expect($activeAccess->active)->toBeTrue()
            ->and($activeAccess->tokenType)->toBe('Bearer')
            ->and($activeRefresh->active)->toBeTrue()
            ->and($activeRefresh->tokenType)->toBe('refresh_token');

        $revocation->revoke($issued->accessToken, $authentication, 'access_token');
        expect($introspection->introspect($issued->accessToken, $authentication)->active)->toBeFalse();

        $revocation->revoke((string) $issued->refreshToken, $authentication, 'refresh_token');
        expect($introspection->introspect((string) $issued->refreshToken, $authentication)->active)->toBeFalse();
    } finally {
        $fixture->close();
    }
});
