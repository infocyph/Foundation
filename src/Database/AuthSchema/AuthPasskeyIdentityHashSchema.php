<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;
use RuntimeException;

/**
 * Preserve Foundation 3 opaque passkey credentials while adding the
 * authoritative Foundation 4 digest lookup.
 *
 * A nullable unique digest is required while backfilling existing nonempty
 * SQL tables; fresh Foundation 4 schemas already create the column.
 * Run migrations with application passkey writes quiesced.
 */
final readonly class AuthPasskeyIdentityHashSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    #[\Override]
    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        // Preserve the original raw credential ID and its digest across an
        // older-source rollback. Full schema reset still drops the table.
        unset($schema);
        $context->checkpoint();
    }

    #[\Override]
    public function id(): string
    {
        return '20261107000000_foundation_auth_passkey_identity_hash';
    }

    #[\Override]
    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $table = $this->tables->passkeyCredentials();
        if (!$schema->hasTable($table)) {
            $context->checkpoint();

            return;
        }

        if (!$schema->hasColumn($table, 'credential_id_hash')) {
            $schema->table($table, static function (Blueprint $blueprint): void {
                $blueprint->string('credential_id_hash', 64)->nullable()->unique();
            });
        }

        $schema->connection()->table($table)->chunkById(
            200,
            static function (array $rows, int $page) use ($schema, $table, $context): bool {
                unset($page);
                foreach ($rows as $row) {
                    self::backfillRow($schema, $context, $table, $row);
                }

                return true;
            },
        );
        $context->checkpoint();
    }

    /**
     * @param array<string, mixed> $row
     * @throws RuntimeException
     */
    private static function backfillRow(
        SchemaManager $schema,
        MigrationContext $context,
        string $table,
        array $row,
    ): void {
        $context->checkpoint();
        $id = $row['id'] ?? null;
        $raw = $row['credential_id'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($raw) || $raw === '') {
            throw new RuntimeException('Stored passkey credential identity is malformed.');
        }

        $hash = hash('sha3-256', $raw);
        $persisted = $row['credential_id_hash'] ?? null;
        if ($persisted !== null) {
            if (!is_string($persisted) || !hash_equals($hash, $persisted)) {
                throw new RuntimeException('Persisted passkey identity digest does not match its opaque credential ID.');
            }

            return;
        }

        $affected = $schema->connection()->table($table)
            ->where('id', '=', $id)
            ->where('credential_id', '=', $raw)
            ->whereNull('credential_id_hash')
            ->update(['credential_id_hash' => $hash]);
        if ($affected !== 1) {
            throw new RuntimeException('Stored passkey identity changed while applying its digest migration.');
        }
    }
}
