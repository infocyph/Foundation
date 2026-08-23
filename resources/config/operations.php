<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Execution History
    |--------------------------------------------------------------------------
    |
    | Execution history is opt-in because every recorded state transition writes
    | operational metadata. Enable it when command/scheduler history is useful.
    |
    */
    'history' => [
        'enabled' => env_bool('OPERATIONS_HISTORY_ENABLED', false),
        'path' => env_string('OPERATIONS_HISTORY_PATH', 'storage/logs/executions.jsonl'),
        'max_bytes' => env_int('OPERATIONS_HISTORY_MAX_BYTES', 16_777_216),
        'retained_files' => env_int('OPERATIONS_HISTORY_RETAINED_FILES', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance State
    |--------------------------------------------------------------------------
    |
    | `file` is dependency-free and suitable for one shared application volume.
    | `cache` shares maintenance state across nodes and requires CacheLayer.
    |
    */
    'maintenance' => [
        'driver' => env_string('OPERATIONS_MAINTENANCE_DRIVER', 'file'),
        'path' => env_string('OPERATIONS_MAINTENANCE_PATH', 'storage/framework/maintenance.json'),
        'store' => env('OPERATIONS_MAINTENANCE_STORE'),
        'key' => env_string('OPERATIONS_MAINTENANCE_KEY', 'foundation:maintenance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Runtime Control
    |--------------------------------------------------------------------------
    |
    | Generation markers request graceful worker/scheduler shutdown. Use the
    | cache driver for a multi-node deployment; process managers still own
    | starting replacement processes.
    |
    */
    'runtime_control' => [
        'driver' => env_string('OPERATIONS_RUNTIME_CONTROL_DRIVER', 'file'),
        'path' => env_string('OPERATIONS_RUNTIME_CONTROL_PATH', 'storage/framework/runtime-control.json'),
        'store' => env('OPERATIONS_RUNTIME_CONTROL_STORE'),
        'key' => env_string('OPERATIONS_RUNTIME_CONTROL_KEY', 'foundation:runtime-control'),
    ],

    /*
    | Runtime process records are heartbeat-based visibility metadata. A record
    | older than stale_seconds is reported as not running; process managers remain
    | the authority for process supervision and replacement.
    */
    'runtime_registry' => [
        'path' => env_string('OPERATIONS_RUNTIME_REGISTRY_PATH', 'storage/framework/runtime'),
        'stale_seconds' => env_int('OPERATIONS_RUNTIME_REGISTRY_STALE_SECONDS', 15),
    ],
];
