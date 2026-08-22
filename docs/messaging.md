# Events, queues, workers, and scheduled messages

Foundation composes Omnibus through `config/messaging.php`. Omnibus owns message
delivery, receiving, retries, failure handling, worker loops, and the optional
Unix process pool. Foundation owns configuration, application handler mapping,
InterMix execution scopes, lifecycle cleanup, and runtime composition.

Registering no messaging services leaves the optional package graph dormant.

## Explicit maps

```php
return [
    'handlers' => [
        App\Message\GenerateReport::class => App\Handler\GenerateReport::class,
    ],
    'routes' => [
        App\Message\GenerateReport::class => [
            'transport' => 'redis',
            'queue' => 'reports',
            'delay_seconds' => 0.0,
        ],
    ],
    'listeners' => [
        App\Event\OrderPaid::class => [
            App\Listener\SendReceipt::class,
        ],
    ],
    'scheduled_messages' => [
        'reports.daily' => App\MessageFactory\DailyReport::class,
    ],
];
```

Invokable class names are resolved through InterMix only when dispatched. Live
configuration may contain callables for single-process use. Pooled workers
require declarative scalar/array configuration and class names because each
child reconstructs a fresh Foundation application after fork.

## Dispatch

```php
$envelope = $app->messaging()->dispatch(new GenerateReport($id));
$app->messaging()->event(new OrderPaid($orderId));
```

The native Omnibus `MessageBus`, `EventDispatcher`, transport registry, consumer,
and scheduled-message dispatcher are also available from the container. Queued
listeners and messages follow Omnibus routing, retry, failure, serialization,
and transport contracts.

## Bounded consumption

Use `queue:consume` for one bounded receive operation:

```bash
php infbyte queue:consume
php infbyte queue:consume --queue=reports --limit=100
```

`Consumer::run()` performs one receive batch. Every successfully decoded
delivery enters Foundation's canonical `ExecutionScope`, so the message,
Envelope, ExecutionId, scoped services, DBLayer runtime state, principal/session
state, and process-local memoizers are isolated from the next delivery.

## Persistent workers

Declare persistent Omnibus workers under `messaging.workers`:

```php
'workers' => [
    'reports' => [
        'transport' => 'redis',
        'queue' => 'reports',
        'prefetch' => 4,
        'visibility_seconds' => 60.0,
        'max_messages' => 1000,
        'max_runtime_seconds' => 3600.0,
        'max_memory_growth_bytes' => 134217728,
        'pool' => [
            'enabled' => false,
        ],
    ],
],
```

Run one worker process with:

```bash
php infbyte worker:run reports
```

Omnibus `Worker` owns idle backoff, signal handling, receive batching, runtime,
message-count, absolute-memory, and memory-growth limits. `prefetch` controls a
single process's receive batch and is independent of process concurrency.

The normal production model keeps `pool.enabled=false` and lets
Supervisor, systemd, Docker, Kubernetes, or another external supervisor run the
desired number of worker processes. Bounded worker limits then provide routine
process recycling.

## Optional process pool

On Unix-like systems with `pcntl` and `posix`, Foundation can compose Omnibus
`WorkerPool`:

```php
'pool' => [
    'enabled' => true,
    'concurrency' => 4,
    'maximum_restarts' => 5,
    'restart_backoff_seconds' => 0.25,
    'shutdown_grace_seconds' => 30.0,
],
```

The parent validates worker options without constructing a receiver. Each child
then creates and boots a new Foundation worker application after `fork()`, and
only there resolves the transport, Consumer, DBLayer connections, CacheLayer
clients, and other process-bound services. Pool startup is rejected if the
parent Foundation application has already been booted.

Foundation provider registration happens while the parent application is being
constructed, so custom providers intended for pool mode must keep `register()`
resource-free: register factories, class names, and immutable descriptors only.
Do not open PDO connections, Redis/Valkey clients, brokers, HTTP clients, files,
or other process-bound resources from provider registration. Resolve those
services lazily in the child after fork.

Pool mode rejects the built-in `memory` transport because its queue is
process-local. `sync` cannot be consumed. Application-supplied pooled transports
must be shared/durable. Pool configuration must contain scalars/arrays rather
than runtime objects, resources, or closures.

A pool is intentionally optional rather than the default supervisor. It has
fixed concurrency and a bounded per-slot crash-restart budget. Clean worker
recycling caused by message/runtime/memory limits respawns the slot without
consuming that crash budget. External supervisors remain preferable when
orchestration already exists.

## Maintenance workers

`routes/workers.php` is for application-specific maintenance workers implementing
`Infocyph\Foundation\Worker\WorkerProvider`; it is not a second queue engine.
Provider workers are unlocked by default:

```php
return [
    'metrics' => App\Worker\MetricsWorker::class,
    'leader-only' => [
        'provider' => App\Worker\LeaderWorker::class,
        'singleton' => true,
        'lock_wait_seconds' => 0.0,
        'lock_lease_seconds' => 300.0,
    ],
];
```

Only explicit singleton providers acquire a CacheLayer lock. Their
`WorkerRuntime` refreshes ownership before each `execute()` unit; providers with
long individual units may call `heartbeat()` themselves. The configured lease
must exceed the maximum interval between heartbeats.

## Scheduled messages

Foundation schedules application operations. Omnibus owns scheduled-message
creation and dispatch:

```bash
php infbyte schedule:dispatch-message reports.daily
```

This separation keeps the Foundation scheduler from becoming a second durable
message scheduler.

## Authentication forwarding

`messaging.forward_auth_events=false` is the default. When enabled, Foundation
first persists an auth audit event and then forwards it to Omnibus. Messaging
failure never replaces the canonical audit store.

## Testing

```php
$recording = $app->testing()->fakeMessaging();
$app->messaging()->dispatch(new GenerateReport('42'));

assert($recording->count(GenerateReport::class) === 1);
$app->messaging()->restore();
```
