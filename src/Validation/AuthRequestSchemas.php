<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

final class AuthRequestSchemas
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'auth.login' => [
                'identifier' => 'required|string|max:255',
                'password' => 'required|string|max:255',
                'remember' => 'nullable|boolean',
            ],
            'auth.password_reset.request' => [
                'identifier' => 'required|string|max:255',
            ],
            'auth.password_reset.complete' => [
                'token' => 'required|string|max:512',
                'password' => 'required|string|min:8|max:255',
            ],
            'auth.email_verification.verify' => [
                'token' => 'required|string|max:512',
            ],
            'auth.password_change' => [
                'current_password' => 'required|string|max:255',
                'password' => 'required|string|min:8|max:255',
            ],
            'auth.passwordless.request' => [
                'identifier' => 'required|string|max:255',
            ],
            'auth.mfa.verify' => [
                'account_id' => 'required|string|max:255',
                'code' => 'required|string|min:6|max:32',
            ],
            'auth.passkey.registration_start' => [
                'account_id' => 'required|string|max:255',
                'label' => 'nullable|string|max:255',
            ],
            'auth.passkey.registration_finish' => [
                'account_id' => 'required|string|max:255',
                'credential' => 'required|array',
            ],
            'auth.passkey.authentication_start' => [
                'identifier' => 'nullable|string|max:255',
            ],
            'auth.passkey.authentication_finish' => [
                'credential' => 'required|array',
            ],
            'auth.role.create' => [
                'name' => 'required|string|max:255',
            ],
            'auth.permission.assign' => [
                'account_id' => 'nullable|string|max:255',
                'role_id' => 'nullable|string|max:255',
                'permission_id' => 'required|string|max:255',
            ],
            'auth.delegation.grant' => [
                'principal_id' => 'required|string|max:255',
                'ability' => 'required|string|max:255',
                'resource_type' => 'nullable|string|max:255',
                'resource_id' => 'nullable|string|max:255',
            ],
        ];
    }
}
