<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Auth\OAuth\OAuthManager;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;

it('keeps OAuth fully inert when disabled', function (): void {
    DB::purge();
    $root = dirname(__DIR__, 2);
    $app = Foundation::web([
        'base_path' => $root,
        'database' => [
            'default' => 'oauth-disabled',
            'connections' => [
                'oauth-disabled' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'auth' => [
            'oauth' => [
                'enabled' => false,
                'issuer' => 'not-a-valid-oauth-issuer',
                'signing' => [
                    'algorithm' => 'RS256',
                    'active_key_id' => 'missing-key',
                    'private_key' => '/definitely/missing/oauth-private.pem',
                    'public_keys' => [
                        [
                            'id' => 'missing-key',
                            'key' => '/definitely/missing/oauth-public.pem',
                        ],
                    ],
                ],
            ],
        ],
    ])->boot();

    try {
        expect($app->config()->get('auth.oauth.enabled'))->toBeFalse()
            ->and($app->config()->get('auth.http.principal_resolvers'))->toBe([
                'session',
                'bearer',
                'remember',
            ])
            ->and($app->has(BearerTokenPrincipalResolver::class))->toBeTrue()
            ->and($app->has(RequestPrincipalResolver::class))->toBeTrue()
            ->and($app->has(OAuthManager::class))->toBeFalse()
            ->and($app->has(OAuthSigningKeySet::class))->toBeFalse();

        $installer = $app->make(AuthSchemaInstaller::class);
        $installer->install();
        $tables = $app->make(AuthTables::class);
        $schema = new SchemaManager($app->make(DBLayerFactory::class)->connection());

        expect($installer->readiness()['installed'])->toBeTrue();
        foreach ($tables->oauth() as $oauthTable) {
            expect($schema->hasTable($oauthTable))->toBeFalse();
        }
    } finally {
        DB::purge();
    }
});
