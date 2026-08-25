<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final readonly class AuthMfaRevisionSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $table = $this->tables->mfaFactors();
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'revision')) {
            $schema->table($table, static function (Blueprint $table): void {
                $table->dropColumn('revision');
            });
        }
        $context->checkpoint();
    }

    public function id(): string
    {
        return '20260822000000_foundation_auth_mfa_revision';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $table = $this->tables->mfaFactors();
        if ($schema->hasTable($table) && !$schema->hasColumn($table, 'revision')) {
            $schema->table($table, static function (Blueprint $table): void {
                $table->bigInteger('revision')->default(0);
            });
        }
        $context->checkpoint();
    }
}
