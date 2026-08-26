<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('does not leak OAuth principal metadata across repeated persistent worker resets', function (): void {
    $tracker = new RuntimeContextTracker();
    $principals = new CurrentPrincipalContext($tracker);
    $iterations = 1_000;

    for ($iteration = 1; $iteration <= $iterations; $iteration++) {
        $principal = $iteration % 2 === 0
            ? new Principal(
                id: 'oauth-service-' . $iteration,
                type: PrincipalType::SERVICE,
                accountId: null,
                metadata: [
                    'auth_via' => 'oauth_bearer',
                    'oauth_token_id' => 'token-' . $iteration,
                    'oauth_client_id' => 'client-' . $iteration,
                    'oauth_scopes' => ['service.read'],
                    'oauth_audiences' => ['https://api.example.test'],
                ],
            )
            : new Principal(
                id: 'oauth-account-' . $iteration,
                type: PrincipalType::ACCOUNT,
                accountId: 'account-' . $iteration,
                metadata: [
                    'auth_via' => 'oauth_bearer',
                    'oauth_token_id' => 'token-' . $iteration,
                    'oauth_client_id' => 'client-' . $iteration,
                    'oauth_authorization_id' => 'authorization-' . $iteration,
                    'oauth_scopes' => ['profile.read'],
                    'oauth_audiences' => ['https://api.example.test'],
                ],
            );

        $principals->set($principal);

        expect($principals->get()?->metadata())
            ->toHaveKey('oauth_token_id', 'token-' . $iteration)
            ->toHaveKey('oauth_client_id', 'client-' . $iteration);

        $tracker->reset();

        expect($principals->get())->toBeNull();

        $principals->set(new Principal(
            id: 'session-account-' . $iteration,
            type: PrincipalType::ACCOUNT,
            accountId: 'session-account-' . $iteration,
            metadata: [
                'auth_via' => 'session',
                'request_iteration' => $iteration,
            ],
        ));

        expect($principals->get()?->metadata())
            ->toBe([
                'auth_via' => 'session',
                'request_iteration' => $iteration,
            ])
            ->not->toHaveKey('oauth_token_id')
            ->not->toHaveKey('oauth_client_id')
            ->not->toHaveKey('oauth_authorization_id')
            ->not->toHaveKey('oauth_scopes')
            ->not->toHaveKey('oauth_audiences');

        $tracker->reset();
        expect($principals->get())->toBeNull();
    }
});
