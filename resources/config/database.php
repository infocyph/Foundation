<?php

declare(strict_types=1);

$envNullableBool = static function (string $key): ?bool {
    $value = env($key);
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

$security = static function (bool $networked = true) use ($envNullableBool): array {
    $security = [
        'enabled' => env_bool('DB_SECURITY_ENABLED', true),
        'max_sql_length' => env_int('DB_SECURITY_MAX_SQL_LENGTH', 16_384),
        'max_params' => env_int('DB_SECURITY_MAX_PARAMS', 512),
        'max_param_bytes' => env_int('DB_SECURITY_MAX_PARAM_BYTES', 1_024),
        'queries_per_second' => env_int('DB_QUERIES_PER_SECOND', 0),
        'queries_per_minute' => env_int('DB_QUERIES_PER_MINUTE', 0),
        'rate_limit_key' => env('DB_RATE_LIMIT_KEY'),
        'strict_identifiers' => env_bool('DB_STRICT_IDENTIFIERS', true),
        'allow_insecure' => env_bool('DB_ALLOW_INSECURE', false),
        'raw_sql_policy' => env_string('DB_RAW_SQL_POLICY', 'allow'),
        'raw_sql_allowlist' => [],
        'cursor_signing_key' => env('DB_CURSOR_SIGNING_KEY'),
    ];

    if ($networked) {
        $security['require_tls'] = $envNullableBool('DB_REQUIRE_TLS');
    }

    return $security;
};

$shared = static function (string $driver, string $database, bool $networked = true) use ($security): array {
    return [
        'driver' => $driver,
        'database' => $database,
        'prefix' => env_string('DB_PREFIX', ''),
        'options' => [],
        'timeout' => env_int('DB_TIMEOUT', 5),
        'persistent' => env_bool('DB_PERSISTENT', false),
        'write' => [],
        'read' => [],
        'read_strategy' => env_string('DB_READ_STRATEGY', 'random'),
        'read_health_cooldown' => env_int('DB_READ_HEALTH_COOLDOWN', 30),
        'read_latency_ttl' => env_int('DB_READ_LATENCY_TTL', 15),
        'read_probe_sample_size' => env_int('DB_READ_PROBE_SAMPLE_SIZE', 0),
        'statement_cache_enabled' => env_bool('DB_STATEMENT_CACHE_ENABLED', false),
        'statement_cache_size' => env_int('DB_STATEMENT_CACHE_SIZE', 64),
        'query_comment_enabled' => env_bool('DB_QUERY_COMMENT_ENABLED', false),
        'query_comment_max_length' => env_int('DB_QUERY_COMMENT_MAX_LENGTH', 160),
        'query_comment_context' => [],
        'sticky' => env_bool('DB_STICKY', false),
        'security' => $security($networked),
    ];
};

$mysqlFamily = static function (string $driver, string $defaultUser) use ($envNullableBool, $shared): array {
    return array_replace($shared($driver, env_string('DB_DATABASE', 'infbyte')), [
        'host' => env_string('DB_HOST', '127.0.0.1'),
        'port' => env_int('DB_PORT', 3306),
        'username' => env_string('DB_USERNAME', $defaultUser),
        'password' => env_string('DB_PASSWORD', ''),
        'charset' => env_string('DB_CHARSET', 'utf8mb4'),
        'collation' => env_string('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'unix_socket' => env('DB_SOCKET'),
        'ssl_ca' => env('DB_SSL_CA'),
        'ssl_cert' => env('DB_SSL_CERT'),
        'ssl_key' => env('DB_SSL_KEY'),
        'ssl_verify_server_cert' => $envNullableBool('DB_SSL_VERIFY_SERVER_CERT'),
        'read_session_read_only' => env_bool('DB_READ_SESSION_READ_ONLY', false),
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
    'default' => env_string('DB_CONNECTION', 'sqlite'),

    /**
     * Migrations and Seeders
     *
     * Foundation only supplies application conventions. DBLayer remains the
     * migration/schema engine. Classes are explicit so runtime boot never scans
     * migration directories.
     */
    'migrations' => [
        'classes' => [],
        'table' => env_string('DB_MIGRATION_TABLE', 'migrations'),
        'lock_store' => env('DB_MIGRATION_LOCK_STORE'),
        'lock_wait_seconds' => env('DB_MIGRATION_LOCK_WAIT', 10.0),
        'lock_lease_seconds' => env('DB_MIGRATION_LOCK_LEASE', 300.0),
    ],
    'seeders' => [],

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
        'mysql' => $mysqlFamily('mysql', 'root'),
        'mariadb' => $mysqlFamily('mariadb', 'root'),

        'pgsql' => array_replace($shared('pgsql', env_string('DB_DATABASE', 'infbyte')), [
            'host' => env_string('DB_HOST', '127.0.0.1'),
            'port' => env_int('DB_PORT', 5432),
            'username' => env_string('DB_USERNAME', 'postgres'),
            'password' => env_string('DB_PASSWORD', ''),
            'charset' => env_string('DB_CHARSET', 'utf8'),
            'schema' => env_string('DB_SCHEMA', 'public'),
            'sslmode' => env_string('DB_SSLMODE', 'prefer'),
            'read_session_read_only' => env_bool('DB_READ_SESSION_READ_ONLY', false),
        ]),

        'mssql' => array_replace($shared('mssql', env_string('DB_DATABASE', 'infbyte')), [
            'host' => env_string('DB_HOST', '127.0.0.1'),
            'port' => env_int('DB_PORT', 1433),
            'username' => env_string('DB_USERNAME', 'sa'),
            'password' => env_string('DB_PASSWORD', ''),
            'encrypt' => env_bool('DB_ENCRYPT', true),
            'trust_server_certificate' => env_bool('DB_TRUST_SERVER_CERTIFICATE', false),
            'application_intent' => env_string('DB_APPLICATION_INTENT', 'ReadWrite'),
        ]),

        'sqlite' => $shared(
            'sqlite',
            env_string('DB_DATABASE', 'database/database.sqlite'),
            false,
        ),
    ],
];
