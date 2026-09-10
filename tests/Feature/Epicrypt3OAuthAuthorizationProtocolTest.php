<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Exception\OAuthProtocolException;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthClientType;
use Infocyph\Foundation\Auth\OAuth\Value\OAuthGrantType;
use Infocyph\Foundation\Tests\Fixtures\OAuth21FlowFixture;

it('delegates authorization request singleton and audience validation to Epicrypt', function (): void {
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
        ];

        $request = $fixture->requests->validate($base);
        expect($request->audiences)->toBe([$audience]);

        try {
            $fixture->requests->validate([
                ...$base,
                'client_id' => [$registration->client->clientId, $registration->client->clientId],
            ]);
            throw new RuntimeException('Expected duplicate client_id rejection.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_request')
                ->and($exception->redirectAllowed)->toBeFalse();
        }

        try {
            $fixture->requests->validate([
                ...$base,
                'audience' => [$audience, $audience],
            ]);
            throw new RuntimeException('Expected duplicate audience rejection.');
        } catch (OAuthProtocolException $exception) {
            expect($exception->error)->toBe('invalid_request')
                ->and($exception->redirectAllowed)->toBeTrue();
        }
    } finally {
        $fixture->close();
    }
});
