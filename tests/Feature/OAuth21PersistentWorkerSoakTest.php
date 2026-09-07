<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Foundation;

it('does not leak OAuth principal metadata across repeated persistent worker resets', function (): void {
    $app = Foundation::worker(['app' => ['env' => 'testing']]);
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

        $app->execution()->run(static function () use ($app, $principal, $iteration): void {
            $principals = $app->make(CurrentPrincipalContext::class);
            expect($principals->get())->toBeNull();
            $principals->set($principal);

            expect($principals->get()?->metadata())
                ->toHaveKey('oauth_token_id', 'token-' . $iteration)
                ->toHaveKey('oauth_client_id', 'client-' . $iteration);
        });

        $app->execution()->run(static function () use ($app, $iteration): void {
            $principals = $app->make(CurrentPrincipalContext::class);
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
        });
    }
});
