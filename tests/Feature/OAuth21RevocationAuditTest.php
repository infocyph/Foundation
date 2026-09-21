<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Exception\OAuthTokenException;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

it('audits access-token revocation only after authoritative Epicrypt status changes', function (): void {
    $now = 1_700_000_000;
    $capture = new OAuthAuditCapture();
    $fixture = new OAuth21FlowFixture($now, $capture->recorder($now));
    $audience = 'https://api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['profile:read'],
            [$audience],
        );
        $authentication = new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $registration->client->clientId,
            $registration->secret,
        );
        $issued = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'profile:read',
        ], $authentication);

        $capture->events = [];
        $fixture->revocation->revoke($issued->accessToken, $authentication, 'access_token');

        expect(fn() => $fixture->accessTokens->verify($issued->accessToken, $audience))
            ->toThrow(OAuthTokenException::class)
            ->and($capture->events)->toHaveCount(1)
            ->and($capture->events[0]->type)->toBe(AuthEventType::OAUTH_ACCESS_TOKEN_REVOKED)
            ->and($capture->events[0]->metadata)->toMatchArray([
                'client_id' => $registration->client->clientId,
                'result' => 'revoked',
                'token_type' => 'access_token',
            ])
            ->and(json_encode($capture->events[0]->metadata, JSON_THROW_ON_ERROR))
            ->not->toContain($issued->accessToken);
    } finally {
        $fixture->close();
    }
});

it('keeps revocation non-oracular for a token owned by another client', function (): void {
    $now = 1_700_000_000;
    $capture = new OAuthAuditCapture();
    $fixture = new OAuth21FlowFixture($now, $capture->recorder($now));
    $audience = 'https://api.example.test';

    try {
        $owner = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['profile:read'],
            [$audience],
        );
        $other = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['profile:read'],
            [$audience],
        );
        $issued = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'profile:read',
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $owner->client->clientId,
            $owner->secret,
        ));

        $capture->events = [];
        $fixture->revocation->revoke(
            $issued->accessToken,
            new OAuthClientAuthentication(
                OAuthClientAuthenticationMethod::ClientSecretBasic,
                $other->client->clientId,
                $other->secret,
            ),
            'access_token',
        );

        expect($fixture->accessTokens->verify($issued->accessToken, $audience)->clientId)
            ->toBe($owner->client->clientId)
            ->and($capture->events)->toBe([]);
    } finally {
        $fixture->close();
    }
});
