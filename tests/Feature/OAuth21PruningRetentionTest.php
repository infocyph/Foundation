<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Auth\AuthPruner;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('prunes OAuth expiry state idempotently without deleting active authorizations or live replay evidence', function (): void {
    DB::purge();
    $config = new ConfigRepository([
        'database' => [
            'default' => 'oauth-prune',
            'connections' => [
                'oauth-prune' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ]);
    $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), new RuntimeContextTracker());
    $tables = new AuthTables();
    $schema = new AuthSchema($tables);
    $mfa = new AuthMfaRevisionSchema($tables);
    $oauth = new AuthOAuthRevisionSchema($tables);
    $installer = new AuthSchemaInstaller($factory, $schema, $mfa, $tables, $oauth, true);
    $pruner = new AuthPruner($factory, $tables, $installer, true);
    $connection = $factory->connection();
    $now = time();

    try {
        $installer->install();

        $connection->table($tables->oauthAuthorizations())->insert([
            'id' => 'authorization-active',
            'client_id' => 'oc_client',
            'account_id' => 'account-1',
            'scopes' => json_encode(['profile.read'], JSON_THROW_ON_ERROR),
            'audiences' => json_encode(['https://api.example.test'], JSON_THROW_ON_ERROR),
            'created_at' => $now - 100,
            'expires_at' => $now + 3600,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $connection->table($tables->oauthRefreshTokens())->insert([
            'id' => 'refresh-replay-evidence',
            'token_hash' => hash('sha256', 'live-rotated-token'),
            'family_id' => 'family-live',
            'client_id' => 'oc_client',
            'account_id' => 'account-1',
            'device_id' => null,
            'authorization_id' => 'authorization-active',
            'scopes' => json_encode(['profile.read'], JSON_THROW_ON_ERROR),
            'audiences' => json_encode(['https://api.example.test'], JSON_THROW_ON_ERROR),
            'issued_at' => $now - 200,
            'expires_at' => $now + 3600,
            'rotated_at' => $now - 100,
            'revoked_at' => $now - 90,
            'metadata' => null,
        ]);
        $connection->table($tables->oauthRefreshTokens())->insert([
            'id' => 'refresh-expired',
            'token_hash' => hash('sha256', 'expired-token'),
            'family_id' => 'family-expired',
            'client_id' => 'oc_client',
            'account_id' => 'account-1',
            'device_id' => null,
            'authorization_id' => 'authorization-active',
            'scopes' => json_encode(['profile.read'], JSON_THROW_ON_ERROR),
            'audiences' => json_encode(['https://api.example.test'], JSON_THROW_ON_ERROR),
            'issued_at' => $now - 7200,
            'expires_at' => $now - 1,
            'rotated_at' => $now - 7100,
            'revoked_at' => $now - 7000,
            'metadata' => null,
        ]);

        $first = $pruner->prune(retentionHours: 0);
        $second = $pruner->prune(retentionHours: 0);

        $authorizationRows = $connection->select(
            'SELECT id FROM ' . $tables->oauthAuthorizations() . ' WHERE id = ?',
            ['authorization-active'],
        );
        $replayRows = $connection->select(
            'SELECT id FROM ' . $tables->oauthRefreshTokens() . ' WHERE id = ?',
            ['refresh-replay-evidence'],
        );
        $expiredRows = $connection->select(
            'SELECT id FROM ' . $tables->oauthRefreshTokens() . ' WHERE id = ?',
            ['refresh-expired'],
        );

        expect($first['oauth_refresh_tokens'])->toBe(1)
            ->and($second['oauth_refresh_tokens'])->toBe(0)
            ->and($authorizationRows)->toHaveCount(1)
            ->and($replayRows)->toHaveCount(1)
            ->and($expiredRows)->toBe([]);
    } finally {
        DB::purge();
    }
});
