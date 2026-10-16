<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Schema\SchemaManager;
use Infocyph\Foundation\Database\AuthSchema\AuthMfaRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptProtocolSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRecordSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthPasskeyRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;

it('restores independently created Foundation 4 auth and OAuth state from a verified snapshot', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-4-restore-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create isolated restore directory.');
    }

    $working = $directory . '/application.sqlite';
    $backup = $directory . '/snapshot.sqlite';
    $config = ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => $working]);
    $tables = new AuthTables();
    $migrations = [
        new AuthSchema($tables),
        new AuthMfaRevisionSchema($tables),
        new AuthPasskeyRevisionSchema($tables),
        new AuthPasskeyRecordSchema($tables),
        new AuthOAuthRevisionSchema($tables),
        new AuthOAuthEpicryptRevisionSchema($tables),
        new AuthOAuthEpicryptProtocolSchema($tables),
    ];
    $writer = null;
    $changed = null;
    $restored = null;

    try {
        $writer = new Connection($config);
        expect((new MigrationRunner($writer, $migrations))->run())->toHaveCount(count($migrations));

        $schema = new SchemaManager($writer);
        expect($schema->hasColumn($tables->passkeyCredentials(), 'credential_record'))->toBeTrue();
        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }

        $now = time();
        $hash = password_hash('foundation-4-password', PASSWORD_BCRYPT);
        $secret = base64_encode(random_bytes(32));
        $credential = json_encode(['format' => 'webauthn', 'counter' => 4], JSON_THROW_ON_ERROR);
        $writer->table($tables->accounts())->insert([
            'id' => 'f4-account',
            'identifier' => 'current@example.test',
            'status' => 'active',
            'password_hash' => $hash,
            'metadata' => '{"tenant":"alpha"}',
        ]);
        $writer->table($tables->sessions())->insert([
            'id' => 'f4-session',
            'account_id' => 'f4-account',
            'device_id' => null,
            'created_at' => $now - 60,
            'last_seen_at' => $now - 30,
            'expires_at' => $now + 3600,
            'recent_auth_at' => $now - 60,
            'metadata' => '{"client":"first"}',
        ]);
        $writer->table($tables->refreshTokens())->insert([
            'id' => 'f4-refresh',
            'account_id' => 'f4-account',
            'client_id' => 'f4-client',
            'device_id' => null,
            'token_hash' => hash('sha3-256', 'f4-refresh-material'),
            'family_id' => 'f4-family',
            'issued_at' => $now - 60,
            'expires_at' => $now + 3600,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $writer->table($tables->mfaFactors())->insert([
            'id' => 'f4-factor',
            'account_id' => 'f4-account',
            'type' => 'totp',
            'label' => 'current',
            'enabled' => 1,
            'created_at' => $now,
            'metadata' => json_encode(['protected' => $secret], JSON_THROW_ON_ERROR),
            'revision' => 2,
        ]);
        $writer->table($tables->passkeyCredentials())->insert([
            'id' => 'f4-passkey',
            'account_id' => 'f4-account',
            'credential_id' => 'f4-credential',
            'credential_id_hash' => hash('sha3-256', 'f4-credential'),
            'credential_record' => $credential,
            'public_key' => 'opaque-f4-public-key',
            'sign_count' => 4,
            'transports' => '["internal"]',
            'created_at' => $now,
            'last_used_at' => null,
            'revoked_at' => null,
            'metadata' => null,
            'revision' => 3,
        ]);
        $writer->table($tables->oauthRefreshTokens())->insert([
            'id' => 'f4-oauth-refresh',
            'token_hash' => hash('sha3-256', 'f4-oauth-material'),
            'family_id' => 'f4-oauth-family',
            'client_id' => 'f4-client',
            'account_id' => 'f4-account',
            'device_id' => null,
            'authorization_id' => 'f4-authorization',
            'scopes' => '["openid"]',
            'audiences' => '["f4-api"]',
            'issued_at' => $now - 60,
            'expires_at' => $now + 3600,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $writer->disconnect();

        if (!copy($working, $backup)) {
            throw new RuntimeException('Unable to save the Foundation 4 snapshot.');
        }
        $snapshotHash = hash_file('sha256', $backup);
        expect($snapshotHash)->toBeString()->toHaveLength(64);

        $changed = new Connection($config);
        $changed->table($tables->accounts())->insert([
            'id' => 'post-snapshot',
            'identifier' => 'new@example.test',
            'status' => 'active',
            'password_hash' => null,
            'metadata' => null,
        ]);
        $changed->disconnect();

        if (!copy($backup, $working)) {
            throw new RuntimeException('Unable to restore Foundation 4 snapshot.');
        }
        expect(hash_file('sha256', $working))->toBe($snapshotHash);

        $restored = new Connection($config);
        expect($restored->select(
            'SELECT id FROM ' . $tables->accounts() . ' WHERE id = ?',
            ['post-snapshot'],
        ))->toBe([]);

        $savedHash = $restored->select(
            'SELECT password_hash FROM ' . $tables->accounts() . ' WHERE id = ?',
            ['f4-account'],
        )[0]['password_hash'];
        $savedSession = $restored->select(
            'SELECT expires_at FROM ' . $tables->sessions() . ' WHERE id = ?',
            ['f4-session'],
        )[0]['expires_at'];
        $savedFamily = $restored->select(
            'SELECT family_id FROM ' . $tables->refreshTokens() . ' WHERE id = ?',
            ['f4-refresh'],
        )[0]['family_id'];
        $savedMfa = $restored->select(
            'SELECT metadata, revision FROM ' . $tables->mfaFactors() . ' WHERE id = ?',
            ['f4-factor'],
        )[0];
        $savedPasskey = $restored->select(
            'SELECT credential_record, sign_count, revision FROM ' . $tables->passkeyCredentials() . ' WHERE id = ?',
            ['f4-passkey'],
        )[0];
        $savedOAuth = $restored->select(
            'SELECT family_id, revoked_at FROM ' . $tables->oauthRefreshTokens() . ' WHERE id = ?',
            ['f4-oauth-refresh'],
        )[0];

        expect(password_verify('foundation-4-password', $savedHash))->toBeTrue()
            ->and((int) $savedSession)->toBe($now + 3600)
            ->and($savedFamily)->toBe('f4-family')
            ->and(json_decode($savedMfa['metadata'], true, flags: JSON_THROW_ON_ERROR)['protected'])->toBe($secret)
            ->and((int) $savedMfa['revision'])->toBe(2)
            ->and($savedPasskey['credential_record'])->toBe($credential)
            ->and((int) $savedPasskey['sign_count'])->toBe(4)
            ->and((int) $savedPasskey['revision'])->toBe(3)
            ->and($savedOAuth['family_id'])->toBe('f4-oauth-family')
            ->and($savedOAuth['revoked_at'])->toBeNull();
    } finally {
        $writer?->disconnect();
        $changed?->disconnect();
        $restored?->disconnect();
        if (is_file($working)) {
            unlink($working);
        }
        if (is_file($backup)) {
            unlink($backup);
        }
        rmdir($directory);
    }
});
