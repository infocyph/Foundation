<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('upgrades an installed Foundation 2.0 auth schema to OAuth 2.1 without disturbing existing auth state', function (): void {
    DB::purge();
    $config = new ConfigRepository([
        'database' => [
            'default' => 'oauth-upgrade',
            'connections' => [
                'oauth-upgrade' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
    $connection = $factory->connection();
    $tables = new AuthTables();
    $base = new AuthSchema($tables);
    $mfa = new AuthMfaRevisionSchema($tables);
    $oauth = new AuthOAuthRevisionSchema($tables);
    $releasedRunner = new MigrationRunner($connection, [$base, $mfa]);
    $installer = new AuthSchemaInstaller($factory, $base, $mfa, $tables, $oauth, true);
    $now = time();

    try {
        expect($releasedRunner->run())->toBe([$base->id(), $mfa->id()]);

        $connection->table($tables->accounts())->insert([
            'id' => 'account-2-0',
            'identifier' => 'upgrade@example.test',
            'status' => 'active',
            'password_hash' => 'existing-password-hash',
            'metadata' => json_encode(['version' => '2.0'], JSON_THROW_ON_ERROR),
        ]);
        $connection->table($tables->sessions())->insert([
            'id' => 'session-2-0',
            'account_id' => 'account-2-0',
            'device_id' => null,
            'created_at' => $now - 10,
            'last_seen_at' => $now,
            'expires_at' => $now + 3600,
            'recent_auth_at' => $now,
            'metadata' => json_encode(['source' => '2.0'], JSON_THROW_ON_ERROR),
        ]);
        $connection->table($tables->refreshTokens())->insert([
            'id' => 'application-refresh-2-0',
            'account_id' => 'account-2-0',
            'client_id' => 'legacy-app-client',
            'device_id' => null,
            'token_hash' => hash('sha256', 'legacy-application-refresh'),
            'family_id' => 'legacy-family',
            'issued_at' => $now,
            'expires_at' => $now + 3600,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);

        $before = $installer->readiness();
        expect($before['installed'])->toBeFalse();
        foreach ($tables->oauth() as $oauthTable) {
            expect($before['missing_tables'])->toContain($oauthTable);
        }

        expect($installer->runner()->run())->toBe([$oauth->id()]);
        $after = $installer->readiness();

        expect($after['installed'])->toBeTrue()
            ->and($after['missing_tables'])->toBe([])
            ->and($after['missing_columns'])->toBe([])
            ->and($installer->runner()->run())->toBe([])
            ->and($connection->select('SELECT id FROM ' . $tables->accounts() . ' WHERE id = ?', ['account-2-0']))->toHaveCount(1)
            ->and($connection->select('SELECT id FROM ' . $tables->sessions() . ' WHERE id = ?', ['session-2-0']))->toHaveCount(1)
            ->and($connection->select('SELECT id FROM ' . $tables->refreshTokens() . ' WHERE id = ?', ['application-refresh-2-0']))->toHaveCount(1);

        foreach ($tables->oauth() as $oauthTable) {
            expect($after['installed_tables'])->toContain($oauthTable);
        }
    } finally {
        DB::purge();
    }
});
