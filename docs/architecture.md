# Architecture and lifecycle

Foundation is the reusable application composition/runtime layer for the
Infocyph ecosystem. It owns framework-level orchestration and deliberately does
not rebuild specialist engines.

## Ownership

| Capability | Owner |
| --- | --- |
| Dependency injection, lifetimes, scopes | InterMix |
| HTTP routing, requests, responses, emitters | Webrick |
| Selected runtime engine, host adaptation and host lifecycle | Runwire |
| Foundation CLI parsing/definitions/execution policy | Foundation |
| Events, messages, retries, failure storage, messaging workers | Omnibus |
| Database connections, queries, schema, migrations, telemetry | DBLayer |
| Cache stores, counters, locks, node/cluster cache | CacheLayer |
| Generic filesystem/storage behavior | Pathwise/Flysystem |
| Validation/sanitization mechanics | ReqShield |
| Cryptographic primitives | Epicrypt |
| HTTP/email/webhook/gRPC protocol mechanics | TalkingBytes |
| Identifier algorithms | UID |

Foundation owns application bootstrap/config composition, explicit runtime
selection, deterministic provider graph composition, application paths,
authentication/browser sessions, application notification/resource contracts,
scheduler orchestration, CLI/runtime control, purpose-first module policy, and
the bridges required to compose those packages.

It does not provide generic Foundation-prefixed forwarding facades/managers for
specialist libraries, and it does not duplicate Webrick or InterMix runtime
ownership.

## Four explicit runtime graphs

Create the runtime deliberately:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
$worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
$scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);
```

These are the only Foundation runtimes. Runtime mode is never inferred from
`PHP_SAPI`; a worker, scheduler, web test, and CLI command may all execute under
a CLI SAPI while retaining distinct application policy.

All four graphs originate from the same builder-first composition model and use
fresh InterMix `ContainerBuilder` instances. The mutable InterMix development
container exists only during development/build composition. Generated
production runtimes use `ProductionContainer` artifacts whose graph is finalized
before execution.

There is no `FoundationConsole`, `Foundation::console()`, or second console or
HTTP runtime hierarchy.

### InterMix compilation and CacheLayer ownership

InterMix owns DI composition, static planning, generated `ProductionContainer`
artifacts, their native sidecar manifest, and production container loading.
Foundation owns application topology and the outer immutable release generation;
it does not wrap the generated container in another cache format.

The production fast path is generated PHP plus the PHP runtime/OPcache:

```text
Foundation release generation
  -> InterMix container.php
  -> InterMix native .meta.json
  -> ProductionContainer
  -> OPcache / process-local singleton and scope state
```

CacheLayer is core Foundation infrastructure, but it is not a replacement for
InterMix's compiled-container representation. InterMix's optional PSR-6
definition cache persists only safe scalar/null/array singleton results and is
most relevant to dynamic/development graphs. Foundation does not automatically
enable that cache for generated production containers: compiled slots, object
construction, singleton/scoped state, and runtime islands remain InterMix-owned
in-process behavior.

Trusted production loading validates Foundation-owned release identity at the
Foundation boundary and delegates InterMix artifact/manifest validation to
InterMix exactly once. Foundation must not reread or reinterpret InterMix's
native sidecar as an independent cache format.

## Provider and capability topology

Providers contribute graph definitions through `ContainerBuilder` before
compilation. Provider boot hooks may initialize already-defined services but do
not mutate the production graph.

Dependency presence and application capability activation are separate concerns. CacheLayer is always present as Foundation core infrastructure, but its runtime capability remains activation-controlled.
Development may discover installed optional packages when no explicit topology
is supplied. Production release compilation is explicit: omitted capability
sets mean a deliberately minimal topology, not installed-package activation.
Consequently, Foundation requiring CacheLayer—and applications installing
DBLayer, Omnibus, TalkingBytes, Epicrypt, Pathwise, ReqShield, OTP, or WebAuthn—
does not by itself activate the corresponding runtime capability, open a
connection, construct a store, or add unrelated request work.

Purpose-first modules (`database`, `security`, `auth`, `messaging`, and so on)
select application capabilities. One module may have several backing packages;
Foundation does not expose package-presence as runtime policy.

## Execution boundaries

### Web

Webrick owns web Request creation, InterMix request-scope entry/leave, middleware
execution plans, exception routing, and native response emission. Foundation
does not wrap every web request in a universal outer scope.

A compiled route that does not need a `Request`, middleware, or scoped services
can remain Request-free and scope-free. Routes that require scoped state use
Webrick's stable `webrick.request` InterMix scope. Foundation attaches only its
own deterministic scope cleanup to that lower-layer lifecycle.

### CLI, worker, and scheduler

Non-web work enters stable semantic InterMix scopes only at the execution
boundary:

- `foundation.cli`;
- `foundation.worker`;
- `foundation.scheduler`.

`ExecutionId` and other execution context are scope seeds, not randomized scope
names. InterMix execution-context identity provides Fiber/coroutine isolation
beneath the stable semantic label.

Mutable request/job/command state lives in scoped `RuntimeExecutionState` or
other scoped services. Reusable process infrastructure may remain singleton only
when it does not retain execution-local state.

Cleanup always attempts owned resources while preserving the original
application failure over later cleanup failures.

## Web production path

Foundation delegates route compilation to Webrick's coordinated release
compiler. Route registration and `Registrar`/route collection mutation are
build/development concerns only. The production graph removes those mutable
identities before InterMix compilation.

The generated router contains route/middleware descriptors and execution-plan
data, not a captured Foundation `Application` or mutable service graph. Webrick's
`CompiledRouterKernel` and selected `RuntimeAdapter` own production HTTP
execution. `Application::handle(Request)` remains an embedded/testing
convenience, not a second native emitter.

## Runwire and host ownership

When Runwire is selected, Foundation composes its generated application through
Webrick's Runwire bridge. Runwire owns native portable/prefork execution and the
FPM, FrankenPHP, RoadRunner and Swoole/OpenSwoole host-driver boundary. It also
owns runtime capabilities, admission, cancellation/deadlines and drain/shutdown.
Webrick owns HTTP semantics, request adaptation/scoping and response writing;
Foundation owns application policy and cleanup of its scoped services.

Foundation must propagate the selected Runwire context/capabilities rather than
infer persistence from application mode, duplicate host detection or introduce
parallel server adapters. Ordinary PHP/FPM without Runwire continues to use the
Webrick SAPI path. Existing direct Webrick host adapters remain explicit
alternatives, not evidence that Foundation needs its own host drivers.

See [Runtime hosting](runtime-hosting.md) for supported upstream host paths and
the Foundation integration acceptance requirements.

## Non-web generated runtimes

CLI, worker, and scheduler compile directly through InterMix. Their generated
containers are loaded once per process/runtime and reused across execution
units. Worker and scheduler item loops do not rebuild the graph.

Worker/scheduler execution state is isolated per scope. Persistent processes
reuse safe singleton infrastructure, release locks/temp resources
deterministically, roll back scope-owned DB transactions, and stop/restart when
the active release generation changes.

## Immutable release generation

Production deployment publishes one Foundation generation containing the four
runtime identities together. A generation includes:

- `foundation.php` — Foundation generation/trust manifest;
- `config.php` — normalized, exportable, compiled configuration snapshot;
- Webrick web container/router/release artifacts;
- generated InterMix CLI, worker, and scheduler containers plus Foundation
  metadata;
- deterministic worker provider topology when required.

Build occurs under a staging generation. Foundation verifies the config snapshot,
subordinate artifact identities, skipped-definition reports, environment/config
fingerprints, and worker topology before atomically switching the active pointer.
A failed build leaves the previous generation active.

Trusted/prevalidated loading requires immutable trust data supplied from outside
the writable subordinate artifact being validated. Normal production loading
still validates generated artifacts. Neither path falls back to application
`config/*.php`, `bootstrap/providers.php`, route files, or a mutable resolver map
when a release artifact is missing, stale, or corrupt.

Old generations are pruned only through explicit build-plane housekeeping, never
from request/job hot paths.

## Configuration artifacts versus release artifacts

Development/build commands may use Foundation's single or sharded config cache
to reduce source parsing while composing a graph. That cache is separate from
the immutable production release generation.

The removed Foundation 2/early-Foundation-3 switches
`app.container.compiled`, `app.container.compiled_activation`,
`app.container.alias`, and `router.cache` do not select production runtime
behavior. InterMix artifact paths and Webrick route artifact identity belong to
the release compiler/manifests instead of mutable application configuration.

## Worker lifecycle

Application maintenance workers and Omnibus queue workers remain distinct:

- `routes/workers.php` -> Foundation `WorkerProvider` maintenance workers;
- `messaging.workers` -> Omnibus messaging workers.

Foundation provider workers expose `WorkerRuntime::heartbeat()` for singleton
lease refresh and release-generation checks. Omnibus single workers use native
`WorkerLifecycle` callbacks. Optional `WorkerPool` remains an upstream
Unix/pcntl feature; Foundation forks only after checking that the parent has not
resolved process-bound DB/cache/network state.

Foundation owns graceful generation signalling (`runtime:reload`,
`worker:restart`, `schedule:interrupt`) but external process managers own daemon
replacement and scaling.

## Scheduling and ownership

Foundation schedules application commands and uses CacheLayer coordination only
when overlap/single-server policy is explicitly requested. Long child executions
refresh their lease through `ProcessRunner` heartbeat callbacks. Losing ownership
terminates the child instead of allowing it to continue unowned.

Schedule execution history uses stable schedule identity so duplicate command
names remain distinguishable. Lock/process cleanup cannot replace a primary
schedule failure.

## Framework/application boundary

Foundation never depends on the Infbyte skeleton. Infbyte is an opinionated host
application that selects Foundation runtimes and supplies application defaults,
routes, providers, code, writable directories, and deployment UX.

Foundation owns reusable framework behavior; Infbyte consumes the final
build/runtime lifecycle instead of recreating another framework runtime.
