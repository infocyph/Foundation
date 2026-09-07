<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final readonly class AuthPasskeyRecordSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $table = $this->tables->passkeyCredentials();
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'credential_record')) {
            $schema->table($table, static function (Blueprint $table): void {
                $table->dropColumn('credential_record');
            });
        }
        $context->checkpoint();
    }

    public function id(): string
    {
        return '20260907010000_foundation_auth_passkey_record';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $table = $this->tables->passkeyCredentials();
        if ($schema->hasTable($table) && !$schema->hasColumn($table, 'credential_record')) {
            $schema->table($table, static function (Blueprint $table): void {
                $table->text('credential_record')->nullable();
            });
        }
        $context->checkpoint();
    }
}
