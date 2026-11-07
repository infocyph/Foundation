<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthPasskeyIdentityHashSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRecordSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;

if (count($argv) !== 5) {
    throw new InvalidArgumentException('Expected stage, SQLite file, versioned autoloader and digest path.');
}

[$stage, $database, $autoload, $digestFile] = array_slice($argv, 1);
if (!in_array($stage, ['seed', 'upgrade', 'legacy-read'], true)) {
    throw new InvalidArgumentException('Unknown cross-major rehearsal stage.');
}

require $autoload;

$connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => $database]));
$tables = new AuthTables();

try {
    $migrations = [
        new AuthSchema($tables),
        new AuthMfaRevisionSchema($tables),
        new AuthPasskeyRevisionSchema($tables),
        new AuthPasskeyRecordSchema($tables),
    ];
    if ($stage === 'upgrade') {
        $migrations[] = new AuthPasskeyIdentityHashSchema($tables);
    }
    $runner = new MigrationRunner($connection, $migrations);
    $schema = new SchemaManager($connection);

    if ($stage === 'seed') {
        if ($schema->hasTable($tables->accounts())) {
            throw new RuntimeException('Historical seed must start with an empty database.');
        }
        $runner->run();
        if ($schema->hasColumn($tables->passkeyCredentials(), 'credential_id_hash')) {
            throw new RuntimeException('Historical Foundation 3 already contains the Foundation 4 credential hash.');
        }

        $now = 1760000000;
        $connection->table($tables->accounts())->insert([
            'id' => 'legacy-account',
            'identifier' => 'legacy@example.test',
            'status' => 'active',
            'password_hash' => password_hash('retained-secret', PASSWORD_BCRYPT),
            'metadata' => '{"owner":"legacy"}',
        ]);
        $connection->table($tables->sessions())->insert([
            'id' => 'legacy-session',
            'account_id' => 'legacy-account',
            'device_id' => null,
            'created_at' => $now - 60,
            'last_seen_at' => $now,
            'expires_at' => $now + 3600,
            'recent_auth_at' => $now,
            'metadata' => '{"client":"legacy"}',
        ]);
        $connection->table($tables->refreshTokens())->insert([
            'id' => 'legacy-refresh',
            'account_id' => 'legacy-account',
            'client_id' => 'legacy-client',
            'device_id' => null,
            'token_hash' => hash('sha3-256', 'legacy-refresh-material'),
            'family_id' => 'legacy-family',
            'issued_at' => $now,
            'expires_at' => $now + 3600,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $connection->table($tables->mfaFactors())->insert([
            'id' => 'legacy-factor',
            'account_id' => 'legacy-account',
            'type' => 'totp',
            'label' => 'legacy',
            'enabled' => 1,
            'created_at' => $now,
            'metadata' => '{"protected":"opaque"}',
            'revision' => 2,
        ]);
        $connection->table($tables->passkeyCredentials())->insert([
            'id' => 'legacy-passkey',
            'account_id' => 'legacy-account',
            'credential_id' => 'legacy-opaque-credential',
            'credential_record' => '{"counter":4}',
            'public_key' => 'opaque-public-key',
            'sign_count' => 4,
            'transports' => '["internal"]',
            'created_at' => $now,
            'last_used_at' => null,
            'revoked_at' => null,
            'metadata' => null,
            'revision' => 3,
        ]);
    } else {
        $applied = $runner->run();
        if ($stage === 'upgrade') {
            if (!in_array('20261107000000_foundation_auth_passkey_identity_hash', $applied, true)) {
                throw new RuntimeException('Foundation 4 did not apply its required credential migration.');
            }
            if ($runner->run() !== []) {
                throw new RuntimeException('Foundation 4 upgrade migration is not idempotent.');
            }
        } elseif ($applied !== []) {
            throw new RuntimeException('Old source attempted to repeat a historical migration.');
        }
    }

    $retained = [];
    foreach ([
        [$tables->accounts(), 'id, identifier, status, password_hash, metadata'],
        [$tables->sessions(), 'id, account_id, expires_at, metadata'],
        [$tables->refreshTokens(), 'id, account_id, token_hash, family_id, revoked_at'],
        [$tables->mfaFactors(), 'id, account_id, type, metadata, revision'],
        [$tables->passkeyCredentials(), 'id, account_id, credential_id, credential_record, public_key, sign_count, revision'],
    ] as [$table, $columns]) {
        $rows = $connection->select('SELECT ' . $columns . ' FROM ' . $table . ' ORDER BY id');
        if (count($rows) !== 1) {
            throw new RuntimeException('Retained state row mismatch for ' . $table);
        }
        $retained[$table] = $rows;
    }

    $digest = hash('sha256', json_encode($retained, JSON_THROW_ON_ERROR));
    if ($stage === 'seed') {
        if (file_put_contents($digestFile, $digest . "\n") === false) {
            throw new RuntimeException('Unable to save original retained-state digest.');
        }
    } elseif (!hash_equals(trim((string) file_get_contents($digestFile)), $digest)) {
        throw new RuntimeException('Retained auth/session/refresh/MFA/passkey data changed.');
    }

    if ($stage === 'upgrade') {
        $row = $connection->select(
            'SELECT credential_id_hash FROM ' . $tables->passkeyCredentials() . ' WHERE id = ?',
            ['legacy-passkey'],
        );
        if (($row[0]['credential_id_hash'] ?? null) !== hash('sha3-256', 'legacy-opaque-credential')) {
            throw new RuntimeException('Foundation 4 identity digest mismatch.');
        }
    }

    echo json_encode(['stage' => $stage, 'php' => PHP_VERSION, 'state_sha256' => $digest], JSON_THROW_ON_ERROR), "\n";
} finally {
    $connection->disconnect();
}
