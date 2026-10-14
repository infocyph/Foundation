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

it('restores a retained 2.1.1-shaped auth snapshot after additive Foundation 4 migrations', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-4-restore-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create isolated restore directory.');
    }

    $working = $directory . '/application.sqlite';
    $backup = $directory . '/pre-upgrade.sqlite';
    $config = ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => $working]);
    $tables = new AuthTables();
    $baseline = new AuthSchema($tables);
    $mfa = new AuthMfaRevisionSchema($tables);
    $writer = null;
    $candidate = null;
    $restored = null;

    try {
        $writer = new Connection($config);
        expect((new MigrationRunner($writer, [$baseline, $mfa]))->run())
            ->toBe([$baseline->id(), $mfa->id()]);

        $now = time();
        $writer->table($tables->accounts())->insert([
            'id' => 'preserved-account',
            'identifier' => 'retained@example.test',
            'status' => 'active',
            'password_hash' => 'opaque-hash-from-published-format',
            'metadata' => '{"tenant":"alpha"}',
        ]);
        $writer->table($tables->sessions())->insert([
            'id' => 'preserved-session',
            'account_id' => 'preserved-account',
            'device_id' => null,
            'created_at' => $now - 60,
            'last_seen_at' => $now - 30,
            'expires_at' => $now + 3600,
            'recent_auth_at' => $now - 60,
            'metadata' => '{"source":"2.1.1"}',
        ]);
        $writer->table($tables->refreshTokens())->insert([
            'id' => 'preserved-refresh',
            'account_id' => 'preserved-account',
            'client_id' => 'legacy-client',
            'device_id' => null,
            'token_hash' => hash('sha256', 'opaque-refresh'),
            'family_id' => 'original-family',
            'issued_at' => $now - 60,
            'expires_at' => $now + 3600,
            'rotated_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $writer->table($tables->mfaFactors())->insert([
            'id' => 'preserved-factor',
            'account_id' => 'preserved-account',
            'type' => 'totp',
            'label' => 'original',
            'enabled' => 1,
            'created_at' => $now,
            'metadata' => '{"secret":"opaque-protected-material"}',
            'revision' => 0,
        ]);
        $writer->table($tables->passkeyCredentials())->insert([
            'id' => 'preserved-passkey',
            'account_id' => 'preserved-account',
            'credential_id' => 'original-credential',
            'public_key' => 'opaque-public-key',
            'sign_count' => 4,
            'transports' => '["internal"]',
            'created_at' => $now,
            'last_used_at' => null,
            'revoked_at' => null,
            'metadata' => null,
        ]);
        $writer->disconnect();

        if (!copy($working, $backup)) {
            throw new RuntimeException('Unable to stage the pre-upgrade SQLite snapshot.');
        }
        $hash = hash_file('sha256', $backup);
        expect($hash)->toBeString()->toHaveLength(64);

        $candidate = new Connection($config);
        $migrations = [
            $baseline,
            $mfa,
            new AuthPasskeyRevisionSchema($tables),
            new AuthPasskeyRecordSchema($tables),
            new AuthOAuthRevisionSchema($tables),
            new AuthOAuthEpicryptRevisionSchema($tables),
            new AuthOAuthEpicryptProtocolSchema($tables),
        ];
        expect((new MigrationRunner($candidate, $migrations))->run())->not->toBeEmpty();

        $schema = new SchemaManager($candidate);
        foreach ($tables->oauth() as $table) {
            expect($schema->hasTable($table))->toBeTrue();
        }
        expect($candidate->select(
            'SELECT password_hash FROM ' . $tables->accounts() . ' WHERE id = ?',
            ['preserved-account'],
        )[0]['password_hash'])->toBe('opaque-hash-from-published-format');

        $candidate->table($tables->accounts())->insert([
            'id' => 'candidate-only',
            'identifier' => 'new@example.test',
            'status' => 'active',
            'password_hash' => null,
            'metadata' => null,
        ]);
        $candidate->disconnect();

        // Code rollback is insufficient; restore the original *data* bytes.
        if (!copy($backup, $working)) {
            throw new RuntimeException('Unable to restore original database bytes.');
        }
        expect(hash_file('sha256', $working))->toBe($hash);

        $restored = new Connection($config);
        $restoredSchema = new SchemaManager($restored);
        foreach ($tables->oauth() as $table) {
            expect($restoredSchema->hasTable($table))->toBeFalse();
        }

        expect($restored->select('SELECT id FROM ' . $tables->accounts() . ' WHERE id = ?', ['candidate-only']))
            ->toBe([])
            ->and($restored->select(
                'SELECT password_hash FROM ' . $tables->accounts() . ' WHERE id = ?',
                ['preserved-account'],
            )[0]['password_hash'])->toBe('opaque-hash-from-published-format')
            ->and($restored->select(
                'SELECT id FROM ' . $tables->sessions() . ' WHERE id = ?',
                ['preserved-session'],
            ))->toHaveCount(1)
            ->and($restored->select(
                'SELECT family_id FROM ' . $tables->refreshTokens() . ' WHERE id = ?',
                ['preserved-refresh'],
            )[0]['family_id'])->toBe('original-family')
            ->and($restored->select(
                'SELECT metadata FROM ' . $tables->mfaFactors() . ' WHERE id = ?',
                ['preserved-factor'],
            )[0]['metadata'])->toContain('opaque-protected-material')
            ->and((int) $restored->select(
                'SELECT sign_count FROM ' . $tables->passkeyCredentials() . ' WHERE id = ?',
                ['preserved-passkey'],
            )[0]['sign_count'])->toBe(4);
    } finally {
        $writer?->disconnect();
        $candidate?->disconnect();
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
