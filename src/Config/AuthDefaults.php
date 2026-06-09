<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class AuthDefaults
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'auth' => [
                'cachelayer' => [
                    'namespace' => 'foundation-auth',
                    'store' => 'memory',
                ],
                'drivers' => [
                    'cache' => 'array',
                    'mfa' => 'simple',
                    'notifications' => 'collect',
                    'passkey' => 'memory',
                    'passwords' => 'native',
                    'security' => 'native',
                    'storage' => 'memory',
                    'tokens' => 'simple',
                ],
                'email_verification_ttl' => 3600,
                'epicrypt' => [
                    'password' => [],
                    'tokens' => [
                        'audience' => null,
                        'issuer' => null,
                        'leeway_seconds' => 0,
                    ],
                ],
                'lockout' => [
                    'lock_seconds' => 900,
                    'max_login_failures' => 5,
                    'max_mfa_failures' => 5,
                    'max_passkey_failures' => 5,
                    'window_seconds' => 900,
                ],
                'mfa_challenge_ttl' => 300,
                'mfa_default_code' => '000000',
                'mfa_satisfied_ttl' => 900,
                'passkey_challenge_ttl' => 300,
                'password_reset_ttl' => 3600,
                'passwordless_ttl' => 900,
                'recent_auth_window' => 900,
                'refresh_token_ttl' => 1209600,
                'remember_me_ttl' => 2592000,
                'session_ttl' => 3600,
                'token_secret' => 'foundation-dev-secret',
            ],
        ];
    }
}
