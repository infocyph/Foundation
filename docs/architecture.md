# Architecture and lifecycle

Foundation is the reusable application composition/runtime layer for the
Infocyph ecosystem. It owns framework-level orchestration and deliberately does
not rebuild specialist engines.

## Ownership

| Capability | Owner |
| --- | --- |
| Dependency injection, lifetimes, scopes | InterMix |
| HTTP routing, requests, responses, emitters | Webrick |
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
selection, provider activation, application paths, authentication/browser
sessions, application notification/resource contracts, scheduler orchestration,
CLI/runtime control, purpose-first module policy, and bridges required to compose
those packages.

It does not provide generic Foundation-prefixed forwarding facades/managers for
specialist libraries.

## Four explicit runtime graphs

Create the runtime deliberately:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
$worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
$scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);
```

These are the only Foundation runtimes. Runtime mode is not inferred from
`PHP_SAPI`; a worker/scheduler/Web test may all execute under a CLI SAPI while
retaining different application policy.

Web eagerly prepares only its minimal HTTP boundary. CLI metadata preflight can
run without constructing an `Application`. Worker and Scheduler runtimes are
selected by Foundation's `CommandDispatcher` for the corresponding system
commands.

There is no `FoundationConsole`, `Foundation::console()`, or second Console
hierarchy.

## Lazy capabilities

`Application::has()` asks whether a service can be provided without activating
it. `Application::make()` activates a managed provider only when resolution
requires it.

Optional package presence is separate from application activation. Installing
CacheLayer, DBLayer, Omnibus, TalkingBytes, Epicrypt, Pathwise, ReqShield, OTP,
or WebAuthn does not by itself open a connection or add unrelated work to a
request.

Route middleware activates auth/browser-session behavior only where selected.
Application code/commands activate other capabilities by resolving their actual
services.

## Purpose-first modules

Public modules represent application purposes (`database`, `security`, `auth`,
`messaging`, etc.), not package names. One module may contain multiple backing
packages, as `auth` does for OTP and WebAuthn.

Foundation's module catalog is curated. Normal runtime/optimization does not
scan installed packages for arbitrary `foundation-module.php` manifests.

Module installation may orchestrate Composer, publish missing config, invalidate
compiled state, and synchronize applicable capability-owned schemas. Removal
never drops schemas/application data.

## Execution boundaries

Each request/command/job/scheduled unit enters one InterMix execution scope.
Foundation assigns/seeds an `ExecutionId` and `RuntimeMode` and uses
`RuntimeContextTracker` to reset only external/static state that Foundation
actually touched.

Conceptually:

```text
enter InterMix scope
  execute one unit
finally
  reset targeted external state
  leave InterMix scope
```

If application work fails, a later cleanup failure does not replace/mask that
original application exception. Cleanup still attempts both targeted reset and
scope exit.

Persistent processes may reuse immutable/safe singleton infrastructure, but
request/message/tenant/principal/session state must remain execution-scoped or
explicitly durable.

## Worker lifecycle

Application maintenance workers and Omnibus queue workers are distinct:

- `routes/workers.php` -> Foundation `WorkerProvider` maintenance workers;
- `messaging.workers` -> Omnibus messaging workers.

Foundation provider workers expose `WorkerRuntime::heartbeat()` for singleton
lease refresh and runtime generation checks.

Omnibus 2.5 single workers use native `WorkerLifecycle` callbacks. Optional
`WorkerPool` remains an upstream Unix/pcntl process-pool feature; Foundation
constructs a fresh application in each child after fork.

Foundation owns graceful generation signalling (`runtime:reload`,
`worker:restart`, `schedule:interrupt`) but does not respawn/supervise daemons.
External process managers own process replacement/scaling.

## Scheduling and ownership

Foundation schedules application commands and uses CacheLayer coordination for
explicit overlap/single-server policy. Long child executions refresh their lease
through `ProcessRunner` heartbeat callbacks. Losing ownership terminates the
child instead of allowing it to continue unowned.

Schedule execution history uses stable schedule identity, not only command text,
to keep duplicated commands distinct.

## Deployment-owned compiled artifacts

Foundation can move parsing/normalization to deployment:

- configuration -> single/sharded config cache;
- routes -> Webrick matcher cache;
- command definitions -> `bootstrap/cache/commands.php`;
- scheduler metadata -> `bootstrap/cache/schedule.php`;
- eligible InterMix container graph -> configured container artifact;
- aggregate optimization state -> Foundation optimize artifacts.

Build/report/clear with:

```bash
php infbyte optimize
php infbyte optimize:report
php infbyte optimize:clear
```

These artifacts are deployment-owned and should not be committed. Cache
artifacts are optimizations; where designed to do so, invalid/stale artifacts
fall back to authoritative source definitions.

## Framework/application boundary

Foundation never depends on the Infbyte skeleton. Infbyte is an opinionated host
application that selects Foundation runtimes and supplies application defaults,
routes, providers, code, writable directories, and deployment UX.

Foundation owns reusable framework behavior; Infbyte does not recreate it.
