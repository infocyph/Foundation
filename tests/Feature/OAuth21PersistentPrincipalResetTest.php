<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Foundation;

it('clears OAuth account principal metadata at the persistent execution reset boundary', function (): void {
    $app = Foundation::worker(['app' => ['env' => 'testing']]);
    $app->execution()->run(static function () use ($app): void {
        $principals = $app->make(CurrentPrincipalContext::class);
        $principals->set(new Principal(
            id: 'account-1',
            type: PrincipalType::ACCOUNT,
            accountId: 'account-1',
            metadata: [
                'auth_via' => 'oauth_bearer',
                'oauth_token_id' => 'token-1',
                'oauth_client_id' => 'oc_client',
                'oauth_authorization_id' => 'authorization-1',
                'oauth_scopes' => ['profile.read'],
                'oauth_audiences' => ['https://api.example.test'],
                'oauth_expires_at' => time() + 300,
            ],
        ));

        expect($principals->get()?->metadata())->toHaveKey('oauth_token_id', 'token-1');
    });

    $app->execution()->run(static function () use ($app): void {
        $principals = $app->make(CurrentPrincipalContext::class);
        expect($principals->get())->toBeNull();

        $principals->set(new Principal(
            'next-account',
            accountId: 'next-account',
            metadata: ['auth_via' => 'session'],
        ));
        expect($principals->get()?->metadata())
            ->toBe(['auth_via' => 'session'])
            ->not->toHaveKey('oauth_token_id')
            ->not->toHaveKey('oauth_client_id')
            ->not->toHaveKey('oauth_scopes');
    });
});

it('clears OAuth service principal metadata at the same reset boundary', function (): void {
    $app = Foundation::worker(['app' => ['env' => 'testing']]);
    $app->execution()->run(static function () use ($app): void {
        $principals = $app->make(CurrentPrincipalContext::class);
        $principals->set(new Principal(
            id: 'oc_service',
            type: PrincipalType::SERVICE,
            accountId: null,
            metadata: [
                'auth_via' => 'oauth_bearer',
                'oauth_token_id' => 'service-token',
                'oauth_client_id' => 'oc_service',
                'oauth_scopes' => ['service.read'],
                'oauth_audiences' => ['https://api.example.test'],
            ],
        ));

        expect($principals->get()?->type())->toBe(PrincipalType::SERVICE);
    });
    $app->execution()->run(static function () use ($app): void {
        expect($app->make(CurrentPrincipalContext::class)->get())->toBeNull();
    });
});
