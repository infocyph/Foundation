<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Store And Namespace
    |--------------------------------------------------------------------------
    |
    | "default" names the store used when callers do not select one. Shipped
    | names: `apcu|auth|file|local|memory|null|php_files|sqlite|database|`
    | `redis|redis_cluster|valkey|memcached|shared_memory|weak_map|mongodb|`
    | `node|scylladb|tiered`.
    | "default_counter" optionally names an entry from "counters" for shared
    | atomic increments such as authentication lockouts. Leave it null to use
    | the selected store-backed fallback, which is not guaranteed atomic.
    | "prefix" namespaces entries; example: `acme:production:cache:`.
    | The shipped `local` default is suitable for development. Production
    | applications should explicitly select a durable or distributed store
    | such as `tiered`, `redis`, `valkey`, or `memcached`.
    |
    */
    'default' => env('CACHE_STORE', 'local'),
    'default_counter' => env('CACHE_COUNTER'),
    'prefix' => env_string('CACHE_PREFIX', 'infbyte:cache:'),

    /*
    |--------------------------------------------------------------------------
    | Shared Lock Provider
    |--------------------------------------------------------------------------
    |
    | Console overlap, scheduling, worker supervision, and cache stampede
    | protection can share CacheLayer's lock contract. "driver" accepts
    | `file|redis|valkey|memcache|memcached|pdo` or null to leave cache-store
    | locking unchanged while Console falls back to file locks. "store" names
    | a configured cache store used for connection details; example: `redis`,
    | `memcached`, `sqlite`, or `database`.
    |
    | "path" is used by file locks and as the safe fallback for SQLite/PDO;
    | example: `storage/cache/locks`. "prefix" is an arbitrary namespace such
    | as `acme:production:lock:`. "retry_sleep_micros" is a positive polling
    | interval such as `50000`. Redis/Valkey stores use their DSN, Memcached
    | stores use their server list, and PDO stores use their PDO connection.
    |
    */
    'lock' => [
        'driver' => env('CACHE_LOCK_DRIVER'),
        'store' => env_string('CACHE_LOCK_STORE', 'local'),
        'path' => env_string('CACHE_LOCK_PATH', storage_path('cache/locks')),
        'prefix' => env_string('CACHE_LOCK_PREFIX', 'infbyte:cache:lock:'),
        'retry_sleep_micros' => env_int('CACHE_LOCK_RETRY_SLEEP_MICROS', 50_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Compression
    |--------------------------------------------------------------------------
    |
    | "threshold_bytes" enables compression for payloads at or above the given
    | size; zero disables it. "level" is the backend compression level and
    | should be balanced against CPU capacity using production measurements.
    | Threshold is null/disabled or a positive byte count such as `1024`.
    | Leave CACHE_COMPRESSION_THRESHOLD_BYTES unset to disable compression.
    | Compression level is `1..9`.
    |
    */
    'compression' => [
        'threshold_bytes' => env('CACHE_COMPRESSION_THRESHOLD_BYTES'),
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
    | Named Redis-Compatible Connections
    |--------------------------------------------------------------------------
    |
    | Redis/Valkey stores, lock providers, atomic counters, and invalidation
    | transports may share a named connection instead of repeating a DSN.
    | "driver" accepts `redis|valkey`; "dsn" examples:
    | `redis://:secret@127.0.0.1:6379/0` and
    | `valkey://:secret@valkey.internal:6379/2`.
    |
    | A runtime override may provide an initialized phpredis "client" object.
    | Objects should not be written into source or compiled configuration.
    |
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
    | MongoDB uses "uri", "database", and "collection_name", or runtime
    | "client"/"collection" objects. Node cache uses "sqlite_file",
    | "lock_directory", non-negative "busy_timeout_ms", "apcu_enabled", and
    | "fail_open". ScyllaDB uses "keyspace", "table", and an optional runtime
    | "session"/"client". APCu, MongoDB, ScyllaDB, shared memory, Redis,
    | Redis Cluster, Valkey, and Memcached require their matching extension or
    | client only when the store is selected.
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
    | Any store may override the top-level "compression", "security", and
    | "serialization" arrays using the same keys documented above.
    |
    */
    'stores' => [
        'apcu' => [
            'driver' => 'apcu',
        ],
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
            'sqlite_file' => env_string('CACHE_NODE_SQLITE_FILE', storage_path('cache/node.sqlite')),
            'lock_directory' => env_string('CACHE_NODE_LOCK_DIRECTORY', storage_path('cache/locks')),
            'busy_timeout_ms' => env_int('CACHE_NODE_BUSY_TIMEOUT_MS', 1_000),
            'apcu_enabled' => env_bool('CACHE_NODE_APCU_ENABLED', true),
            'fail_open' => env_bool('CACHE_NODE_FAIL_OPEN', true),
        ],
        'scylladb' => [
            'driver' => 'scylladb',
            'keyspace' => env_string('CACHE_SCYLLADB_KEYSPACE', 'cachelayer'),
            'table' => env_string('CACHE_SCYLLADB_TABLE', 'cachelayer_entries'),
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

    /*
    |--------------------------------------------------------------------------
    | Atomic Counters
    |--------------------------------------------------------------------------
    |
    | Named counters are created only when requested. "driver" accepts
    | `redis|valkey`; "connection" references the named connection above;
    | "namespace" is an arbitrary key prefix such as `acme:limits:`.
    | A direct "dsn" or runtime phpredis "client" may replace "connection".
    |
    | Example:
    | 'rate_limits' => [
    |     'driver' => 'redis',
    |     'connection' => 'redis',
    |     'namespace' => 'acme:limits:',
    | ],
    |
    */
    'counters' => [],

    /*
    |--------------------------------------------------------------------------
    | Cluster Invalidation Transports
    |--------------------------------------------------------------------------
    |
    | Transport drivers: `pdo|redis_stream|valkey_stream`; aliases include
    | `redis-stream|valkey-stream|stream`. PDO "connection" names a database
    | connection. "allow_sqlite_for_testing" accepts `true|false` and should
    | remain false outside tests. Stream transports accept a named cache
    | "connection" or direct "dsn", a "prefix" such as
    | `cachelayer:invalidation:`, and positive "max_length" such as `100000`.
    |
    | Example:
    | 'events' => [
    |     'driver' => 'redis_stream',
    |     'connection' => 'redis',
    |     'prefix' => 'acme:cache-events:',
    |     'max_length' => 100000,
    | ],
    |
    */
    'transports' => [],

    /*
    |--------------------------------------------------------------------------
    | Node Cache Clusters
    |--------------------------------------------------------------------------
    |
    | A cluster references a `node` store and an invalidation transport.
    | "cluster" is a stable deployment name such as `production-catalog`;
    | "node_id" must uniquely and stably identify the process host, for example
    | `web-az1-03`; "consumer_batch_size" is a positive count such as `1000`;
    | and "invalidate_locally_first" accepts `true|false`.
    |
    | Example:
    | 'catalog' => [
    |     'store' => 'node',
    |     'cluster' => 'production-catalog',
    |     'node_id' => 'web-az1-03',
    |     'transport' => 'events',
    |     'consumer_batch_size' => 1000,
    |     'invalidate_locally_first' => true,
    | ],
    |
    */
    'clusters' => [],
];
