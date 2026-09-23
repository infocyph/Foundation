<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

/**
 * Additive state required by Epicrypt 3.1 OAuth protocol contracts.
 *
 * Raw authorization-code/refresh/access credentials are never stored here.
 */
final readonly class AuthOAuthEpicryptProtocolSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        $refresh = $this->tables->oauthRefreshTokens();
        if ($schema->hasTable($refresh)) {
            $dropDpop = $schema->hasColumn($refresh, 'dpop_jkt');
            $dropIdle = $schema->hasColumn($refresh, 'idle_expires_at');

            if ($dropDpop || $dropIdle) {
                $schema->table($refresh, static function (Blueprint $table) use ($dropDpop, $dropIdle): void {
                    if ($dropDpop) {
                        $table->dropIndex('auth_oauth_refresh_tokens_dpop_jkt_index');
                    }
                    if ($dropIdle) {
                        $table->dropIndex('auth_oauth_refresh_tokens_idle_expires_at_index');
                    }
                });
                $schema->table($refresh, static function (Blueprint $table) use ($dropDpop, $dropIdle): void {
                    if ($dropDpop) {
                        $table->dropColumn('dpop_jkt');
                    }
                    if ($dropIdle) {
                        $table->dropColumn('idle_expires_at');
                    }
                });
            }
        }

        $schema->dropIfExists($this->tables->oauthReplayStates());
        $schema->dropIfExists($this->tables->oauthAccessStatuses());
        $context->checkpoint();
    }

    public function id(): string
    {
        return '20260921000000_foundation_auth_oauth_epicrypt_protocol';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $this->extendRefreshState($schema);
        $this->createAccessStatus($schema);
        $this->createReplayState($schema);
        $context->checkpoint();
    }

    private function createAccessStatus(SchemaManager $schema): void
    {
        $table = $this->tables->oauthAccessStatuses();
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->text('issuer');
            $table->string('token_id', 128)->index();
            $table->string('subject', 255)->index();
            $table->string('client_id', 128)->index();
            $table->string('authorization_id', 255)->nullable()->index();
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('revoked_at')->nullable()->index();
        });
    }

    private function createReplayState(SchemaManager $schema): void
    {
        $table = $this->tables->oauthReplayStates();
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('namespace_hash', 64)->index();
            $table->string('token_id', 128);
            $table->bigInteger('expires_at')->index();
        });
    }

    private function extendRefreshState(SchemaManager $schema): void
    {
        $table = $this->tables->oauthRefreshTokens();
        if (!$schema->hasTable($table)) {
            return;
        }

        $needsIdle = !$schema->hasColumn($table, 'idle_expires_at');
        $needsDpop = !$schema->hasColumn($table, 'dpop_jkt');
        if (!$needsIdle && !$needsDpop) {
            return;
        }

        $schema->table($table, static function (Blueprint $blueprint) use ($needsIdle, $needsDpop): void {
            if ($needsIdle) {
                $blueprint->bigInteger('idle_expires_at')->nullable()->index();
            }
            if ($needsDpop) {
                $blueprint->string('dpop_jkt', 64)->nullable()->index();
            }
        });
    }
}
