<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Token\OAuthClientAuthentication;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientAuthenticationMethod;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('completes the confidential client credentials flow as a service authorization', function (): void {
    $fixture = new OAuth21FlowFixture();
    $audience = 'https://service-api.example.test';

    try {
        $registration = $fixture->clients->register(
            OAuthClientType::Confidential,
            [OAuthGrantType::ClientCredentials],
            [],
            ['service.read'],
            [$audience],
        );
        expect($registration->secret)->not->toBeNull();

        $response = $fixture->tokens->exchange([
            'grant_type' => OAuthGrantType::ClientCredentials->value,
            'scope' => 'service.read',
            'audience' => $audience,
        ], new OAuthClientAuthentication(
            OAuthClientAuthenticationMethod::ClientSecretBasic,
            $registration->client->clientId,
            $registration->secret,
        ));
        $claims = $fixture->accessTokens->verify($response->accessToken, $audience);
        $authorization = $fixture->authorizationStore->find((string) $claims->authorizationId);

        expect($response->tokenType)->toBe('Bearer')
            ->and($response->scopes)->toBe(['service.read'])
            ->and($response->refreshToken)->toBeNull()
            ->and($claims->subject)->toBe('client:' . $registration->client->clientId)
            ->and($claims->clientId)->toBe($registration->client->clientId)
            ->and($claims->scopes)->toBe(['service.read'])
            ->and($authorization)->not->toBeNull()
            ->and($authorization?->accountId)->toBeNull();
    } finally {
        $fixture->close();
    }
});
