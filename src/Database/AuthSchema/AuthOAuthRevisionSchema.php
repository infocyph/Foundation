<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final readonly class AuthOAuthRevisionSchema implements Migration
{
    public function __construct(private AuthTables $tables) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        foreach (array_reverse($this->tables->oauth()) as $table) {
            $schema->dropIfExists($table);
            $context->checkpoint();
        }
    }

    public function id(): string
    {
        return '20260826000000_foundation_auth_oauth';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $this->createClients($schema);
        $this->createRedirectsAndScopes($schema);
        $this->createAuthorizations($schema);
        $this->createAuthorizationCodes($schema);
        $this->createConsents($schema);
        $this->createRefreshTokens($schema);
        $this->createAccessRevocations($schema);
        $context->checkpoint();
    }

    private function createAccessRevocations(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthAccessRevocations(), static function (Blueprint $table): void {
            $table->string('token_id', 128)->primary();
            $table->string('client_id', 64)->index();
            $table->string('authorization_id', 64)->nullable()->index();
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('revoked_at')->index();
            $table->string('reason', 128)->nullable();
            $table->json('metadata')->nullable();
        });
    }

    private function createAuthorizationCodes(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthAuthorizationCodes(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 64)->index();
            $table->string('account_id', 64)->index();
            $table->string('authorization_id', 64)->index();
            $table->string('redirect_uri_hash', 64);
            $table->string('pkce_challenge', 128);
            $table->string('pkce_method', 16);
            $table->json('scopes');
            $table->json('audiences');
            $table->bigInteger('issued_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('consumed_at')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    private function createAuthorizations(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthAuthorizations(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('client_id', 64)->index();
            $table->string('account_id', 64)->nullable()->index();
            $table->json('scopes');
            $table->json('audiences');
            $table->bigInteger('created_at');
            $table->bigInteger('expires_at')->nullable()->index();
            $table->bigInteger('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
        });
    }

    private function createClients(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthClients(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('client_id', 128)->unique();
            $table->string('client_type', 32)->index();
            $table->string('auth_method', 64);
            $table->text('secret_hash')->nullable();
            $table->json('grants');
            $table->json('audiences');
            $table->boolean('enabled');
            $table->bigInteger('created_at');
            $table->bigInteger('updated_at');
            $table->bigInteger('disabled_at')->nullable()->index();
            $table->json('metadata')->nullable();
        });
    }

    private function createConsents(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthConsents(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('client_id', 64)->index();
            $table->string('scope_fingerprint', 64);
            $table->json('scopes');
            $table->json('audiences');
            $table->bigInteger('granted_at');
            $table->bigInteger('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->unique(['account_id', 'client_id', 'scope_fingerprint']);
        });
    }

    private function createRedirectsAndScopes(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthRedirectUris(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('client_id', 64)->index();
            $table->string('redirect_uri_hash', 64);
            $table->text('redirect_uri');
            $table->bigInteger('created_at');
            $table->unique(['client_id', 'redirect_uri_hash']);
        });
        $schema->create($this->tables->oauthClientScopes(), static function (Blueprint $table): void {
            $table->string('client_id', 64);
            $table->string('scope', 191)->index();
            $table->bigInteger('created_at');
            $table->unique(['client_id', 'scope']);
        });
    }

    private function createRefreshTokens(SchemaManager $schema): void
    {
        $schema->create($this->tables->oauthRefreshTokens(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('token_hash', 64)->unique();
            $table->string('family_id', 64)->index();
            $table->string('client_id', 64)->index();
            $table->string('account_id', 64)->nullable()->index();
            $table->string('device_id', 64)->nullable();
            $table->string('authorization_id', 64)->index();
            $table->json('scopes');
            $table->json('audiences');
            $table->bigInteger('issued_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('rotated_at')->nullable();
            $table->bigInteger('revoked_at')->nullable()->index();
            $table->json('metadata')->nullable();
        });
    }
}
