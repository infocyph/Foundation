<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

final readonly class AuthSchema
{
    public function __construct(
        private AuthTables $tables,
    ) {}

    /**
     * @return list<string>
     */
    public function dropStatements(): array
    {
        return array_map(
            static fn(string $table): string => sprintf('DROP TABLE IF EXISTS %s', $table),
            array_reverse($this->tables->all()),
        );
    }

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        $accounts = $this->tables->accounts();
        $sessions = $this->tables->sessions();
        $passwordResets = $this->tables->passwordResets();
        $emailVerifications = $this->tables->emailVerifications();
        $rememberTokens = $this->tables->rememberTokens();
        $refreshTokens = $this->tables->refreshTokens();
        $mfaFactors = $this->tables->mfaFactors();
        $passkeys = $this->tables->passkeyCredentials();
        $roles = $this->tables->roles();
        $permissions = $this->tables->permissions();
        $accountRoles = $this->tables->accountRoles();
        $accountPermissions = $this->tables->accountPermissions();
        $rolePermissions = $this->tables->rolePermissions();
        $grants = $this->tables->grants();
        $devices = $this->tables->devices();
        $auditEvents = $this->tables->auditEvents();
        $lockouts = $this->tables->lockouts();

        return [
            "CREATE TABLE IF NOT EXISTS {$accounts} (
                id TEXT PRIMARY KEY,
                identifier TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL,
                password_hash TEXT NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$sessions} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                device_id TEXT NULL,
                created_at INTEGER NOT NULL,
                last_seen_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                recent_auth_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$passwordResets} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                requested_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER NULL,
                context TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$emailVerifications} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                email TEXT NOT NULL,
                requested_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed_at INTEGER NULL,
                context TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$rememberTokens} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                device_id TEXT NOT NULL,
                selector TEXT NOT NULL UNIQUE,
                verifier_hash TEXT NOT NULL,
                family_id TEXT NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                last_used_at INTEGER NULL,
                rotated_at INTEGER NULL,
                revoked_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$refreshTokens} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                client_id TEXT NULL,
                device_id TEXT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                family_id TEXT NOT NULL,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                rotated_at INTEGER NULL,
                revoked_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$mfaFactors} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                type TEXT NOT NULL,
                label TEXT NULL,
                enabled INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$passkeys} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                credential_id TEXT NOT NULL UNIQUE,
                public_key TEXT NOT NULL,
                sign_count INTEGER NOT NULL,
                transports TEXT NULL,
                created_at INTEGER NOT NULL,
                last_used_at INTEGER NULL,
                revoked_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$roles} (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$permissions} (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$accountRoles} (
                account_id TEXT NOT NULL,
                role_id TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                UNIQUE(account_id, role_id)
            )",
            "CREATE TABLE IF NOT EXISTS {$accountPermissions} (
                account_id TEXT NOT NULL,
                permission_id TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                UNIQUE(account_id, permission_id)
            )",
            "CREATE TABLE IF NOT EXISTS {$rolePermissions} (
                role_id TEXT NOT NULL,
                permission_id TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                UNIQUE(role_id, permission_id)
            )",
            "CREATE TABLE IF NOT EXISTS {$grants} (
                id TEXT PRIMARY KEY,
                principal_id TEXT NOT NULL,
                permission TEXT NOT NULL,
                resource_type TEXT NULL,
                resource_id TEXT NULL,
                expires_at INTEGER NULL,
                revoked_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$devices} (
                id TEXT PRIMARY KEY,
                account_id TEXT NOT NULL,
                label TEXT NULL,
                fingerprint TEXT NULL,
                trusted INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                last_seen_at INTEGER NULL,
                revoked_at INTEGER NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$auditEvents} (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                severity TEXT NOT NULL,
                account_id TEXT NULL,
                actor_id TEXT NULL,
                session_id TEXT NULL,
                device_id TEXT NULL,
                correlation_id TEXT NULL,
                occurred_at INTEGER NOT NULL,
                metadata TEXT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$lockouts} (
                account_id TEXT PRIMARY KEY,
                reason TEXT NOT NULL,
                until_at INTEGER NULL
            )",
            ...$this->indexStatements([
                [$accounts, 'status', ['status']],
                [$sessions, 'account', ['account_id']],
                [$sessions, 'device', ['device_id']],
                [$sessions, 'expires', ['expires_at']],
                [$passwordResets, 'account', ['account_id']],
                [$passwordResets, 'expires', ['expires_at']],
                [$emailVerifications, 'account', ['account_id']],
                [$emailVerifications, 'email', ['email']],
                [$emailVerifications, 'expires', ['expires_at']],
                [$rememberTokens, 'account', ['account_id']],
                [$rememberTokens, 'family', ['family_id']],
                [$rememberTokens, 'expires', ['expires_at']],
                [$refreshTokens, 'account', ['account_id']],
                [$refreshTokens, 'family', ['family_id']],
                [$refreshTokens, 'expires', ['expires_at']],
                [$mfaFactors, 'account', ['account_id']],
                [$mfaFactors, 'type', ['type']],
                [$passkeys, 'account', ['account_id']],
                [$accountRoles, 'role', ['role_id']],
                [$accountPermissions, 'permission', ['permission_id']],
                [$rolePermissions, 'permission', ['permission_id']],
                [$grants, 'principal', ['principal_id']],
                [$grants, 'permission', ['permission']],
                [$grants, 'resource', ['resource_type', 'resource_id']],
                [$devices, 'account', ['account_id']],
                [$devices, 'fingerprint', ['fingerprint']],
                [$auditEvents, 'account', ['account_id']],
                [$auditEvents, 'occurred', ['occurred_at']],
                [$auditEvents, 'type', ['type']],
                [$lockouts, 'until', ['until_at']],
            ]),
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: list<string>}> $definitions
     * @return list<string>
     */
    private function indexStatements(array $definitions): array
    {
        return array_map(
            static fn(array $definition): string => sprintf(
                'CREATE INDEX IF NOT EXISTS %s_%s_idx ON %s (%s)',
                $definition[0],
                $definition[1],
                $definition[0],
                implode(', ', $definition[2]),
            ),
            $definitions,
        );
    }
}
