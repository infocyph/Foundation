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
    | `billing`. "delay_seconds" is a finite non-negative integer or decimal,
    | for example `0`, `1.5`, or `300`.
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
     * "routes" maps each message class to a route array with the three keys
     * documented above. "handlers" maps each message class to an invokable
     * service class. Live, uncached configuration may also contain callables,
     * but class names are required for compiled configuration.
     *
     * Example:
     * App\Message\GenerateReport::class => [
     *     'transport' => 'memory',
     *     'queue' => 'reports',
     *     'delay_seconds' => 0,
     * ]
     *
     * Example:
     * App\Message\GenerateReport::class => App\Handler\GenerateReport::class
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
     *
     * Example:
     * App\Event\OrderPaid::class => [
     *     App\Listener\SendReceipt::class,
     *     App\Listener\UpdateLedger::class,
     * ]
     */
    'listeners' => [],

    /**
     * Scheduled Message Factories
     *
     * "scheduled_messages" maps the key used by Console's
     * `schedule:dispatch-message` action to an invokable service class that
     * returns one message object.
     *
     * Example:
     * `daily-report => App\MessageFactory\DailyReport::class`.
     */
    'scheduled_messages' => [],

    /*
    |--------------------------------------------------------------------------
    | Consumer Transport and Retry
    |--------------------------------------------------------------------------
    |
    | "consumer.transport" must name a transport that implements Omnibus
    | Receiver. The built-in receiver is `memory`; `sync` cannot be consumed.
    |
    | Retry values are passed directly to Omnibus. "maximum_attempts" is a
    | positive integer such as `3`. Delays, "multiplier", and "jitter_ratio"
    | are finite numbers. Delays are non-negative seconds, multiplier is at
    | least `1`, and jitter is between `0` and `1` (for example `0.2`).
    |
    */
    'consumer' => [
        'transport' => 'memory',
    ],
    'retry' => [
        'maximum_attempts' => 3,
        'initial_delay_seconds' => 1.0,
        'multiplier' => 2.0,
        'maximum_delay_seconds' => 60.0,
        'jitter_ratio' => 0.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Event Forwarding
    |--------------------------------------------------------------------------
    |
    | "forward_auth_events" accepts `true|false`. When true, each persisted
    | Foundation AuthEvent is also dispatched through Omnibus after storage
    | succeeds. It defaults false so enabling messaging never changes auth
    | behavior implicitly.
    |
    */
    'forward_auth_events' => env_bool('MESSAGING_FORWARD_AUTH_EVENTS', false),
];
