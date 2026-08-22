<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

final readonly class AuthSchema implements Migration
{
    public function __construct(
        private AuthTables $tables,
    ) {}

    public function down(SchemaManager $schema, MigrationContext $context): void
    {
        foreach (array_reverse($this->tables->all()) as $table) {
            $schema->dropIfExists($table);
            $context->checkpoint();
        }
    }

    public function id(): string
    {
        return '20260730000000_foundation_auth_schema';
    }

    public function up(SchemaManager $schema, MigrationContext $context): void
    {
        $this->createAccounts($schema);
        $this->createSessions($schema);
        $this->createConsumableRequests($schema);
        $this->createTokens($schema);
        $this->createFactorsAndPasskeys($schema);
        $this->createAuthorization($schema);
        $this->createDevicesAuditAndLockouts($schema);
        $context->checkpoint();
    }

    private function createAccounts(SchemaManager $schema): void
    {
        $schema->create($this->tables->accounts(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('identifier', 255)->unique();
            $table->string('status', 32)->index();
            $table->text('password_hash')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    private function createAssignment(
        SchemaManager $schema,
        string $name,
        string $left,
        string $right,
    ): void {
        $schema->create($name, static function (Blueprint $table) use ($left, $right): void {
            $table->string($left, 64);
            $table->string($right, 64)->index();
            $table->bigInteger('created_at');
            $table->unique([$left, $right]);
        });
    }

    private function createAuthorization(SchemaManager $schema): void
    {
        $schema->create($this->tables->roles(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255)->unique();
            $table->json('metadata')->nullable();
        });
        $schema->create($this->tables->permissions(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255)->unique();
            $table->json('metadata')->nullable();
        });
        $this->createAssignment($schema, $this->tables->accountRoles(), 'account_id', 'role_id');
        $this->createAssignment($schema, $this->tables->accountPermissions(), 'account_id', 'permission_id');
        $this->createAssignment($schema, $this->tables->rolePermissions(), 'role_id', 'permission_id');
        $schema->create($this->tables->grants(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('principal_id', 64)->index();
            $table->string('permission', 255)->index();
            $table->string('resource_type', 255)->nullable();
            $table->string('resource_id', 255)->nullable();
            $table->bigInteger('expires_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->index(['resource_type', 'resource_id']);
        });
    }

    private function createConsumableRequests(SchemaManager $schema): void
    {
        $schema->create($this->tables->passwordResets(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->bigInteger('requested_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('consumed_at')->nullable();
            $table->json('context')->nullable();
        });
        $schema->create($this->tables->emailVerifications(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('email', 255)->index();
            $table->bigInteger('requested_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('consumed_at')->nullable();
            $table->json('context')->nullable();
        });
    }

    private function createDevicesAuditAndLockouts(SchemaManager $schema): void
    {
        $schema->create($this->tables->devices(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('label', 255)->nullable();
            $table->string('fingerprint', 255)->nullable()->index();
            $table->boolean('trusted');
            $table->bigInteger('created_at');
            $table->bigInteger('last_seen_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
        });
        $schema->create($this->tables->auditEvents(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('type', 128)->index();
            $table->string('severity', 32);
            $table->string('account_id', 64)->nullable()->index();
            $table->string('actor_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->bigInteger('occurred_at')->index();
            $table->json('metadata')->nullable();
        });
        $schema->create($this->tables->lockouts(), static function (Blueprint $table): void {
            $table->string('account_id', 64)->primary();
            $table->string('reason', 128);
            $table->bigInteger('until_at')->nullable()->index();
        });
    }

    private function createFactorsAndPasskeys(SchemaManager $schema): void
    {
        $schema->create($this->tables->mfaFactors(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('type', 64)->index();
            $table->string('label', 255)->nullable();
            $table->boolean('enabled');
            $table->bigInteger('created_at');
            $table->json('metadata')->nullable();
            $table->bigInteger('revision')->default(0);
        });
        $schema->create($this->tables->passkeyCredentials(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('credential_id', 1024)->unique();
            $table->text('public_key');
            $table->bigInteger('sign_count');
            $table->json('transports')->nullable();
            $table->bigInteger('created_at');
            $table->bigInteger('last_used_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    private function createSessions(SchemaManager $schema): void
    {
        $schema->create($this->tables->sessions(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('device_id', 64)->nullable()->index();
            $table->bigInteger('created_at');
            $table->bigInteger('last_seen_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('recent_auth_at')->nullable();
            $table->json('metadata')->nullable();
        });
    }

    private function createTokens(SchemaManager $schema): void
    {
        $schema->create($this->tables->rememberTokens(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('device_id', 64);
            $table->string('selector', 255)->unique();
            $table->text('verifier_hash');
            $table->string('family_id', 64)->index();
            $table->bigInteger('issued_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('last_used_at')->nullable();
            $table->bigInteger('rotated_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
        });
        $schema->create($this->tables->refreshTokens(), static function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('account_id', 64)->index();
            $table->string('client_id', 255)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('token_hash', 255)->unique();
            $table->string('family_id', 64)->index();
            $table->bigInteger('issued_at');
            $table->bigInteger('expires_at')->index();
            $table->bigInteger('rotated_at')->nullable();
            $table->bigInteger('revoked_at')->nullable();
            $table->json('metadata')->nullable();
        });
    }
}
