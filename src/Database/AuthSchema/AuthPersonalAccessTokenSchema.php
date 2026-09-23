<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final readonly class AuthPersonalAccessTokenSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $schema->dropIfExists($this->tables->personalAccessTokens());
        $schema->dropIfExists($this->tables->personalAccessTokenSubjects());
        $context->checkpoint();
    }

    public function id(): string
    {
        return '20260922000000_foundation_auth_personal_access_tokens';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        if (!$schema->hasTable($this->tables->personalAccessTokenSubjects())) {
            $schema->create($this->tables->personalAccessTokenSubjects(), static function (Blueprint $table): void {
                $table->string('subject_hash', 64)->primary();
                $table->bigInteger('revision');
            });
        }

        if (!$schema->hasTable($this->tables->personalAccessTokens())) {
            $schema->create($this->tables->personalAccessTokens(), static function (Blueprint $table): void {
                $table->string('token_id', 128)->primary();
                $table->string('subject_hash', 64)->index();
                $table->text('subject');
                $table->string('name', 255);
                $table->json('abilities');
                $table->bigInteger('created_at')->index();
                $table->bigInteger('expires_at')->nullable()->index();
                $table->bigInteger('revoked_at')->nullable()->index();
                $table->bigInteger('last_used_at')->nullable();
            });
        }

        $context->checkpoint();
    }
}
