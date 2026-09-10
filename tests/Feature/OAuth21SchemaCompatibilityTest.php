<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptRevisionSchema;
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
            'auth_oauth_authorization_code_states',
            'auth_oauth_consents',
            'auth_oauth_authorizations',
            'auth_oauth_refresh_tokens',
            'auth_oauth_access_revocations',
        ]);
});

it('installs and rolls back OAuth revisions independently from the base auth schema', function (): void {
    DB::purge();
    $connection = DB::addConnection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $tables = new AuthTables();
    $oauth = new AuthOAuthRevisionSchema($tables);
    $epicrypt = new AuthOAuthEpicryptRevisionSchema($tables);
    $runner = new MigrationRunner($connection, [$oauth, $epicrypt]);
    $schema = new SchemaManager($connection);

    try {
        expect($runner->run())->toBe([$oauth->id(), $epicrypt->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }

        expect($runner->reset(true))->toBe([$epicrypt->id(), $oauth->id()]);

        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeFalse();
        }
    } finally {
        DB::purge();
    }
});
