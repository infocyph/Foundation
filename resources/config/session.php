<?php

declare(strict_types=1);

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
    'driver' => env_string('SESSION_DRIVER', 'file'),
    'lifetime' => env_int('SESSION_LIFETIME', 7_200),
    'max_payload_bytes' => env_int('SESSION_MAX_PAYLOAD_BYTES', 65_536),

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
        'name' => env_string('SESSION_COOKIE', 'infbyte_session'),
        'path' => env_string('SESSION_COOKIE_PATH', '/'),
        'domain' => env('SESSION_COOKIE_DOMAIN'),
        'secure' => env_bool('SESSION_COOKIE_SECURE', true),
        'http_only' => env_bool('SESSION_COOKIE_HTTP_ONLY', true),
        'same_site' => env_string('SESSION_COOKIE_SAME_SITE', 'Lax'),
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
            'path' => env_string('SESSION_FILE_PATH', storage_path('sessions')),
        ],
        'cache' => [
            'store' => env('SESSION_CACHE_STORE'),
        ],
        'database' => [
            'connection' => env('SESSION_DB_CONNECTION'),
            'table' => env_string('SESSION_DB_TABLE', 'sessions'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrent Request Locking
    |--------------------------------------------------------------------------
    |
    | Locking prevents two requests carrying the same session ID from losing
    | each other's writes. "enabled" accepts `true|false`. When enabled,
    | CacheLayer must be installed. "store" selects a cache store whose lock
    | configuration may use `file|redis|valkey|memcache|memcached|pdo`;
    | examples: `local`, `redis`, `memcached`, `sqlite`, or `database`.
    |
    | "wait" is the maximum acquisition wait in seconds, including decimals
    | such as `2.0`; zero means do not wait. "lease" is a positive lock lease
    | in seconds, such as `30.0`. Locks are acquired lazily only when a valid
    | incoming session is first read and are always released after dispatch.
    |
    */
    'lock' => [
        'enabled' => env_bool('SESSION_LOCK_ENABLED', false),
        'store' => env('SESSION_LOCK_STORE'),
        'wait' => env('SESSION_LOCK_WAIT', 2.0),
        'lease' => env('SESSION_LOCK_LEASE', 30.0),
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
        'header' => env_string('SESSION_CSRF_HEADER', 'X-CSRF-Token'),
        'field' => env_string('SESSION_CSRF_FIELD', '_token'),
        'check_origin' => env_bool('SESSION_CSRF_CHECK_ORIGIN', true),
        'origin' => env('SESSION_CSRF_ORIGIN'),
    ],
];
