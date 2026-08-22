<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Message Route
    |--------------------------------------------------------------------------
    |
    | "transport" accepts the built-in `sync|memory` names, or a transport
    | name supplied by an application provider. `sync` invokes the configured
    | handler immediately. `memory` is process-local and intended for tests,
    | development workers, and deterministic examples.
    |
    | "queue" is a non-empty logical queue such as `default`, `mail`, or
    | `billing`. "delay_seconds" is a finite non-negative integer or decimal.
    |
    */
    'default_route' => [
        'transport' => 'sync',
        'queue' => 'default',
        'delay_seconds' => 0.0,
    ],

    /**
     * Message Routes and Handlers
     *
     * "routes" maps each message class to an Omnibus route. "handlers" maps
     * each message class to an invokable service class. Live configuration may
     * contain callables, but pooled workers require class-name/declarative
     * configuration so the post-fork application can be reconstructed safely.
     */
    'routes' => [],
    'handlers' => [],

    /**
     * Synchronous Events
     *
     * "listeners" maps an event class to an ordered list of invokable listener
     * service classes. Parent classes and implemented interfaces are honored by
     * Omnibus. A listener implementing Omnibus ShouldQueue is sent through the
     * message bus; other listeners run synchronously.
     */
    'listeners' => [],

    /**
     * Scheduled Message Factories
     *
     * "scheduled_messages" maps the key used by `schedule:dispatch-message`
     * to an invokable service class that returns one message object. Foundation
     * owns application scheduling; Omnibus owns scheduled-message dispatch.
     */
    'scheduled_messages' => [],

    /*
    |--------------------------------------------------------------------------
    | Consumer Transport and Retry
    |--------------------------------------------------------------------------
    |
    | "consumer.transport" names the default Omnibus Receiver used by the
    | bounded `queue:consume` command and by workers that do not override it.
    | Built-in `memory` is process-local; `sync` cannot receive.
    |
    */
    'consumer' => [
        'transport' => env_string('MESSAGING_CONSUMER_TRANSPORT', 'memory'),
    ],
    'retry' => [
        'maximum_attempts' => env_int('MESSAGING_RETRY_MAXIMUM_ATTEMPTS', 3),
        'initial_delay_seconds' => env('MESSAGING_RETRY_INITIAL_DELAY_SECONDS', 1.0),
        'multiplier' => env('MESSAGING_RETRY_MULTIPLIER', 2.0),
        'maximum_delay_seconds' => env('MESSAGING_RETRY_MAXIMUM_DELAY_SECONDS', 60.0),
        'jitter_ratio' => env('MESSAGING_RETRY_JITTER_RATIO', 0.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Message Workers
    |--------------------------------------------------------------------------
    |
    | Each worker is consumed by Omnibus\Consumer\Worker. Foundation provides
    | configuration, post-delivery execution scoping, and optional process-pool
    | composition; Omnibus owns receiving, retries, idle backoff, lifecycle
    | limits, signals, and supervision behavior.
    |
    | "prefetch" is receive batch size and is independent from process
    | concurrency. "max_messages", "max_runtime_seconds", and memory limits
    | recycle long-lived workers. The shipped defaults bound message count,
    | runtime, and memory growth so persistent workers periodically refresh.
    |
    | `pool.enabled=false` is the normal deployment default: run one worker per
    | process and let Supervisor/systemd/Docker/Kubernetes scale processes.
    | Enable the built-in pool only on Unix-like systems with pcntl + posix.
    | Pool children construct a fresh Foundation worker application after fork.
    | The built-in `memory` transport is rejected for pool mode because it is
    | process-local; custom pooled transports must be shared/durable.
    |
    */
    'workers' => [
        'default' => [
            'transport' => env_string(
                'MESSAGING_WORKER_TRANSPORT',
                env_string('MESSAGING_CONSUMER_TRANSPORT', 'memory'),
            ),
            'queue' => env_string('MESSAGING_WORKER_QUEUE', 'default'),
            'prefetch' => env_int('MESSAGING_WORKER_PREFETCH', 1),
            'visibility_seconds' => env('MESSAGING_WORKER_VISIBILITY_SECONDS', 60.0),
            'idle_sleep_seconds' => env('MESSAGING_WORKER_IDLE_SLEEP_SECONDS', 0.05),
            'max_idle_sleep_seconds' => env('MESSAGING_WORKER_MAX_IDLE_SLEEP_SECONDS', 1.0),
            'idle_jitter_ratio' => env('MESSAGING_WORKER_IDLE_JITTER_RATIO', 0.20),
            'max_messages' => env_int('MESSAGING_WORKER_MAX_MESSAGES', 1_000),
            'max_runtime_seconds' => env('MESSAGING_WORKER_MAX_RUNTIME_SECONDS', 3_600.0),
            'memory_limit_bytes' => env('MESSAGING_WORKER_MEMORY_LIMIT_BYTES'),
            'max_memory_growth_bytes' => env_int('MESSAGING_WORKER_MAX_MEMORY_GROWTH_BYTES', 134_217_728),
            'handle_signals' => env_bool('MESSAGING_WORKER_HANDLE_SIGNALS', true),
            'pool' => [
                'enabled' => env_bool('MESSAGING_WORKER_POOL_ENABLED', false),
                'concurrency' => env_int('MESSAGING_WORKER_POOL_CONCURRENCY', 2),
                'maximum_restarts' => env_int('MESSAGING_WORKER_POOL_MAXIMUM_RESTARTS', 5),
                'restart_backoff_seconds' => env('MESSAGING_WORKER_POOL_RESTART_BACKOFF_SECONDS', 0.25),
                'shutdown_grace_seconds' => env('MESSAGING_WORKER_POOL_SHUTDOWN_GRACE_SECONDS', 30.0),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Event Forwarding
    |--------------------------------------------------------------------------
    |
    | When enabled, each persisted Foundation AuthEvent is also dispatched
    | through Omnibus after storage succeeds. Messaging failure never replaces
    | the canonical audit store.
    |
    */
    'forward_auth_events' => env_bool('MESSAGING_FORWARD_AUTH_EVENTS', false),
];
