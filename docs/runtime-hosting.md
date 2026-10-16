# Runtime hosting

Foundation has four application modes: `web`, `cli`, `worker`, and `scheduler`.
The HTTP server is a separate choice. Foundation composes application services;
Webrick supplies HTTP semantics; Core Runwire supplies native runtime integration and
capability-aware host lifecycle boundaries. Runwire is a required Foundation dependency and is available in
production installations across its supported environments. PCNTL/POSIX and
host-specific extensions enable capabilities rather than package installation.

## Ownership and selection

| Path | Runtime responsibility | Foundation integration |
| --- | --- | --- |
| Ordinary PHP / direct FPM | Web server/FPM, Webrick SAPI adaptation | Existing generated web release |
| Native Runwire portable/prefork | Runwire listener, event loop and applicable process supervision | Generated web release through Webrick's Runwire bridge |
| FPM through Runwire | Runwire host adaptation; web server/FPM owns listeners/workers | Same bridge, request-lifetime context |
| FrankenPHP through Runwire | Runwire host adaptation; lifetime depends on host mode | Same bridge, lifetime from Runwire context |
| RoadRunner through Runwire | Runwire host adaptation; RoadRunner owns workers | Same bridge, persistent application with isolated requests |
| Swoole/OpenSwoole through Runwire | Explicit Runwire Swoole driver; host owns its execution model | Same bridge, concurrent request isolation |

Runwire's AUTO selection recognizes active FPM, FrankenPHP and RoadRunner hosts.
Installing a Swoole-family extension does not select that host: configure
`RuntimeDriver::SWOOLE` explicitly. Native listener execution and host-owned
serving are different entry paths; do not start a second listener or worker pool
inside a host-managed path.

These host capabilities come from [Runwire's runtime contract](https://github.com/infocyph/Runwire#runtime-modes).
The native [Webrick bridge](https://github.com/infocyph/Webrick#persistent-runtimes)
provides `RunwireRuntimeApplicationFactory`, `RunwireRuntimeApplication` and
`RunwireRuntimeAdapter`. Use these existing boundaries rather than creating
Foundation versions of Runwire host drivers. Webrick's direct host adapters
remain available for deliberately selected non-Runwire deployments; they are
not automatically rerouted through Runwire.

## Foundation composition contract

A Runwire-hosted Foundation application must:

1. Let Runwire resolve the actual runtime context and capabilities before
   constructing the application lifetime and selecting persistence/concurrency.
2. Load the trusted Foundation web generation using its release bootstrap and
   the Webrick Runwire adapter. Keep Foundation's CLI/worker/scheduler application
   modes separate from HTTP host selection.
3. Pass native Runwire request/writer handles through Webrick's runtime server.
   Webrick owns request adaptation, InterMix request scope and exactly one HTTP
   response emission. Do not invoke an additional Foundation/SAPI emitter.
4. Propagate cancellation/deadlines and admission through the existing bridge.
   Do not duplicate Runwire detection, coroutine scheduling or process pools.
5. Release Foundation-owned session/principal/database state at the established
   request boundary, including failure and cancellation. Never infer web-host
   persistence from `RuntimeMode::isPersistent()` alone.
6. Preserve Runwire drain/shutdown and Foundation generation replacement policy.
   Restart or replace applications through their actual lifetime owner rather
   than replacing a loaded generation in place.

The installed Webrick bridge remains the runtime integration boundary. Foundation 4
accepted the compiled host identity, generation leases, cancellation, deferred
response drain and scoped native selected-module propagation in [Batch 3's
release plan](plans/foundation-4.0-release-audit-and-plan.md). A deployment must
still rehearse its actual chosen FPM/RoadRunner/Swoole host and transport.

## Intermediary-library context forwarding

When another library has already shared a native Runwire context, a non-web
Foundation execution validates it through the same process/request/task checks
as explicit inputs. Core seeds the exact runtime, request and coroutine objects
into InterMix as well as forwarding them to native operations. Completed or
cancelled shared requests are rejected before framework work starts. A
runtime-only context leaves absent request/coroutine inputs null; Foundation
does not manufacture them or complete caller-owned lifecycle objects.

## Borrowed non-web scope propagation (Batch 3)

For work that a host has already placed inside an InterMix worker, scheduler or
CLI execution scope, Foundation provides
`ExecutionScope::withinBorrowedScope(ScopeContext $context, callable $handler)`.
The caller passes the **exact native InterMix scope handle** captured from its
original live owner; Foundation does not create a second execution ID, start
another DI scope, or clean up host-owned state.

The host controls task creation and the parent's scope lifetime. A child carrier
may attach the passed context through `withinBorrowedScope()`; the callback
receives the **same** `ExecutionId` object and sees the parent's scoped services.
After child completion or exception, native InterMix detaches the borrowed
carrier. The original scope owner still performs the single authoritative cleanup.
Expired handles, unrelated container handles and missing scoped execution IDs
must fail, not trigger new runtime construction. This API intentionally rejects
web mode: Webrick is the sole owner of its HTTP request scope.

Batch 3 completed full native compiled-host Runwire identity, request/writer,
coroutine scope, cancellation and deferred output coverage ([QA #38508326050](https://github.com/infocyph/Foundation/actions/runs/38508326050)).
Call `WebReleaseRuntime::runwireApplication($runtime)` only on a *trusted
Foundation generation with native Core inputs*, passing the exact host-owned Runwire `RuntimeContext`
instance, not a newly generated substitute. An execution without an active passed runtime uses the synchronous native path. See the [worker smoke](examples/foundation-4-runwire-worker.php).

## Core dependency and separate worker support

Foundation requires `infocyph/runwire:^2.1.1`, including ordinary PHP/FPM
installations. Runwire supports FPM, FrankenPHP, RoadRunner, explicit
Swoole/OpenSwoole hosting and portable native CLI without PCNTL/POSIX.
The package's presence does not start listeners, workers or an event loop.
Omnibus's optional Runwire process-pool backend
is a separate selection from HTTP hosting; see [messaging](messaging.md).

HTTP/2, HTTP/3/QUIC and native transport supervision remain Runwire concerns.
Advertise them for a Foundation deployment only after testing its selected
runtime, extensions and configuration. Do not infer a Runwire Workerman driver
from Webrick's independent Workerman adapter.
