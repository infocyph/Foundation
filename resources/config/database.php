<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return $value === false || $value === null || $value === '' ? $default : $value;
};

$envString = static function (string $key, string $default) use ($env): string {
    $value = $env($key);

    return is_string($value) && $value !== '' ? $value : $default;
};

$envInt = static function (string $key, int $default) use ($env): int {
    $value = $env($key);

    return is_numeric($value) ? (int) $value : $default;
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

$envNullableBool = static function (string $key) use ($env): ?bool {
    $value = $env($key);
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
};

$databasePath = static fn(string $path): string => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database'
    . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);

$security = static function (bool $networked = true) use ($env, $envBool, $envInt, $envString, $envNullableBool): array {
    $security = [
        'enabled' => $envBool('DB_SECURITY_ENABLED', true),
        'max_sql_length' => $envInt('DB_SECURITY_MAX_SQL_LENGTH', 16_384),
        'max_params' => $envInt('DB_SECURITY_MAX_PARAMS', 512),
        'max_param_bytes' => $envInt('DB_SECURITY_MAX_PARAM_BYTES', 1_024),
        'queries_per_second' => $envInt('DB_QUERIES_PER_SECOND', 0),
        'queries_per_minute' => $envInt('DB_QUERIES_PER_MINUTE', 0),
        'rate_limit_key' => $env('DB_RATE_LIMIT_KEY'),
        'strict_identifiers' => $envBool('DB_STRICT_IDENTIFIERS', true),
        'allow_insecure' => $envBool('DB_ALLOW_INSECURE', false),
        'raw_sql_policy' => $envString('DB_RAW_SQL_POLICY', 'allow'),
        'raw_sql_allowlist' => [],
        'cursor_signing_key' => $env('DB_CURSOR_SIGNING_KEY'),
    ];

    if ($networked) {
        $security['require_tls'] = $envNullableBool('DB_REQUIRE_TLS');
    }

    return $security;
};

$shared = static function (string $driver, string $database, bool $networked = true) use (
    $env,
    $envBool,
    $envInt,
    $envString,
    $security,
): array {
    return [
        'driver' => $driver,
        'database' => $database,
        'prefix' => $envString('DB_PREFIX', ''),
        'options' => [],
        'timeout' => $envInt('DB_TIMEOUT', 5),
        'persistent' => $envBool('DB_PERSISTENT', false),
        'write' => [],
        'read' => [],
        'read_strategy' => $envString('DB_READ_STRATEGY', 'random'),
        'read_health_cooldown' => $envInt('DB_READ_HEALTH_COOLDOWN', 30),
        'read_latency_ttl' => $envInt('DB_READ_LATENCY_TTL', 15),
        'read_probe_sample_size' => $envInt('DB_READ_PROBE_SAMPLE_SIZE', 0),
        'statement_cache_enabled' => $envBool('DB_STATEMENT_CACHE_ENABLED', false),
        'statement_cache_size' => $envInt('DB_STATEMENT_CACHE_SIZE', 64),
        'query_comment_enabled' => $envBool('DB_QUERY_COMMENT_ENABLED', false),
        'query_comment_max_length' => $envInt('DB_QUERY_COMMENT_MAX_LENGTH', 160),
        'query_comment_context' => [],
        'sticky' => $envBool('DB_STICKY', false),
        'security' => $security($networked),
    ];
};

$mysqlFamily = static function (string $driver, string $defaultUser) use (
    $env,
    $envBool,
    $envInt,
    $envNullableBool,
    $envString,
    $shared,
): array {
    return array_replace($shared($driver, (string) $env('DB_DATABASE', 'infbyte')), [
        'host' => $envString('DB_HOST', '127.0.0.1'),
        'port' => $envInt('DB_PORT', 3306),
        'username' => $envString('DB_USERNAME', $defaultUser),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset' => $envString('DB_CHARSET', 'utf8mb4'),
        'collation' => $envString('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'unix_socket' => $env('DB_SOCKET'),
        'ssl_ca' => $env('DB_SSL_CA'),
        'ssl_cert' => $env('DB_SSL_CERT'),
        'ssl_key' => $env('DB_SSL_KEY'),
        'ssl_verify_server_cert' => $envNullableBool('DB_SSL_VERIFY_SERVER_CERT'),
        'read_session_read_only' => $envBool('DB_READ_SESSION_READ_ONLY', false),
    ]);
};

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | This value names the connection used when a database operation does not
    | request one explicitly. It must match a key in "connections" below.
    | Shipped values: `mysql|mariadb|pgsql|mssql|sqlite`; custom names are valid.
    |
    */
    'default' => $envString('DB_CONNECTION', 'sqlite'),

    /**
     * Migrations and Seeders
     *
     * Foundation only supplies application conventions. DBLayer remains the
     * migration/schema engine. Classes are explicit so runtime boot never scans
     * migration directories.
     */
    'migrations' => [
        'classes' => [],
        'table' => $envString('DB_MIGRATION_TABLE', 'migrations'),
        'lock_store' => $env('DB_MIGRATION_LOCK_STORE'),
        'lock_wait_seconds' => $env('DB_MIGRATION_LOCK_WAIT', 10.0),
        'lock_lease_seconds' => $env('DB_MIGRATION_LOCK_LEASE', 300.0),
    ],
    'seeders' => [],

    /*
    |--------------------------------------------------------------------------
    | Long-Running Connection Pool
    |--------------------------------------------------------------------------
    |
    | These values are consumed only when Foundation initializes DBLayer's pool.
    | Ordinary one-shot PHP requests do not create a pool implicitly.
    |
    */
    'pool' => [
        'max_connections' => $envInt('DB_POOL_MAX_CONNECTIONS', 10),
        'idle_timeout' => $envInt('DB_POOL_IDLE_TIMEOUT', 60),
        'max_lifetime' => $envInt('DB_POOL_MAX_LIFETIME', 3_600),
        'health_check_interval' => $envInt('DB_POOL_HEALTH_CHECK_INTERVAL', 30),
    ],

    /**
     * Database Connections
     *
     * Foundation forwards each connection to DBLayer ConnectionConfig. DBLayer
     * owns driver normalization, validation, replicas, pooling, security,
     * statement caching, query comments, timeouts, telemetry and query caching.
     * Foundation only resolves application-relative SQLite paths.
     *
     * Canonical drivers: `mysql`, `mariadb`, `pgsql`, `mssql`, `sqlite`.
     * Common aliases accepted by DBLayer include `pdo_mysql|mysqli`,
     * `postgres|postgresql|psql|pdo_pgsql`, `sqlsrv|sqlserver|pdo_sqlsrv`, and
     * `sqlite3|pdo_sqlite`.
     */
    'connections' => [
        /* MySQL and MariaDB are separate DBLayer drivers over the same protocol. */
        'mysql' => $mysqlFamily('mysql', 'root'),
        'mariadb' => $mysqlFamily('mariadb', 'root'),

        'pgsql' => array_replace($shared('pgsql', (string) $env('DB_DATABASE', 'infbyte')), [
            'host' => $envString('DB_HOST', '127.0.0.1'),
            'port' => $envInt('DB_PORT', 5432),
            'username' => $envString('DB_USERNAME', 'postgres'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'charset' => $envString('DB_CHARSET', 'utf8'),
            'schema' => $envString('DB_SCHEMA', 'public'),
            'sslmode' => $envString('DB_SSLMODE', 'prefer'),
            'read_session_read_only' => $envBool('DB_READ_SESSION_READ_ONLY', false),
        ]),

        /* SQL Server uses the canonical DBLayer driver name `mssql`. */
        'mssql' => array_replace($shared('mssql', (string) $env('DB_DATABASE', 'infbyte')), [
            'host' => $envString('DB_HOST', '127.0.0.1'),
            'port' => $envInt('DB_PORT', 1433),
            'username' => $envString('DB_USERNAME', 'sa'),
            'password' => (string) $env('DB_PASSWORD', ''),
            'encrypt' => $envBool('DB_ENCRYPT', true),
            'trust_server_certificate' => $envBool('DB_TRUST_SERVER_CERTIFICATE', false),
            'application_intent' => $envString('DB_APPLICATION_INTENT', 'ReadWrite'),
        ]),

        'sqlite' => $shared(
            'sqlite',
            (string) $env('DB_DATABASE', $databasePath('database.sqlite')),
            false,
        ),
    ],
];
