# Events, queues, workers, and scheduled messages

Foundation composes Omnibus 3.0.3 through the purpose-first `messaging` module.
Omnibus owns message delivery, receiving, retries, failure storage, handler
execution, worker loops, and the optional Unix process pool. Foundation owns
application configuration, DI composition, job middleware adaptation,
execution-scope cleanup, and persistent-runtime control.

Install and publish messaging configuration with:

```bash
php infbyte module:install execution.messaging
```

Foundation ships a bounded `messaging.workers.default` profile, but no worker
process starts merely because Omnibus is installed. Worker execution is explicit
through `worker:run` or the deployment supervisor.

## Explicit maps

```php
return [
    'handlers' => [
        App\Jobs\GenerateReportJob::class => App\Messaging\Handlers\GenerateReportHandler::class,
    ],

    'handler_middleware' => [],
    'job_middleware' => [
        App\Jobs\Middleware\AuditJobMiddleware::class,
    ],

    'routes' => [
        App\Jobs\GenerateReportJob::class => [
            'transport' => 'redis',
            'queue' => 'reports',
            'delay_seconds' => 0.0,
        ],
    ],

    'listeners' => [
        App\Events\OrderPaidEvent::class => [
            App\Listeners\SendReceiptListener::class,
        ],
    ],

    'scheduled_messages' => [
        'reports.daily' => App\MessageFactory\DailyReport::class,
    ],
];
```

Invokable class names are resolved through InterMix only when required. Pooled
workers require declarative scalar/array configuration because each child
constructs a fresh Foundation worker application after fork.

## Native Omnibus dispatch

Foundation does not expose a second messaging manager. Resolve the native
Omnibus APIs directly:

```php
use Infocyph\Omnibus\Event\EventDispatcher;
use Infocyph\Omnibus\MessageBus;

$bus = $app->make(MessageBus::class);
$events = $app->make(EventDispatcher::class);

$envelope = $bus->dispatch(new App\Jobs\GenerateReportJob($id));
$events->dispatch(new App\Events\OrderPaidEvent($orderId));
```

The same native `HandlerInvoker` executes handlers for sync and queued delivery.
Transport, retry, serialization, failure, acknowledgment, and release semantics
remain Omnibus responsibilities.

## Handler middleware and Foundation JobMiddleware

Omnibus 3.0.3 supplies the framework-neutral handler pipeline:

```text
ExecutionScope
  Omnibus handler middleware
    Foundation JobMiddleware adapter (for Foundation Job messages only)
      handler
```

`messaging.handler_middleware` applies to all Omnibus message handlers.
`messaging.job_middleware` is Foundation's application-facing layer and is
entered only when the message implements `Infocyph\Foundation\Messaging\Job`.

Foundation `JobMiddleware` receives a `JobContext` containing the queue, attempt,
and asynchronous flag; it does not expose Omnibus `Envelope` or
`HandlerContext`. Jobs remain data objects and handlers remain explicit through
`messaging.handlers`.

Generate the corresponding application starting points with:

```bash
php infbyte create:job GenerateReport
php infbyte create:handler GenerateReport
php infbyte create:job-middleware AuditJob
```

## Durable DBLayer messaging

Durable messaging is opt-in and uses Omnibus 3.0.3's native DBLayer integrations;
Foundation does not implement a second queue/failure/workflow engine.

A minimal database-backed profile is:

```php
'durable' => [
    'enabled' => true,
    'connection' => 'main',
    'failure_store' => 'database',
    'tables' => [
        'messages' => 'omnibus_messages',
        'failures' => 'omnibus_failures',
        'workflows' => 'omnibus_workflows',
        'workflow_items' => 'omnibus_workflow_items',
    ],
],

'serialization' => [
    'message_codecs' => [
        App\Messaging\Codec\GenerateReportCodec::class,
    ],
    'stamp_codecs' => [],
    'maximum_bytes' => 262144,
    'maximum_depth' => 32,
    'maximum_stamps' => 64,
],

'consumer' => [
    'transport' => 'database',
],
```

Foundation composes Omnibus `DBLayerTransport`, `DBLayerFailureStore`,
`DBLayerWorkflowStore`, `JsonEnvelopeSerializer`, and `QueueSchema`.
Queue/failure/workflow services use one process-owned DBLayer infrastructure
connection, preserving Omnibus's same-connection durable semantics.
`AfterCommitDispatcher` is execution-scoped and binds to the current
Foundation DBLayer execution connection, so transaction callbacks are never
registered on a connection captured by a process singleton.

Durable payloads require an explicit Omnibus `MessageCodec` allow-list.
Foundation adds Omnibus's core stamp codecs automatically; application stamp
codecs may be listed separately. Unknown aliases fail closed. Do not use PHP
object serialization or derive runtime class names from stored payload data.

A database consumer or worker must deliberately choose
`messaging.durable.failure_store=database|memory` unless the application has
already supplied its own `FailureStore` binding. Use `database` for normal
durable processing. Selecting `memory` is an explicit decision to make terminal
failure inspection volatile; a custom binding is fully application-owned.

Provision or inspect Omnibus's durable tables through the module lifecycle:

```bash
php infbyte module:schema:status execution.messaging
php infbyte module:schema:install execution.messaging
php infbyte module:schema:sync
```

The messaging schema remains non-applicable while
`messaging.durable.enabled=false`.

### Retained durable state across releases

Before upgrading persisted queue, workflow, or failure-store data, drain or quiesce
active workers and capture a compatible database snapshot. Verify the installed
Omnibus 3.0.3 codecs, transport schema, reserved-message receipts, failure
redelivery and restart ownership against the retained data. Restore a coherent
code/dependency/database snapshot for rollback; do not start older readers on
newly written formats without an explicitly verified data migration.

See [the retained-data rehearsal](foundation-4-retained-data-rehearsal.md)
for the Foundation 4 deployment and rollback checklist.

## Bounded consumption

Use `queue:consume` for one bounded receive operation:

```bash
php infbyte queue:consume
php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60
```

The command runs in the Worker runtime. Every successfully decoded delivery
enters Foundation's canonical execution scope, so scoped services and tracked
external state are cleaned before the next message.

## Failed-message operations

Foundation exposes Omnibus failure-store operations without implementing a
second failure engine:

```bash
php infbyte queue:failed
php infbyte queue:failed --limit=100
php infbyte queue:failed:show <id>
php infbyte queue:retry <id>
php infbyte queue:retry <id> --transport=redis --queue=reports
php infbyte queue:forget <id>
php infbyte queue:prune-failed --hours=168
php infbyte queue:flush --force
```

`queue:flush` clears the **failed-message store**. It is not a live-queue purge
command.

Inspect a receiver's current queue size when the transport supports it:

```bash
php infbyte queue:monitor --transport=redis --queue=reports
```

## Persistent messaging workers

Declare workers under `messaging.workers`:

```php
'workers' => [
    'reports' => [
        'transport' => 'redis',
        'queue' => 'reports',
        'prefetch' => 4,
        'visibility_seconds' => 60.0,
        'idle_sleep_seconds' => 0.05,
        'max_idle_sleep_seconds' => 1.0,
        'idle_jitter_ratio' => 0.20,
        'max_messages' => 1000,
        'max_runtime_seconds' => 3600.0,
        'memory_limit_bytes' => null,
        'max_memory_growth_bytes' => 134217728,
        'handle_signals' => true,
        'pool' => [
            'enabled' => false,
        ],
    ],
],
```

Run/list workers with:

```bash
php infbyte worker:list
php infbyte worker:run reports
```

Omnibus `Worker` owns idle backoff, signal handling, receive batching, runtime,
message-count, absolute-memory, and memory-growth limits. Foundation supplies an
Omnibus 3.0.3 `WorkerLifecycle` implementation that updates process visibility and
checks Foundation runtime/worker generation tokens.

That means single messaging workers can observe:

```bash
php infbyte runtime:reload
php infbyte worker:restart
php infbyte worker:restart reports
```

without Foundation requiring `pcntl` merely for polling. Omnibus performs the
actual graceful stop. An external process manager remains responsible for
starting replacement processes.

Inspect visible workers with:

```bash
php infbyte worker:status
php infbyte worker:status reports
```

Process-registry state is heartbeat-based observability, not supervisor truth.
`operations.runtime_registry.visibility=host` reports this host only;
`shared` intentionally aggregates records from a shared registry directory.

## Optional process pool

Ordinary synchronous messaging does not require `pcntl` or `posix`. The
selected native Unix `WorkerPool` requires the supported process extensions;
Foundation can explicitly enable that profile:

```php
'pool' => [
    'enabled' => true,
    'concurrency' => 4,
    'maximum_restarts' => 5,
    'restart_backoff_seconds' => 0.25,
    'shutdown_grace_seconds' => 30.0,
],
```

Omnibus 3.0.3 owns native pool supervision and parent `WorkerLifecycle` polling.
Foundation supplies only its heartbeat/generation-stop policy; there is no
Foundation SIGALRM/watchdog layer. Each child creates and boots a fresh
Foundation application after fork. Parent-side worker configuration reads remain
cold, and process-bound DBLayer/CacheLayer/broker/consumer resources are created
only in the child.

The process-local `memory` transport cannot be pooled, and `sync` is not a
receiver. Pooled application configuration must contain only scalar/array
values; runtime objects, resources, and closures are rejected before fork.

External Supervisor/systemd/Docker/Kubernetes process management remains the
preferred deployment model when already available.

## Maintenance workers

`routes/workers.php` is reserved for non-message application workers implementing
`Infocyph\Foundation\Worker\WorkerProvider`. It is not a second queue engine.

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

Only explicit singleton providers obtain CacheLayer ownership. `WorkerRuntime`
exposes `heartbeat()` so a long-running provider can refresh ownership and
observe graceful restart requests. Losing singleton ownership is an execution
failure, not permission to continue unowned.

## Scheduled messages

Foundation schedules application operations while Omnibus owns message creation
and dispatch:

```bash
php infbyte schedule:dispatch-message reports.daily
```

This keeps Foundation's scheduler from becoming a second durable message
scheduler.

## Authentication event forwarding

`messaging.forward_auth_events=false` is the default. When enabled, Foundation
forwards configured authentication events into Omnibus after the Foundation
auth workflow has performed its own canonical state/audit work. Messaging
failure does not redefine authentication persistence semantics.
