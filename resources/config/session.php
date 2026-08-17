<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return $value === false || $value === null ? $default : $value;
};

$envString = static function (string $key, string $default) use ($env): string {
    $value = $env($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env($key);

    return is_numeric($value) ? (int) $value : $default;
};

$envFloat = static function (string $key, float $default) use ($env): float {
    $value = $env($key);

    return is_numeric($value) ? (float) $value : $default;
};

$envBool = static function (string $key, bool $default) use ($env): bool {
    $value = $env($key);
    if (is_bool($value)) {
        return $value;
    }
    if (!is_string($value) && !is_int($value)) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

$basePath = dirname(__DIR__);

return [
    /*
    |--------------------------------------------------------------------------
    | Browser Session Store
    |--------------------------------------------------------------------------
    |
    | "driver" accepts `array|file|cache|database`. The `array` store is
    | process-local and intended for tests. `file` is the dependency-free
    | default. `cache` requires the CacheLayer module and `database` requires
    | the DBLayer module. Session services remain unloaded until a route uses
    | the `session` or `csrf` middleware.
    |
    | "lifetime" is the idle lifetime in seconds and must be a positive
    | integer; examples: `7200` (two hours) or `1209600` (fourteen days).
    | "max_payload_bytes" is the positive encoded payload limit; examples:
    | `65536` (64 KiB) or `262144` (256 KiB).
    |
    */
    'driver' => $envString('SESSION_DRIVER', 'file'),
    'lifetime' => $envInt('SESSION_LIFETIME', 7_200),
    'max_payload_bytes' => $envInt('SESSION_MAX_PAYLOAD_BYTES', 65_536),

    /*
    |--------------------------------------------------------------------------
    | Session Cookie
    |--------------------------------------------------------------------------
    |
    | "name" is an RFC 6265 cookie name, for example `infbyte_session`.
    | "path" is normally `/`. "domain" is null for a host-only cookie or a
    | domain such as `.example.com`. "secure" and "http_only" accept
    | `true|false`. "same_site" accepts `Lax|Strict|None`; `None` requires
    | "secure" to be true. Production HTTPS applications should keep both
    | security flags enabled.
    |
    */
    'cookie' => [
        'name' => $envString('SESSION_COOKIE', 'infbyte_session'),
        'path' => $envString('SESSION_COOKIE_PATH', '/'),
        'domain' => $env('SESSION_COOKIE_DOMAIN'),
        'secure' => $envBool('SESSION_COOKIE_SECURE', true),
        'http_only' => $envBool('SESSION_COOKIE_HTTP_ONLY', true),
        'same_site' => $envString('SESSION_COOKIE_SAME_SITE', 'Lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Settings
    |--------------------------------------------------------------------------
    |
    | File "path" is an absolute or application-resolved directory, for
    | example `storage/sessions`. Cache "store" is null for the default cache
    | store or a configured name such as `redis`, `memcached`, or `sqlite`.
    | Database "connection" is null for the default DBLayer connection or a
    | name such as `mysql`, `pgsql`, or `sqlite`; "table" is a portable SQL
    | identifier such as `sessions`.
    |
    | Stored values must be JSON-serializable PHP scalars/arrays. Cache
    | backends expire records themselves. File and database stores are pruned
    | explicitly, never by random request-time garbage collection.
    |
    */
    'stores' => [
        'file' => [
            'path' => $envString('SESSION_FILE_PATH', $basePath . '/storage/sessions'),
        ],
        'cache' => [
            'store' => $env('SESSION_CACHE_STORE'),
        ],
        'database' => [
            'connection' => $env('SESSION_DB_CONNECTION'),
            'table' => $envString('SESSION_DB_TABLE', 'sessions'),
        ],
    ],

    /**
     * Concurrent Request Locking
     *
     * Locking prevents two requests carrying the same session ID from losing
     * each other's writes. "enabled" accepts `true|false`. When enabled,
     * CacheLayer must be installed. "store" selects a cache store whose lock
     * configuration may use `file|redis|valkey|memcache|memcached|pdo`.
     *
     * Examples:
     * Stores include `local`, `redis`, `memcached`, `sqlite`, and `database`.
     * "wait" is the maximum acquisition wait in seconds, including decimals
     * such as `2.0`; zero means do not wait. "lease" is a positive lock lease
     * in seconds, such as `30.0`. Locks are acquired lazily only when a valid
     * incoming session is first read and are always released after dispatch.
     */
    'lock' => [
        'enabled' => $envBool('SESSION_LOCK_ENABLED', false),
        'store' => $env('SESSION_LOCK_STORE'),
        'wait' => $envFloat('SESSION_LOCK_WAIT', 2.0),
        'lease' => $envFloat('SESSION_LOCK_LEASE', 30.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-Site Request Forgery Protection
    |--------------------------------------------------------------------------
    |
    | "header" and "field" are non-empty names; examples are `X-CSRF-Token`
    | and `_token`. Unsafe requests may provide the token in either location.
    | Query-string and cookie tokens are never accepted.
    |
    | "check_origin" accepts `true|false`. When enabled, a supplied Origin
    | header must match "origin"; null derives the expected origin from the
    | request URI. An explicit origin example is `https://app.example.com`.
    |
    */
    'csrf' => [
        'header' => $envString('SESSION_CSRF_HEADER', 'X-CSRF-Token'),
        'field' => $envString('SESSION_CSRF_FIELD', '_token'),
        'check_origin' => $envBool('SESSION_CSRF_CHECK_ORIGIN', true),
        'origin' => $env('SESSION_CSRF_ORIGIN'),
    ],
];
