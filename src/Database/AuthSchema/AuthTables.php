<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database\AuthSchema;

final class AuthTables
{
    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [
            $this->accounts(),
            $this->sessions(),
            $this->passwordResets(),
            $this->emailVerifications(),
            $this->rememberTokens(),
            $this->refreshTokens(),
            $this->mfaFactors(),
            $this->passkeyCredentials(),
            $this->roles(),
            $this->permissions(),
            $this->accountRoles(),
            $this->accountPermissions(),
            $this->rolePermissions(),
            $this->grants(),
            $this->devices(),
            $this->auditEvents(),
            $this->lockouts(),
        ];
    }

    public function accountPermissions(): string
    {
        return 'auth_account_permissions';
    }

    public function accountRoles(): string
    {
        return 'auth_account_roles';
    }

    public function accounts(): string
    {
        return 'auth_accounts';
    }

    public function auditEvents(): string
    {
        return 'auth_audit_events';
    }

    public function devices(): string
    {
        return 'auth_devices';
    }

    public function emailVerifications(): string
    {
        return 'auth_email_verifications';
    }

    public function grants(): string
    {
        return 'auth_grants';
    }

    public function lockouts(): string
    {
        return 'auth_lockouts';
    }

    public function mfaFactors(): string
    {
        return 'auth_mfa_factors';
    }

    public function passkeyCredentials(): string
    {
        return 'auth_passkey_credentials';
    }

    public function passwordResets(): string
    {
        return 'auth_password_resets';
    }

    public function permissions(): string
    {
        return 'auth_permissions';
    }

    public function refreshTokens(): string
    {
        return 'auth_refresh_tokens';
    }

    public function rememberTokens(): string
    {
        return 'auth_remember_tokens';
    }

    public function rolePermissions(): string
    {
        return 'auth_role_permissions';
    }

    public function roles(): string
    {
        return 'auth_roles';
    }

    public function sessions(): string
    {
        return 'auth_sessions';
    }
}
