<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);

    return $value === false ? $default : $value;
};

$envString = static function (string $key, string $default) use ($env): string {
    $value = $env($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env($key);

    return is_numeric($value) ? (int) $value : $default;
};

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Password Hashing
    |--------------------------------------------------------------------------
    |
    | Foundation maps this application policy to Epicrypt PasswordHashOptions.
    | Supported algorithms are argon2id, argon2i, and bcrypt. Argon memory,
    | time, and thread settings apply to Argon variants; cost applies to bcrypt.
    | General Epicrypt password APIs remain native and are not mirrored by
    | Foundation.
    |
    */
    'password' => [
        'algorithm' => $envString('SECURITY_PASSWORD_ALGORITHM', 'argon2id'),
        'memory_cost' => $envInt('SECURITY_PASSWORD_MEMORY_COST', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
        'time_cost' => $envInt('SECURITY_PASSWORD_TIME_COST', PASSWORD_ARGON2_DEFAULT_TIME_COST),
        'threads' => $envInt('SECURITY_PASSWORD_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        'cost' => $envInt('SECURITY_PASSWORD_BCRYPT_COST', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Token Policy
    |--------------------------------------------------------------------------
    |
    | These values are consumed only when auth.drivers.tokens is "security".
    | Algorithm accepts HS256, HS384, or HS512. HS256 is the default so the
    | Foundation production minimum of a 32-byte auth token secret is valid.
    | HS384 and HS512 require correspondingly larger raw secrets as enforced by
    | Epicrypt. Foundation supplies issuer/audience/lifetime policy while
    | Epicrypt owns JWT encoding, signing, parsing, and verification.
    |
    */
    'jwt' => [
        'algorithm' => $envString('SECURITY_JWT_ALGORITHM', 'HS256'),
        'audience' => $env('SECURITY_JWT_AUDIENCE'),
        'issuer' => $env('SECURITY_JWT_ISSUER'),
        'maximum_lifetime_seconds' => $envInt('SECURITY_JWT_MAXIMUM_LIFETIME_SECONDS', 1209600),
        'leeway_seconds' => $envInt('SECURITY_JWT_LEEWAY_SECONDS', 0),
    ],
];
