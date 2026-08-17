<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Password Hashing
    |--------------------------------------------------------------------------
    |
    | These values are read only when auth.drivers.passwords is `security`.
    | The cryptography provider supplies its secure policy defaults; explicit
    | values below override only the corresponding password settings.
    |
    | Password algorithms: `argon2id|argon2i|bcrypt`. Argon algorithms use
    | positive "memory_cost" (KiB), "time_cost", and "threads" values. Bcrypt
    | uses the positive "cost" value, commonly `10..14`.
    |
    */
    'password' => [
        'algorithm' => env_string('SECURITY_PASSWORD_ALGORITHM', 'argon2id'),
        'memory_cost' => env_int('SECURITY_PASSWORD_MEMORY_COST', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
        'time_cost' => env_int('SECURITY_PASSWORD_TIME_COST', PASSWORD_ARGON2_DEFAULT_TIME_COST),
        'threads' => env_int('SECURITY_PASSWORD_THREADS', PASSWORD_ARGON2_DEFAULT_THREADS),
        'cost' => env_int('SECURITY_PASSWORD_BCRYPT_COST', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Web Tokens
    |--------------------------------------------------------------------------
    |
    | These values are read only when auth.drivers.tokens is `security`.
    | Epicrypt 2.1 requires explicit non-empty issuer and audience policy values;
    | Foundation therefore fails closed when either value is missing. The maximum
    | lifetime is the longest JWT the verifier will accept and defaults to the
    | framework's 14-day refresh-token lifetime. Increase it explicitly only when
    | the application intentionally issues longer-lived JWTs. Signing continues to
    | use auth.token_secret; this section does not introduce another secret.
    |
    */
    'jwt' => [
        'audience' => env('SECURITY_JWT_AUDIENCE'),
        'issuer' => env('SECURITY_JWT_ISSUER'),
        'maximum_lifetime_seconds' => env_int('SECURITY_JWT_MAXIMUM_LIFETIME_SECONDS', 1209600),
        'leeway_seconds' => env_int('SECURITY_JWT_LEEWAY_SECONDS', 0),
    ],

    /**
     * Integrity Hashing
     *
     * The algorithm is used by the security manager's fileHasher(), stringHasher(),
     * hashFile(), and hashString() when no method-level algorithm is supplied.
     * It may be any algorithm supported by PHP's hash extension.
     *
     * Examples:
     * Recommended values are `sha256|sha384|sha512`.
     */
    'integrity' => [
        'algorithm' => env_string('SECURITY_INTEGRITY_ALGORITHM', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Rings
    |--------------------------------------------------------------------------
    |
    | Each named ring has an "active" key id and a "keys" map. A key may be a
    | secret string or an array with "key" plus optional metadata. Status:
    | `active|fallback|retired|disabled`. "not_before" and "not_after" are Unix
    | timestamps; "purpose" is an application value such as `customer-pii`.
    |
    | 'customer_data' => [
    |     'active' => '2026-07',
    |     'keys' => [
    |         '2026-07' => [
    |             'key' => env('DATA_KEY_CURRENT'),
    |             'status' => 'active',
    |             'purpose' => 'customer-pii',
    |         ],
    |         '2026-01' => [
    |             'key' => env('DATA_KEY_PREVIOUS'),
    |             'status' => 'fallback',
    |         ],
    |     ],
    | ],
    |
    */
    'key_rings' => [],
];
