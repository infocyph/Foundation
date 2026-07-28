<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | This value names the connection used when a database operation does not
    | request one explicitly. It must match a key in "connections" below.
    | Shipped values: `mysql|pgsql|sqlite`; custom connection names are allowed.
    |
    */
    'default' => env_string('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Long-Running Connection Pool
    |--------------------------------------------------------------------------
    |
    | Pooling is initialized only by pool()/poolManager() or a pooled database
    | operation; ordinary PHP-FPM requests continue to use DBLayer's shared
    | request connection. All values are integer counts or seconds.
    |
    | "max_connections" is the hard process limit, "idle_timeout" retires
    | unused connections, "max_lifetime" recycles old connections, and
    | "health_check_interval" controls probes. Examples: `10`, `60`, `3600`,
    | and `30`.
    |
    */
    'pool' => [
        'max_connections' => env_int('DB_POOL_MAX_CONNECTIONS', 10),
        'idle_timeout' => env_int('DB_POOL_IDLE_TIMEOUT', 60),
        'max_lifetime' => env_int('DB_POOL_MAX_LIFETIME', 3_600),
        'health_check_interval' => env_int('DB_POOL_HEALTH_CHECK_INTERVAL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Every connection array is passed to DBLayer's ConnectionConfig, which is
    | the validation and normalization authority. Foundation only resolves
    | application-relative SQLite paths. Drivers and aliases:
    | `mysql|mariadb|pdo_mysql|mysqli`, `pgsql|postgres|postgresql`, and
    | `sqlite|sqlite3`.
    |
    | Core keys: "host" (`db.internal`), "port" (`3306|5432`), "database"
    | (`acme` or `/srv/acme/database/database.sqlite`), "username"
    | (`acme_app`), secret "password", "charset" (`utf8mb4|utf8`),
    | "collation" (`utf8mb4_unicode_ci`), "schema" (`public`), table "prefix"
    | (`acme_`), PDO "options" (`[PDO::ATTR_TIMEOUT => 5]`), generic "timeout"
    | seconds (`5`), and "persistent" (`true|false`). MySQL also accepts
    | "unix_socket", "ssl_ca", "ssl_cert", "ssl_key", and the nullable
    | boolean "ssl_verify_server_cert";
    | PostgreSQL "sslmode" accepts `disable|allow|prefer|require|verify-ca|`
    | `verify-full`.
    |
    | "write" and "read" accept one override, a list of overrides, or a host
    | list. Examples: `['host' => 'writer.internal']`,
    | `[['host' => 'replica-a'], ['host' => 'replica-b', 'weight' => 2]]`,
    | or `['host' => ['replica-a', 'replica-b']]`. Replica strategies:
    | `random|round_robin|least_latency|weighted`; aliases `round-robin`,
    | `least-latency`, `weighted-random`, and `health-aware` are normalized by
    | DBLayer. "sticky" preserves read-after-write consistency.
    |
    | Replica timing keys are non-negative seconds: "read_health_cooldown"
    | (`30`) and "read_latency_ttl" (`15`). "read_probe_sample_size" is `0`
    | for all replicas or a positive sample count. "read_session_read_only"
    | applies to MySQL and PostgreSQL. "statement_cache_enabled" and
    | "query_comment_enabled" accept `true|false`; statement cache size is a
    | non-negative entry count such as `64`. Query comment max length is at
    | least `32`, commonly `160`, and "query_comment_context" is a
    | string-keyed map such as `['service' => 'billing']`. Optional
    | diagnostics remain disabled unless explicitly enabled.
    |
    | Security keys: "enabled" and "strict_identifiers" default true;
    | "max_sql_length", "max_params", and "max_param_bytes" are positive
    | limits; per-second/per-minute query limits use `0` to disable;
    | "rate_limit_key" is an identifier such as `tenant:42`; callbacks should
    | be injected at runtime rather than stored in cached config.
    | "require_tls" applies to client/server drivers and accepts
    | `true|false|null`; "allow_insecure" accepts `true|false`;
    | "raw_sql_policy" accepts `allow|deny|allowlist`; "raw_sql_allowlist" is
    | a list of approved SQL fingerprints/patterns; and "cursor_signing_key"
    | is null or a stable secret of at least 32 bytes shared by every node.
    |
    */
    'connections' => [
        /*
        |--------------------------------------------------------------------------
        | MySQL / MariaDB
        |--------------------------------------------------------------------------
        |
        | Driver values `mysql|mariadb|pdo_mysql|mysqli` normalize to MySQL.
        | Use either a TCP host such as `127.0.0.1` with port `3306`, or a
        | Unix socket such as `/var/run/mysqld/mysqld.sock`. Typical charset
        | and collation values are `utf8mb4` and `utf8mb4_unicode_ci`.
        |
        | TLS material is supplied as deployment paths, for example
        | `/run/secrets/mysql-ca.pem`, `/run/secrets/mysql-client.pem`, and
        | `/run/secrets/mysql-client-key.pem`. "ssl_verify_server_cert" is
        | `true|false|null`; null leaves PDO's driver default unchanged.
        | PostgreSQL's "sslmode" key is intentionally not accepted here.
        | PDO attributes may be placed in "options" for lower-level control.
        |
        */
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'infbyte'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env_string('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => env_string('DB_PREFIX', ''),
            'options' => [],
            'timeout' => env_int('DB_TIMEOUT', 5),
            'persistent' => env_bool('DB_PERSISTENT', false),
            'unix_socket' => env('DB_SOCKET'),
            'ssl_ca' => env('DB_SSL_CA'),
            'ssl_cert' => env('DB_SSL_CERT'),
            'ssl_key' => env('DB_SSL_KEY'),
            'ssl_verify_server_cert' => env('DB_SSL_VERIFY_SERVER_CERT'),
            'write' => [],
            'read' => [],
            'read_strategy' => env_string('DB_READ_STRATEGY', 'random'),
            'read_health_cooldown' => env_int('DB_READ_HEALTH_COOLDOWN', 30),
            'read_latency_ttl' => env_int('DB_READ_LATENCY_TTL', 15),
            'read_probe_sample_size' => env_int('DB_READ_PROBE_SAMPLE_SIZE', 0),
            'read_session_read_only' => env_bool('DB_READ_SESSION_READ_ONLY', false),
            'statement_cache_enabled' => env_bool('DB_STATEMENT_CACHE_ENABLED', false),
            'statement_cache_size' => env_int('DB_STATEMENT_CACHE_SIZE', 64),
            'query_comment_enabled' => env_bool('DB_QUERY_COMMENT_ENABLED', false),
            'query_comment_max_length' => env_int('DB_QUERY_COMMENT_MAX_LENGTH', 160),
            'query_comment_context' => [],
            'sticky' => env_bool('DB_STICKY', false),
            'security' => [
                'enabled' => env_bool('DB_SECURITY_ENABLED', true),
                'max_sql_length' => env_int('DB_SECURITY_MAX_SQL_LENGTH', 16_384),
                'max_params' => env_int('DB_SECURITY_MAX_PARAMS', 512),
                'max_param_bytes' => env_int('DB_SECURITY_MAX_PARAM_BYTES', 1_024),
                'queries_per_second' => env_int('DB_QUERIES_PER_SECOND', 0),
                'queries_per_minute' => env_int('DB_QUERIES_PER_MINUTE', 0),
                'rate_limit_key' => env('DB_RATE_LIMIT_KEY'),
                'strict_identifiers' => env_bool('DB_STRICT_IDENTIFIERS', true),
                'require_tls' => env('DB_REQUIRE_TLS'),
                'allow_insecure' => env_bool('DB_ALLOW_INSECURE', false),
                'raw_sql_policy' => env_string('DB_RAW_SQL_POLICY', 'allow'),
                'raw_sql_allowlist' => [],
                'cursor_signing_key' => env('DB_CURSOR_SIGNING_KEY'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL
        |--------------------------------------------------------------------------
        |
        | Driver values `pgsql|postgres|postgresql` normalize to PostgreSQL.
        | A typical network endpoint is `127.0.0.1:5432`; schema examples are
        | `public|reporting|tenant_42`. SSL mode accepts
        | `disable|allow|prefer|require|verify-ca|verify-full`.
        |
        | Every shared DBLayer option below remains independently configurable:
        | writer/read replicas, selection strategy, sticky reads, connection
        | timeout, persistent PDO, statement caching, SQL comments, and SQL
        | security limits. Replica fragments may override any connection key.
        |
        */
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'infbyte'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env_string('DB_CHARSET', 'utf8'),
            'schema' => env_string('DB_SCHEMA', 'public'),
            'prefix' => env_string('DB_PREFIX', ''),
            'options' => [],
            'timeout' => env_int('DB_TIMEOUT', 5),
            'persistent' => env_bool('DB_PERSISTENT', false),
            'sslmode' => env_string('DB_SSLMODE', 'prefer'),
            'write' => [],
            'read' => [],
            'read_strategy' => env_string('DB_READ_STRATEGY', 'random'),
            'read_health_cooldown' => env_int('DB_READ_HEALTH_COOLDOWN', 30),
            'read_latency_ttl' => env_int('DB_READ_LATENCY_TTL', 15),
            'read_probe_sample_size' => env_int('DB_READ_PROBE_SAMPLE_SIZE', 0),
            'read_session_read_only' => env_bool('DB_READ_SESSION_READ_ONLY', false),
            'statement_cache_enabled' => env_bool('DB_STATEMENT_CACHE_ENABLED', false),
            'statement_cache_size' => env_int('DB_STATEMENT_CACHE_SIZE', 64),
            'query_comment_enabled' => env_bool('DB_QUERY_COMMENT_ENABLED', false),
            'query_comment_max_length' => env_int('DB_QUERY_COMMENT_MAX_LENGTH', 160),
            'query_comment_context' => [],
            'sticky' => env_bool('DB_STICKY', false),
            'security' => [
                'enabled' => env_bool('DB_SECURITY_ENABLED', true),
                'max_sql_length' => env_int('DB_SECURITY_MAX_SQL_LENGTH', 16_384),
                'max_params' => env_int('DB_SECURITY_MAX_PARAMS', 512),
                'max_param_bytes' => env_int('DB_SECURITY_MAX_PARAM_BYTES', 1_024),
                'queries_per_second' => env_int('DB_QUERIES_PER_SECOND', 0),
                'queries_per_minute' => env_int('DB_QUERIES_PER_MINUTE', 0),
                'rate_limit_key' => env('DB_RATE_LIMIT_KEY'),
                'strict_identifiers' => env_bool('DB_STRICT_IDENTIFIERS', true),
                'require_tls' => env('DB_REQUIRE_TLS'),
                'allow_insecure' => env_bool('DB_ALLOW_INSECURE', false),
                'raw_sql_policy' => env_string('DB_RAW_SQL_POLICY', 'allow'),
                'raw_sql_allowlist' => [],
                'cursor_signing_key' => env('DB_CURSOR_SIGNING_KEY'),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | SQLite
        |--------------------------------------------------------------------------
        |
        | Driver values `sqlite|sqlite3` normalize to SQLite. "database"
        | accepts `:memory:`, an absolute path such as
        | `/srv/app/database/app.sqlite`, or an application-relative path such
        | as `database/app.sqlite`, which Foundation resolves from base_path.
        |
        | Network, schema, credential, and TLS keys do not apply. Timeout,
        | persistent PDO, table prefixes, statement caching, query comments,
        | sticky routing, read/write database overrides, and SQL security
        | remain available. Client/server read-session and TLS policies are
        | intentionally absent because SQLite cannot apply them.
        |
        */
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
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
            'security' => [
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
            ],
        ],
    ],
];
