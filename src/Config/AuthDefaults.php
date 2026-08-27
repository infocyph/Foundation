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
                'drivers' => [
                    'cache' => 'array',
                    'mfa' => 'simple',
                    'notifications' => 'collect',
                    'passkey' => 'memory',
                    'passwords' => 'native',
                    'storage' => 'memory',
                    'tokens' => 'simple',
                ],
                'email_verification_ttl' => 3600,
                'http' => [
                    'bearer_header' => 'Authorization',
                    'bearer_prefix' => 'Bearer ',
                    'principal_resolvers' => [
                        'session',
                        'bearer',
                        'remember',
                    ],
                    'remember_cookie' => 'foundation_remember',
                    'remember_header' => 'X-Remember-Token',
                    'session_cookie' => 'foundation_session',
                    'session_header' => 'X-Session-Id',
                ],
                'lockout' => [
                    'lock_seconds' => 900,
                    'max_login_failures' => 5,
                    'max_mfa_failures' => 5,
                    'max_passkey_failures' => 5,
                    'window_seconds' => 900,
                ],
                'oauth' => self::oauth(),
                'otp' => [
                    'issuer' => 'Foundation',
                    'hotp' => [
                        'look_ahead' => 5,
                    ],
                    'totp' => [
                        'algorithm' => 'sha1',
                        'digits' => 6,
                        'period' => 30,
                        'secret_bytes' => 20,
                        'window' => 1,
                    ],
                    'recovery_codes' => [
                        'count' => 10,
                        'length' => 12,
                    ],
                    'replay' => [
                        'store' => null,
                        'ttl' => 90,
                    ],
                ],
                'password_policy' => [
                    'min_length' => 12,
                    'max_length' => 1024,
                ],
                'webauthn' => [
                    'algorithms' => [
                        'ES256',
                        'RS256',
                    ],
                    'attestation' => 'none',
                    'challenge_ttl' => 300,
                    'origin' => null,
                    'resident_key' => 'preferred',
                    'rp_id' => null,
                    'rp_name' => 'Foundation',
                    'timeout' => 60000,
                    'transports' => [
                        'internal',
                        'hybrid',
                        'usb',
                        'nfc',
                        'ble',
                    ],
                    'user_verification' => 'preferred',
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
                'token_secret' => null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function oauth(): array
    {
        $enabled = env_bool('AUTH_OAUTH_ENABLED', false);

        return [
            'enabled' => $enabled,
            'issuer' => $enabled ? env('AUTH_OAUTH_ISSUER') : null,
            'access_token_ttl' => 300,
            'authorization_code_ttl' => 60,
            'refresh_token_ttl' => 1209600,
            'grants' => [
                'authorization_code',
                'client_credentials',
                'refresh_token',
            ],
            'pkce_methods' => ['S256'],
            'rate_limit_store' => null,
            'resource_audiences' => [],
            'scope_permissions' => [],
            'signing' => [
                'algorithm' => 'RS256',
                'active_key_id' => $enabled ? env('AUTH_OAUTH_ACTIVE_KEY_ID') : null,
                'private_key' => $enabled ? env('AUTH_OAUTH_PRIVATE_KEY') : null,
                'public_keys' => self::oauthPublicKeys($enabled),
            ],
            'routes' => [
                'authorization' => '/oauth/authorize',
                'token' => '/oauth/token',
                'revocation' => '/oauth/revoke',
                'introspection' => '/oauth/introspect',
                'jwks' => '/.well-known/jwks.json',
            ],
            'rate_limits' => [
                'authorization' => ['max' => 60, 'window' => 60],
                'token' => ['max' => 30, 'window' => 60],
                'revocation' => ['max' => 60, 'window' => 60],
                'introspection' => ['max' => 120, 'window' => 60],
            ],
        ];
    }

    /** @return list<mixed> */
    private static function oauthPublicKeys(bool $enabled): array
    {
        $encoded = $enabled ? env('AUTH_OAUTH_PUBLIC_KEYS') : null;
        if ($encoded === null || $encoded === '') {
            return [];
        }
        if (!is_string($encoded)) {
            throw new \UnexpectedValueException('AUTH_OAUTH_PUBLIC_KEYS must be a JSON list.');
        }

        try {
            $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException(
                'AUTH_OAUTH_PUBLIC_KEYS must be a valid JSON list.',
                previous: $exception,
            );
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \UnexpectedValueException('AUTH_OAUTH_PUBLIC_KEYS must be a JSON list.');
        }

        return $decoded;
    }
}
