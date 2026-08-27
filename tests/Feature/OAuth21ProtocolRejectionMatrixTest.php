<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('rejects invalid clients redirects responses PKCE scopes and grants', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://client.example.test/callback';
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
        $base = [
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
        ];

        expect(oauth21ProtocolError(fn() => $fixture->requests->validate([
            ...$base,
            'client_id' => 'oc_unknown',
        ]))->error)->toBe('unauthorized_client')
            ->and(oauth21ProtocolError(fn() => $fixture->requests->validate([
                ...$base,
                'redirect_uri' => $redirectUri . '?next=https://evil.example.test',
            ]))->error)->toBe('invalid_request')
            ->and(oauth21ProtocolError(fn() => $fixture->requests->validate([
                ...$base,
                'response_type' => 'token',
            ]))->error)->toBe('unsupported_response_type')
            ->and(oauth21ProtocolError(fn() => $fixture->requests->validate([
                ...$base,
                'code_challenge_method' => 'plain',
            ]))->error)->toBe('invalid_request')
            ->and(oauth21ProtocolError(fn() => $fixture->requests->validate([
                ...$base,
                'code_challenge' => 'short',
            ]))->error)->toBe('invalid_request')
            ->and(oauth21ProtocolError(fn() => $fixture->requests->validate([
                ...$base,
                'scope' => 'profile.write',
            ]))->error)->toBe('invalid_scope')
            ->and(oauth21ProtocolError(fn() => $fixture->tokens->exchange([
                'grant_type' => 'password',
            ], new OAuthClientAuthentication(
                OAuthClientAuthenticationMethod::None,
                $registration->client->clientId,
            )))->error)->toBe('unsupported_grant_type');

        $fixture->clients->setEnabled($registration->client->clientId, false);
        expect(oauth21ProtocolError(fn() => $fixture->requests->validate($base))->error)
            ->toBe('unauthorized_client');
    } finally {
        $fixture->close();
    }
});

it('rejects client authentication downgrade client mismatch PKCE failure and code replay', function (): void {
    $fixture = new OAuth21FlowFixture();
    $redirectUri = 'https://client.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('v', 64);

    try {
        $public = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        $other = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode],
            ['https://other.example.test/callback'],
            ['profile.read'],
            [$audience],
        );
        $confidential = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['profile.read'],
            [$audience],
        );

        expect(oauth21ProtocolError(fn() => $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'profile.read',
            'audience' => $audience,
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $confidential->client->clientId,
        )))->error)->toBe('invalid_client')
            ->and(oauth21ProtocolError(fn() => $fixture->tokens->exchange([
                'grant_type' => OAuthGrantType::AuthorizationCode->value,
                'code' => 'not-a-code',
                'redirect_uri' => $redirectUri,
                'code_verifier' => $verifier,
            ], new OAuthClientAuthentication(
                OAuthClientAuthenticationMethod::ClientSecretBasic,
                $public->client->clientId,
                'downgrade-secret',
            )))->error)->toBe('invalid_client');

        $request = $fixture->requests->validate([
            'client_id' => $public->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => OAuth21FlowFixture::pkceChallenge($verifier),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
        ]);
        $issue = $fixture->codes->issue($request, new Principal('account-1', accountId: 'account-1'));
        $parameters = [
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $issue->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ];

        expect(oauth21ProtocolError(fn() => $fixture->tokens->exchange([
            ...$parameters,
            'code_verifier' => str_repeat('x', 64),
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::None,
            $public->client->clientId,
        )))->error)->toBe('invalid_grant')
            ->and(oauth21ProtocolError(fn() => $fixture->tokens->exchange(
                $parameters,
                new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $other->client->clientId),
            ))->error)->toBe('invalid_grant');

        $fixture->tokens->exchange(
            $parameters,
            new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $public->client->clientId),
        );

        expect(oauth21ProtocolError(fn() => $fixture->tokens->exchange(
            $parameters,
            new OAuthClientAuthentication(OAuthClientAuthenticationMethod::None, $public->client->clientId),
        ))->error)->toBe('invalid_grant');
    } finally {
        $fixture->close();
    }
});

/** @param callable(): mixed $operation */
function oauth21ProtocolError(callable $operation): OAuthProtocolException
{
    try {
        $operation();
    } catch (OAuthProtocolException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an OAuth protocol rejection.');
}
