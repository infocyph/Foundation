<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;

it('keeps OAuth tables outside the released base auth table set', function (): void {
    $tables = new AuthTables();

    expect(array_intersect($tables->all(), $tables->oauth()))->toBe([])
        ->and($tables->oauth())->toBe([
            'auth_oauth_clients',
            'auth_oauth_redirect_uris',
            'auth_oauth_client_scopes',
            'auth_oauth_authorization_codes',
            'auth_oauth_consents',
            'auth_oauth_authorizations',
            'auth_oauth_refresh_tokens',
            'auth_oauth_access_revocations',
        ]);
});

it('installs and rolls back the additive OAuth auth revision independently', function (): void {
    DB::purge();
    $connection = DB::addConnection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $tables = new AuthTables();
    $revision = new AuthOAuthRevisionSchema($tables);
    $runner = new MigrationRunner($connection, [$revision]);
    $schema = new SchemaManager($connection);

    try {
        expect($revision->id())->toBe('20260826000000_foundation_auth_oauth')
            ->and($runner->run())->toBe([$revision->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }

        expect($runner->reset(true))->toBe([$revision->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeFalse();
        }
    } finally {
        DB::purge();
    }
});
