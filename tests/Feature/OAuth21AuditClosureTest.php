<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Contract\JwkSetProviderInterface;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Metadata\AuthorizationServerMetadata;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthIntrospectionManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRevocationManager;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

it('audits authorization denial revocation invalid redirect and invalid scope at manager boundaries', function (): void {
    $now = 1_700_000_000;
    $fixture = new OAuth21FlowFixture($now);
    $redirectUri = 'https://client.example.test/callback';
    $audience = 'https://api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
            [OAuthGrantType::AuthorizationCode, OAuthGrantType::RefreshToken],
            [$redirectUri],
            ['profile.read'],
            [$audience],
        );
        $request = $fixture->requests->validate([
            'client_id' => $registration->client->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'scope' => 'profile.read',
            'audience' => $audience,
        ]);
        $principal = new Principal('account-1', accountId: 'account-1');
        $fixture->consents->grant($principal, $request);
        $capture = new OAuthAuditCapture();
        $manager = new OAuthManager(
            $fixture->requests,
            $fixture->consents,
            $fixture->codes,
            $fixture->tokens,
            oauth21AuditStub(OAuthRevocationManager::class),
            oauth21AuditStub(OAuthIntrospectionManager::class),
            oauth21AuditStub(AuthorizationServerMetadata::class),
            new class implements JwkSetProviderInterface {
                public function jwks(): array
                {
                    return ['keys' => []];
                }
            },
            $fixture->clients,
            $capture->recorder($now),
        );

        $manager->deny($request, $principal);
        expect($manager->revokeConsent($principal, $registration->client->clientId))->toBe(1);

        try {
            $manager->authorizationRedirectContext([
                'client_id' => $registration->client->clientId,
                'redirect_uri' => 'https://attacker.example.test/callback',
            ]);
            throw new RuntimeException('Expected invalid redirect rejection.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_request');
        }

        try {
            $manager->validateAuthorizationRequest([
                'client_id' => $registration->client->clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'code_challenge' => str_repeat('A', 43),
                'code_challenge_method' => 'S256',
                'scope' => 'admin.write',
                'audience' => $audience,
            ]);
            throw new RuntimeException('Expected invalid scope rejection.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_scope');
        }

        expect(array_map(static fn($event): AuthEventType => $event->type, $capture->events))->toBe([
            AuthEventType::OAUTH_AUTHORIZATION_DENIED,
            AuthEventType::OAUTH_AUTHORIZATION_REVOKED,
            AuthEventType::OAUTH_INVALID_REQUEST,
            AuthEventType::OAUTH_INVALID_REQUEST,
        ])->and($capture->events[2]->metadata)->toMatchArray([
            'error' => 'invalid_request',
            'reason' => 'redirect_validation',
        ])->and($capture->events[3]->metadata)->toMatchArray([
            'error' => 'invalid_scope',
            'reason' => 'scope_validation',
        ]);
    } finally {
        $fixture->close();
    }
});

it('audits active Epicrypt refresh rotation revocation and reuse without recording raw refresh tokens', function (): void {
    $now = 1_700_000_000;
    $capture = new OAuthAuditCapture();
    $fixture = new OAuth21FlowFixture($now, $capture->recorder($now));
    $redirectUri = 'https://refresh.example.test/callback';
    $audience = 'https://api.example.test';
    $verifier = str_repeat('r', 64);

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Public,
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
            OAuthClientAuthenticationMethod::None,
            $registration->client->clientId,
        );

        $issued = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::AuthorizationCode->value,
            'code' => $code->code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ], $authentication);
        $rotated = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::RefreshToken->value,
            'refresh_token' => $issued->refreshToken,
        ], $authentication);

        expect(fn() => $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::RefreshToken->value,
            'refresh_token' => $issued->refreshToken,
        ], $authentication))->toThrow(OAuthProtocolException::class);

        $fixture->revocation->revoke(
            (string) $rotated->refreshToken,
            $authentication,
            'refresh_token',
        );

        $types = array_map(static fn($event): AuthEventType => $event->type, $capture->events);
        expect($types)->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_ROTATED)
            ->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_REVOKED)
            ->toContain(AuthEventType::OAUTH_REFRESH_TOKEN_REUSE);

        foreach ($capture->events as $event) {
            $encoded = json_encode($event->metadata, JSON_THROW_ON_ERROR);
            expect($encoded)
                ->not->toContain((string) $issued->refreshToken)
                ->not->toContain((string) $rotated->refreshToken);
        }
    } finally {
        $fixture->close();
    }
});

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function oauth21AuditStub(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}
