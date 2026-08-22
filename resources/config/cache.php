<?php
declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Resources
    |--------------------------------------------------------------------------
    |
    | Foundation selects application resource names; CacheLayer owns cache
    | semantics and backend behavior. The default CacheLayer instance is also
    | exposed as PSR-6/PSR-16 and supplied to DBLayer for opt-in query caching.
    |
    */
    'default' => env('CACHE_STORE', 'local'),
    'default_counter' => env('CACHE_COUNTER'),
    'prefix' => env_string('CACHE_PREFIX', 'infbyte:cache:'),

    /*
    | A shared lock provider may be reused by command overlap, scheduling,
    | worker singleton policies, cache stampede protection and other Foundation
    | coordination. Supported drivers are CacheLayer lock drivers.
    */
    'lock' => [
        'driver' => env('CACHE_LOCK_DRIVER'),
        'store' => env_string('CACHE_LOCK_STORE', 'local'),
        'path' => env_string('CACHE_LOCK_PATH', 'storage/cache/locks'),
        'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
        'retry_sleep_micros' => env_int('CACHE_LOCK_RETRY_SLEEP_MICROS', 50_000),
    ],

    'compression' => [
        'threshold_bytes' => env('CACHE_COMPRESSION_THRESHOLD_BYTES'),
        'level' => env_int('CACHE_COMPRESSION_LEVEL', 6),
    ],

    'security' => [
        'integrity_key' => env('CACHE_INTEGRITY_KEY'),
        'max_payload_bytes' => env_int('CACHE_MAX_PAYLOAD_BYTES', 8_388_608),
    ],

    /*
    | PHP object and closure payloads expand the deserialization/trust surface.
    | Foundation keeps both disabled unless an application explicitly opts in;
    | CacheLayer still owns the serializer and integrity implementation.
    */
    'serialization' => [
        'allow_closure_payloads' => env_bool('CACHE_ALLOW_CLOSURE_PAYLOADS', false),
        'allow_object_payloads' => env_bool('CACHE_ALLOW_OBJECT_PAYLOADS', false),
    ],

    /*
    | Named Redis-compatible connection descriptors are a Foundation composition
    | convenience. The resolved DSN/client is handed to CacheLayer unchanged.
    */
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'dsn' => env_string('CACHE_REDIS_DSN', 'redis://127.0.0.1:6379'),
        ],
        'valkey' => [
            'driver' => 'valkey',
            'dsn' => env_string('CACHE_VALKEY_DSN', 'valkey://127.0.0.1:6379'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Named Stores
    |--------------------------------------------------------------------------
    |
    | Store definitions map application names to CacheLayer factories. A
    | `connection` on PDO/Redis/Valkey stores is only a Foundation composition
    | reference; the resulting native client is passed to CacheLayer.
    |
    | Tiered stores are different: every entry under `tiers` is deliberately a
    | CacheLayer-native TieredPoolFactory descriptor. Foundation only resolves
    | relative paths and optional named DB/Redis connection references there.
    |
    */
    'stores' => [
        'apcu' => [
            'driver' => 'apcu',
        ],
        'auth' => [
            'driver' => 'local',
            'path' => 'storage/cache/auth',
        ],
        'file' => [
            'driver' => 'file',
            'path' => 'storage/cache/file',
        ],
        'local' => [
            'driver' => 'local',
            'path' => 'storage/cache/local',
        ],
        'memory' => [
            'driver' => 'memory',
        ],
        'null' => [
            'driver' => 'null',
        ],
        'php_files' => [
            'driver' => 'php_files',
            'path' => 'storage/cache/php-files',
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'path' => env_string('CACHE_SQLITE_PATH', 'storage/cache/cachelayer.sqlite'),
            'lock' => [
                'driver' => 'pdo',
                'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
            ],
        ],
        'database' => [
            'driver' => 'pdo',
            'connection' => env_string('CACHE_DB_CONNECTION', env_string('DB_CONNECTION', 'sqlite')),
            'table' => env_string('CACHE_TABLE', 'cachelayer_entries'),
            'lock' => [
                'driver' => 'pdo',
                'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
            ],
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'redis',
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
            'connection' => 'valkey',
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
        'mongodb' => [
            'driver' => 'mongodb',
            'uri' => env_string('CACHE_MONGODB_URI', 'mongodb://127.0.0.1:27017'),
            'database' => env_string('CACHE_MONGODB_DATABASE', 'cachelayer'),
            'collection_name' => env_string('CACHE_MONGODB_COLLECTION', 'entries'),
        ],
        'node' => [
            'driver' => 'node',
            'sqlite_file' => env_string('CACHE_NODE_SQLITE_FILE', 'storage/cache/node.sqlite'),
            'lock_directory' => env_string('CACHE_NODE_LOCK_DIRECTORY', 'storage/cache/locks'),
            'busy_timeout_ms' => env_int('CACHE_NODE_BUSY_TIMEOUT_MS', 1_000),
            'apcu_enabled' => env_bool('CACHE_NODE_APCU_ENABLED', true),
            'fail_open' => env_bool('CACHE_NODE_FAIL_OPEN', true),
        ],
        'scylladb' => [
            'driver' => 'scylladb',
            'keyspace' => env_string('CACHE_SCYLLADB_KEYSPACE', 'cachelayer'),
            'table' => env_string('CACHE_SCYLLADB_TABLE', 'cachelayer_entries'),
            'bucket_count' => env_int('CACHE_SCYLLADB_BUCKET_COUNT', 128),
        ],
        'tiered' => [
            'driver' => 'tiered',
            'write_to_l1' => env_bool('CACHE_TIERED_WRITE_TO_L1', true),
            'tiers' => [
                [
                    'driver' => 'memory',
                ],
                [
                    'driver' => 'sqlite',
                    'file' => env_string('CACHE_TIERED_SQLITE_FILE', 'storage/cache/tiered.sqlite'),
                ],
            ],
            'lock' => [
                'driver' => 'file',
                'path' => 'storage/cache/locks',
                'retry_sleep_micros' => env_int('CACHE_LOCK_RETRY_SLEEP_MICROS', 50_000),
            ],
        ],
    ],

    /*
    | Atomic counters remain explicit resources because ordinary cache mutation
    | is not a substitute for cross-process atomicity. Supported configured
    | counter drivers are Redis and Valkey.
    */
    'counters' => [],

    /*
    | Cluster invalidation transports are created only when a configured
    | cluster is requested. PDO transports may name a Foundation DB connection;
    | Redis/Valkey streams may name a connection above.
    */
    'transports' => [],

    /*
    | A cluster references a Node Cache store and invalidation transport.
    | Example:
    |
    | 'catalog' => [
    |     'store' => 'node',
    |     'cluster' => 'production-catalog',
    |     'node_id' => 'web-az1-03',
    |     'transport' => 'events',
    |     'consumer_batch_size' => 1000,
    |     'invalidate_locally_first' => true,
    | ],
    */
    'clusters' => [],
];
