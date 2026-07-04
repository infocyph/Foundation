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
        return [
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->lockouts()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->auditEvents()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->devices()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->grants()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->rolePermissions()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->accountPermissions()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->accountRoles()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->permissions()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->roles()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->passkeyCredentials()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->mfaFactors()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->refreshTokens()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->rememberTokens()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->emailVerifications()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->passwordResets()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->sessions()),
            sprintf('DROP TABLE IF EXISTS %s', $this->tables->accounts()),
        ];
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
            "CREATE INDEX IF NOT EXISTS {$accounts}_status_idx ON {$accounts} (status)",
            "CREATE INDEX IF NOT EXISTS {$sessions}_account_idx ON {$sessions} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$sessions}_device_idx ON {$sessions} (device_id)",
            "CREATE INDEX IF NOT EXISTS {$sessions}_expires_idx ON {$sessions} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$passwordResets}_account_idx ON {$passwordResets} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$passwordResets}_expires_idx ON {$passwordResets} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$emailVerifications}_account_idx ON {$emailVerifications} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$emailVerifications}_email_idx ON {$emailVerifications} (email)",
            "CREATE INDEX IF NOT EXISTS {$emailVerifications}_expires_idx ON {$emailVerifications} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$rememberTokens}_account_idx ON {$rememberTokens} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$rememberTokens}_family_idx ON {$rememberTokens} (family_id)",
            "CREATE INDEX IF NOT EXISTS {$rememberTokens}_expires_idx ON {$rememberTokens} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$refreshTokens}_account_idx ON {$refreshTokens} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$refreshTokens}_family_idx ON {$refreshTokens} (family_id)",
            "CREATE INDEX IF NOT EXISTS {$refreshTokens}_expires_idx ON {$refreshTokens} (expires_at)",
            "CREATE INDEX IF NOT EXISTS {$mfaFactors}_account_idx ON {$mfaFactors} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$mfaFactors}_type_idx ON {$mfaFactors} (type)",
            "CREATE INDEX IF NOT EXISTS {$passkeys}_account_idx ON {$passkeys} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$accountRoles}_role_idx ON {$accountRoles} (role_id)",
            "CREATE INDEX IF NOT EXISTS {$accountPermissions}_permission_idx ON {$accountPermissions} (permission_id)",
            "CREATE INDEX IF NOT EXISTS {$rolePermissions}_permission_idx ON {$rolePermissions} (permission_id)",
            "CREATE INDEX IF NOT EXISTS {$grants}_principal_idx ON {$grants} (principal_id)",
            "CREATE INDEX IF NOT EXISTS {$grants}_permission_idx ON {$grants} (permission)",
            "CREATE INDEX IF NOT EXISTS {$grants}_resource_idx ON {$grants} (resource_type, resource_id)",
            "CREATE INDEX IF NOT EXISTS {$devices}_account_idx ON {$devices} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$devices}_fingerprint_idx ON {$devices} (fingerprint)",
            "CREATE INDEX IF NOT EXISTS {$auditEvents}_account_idx ON {$auditEvents} (account_id)",
            "CREATE INDEX IF NOT EXISTS {$auditEvents}_occurred_idx ON {$auditEvents} (occurred_at)",
            "CREATE INDEX IF NOT EXISTS {$auditEvents}_type_idx ON {$auditEvents} (type)",
            "CREATE INDEX IF NOT EXISTS {$lockouts}_until_idx ON {$lockouts} (until_at)",
        ];
    }
}
