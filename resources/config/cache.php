<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Store And Namespace
    |--------------------------------------------------------------------------
    |
    | "default" names the store used when callers do not select one. Shipped
    | names: `auth|file|local|memory|null|php_files|sqlite|database|redis|`
    | `redis_cluster|valkey|memcached|shared_memory|weak_map|tiered`.
    | "prefix" namespaces entries; example: `acme:production:cache:`.
    |
    */
    'default' => env('CACHE_STORE', env('APP_ENV', 'local') === 'production' ? 'tiered' : 'local'),
    'prefix' => env_string('CACHE_PREFIX', 'infbyte:cache:'),

    /*
    |--------------------------------------------------------------------------
    | Payload Compression
    |--------------------------------------------------------------------------
    |
    | "threshold_bytes" enables compression for payloads at or above the given
    | size; zero disables it. "level" is the backend compression level and
    | should be balanced against CPU capacity using production measurements.
    | Threshold is null/disabled or a positive byte count such as `1024`.
    | Compression level is `1..9`.
    |
    */
    'compression' => [
        'threshold_bytes' => env_int('CACHE_COMPRESSION_THRESHOLD_BYTES', 0) > 0
            ? env_int('CACHE_COMPRESSION_THRESHOLD_BYTES', 0)
            : null,
        'level' => env_int('CACHE_COMPRESSION_LEVEL', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Payload Security
    |--------------------------------------------------------------------------
    |
    | "integrity_key" authenticates stored payloads when configured.
    | "max_payload_bytes" rejects oversized serialized values before they can
    | exhaust process or backend resources. Supply integrity keys as secrets.
    | Example key format: a random 32-byte Base64 string. Maximum payload is a
    | positive byte count, for example `8388608` for 8 MiB, or null for no limit.
    |
    */
    'security' => [
        'integrity_key' => env('CACHE_INTEGRITY_KEY'),
        'max_payload_bytes' => env_int('CACHE_MAX_PAYLOAD_BYTES', 8_388_608),
    ],

    /*
    |--------------------------------------------------------------------------
    | Serialization Policy
    |--------------------------------------------------------------------------
    |
    | "allow_closure_payloads" and "allow_object_payloads" permit those PHP
    | value types in cache serialization. Disable either capability when cache
    | contents cross trust boundaries or the application only stores scalars.
    | Both keys accept `true|false`.
    |
    */
    'serialization' => [
        'allow_closure_payloads' => env_bool('CACHE_ALLOW_CLOSURE_PAYLOADS', true),
        'allow_object_payloads' => env_bool('CACHE_ALLOW_OBJECT_PAYLOADS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Each store declares a "driver" plus driver-specific connection, storage,
    | and lock settings. File-like stores use "path"; PDO stores use "table"
    | and either "path" or "connection"; Redis and Valkey use "dsn".
    | Drivers: `apcu|file|local|memcache|memory|mongodb|node|null_store|pdo|`
    | `php_files|redis|redis_cluster|scylladb|shared_memory|sqlite|tiered|`
    | `valkey|weak_map`; aliases include `array`, `memcached`, `null`, `scylla`.
    |
    | Redis cluster "seeds" is a comma-separated host list. "timeout" and
    | "read_timeout" are seconds, while "persistent" controls connection reuse.
    | Memcached server entries define "host", "port", and selection "weight".
    | Shared memory "segment_size" is bytes. Memory, null, and weak-map stores
    | require no additional keys and are process-local or non-persistent.
    |
    | Tiered caching reads the ordered "tiers" and writes through to L1 when
    | `write_to_l1` is true. Its lock uses `driver` and `path`.
    | "retry_sleep_micros" is the delay between acquisition attempts. PDO lock
    | sections use "driver" and a namespaced "prefix". Lock drivers:
    | `file|pdo|redis|valkey`. Example paths: `storage/cache/local`; table:
    | `cachelayer_entries`; DSN: `redis://127.0.0.1:6379`; cluster seeds:
    | `10.0.0.10:6379,10.0.0.11:6379`; connection: `mysql`.
    |
    | Timeout values are positive seconds such as `1.0`; persistent and
    | write-through switches accept `true|false`; Memcached ports are `1..65535`
    | and weights are non-negative integers. Tier entries use store names, for
    | example `memory` then `sqlite`. Retry delay is microseconds, e.g. `50000`.
    |
    */
    'stores' => [
        'auth' => [
            'driver' => 'local',
            'path' => storage_path('cache/auth'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('cache/file'),
        ],
        'local' => [
            'driver' => 'local',
            'path' => storage_path('cache/local'),
        ],
        'memory' => [
            'driver' => 'memory',
        ],
        'null' => [
            'driver' => 'null',
        ],
        'php_files' => [
            'driver' => 'php_files',
            'path' => storage_path('cache/php-files'),
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'path' => env_string('CACHE_SQLITE_PATH', storage_path('cache/cachelayer.sqlite')),
            'table' => env_string('CACHE_SQLITE_TABLE', 'cachelayer_entries'),
            'lock' => [
                'driver' => 'pdo',
                'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
            ],
        ],
        'database' => [
            'driver' => 'pdo',
            'connection' => env_string('CACHE_DB_CONNECTION', env_string('DB_CONNECTION', env_string('DB_DRIVER', 'sqlite'))),
            'table' => env_string('CACHE_TABLE', 'cachelayer_entries'),
            'lock' => [
                'driver' => 'pdo',
                'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
            ],
        ],
        'redis' => [
            'driver' => 'redis',
            'dsn' => env_string('CACHE_REDIS_DSN', 'redis://127.0.0.1:6379'),
        ],
        'redis_cluster' => [
            'driver' => 'redis_cluster',
            'seeds' => array_values(array_filter(array_map(
                trim(...),
                explode(',', env_string('CACHE_REDIS_CLUSTER_SEEDS', '127.0.0.1:6379')),
            ))),
            'timeout' => env('CACHE_REDIS_CLUSTER_TIMEOUT', 1.0),
            'read_timeout' => env('CACHE_REDIS_CLUSTER_READ_TIMEOUT', 1.0),
            'persistent' => env_bool('CACHE_REDIS_CLUSTER_PERSISTENT', false),
        ],
        'valkey' => [
            'driver' => 'valkey',
            'dsn' => env_string('CACHE_VALKEY_DSN', 'valkey://127.0.0.1:6379'),
        ],
        'memcached' => [
            'driver' => 'memcached',
            'servers' => [
                [
                    'host' => env_string('CACHE_MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env_int('CACHE_MEMCACHED_PORT', 11211),
                    'weight' => env_int('CACHE_MEMCACHED_WEIGHT', 0),
                ],
            ],
        ],
        'shared_memory' => [
            'driver' => 'shared_memory',
            'segment_size' => env_int('CACHE_SHARED_MEMORY_SEGMENT_SIZE', 16_777_216),
        ],
        'weak_map' => [
            'driver' => 'weak_map',
        ],
        'tiered' => [
            'driver' => 'tiered',
            'write_to_l1' => env_bool('CACHE_TIERED_WRITE_TO_L1', true),
            'tiers' => [
                ['store' => 'memory'],
                ['store' => env_string('CACHE_TIERED_BACKING_STORE', 'sqlite')],
            ],
            'lock' => [
                'driver' => 'file',
                'path' => storage_path('cache/locks'),
                'retry_sleep_micros' => env_int('CACHE_LOCK_RETRY_SLEEP_MICROS', 50_000),
            ],
        ],
    ],
];
