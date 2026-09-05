# Foundation 3 runtime migration

Foundation 3 replaces the mutable/fallback runtime architecture with one
builder-first composition model and immutable generated production releases.
This guide covers the runtime changes an InfByte/Foundation host must adopt.

## Dependency baseline

Foundation 3 currently requires:

- PHP `^8.4`;
- InterMix `^10.0.4`;
- Webrick `^5.3`.

`infocyph/phpforge` remains the development QA source at `dev-main@dev`.

## 1. Select the runtime explicitly

Choose exactly one Foundation runtime at bootstrap:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web($config);
$cli = Foundation::cli($config);
$worker = Foundation::worker($config);
$scheduler = Foundation::scheduler($config);
```

Foundation never infers application runtime policy from `PHP_SAPI`.

## 2. Move provider binding work to graph contribution

Providers contribute deterministic definitions to InterMix `ContainerBuilder`
before production compilation. Do not depend on `boot()` mutating the DI graph or
on late production provider activation.

The concrete mutable InterMix development `Container` is a composition-time
identity only. Production code must resolve from the generated
`ProductionContainer` and must not require mutable-container access.

Prefer InterMix recipes, service references, aliases, and explicit descriptors
over closure factories. Any remaining dynamic boundary must be intentional and
visible in compile/skipped reporting.

## 3. Use the Webrick production path for HTTP

Production web routing is compiled once through Webrick's coordinated release
compiler. Route registration, `Registrar`, mutable route collections, and route
source files are build/development concerns only.

Webrick owns:

- compiled route matching and execution plans;
- Request creation;
- `webrick.request` scope entry/leave;
- routing-control errors and application exception dispatch;
- runtime adapter selection;
- native response emission.

Do not add a Foundation outer HTTP scope or a second response emitter. A minimal
compiled route is intentionally allowed to remain Request-free and scope-free.

## 4. Use generated InterMix runtimes for non-web work

CLI, worker, and scheduler containers compile directly through InterMix and are
reused for the process/runtime lifetime.

One execution unit enters the corresponding stable semantic scope only when it
executes scoped work:

- `foundation.cli`;
- `foundation.worker`;
- `foundation.scheduler`.

Execution IDs and job/message/schedule context are scope seeds rather than scope
names. Persistent worker/scheduler processes must not retain principal, session,
DB transaction, message, or other execution-local state between units.

## 5. Deploy one immutable Foundation generation

A production release generation now owns all four runtime identities together.
The generation includes:

```text
foundation.php
config.php
web/
  container.php
  router.php
  release.json
cli/
  container.php
  container.php.foundation.json
worker/
  container.php
  container.php.foundation.json
  providers.php
scheduler/
  container.php
  container.php.foundation.json
```

Exact subordinate metadata files are compiler-owned; applications should not
construct artifact identities themselves.

Foundation stages and verifies the complete generation before atomically
switching the active pointer. If any compile/verification step fails, the
previous generation stays active.

Production release loading consumes the generation-owned `config.php` snapshot
and generated topology/artifacts. It does not rediscover project config,
providers, or routes and does not silently fall back to the mutable/source
runtime.

## 6. Remove obsolete configuration

Delete these old keys from application configuration:

```text
app.container.alias
app.container.compiled
app.container.compiled_activation
router.cache
```

They no longer select runtime behavior. Production artifact paths and trust
identities belong to the Foundation/Webrick release manifests.

The remaining InterMix composition controls are development/build concerns:

```text
app.container.environment
app.container.lazy_loading
app.container.debug_tracing.enabled
app.container.debug_tracing.level
```

## 7. Make production capabilities explicit

Production compilation/loading uses an explicit capability topology. An omitted
capability set is minimal; it no longer means “activate every optional package
that Composer happens to have installed.”

This keeps DBLayer, CacheLayer, messaging, communication, security, filesystem,
validation, OTP, and WebAuthn infrastructure cold when not selected.

Development composition may still discover installed packages when no explicit
topology is supplied.

## 8. Preserve primary failures during cleanup

Application/job/schedule failures take precedence over failures from cleanup.
Foundation attempts owned cleanup (scope state, DB rollback/disconnect, locks,
process registry, signal restoration, temp resources) without allowing a later
cleanup exception to replace the original work exception.

Application integrations should follow the same rule.

## 9. Do not recreate Foundation runtime lifecycle in InfByte

InfByte should provide host/application policy—configuration, providers, routes,
commands, worker definitions, writable paths, and deployment UX—then consume
Foundation's runtime/release APIs directly.

Do not add another container compiler, route cache, universal HTTP scope,
provider activation layer, or response emitter in the application skeleton.

## Final benchmark evidence

Phase 9 measured Foundation against the lower layers after the runtime redesign.
The values below are acceptance evidence from the canonical Foundation 3 runtime
plan; compare future results only under matching environments.

### Generated DI — PHP 8.4

- direct InterMix resolution: **221.8 ns median**;
- Foundation generated-container resolution: **218.67 ns median**;
- `Application::make()`: **240.67 ns median**;
- bare InterMix `withinScope()`: **1,404.21 ns**;
- Foundation execution boundary: **5,113.29 ns**;
- additional Foundation execution-boundary cost: about **3.709 µs**.

The façade/container cost is tens of nanoseconds; no additional DI optimization
was justified.

### Compiled HTTP — PHP 8.5

- standalone compiled Webrick: **4,570.43 ns/request**;
- Foundation compiled web: **4,614.67 ns/request**;
- attributable warm-request delta: **44.24 ns / 0.97%**;
- both processes: **8 MiB final / 10 MiB peak** memory in the attribution run.

The measured Foundation hot-path tax was approximately one percent, so the
accepted decision was to keep the simpler lifecycle/scope semantics rather than
introduce a deoptimization-prone shortcut.

### Real PHP-FPM + OPcache — PHP 8.4.25

20,000 requests at concurrency 32 completed with zero failures:

| Server | Throughput | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: |
| Nginx 1.24 | 2,089.04 req/s | 15 ms | 22 ms | 26 ms |
| Apache 2.4.58 | 1,782.89 req/s | 17 ms | 25 ms | 29 ms |

Cold-first/warm-sequential latency and aggregate PHP-FPM pool RSS are retained in
the Phase 9 benchmark artifacts. RSS in that evidence is aggregate per-process
RSS and must not be interpreted as unique physical memory.

The corrected Phase 9 acceptance workflow was run `33946071733` on commit
`7ce759d36e71e469314fdfa0ca54f75f24e78d9c` and covered PHP 8.4/8.5 stable and
prefer-lowest QA, analyzers, clean install, representative/maintenance/DI/HTTP
benchmarks, and the real PHP-FPM server gate.

## Migration checklist

Before shipping an InfByte/Foundation 3 host:

- choose web/CLI/worker/scheduler explicitly;
- migrate provider binding work to builder contribution;
- remove mutable-container assumptions from production services;
- remove the four obsolete config keys above;
- compile one immutable Foundation release generation;
- pass explicit production capability topology;
- boot HTTP through the Webrick release runtime;
- boot CLI/worker/scheduler from generated InterMix artifacts;
- ensure long-running execution state is scoped and cleanup preserves primary
  failures;
- verify no host-layer route cache, container compiler, universal web scope, or
  native emitter duplicates Foundation/Webrick/InterMix ownership;
- run the full release guard and representative benchmarks in the target
deployment environment.
