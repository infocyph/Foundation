# Infocyph Foundation 2.0 — Final Architecture, Ecosystem Consolidation, and Release Plan

**Status:** Final implementation draft  
**Review date:** 2026-08-17  
**Target:** `infocyph/foundation` 2.0  
**Backward compatibility:** intentionally not preserved  
**Standalone Console package:** retired after merge  
**Console source baseline:** `infocyph/Console` branch `feature/update-26`  
**Foundation source baseline:** current `infocyph/Foundation` `main` / 1.3 source  
**Engineering authority:** current `infocyph/PHPForge` `resources/engineering-principles.md`

---

## 1. Final Decision

Foundation 2.0 becomes the single Infocyph application integration/runtime package.

The standalone `infocyph/console` package is not released as the long-term CLI platform. Its proven command, terminal, process, scheduler, worker, resource-usage, signal, and preflight implementations are absorbed into Foundation according to **domain ownership**, not copied under a `Console` namespace.

There is no compatibility layer.

### Non-negotiable consequences

- Delete the `infocyph/console` runtime dependency.
- Do not retain `Infocyph\Console\...`.
- Do not create `Infocyph\Foundation\Console\...` as the replacement architecture.
- Dissolve Foundation's existing `src/Console/` directory.
- Do not retain aliases, proxy classes, deprecated entrypoints, old service IDs, dual config formats, or adapters whose only purpose is old Console/Foundation interoperability.
- Keep exactly one Foundation application/config/container composition root.
- Preserve separate runtime paths for **Web**, **CLI**, **Worker**, and **Scheduler**.
- Treat database, cache, filesystem, validation, cryptography, messaging, communication, OTP, passkeys, etc. as **lazy capabilities**, not runtime baseline.
- Delegate specialist mechanics to the specialist Infocyph package instead of duplicating them in Foundation.
- Foundation owns application policy, orchestration, lifecycle, runtime isolation, host-project conventions, and integration—not another implementation of each package.

The guiding ownership rule is:

> **Foundation owns application composition and runtime policy. Specialist libraries own their engines.**

---

## 2. Source-of-Truth Order

For this work use the following order whenever sources disagree:

1. Latest tagged package source and `composer.json`.
2. Latest tagged package public code/API.
3. Package `docs/` for semantic contracts and integration guidance.
4. Package README/examples.
5. Foundation/Console current code.
6. Stale prose/version references only as documentation defects—not as implementation truth.

This matters because some package prose still contains older wording even though the tagged source/Composer contract is newer.

---

## 3. Verified Dependency Versions

Use these as the Foundation 2.0 ecosystem baseline:

| Package | Latest verified version | Foundation role |
|---|---:|---|
| PHP | `^8.4` | Runtime |
| `infocyph/arraykit` | `5.1` | Core data/config/environment primitives |
| `infocyph/intermix` | `9.1` | Core DI/container/scopes/compiled resolution |
| `infocyph/uid` | `5.0` | Core application/execution identifiers |
| `infocyph/webrick` | `4.0.1` | Core Web runtime/router/kernel |
| `psr/log` | `3.0.2` generation | Direct Foundation logging contract |
| `infocyph/cachelayer` | `3.1.2` | Optional cache/locks/counters/node/cluster capability |
| `infocyph/dblayer` | `4.0.0` | Optional database capability |
| `infocyph/epicrypt` | `2.1` | Optional cryptography/security capability |
| `infocyph/omnibus` | `2.1.1` | Optional messaging/events/queue/workflow capability |
| `infocyph/otp` | `6.0` | Optional OTP/MFA capability |
| `infocyph/pathwise` | `3.1` | Optional filesystem/storage workflow capability |
| `infocyph/reqshield` | `3.0.0` | Optional validation/sanitization capability |
| `infocyph/talkingbytes` | `2.0.0` | Optional HTTP/email/webhook/gRPC communication capability |
| `web-auth/webauthn-lib` | `5.3.5` | Optional WebAuthn/passkey capability |
| `infocyph/phpforge` | `dev-main@dev` | Development/release engineering authority |

### Important version corrections from Foundation 1.3

Foundation 1.3 is materially stale in its optional package versions and currently requires Webrick `^3.3`; Foundation 2.0 must move to Webrick `^4.0.1` and the current Infocyph package generations above.

### Composer constraints to use

Use package-family constraints while establishing the latest verified patch as the minimum where useful:

```text
arraykit       ^5.1
intermix       ^9.1
uid            ^5.0
webrick        ^4.0.1
cachelayer     ^3.1.2
dblayer        ^4.0
epicrypt       ^2.1
omnibus        ^2.1.1
otp             ^6.0
pathwise        ^3.1
reqshield       ^3.0
talkingbytes    ^2.0
webauthn-lib    ^5.3.5
phpforge        dev-main@dev
```

Do not hard-code these versions separately in multiple command classes, docs, tests, and config files. The module catalog should be the single Foundation-owned module-version registry used by installation commands, documentation generation/tests, and readiness reporting.

---

## 4. Target Foundation Composer Shape

### 4.1 Runtime requirements

Recommended target:

```json
{
    "require": {
        "php": "^8.4",
        "infocyph/arraykit": "^5.1",
        "infocyph/intermix": "^9.1",
        "infocyph/uid": "^5.0",
        "infocyph/webrick": "^4.0.1",
        "psr/log": "^3.0.2"
    }
}
```

Rationale:

- ArrayKit is the common configuration/data primitive.
- InterMix is the one DI/runtime-scope engine.
- UID is the one identifier toolkit.
- Webrick is the supported Web runtime.
- Foundation directly uses PSR-3 types, so `psr/log` must be a direct dependency rather than arriving transitively.
- `infocyph/console` disappears completely.

### 4.2 Optional integration verification

Recommended `require-dev` baseline:

```json
{
    "require-dev": {
        "infocyph/cachelayer": "^3.1.2",
        "infocyph/dblayer": "^4.0",
        "infocyph/epicrypt": "^2.1",
        "infocyph/omnibus": "^2.1.1",
        "infocyph/otp": "^6.0",
        "infocyph/pathwise": "^3.1",
        "infocyph/phpforge": "dev-main@dev",
        "infocyph/reqshield": "^3.0",
        "infocyph/talkingbytes": "^2.0",
        "web-auth/webauthn-lib": "^5.3.5"
    }
}
```

These packages are present in Foundation's development matrix so every optional provider can be integration-tested, but a production consumer should only install the capabilities it uses.

### 4.3 Suggested capability wording

```json
{
    "suggest": {
        "infocyph/cachelayer": "Adds configured cache stores, locks, atomic counters, node/cluster cache, and cache-backed Foundation integrations.",
        "infocyph/dblayer": "Adds database connections, repositories, schema/migrations, persistence, and database-backed Foundation integrations.",
        "infocyph/epicrypt": "Adds cryptography, data protection, secure token/password, key-ring, secret, and authentication security integrations.",
        "infocyph/omnibus": "Adds events, messaging, queues, durable consumers, workflows, scheduled-message dispatch, and broadcasting integrations.",
        "infocyph/otp": "Adds production OTP, HOTP, TOTP, OCRA, recovery-code, provisioning, and MFA integrations.",
        "infocyph/pathwise": "Adds storage mounts, safe filesystem workflows, uploads/downloads, archives, synchronization, retention, and filesystem auditing.",
        "infocyph/reqshield": "Adds request/command/config validation, sanitization, typed output, database validation, and schema export.",
        "infocyph/talkingbytes": "Adds HTTP, inbound/outbound email, webhook, gRPC, retry, and communication integrations.",
        "web-auth/webauthn-lib": "Adds production WebAuthn/passkey registration and authentication."
    }
}
```

### 4.4 Foundation autoload

Remove Foundation's current Composer `autoload.files` entry for `src/Config/config_helpers.php`.

Target:

```json
{
    "autoload": {
        "psr-4": {
            "Infocyph\\Foundation\\": "src/"
        }
    }
}
```

Foundation 2.0 has no BC obligation that justifies process-global helper declarations and a static base-path holder.

### 4.5 CLI ownership

After Console retirement, Foundation must be the canonical owner of CLI bootstrap code. Provide one canonical package entry stub and, if the host-project convention remains `php infbyte`, generate/ship a tiny root `infbyte` delegator from that same implementation. Do not retain a second Console-owned binary implementation.

A Composer binary may be exposed as:

```json
{
    "bin": ["bin/infbyte"]
}
```

if direct `vendor/bin/infbyte` usage is desired. The host `infbyte` script must remain a tiny delegator, not a second command application.

---

## 5. PHPForge Rules That Govern This Redesign

Foundation 2.0 must follow the current PHPForge engineering principles, especially:

1. Correctness, security, authorization, data integrity, and operational stability outrank performance or simplification.
2. Primary performance target is sustained successful end-to-end RPM, not microbenchmark aesthetics.
3. Optional work must be deferred until actually required.
4. Package bootstrap must be deterministic, minimal, and free from unnecessary filesystem scanning, network activity, environment parsing, reflection discovery, or hidden registration.
5. No work at file-include time beyond declarations/compile-safe setup.
6. Prefer simple, explicit, measurable designs.
7. Avoid unnecessary wrappers, adapters, managers, interfaces, DTOs, result objects, and call hops.
8. Create interfaces only at genuine substitution, provider, extension, or infrastructure boundaries.
9. Keep container access at composition/integration boundaries; do not turn it into a service locator inside domain logic.
10. Use InterMix compilation/caching rather than building another resolver engine.
11. Persistent worker/request state must be explicitly scoped/reset.
12. New public types must justify independent existence.
13. Default cognitive-complexity caps remain:

```yaml
cognitive_complexity:
    class: 80
    function: 12
    dependency_tree: 120
```

14. Review excessive small/trivial/pass-through class ratios; do not preserve classes merely because they already exist.
15. Benchmark cold boot, warm boot, first lazy use, repeated use, failure paths, and persistent-worker behavior separately.

This redesign is explicitly a breaking major release, so PHPForge compatibility-preservation rules do not require retaining obsolete Foundation/Console public surfaces.

---

## 6. Runtime Architecture — Hard Isolation

### 6.1 Final runtime enum

Use runtime names that describe actual lifecycles:

```php
enum RuntimeMode: string
{
    case Web = 'web';
    case Cli = 'cli';
    case Worker = 'worker';
    case Scheduler = 'scheduler';
}
```

Prefer `Cli` instead of retaining `Console`: there is no Console package/namespace to preserve and the runtime is the CLI execution path.

### 6.2 Canonical root entrypoints

```php
Foundation::web($config);
Foundation::cli($config);
Foundation::worker($config);
Foundation::scheduler($config);
```

Remove the ambiguity where `local()`, `production()`, and `api()` look like runtime entrypoints. Environment and application presets belong in configuration:

```php
Foundation::web([
    'app' => [
        'env' => 'production',
        'preset' => 'api',
    ],
]);
```

The exact preset representation may stay typed, but it must not obscure runtime selection.

### 6.3 Runtime invariant

> Selecting a Foundation runtime must not initialize another runtime's graph.

#### Web baseline

May eagerly prepare only what every Web request needs:

```text
Application/config/container/path metadata
    -> Web runtime bootstrap
    -> Webrick kernel/router cache metadata
    -> minimal error/logging boundary
    -> request scope
```

Must not load normal CLI registry/output/prompt/completion, worker supervision, or schedule evaluation.

#### CLI baseline

```text
infbyte tiny preflight
    -> version/help/list/completion OR selected route
    -> CLI runtime only when required
    -> selected command metadata
    -> selected command scope
```

Must not construct Webrick HTTP kernel/routes/middleware, worker supervisor, full scheduler graph, DB, cache, filesystem, network, etc. unless the selected command explicitly requires that capability.

#### Worker baseline

```text
Worker runtime bootstrap
    -> process/signal/supervisor primitives
    -> selected worker provider
    -> per-job scopes
```

Omnibus, database, cache, network, filesystem, etc. remain selected-worker capabilities.

#### Scheduler baseline

```text
Scheduler runtime bootstrap
    -> schedule manifest
    -> clock/cron/due evaluation
    -> selected due entry
```

Process execution, DB schedule state, distributed locks, Omnibus scheduled-message dispatch, etc. resolve only for entries that need them.

### 6.4 Runtime hand-off from CLI preflight

`php infbyte worker:run foo` and `php infbyte schedule:work` may be typed as commands to the operator, but the tiny preflight should recognize them and enter the dedicated runtime before building the ordinary CLI graph.

Likewise `version`, `help`, `list`, and completion should finish without a full Foundation boot whenever the command manifest can answer them.

### 6.5 Runtime isolation does not prohibit capabilities

Examples:

- `route:cache` is a CLI command that may load the **routing build capability**, but it must not boot the normal Web request lifecycle.
- A CLI import command may resolve DBLayer.
- A scheduler entry may resolve TalkingBytes.
- A worker may run a Pathwise storage workflow.
- A Web controller may dispatch Omnibus messages.

The rule is **selected work activates its requirements**; runtime name does not ban shared capabilities.

### 6.6 Do not add protocol runtimes prematurely

Do not add `RuntimeMode::Grpc`, `Email`, `Database`, `Cache`, etc.

TalkingBytes 2.0's inbound gRPC side is a framework-neutral dispatcher, not a full Foundation-owned server/listener lifecycle. Outbound gRPC is a communication capability; inbound gRPC can be hosted by an explicit Worker/service process around TalkingBytes. Add a dedicated gRPC runtime only if Foundation later owns a real listener/server lifecycle.

---

## 7. Provider and Capability Activation Model

### 7.1 Host provider map

Move from the current two-path provider map to:

```php
return [
    'common' => [
        // Only genuinely common application providers.
    ],
    'web' => [
        App\Providers\WebServiceProvider::class,
    ],
    'cli' => [
        App\Providers\CliServiceProvider::class,
    ],
    'worker' => [
        App\Providers\WorkerServiceProvider::class,
    ],
    'scheduler' => [
        App\Providers\SchedulerServiceProvider::class,
    ],
];
```

`common` must stay intentionally tiny. Do not place DB/cache/filesystem/network/auth there just because multiple runtimes might eventually use them.

### 7.2 Optional package states are distinct

Foundation must distinguish:

1. **Package present** — Composer installed it, perhaps transitively.
2. **Module configured/enabled** — host config/module setup declares Foundation integration.
3. **Provider activated** — runtime selected a service from that module.
4. **Capability used** — actual DB/cache/network/etc. work occurred.

Do not infer #2–#4 merely from #1.

This is especially important because:

- DBLayer pulls CacheLayer as a dependency.
- OTP pulls CacheLayer.
- Epicrypt pulls Pathwise.
- Webrick pulls ArrayKit/InterMix.

Those transitive package installations do not mean Foundation should publish, initialize, or expose the corresponding Foundation module.

### 7.3 Command capability metadata

Retain coarse command capability metadata where it materially helps preflight, optional-package validation, authorization, or manifest compilation.

Good examples:

```text
DATABASE
CACHE
FILESYSTEM
NETWORK
MESSAGING
CRYPTO
OTP
VALIDATION
AUTH
```

Do not create protocol-level command capabilities such as `HTTP`, `EMAIL`, and `GRPC` when `NETWORK` provides the useful preflight boundary and TalkingBytes owns protocol choice.

### 7.4 Service-driven provider activation

For the normal Foundation runtime, prefer service/provider manifests over a second generic capability engine. When code asks for an optional service, Foundation can activate the one provider known to supply it.

No reflection scan. No package scan. No runtime class-directory discovery.

---

## 8. InterMix 9.1 — Make It the Only Container/Scope Engine

### Use directly

InterMix already provides:

- PSR-11 container behavior.
- scoped, singleton, transient lifetimes.
- lazy resolution.
- explicit/reflection-free factories.
- tags.
- environment-specific overrides.
- compiled resolver maps and compatibility fingerprints.
- compilation reports.
- optional PSR-6 definition caching.
- request/job scope seeding.
- closure serialization when actually required.

### Foundation should keep

- Foundation configuration → InterMix definition composition.
- Foundation provider/module registration.
- runtime-specific container build/activation policy.
- package/config fingerprint inputs that are genuinely Foundation-specific.

### Foundation should delete/reduce

- Console container factory/compiler duplicates.
- custom generic resolver layers that merely forward InterMix.
- custom scope engines.
- repeated container lookups in managers/domains.
- manager base classes that exist only to call `container->get()` repeatedly.

### Lifetime policy — singleton first

Foundation 2.0 must make InterMix service lifetimes explicit instead of allowing provider-by-provider defaults to drift. The default policy is **singleton-first for reusable infrastructure and stateless application services**, with small disposable scopes around each unit of work. `Transient` is exceptional rather than the default.

Use the following rule:

```text
Singleton by default for reusable/stateless services
        ↓
Scoped when mutable state belongs to one execution unit
        ↓
Transient only when a fresh instance is semantically required
```

#### Singleton

Register as InterMix `Singleton` when the service is immutable/stateless after construction, expensive or unnecessary to rebuild, safe to reuse for the lifetime of the Foundation application/process, and does not contain request/command/job-specific mutable state. Typical candidates include:

- Foundation configuration repository after bootstrap/compilation;
- path/application metadata services;
- logging infrastructure;
- compiled manifests, command indexes, route indexes and other immutable artifacts;
- provider/factory objects;
- CacheLayer stores, lock providers and atomic-counter providers when the selected adapter is documented as reusable;
- DBLayer connection managers/pools/factories when the selected DBLayer object is documented as reusable;
- TalkingBytes configured clients/factories/profiles that are safe for repeated use;
- Omnibus buses/transports/handler maps that are safe for repeated use;
- ReqShield compiled validators and immutable validation metadata;
- Epicrypt protection/key-service objects that are safe for reuse;
- UID configuration/policy services;
- Foundation stateless application services and orchestrators whose dependencies are also reusable.

Do **not** mechanically mark every package object singleton. Respect the owning package's lifecycle contract. A client/provider that documents per-operation state or unsafe reuse must use the appropriate narrower lifetime.

A singleton here is an **InterMix application/container singleton**, not process-global static state. Do not reintroduce static service locators or global façade state to simulate singleton access.

#### Scoped

Register as `Scoped` when the object contains state belonging to exactly one unit of work. The scope boundary is the runtime execution unit, not the whole process:

- Web: one HTTP request;
- CLI: one selected command execution;
- Worker: one dequeued job/message/envelope execution;
- Scheduler: one scheduled execution unit when mutable scoped state is required.

Typical scoped values/services include request/input objects, principal/session execution context, command IO/input context, execution/correlation IDs when modeled as mutable context, job/envelope context, schedule-entry execution context, and other per-operation state.

Persistent workers and persistent web runtimes must therefore keep the singleton graph hot while creating and destroying only the small execution scope for each request/job. This is the intended high-throughput model.

#### Transient

Use `Transient` only when a fresh instance is part of the contract, for example a stateful one-shot builder/operation object that cannot safely be reused or scoped. Do not choose transient merely because construction is cheap or because it avoids thinking about lifecycle.

#### Infrastructure state versus DI lifetime

Singleton ownership does not allow mutable unit-of-work state to leak. Examples:

- a DBLayer connection manager/pool may be singleton-owned, but an open transaction, unit-of-work state or per-request query context must never survive the execution boundary;
- a CacheLayer store may be singleton-owned, but request/job-local memoized values must not leak unless explicitly designed as process/application cache;
- TalkingBytes/Omnibus clients or transports may be reusable singletons, but message/request-specific metadata belongs to the execution object/scope;
- authentication principal/session context is scoped even when the auth repositories/services are singleton-owned.

Use InterMix scope destruction for DI-owned scoped state. Keep explicit Foundation/package reset hooks only for external/static/runtime state that the owning package cannot isolate through the InterMix scope.

#### Provider activation still precedes lifetime

`Singleton` does **not** mean eagerly loaded. Runtime and capability eligibility are evaluated first. A singleton DBLayer/CacheLayer/TalkingBytes/Omnibus service is constructed only when the selected runtime/capability actually requests it; once constructed, it is reused safely within that application/process.

Therefore the intended sequence is:

```text
selected runtime
    → eligible provider/capability
        → lazy service resolution
            → singleton reusable infrastructure
            → scoped execution state
```

This preserves both goals: no unused capability bloats startup, and an activated capability is not reconstructed unnecessarily for every request/command/job.

### Runtime scopes

Use InterMix scopes for:

- one HTTP request;
- one CLI command execution;
- one queue/job execution;
- one scheduled unit when scoped application state is needed.

Seed contextual values that already exist when an execution scope begins directly into the InterMix scope instead of registering temporary factories, rebinding container definitions, or storing them in process-global state.

```text
Web scope:
- Request
- execution/correlation ID
- transport context already known at entry

CLI scope:
- parsed input
- IO
- execution ID

Worker job scope:
- message/envelope/job context
- execution ID

Scheduler execution scope:
- scheduled entry/execution context
- execution ID
```

Context discovered later inside an execution must remain scoped state rather than being artificially created as an initial seed. The primary example is an authenticated principal: a web request normally enters its scope before authentication middleware resolves the principal. Seed the incoming request and execution identity first; populate the scoped principal context only after authentication succeeds.

Use the following ownership rule consistently:

```text
Already exists before entering the scope
    -> seed it

Needs lazy construction and belongs to one execution
    -> scoped service

Reusable safely across executions
    -> singleton

Semantically requires a fresh object every resolution
    -> transient
```

Typical examples:

```text
Incoming Webrick Request            -> seed
Parsed CLI input                    -> seed
Omnibus envelope/message            -> seed
Execution/correlation ID            -> seed
Scheduled execution descriptor      -> seed

CurrentPrincipalContext             -> scoped
Browser/request session context      -> scoped
Per-execution authorization state    -> scoped
Per-job mutable context              -> scoped

ConfigRepository                    -> singleton
Path/configuration infrastructure    -> singleton
DBLayer connection/factory layer     -> singleton + lazy capability activation
CacheLayer stores/lock providers     -> singleton + lazy capability activation
TalkingBytes clients/profiles        -> singleton + lazy capability activation
Omnibus bus/transports               -> singleton + lazy capability activation
Compiled ReqShield validators        -> singleton + lazy capability activation
UID application policy/generators    -> singleton
Logger                               -> singleton
```

The goal is a hot reusable singleton application graph with very small disposable execution scopes. Seeding avoids an unnecessary provider/factory/registry layer for objects that Foundation already possesses at the runtime boundary, reduces container mutations and lookups, and prevents request/job context from leaking through static or process-global state.

### Runtime reset

InterMix scope close should dispose of scoped application objects. Keep explicit Foundation reset hooks only for external/static package state that cannot be isolated by DI scope—for example DBLayer runtime/global connection state after a unit of work.

Current `RuntimeContextResetter` is too small to justify a wrapper once lifecycle code can call the tracker/reset hook directly. `RuntimeContextTracker` itself should become a narrow external-state cleanup registry rather than a second scope mechanism.

### Compiled container artifacts

Compile per runtime, not one giant all-capability graph:

```text
bootstrap/cache/container/web.php
bootstrap/cache/container/cli.php
bootstrap/cache/container/worker.php
bootstrap/cache/container/scheduler.php
```

Only include definitions eligible for that runtime and only include optional capability definitions when the module is configured for that artifact. InterMix owns resolver compilation; Foundation owns which definitions belong to the runtime.

Benchmark before making compiled activation mandatory. If dynamic registration makes one runtime ineligible, fall back explicitly and expose the reason in `optimize:report`.

---

## 9. ArrayKit 5.1 — Collapse Foundation Data/Config Duplication

### Native capabilities Foundation should use

ArrayKit 5.1 already provides:

- `Config` with typed getters and bounded read memoization.
- `LazyFileConfig` with first-segment loading.
- namespace cache files.
- a `__flat.php` scalar/null leaf index for fast exact config lookup.
- environment parsing and variable expansion.
- process environment access.
- dot notation with wildcard/bounded safe traversal.
- array helpers/shape checks.
- collections/lazy collections/pipeline.
- DTO hydration primitives.

### Hard deletion: `Data` domain

Foundation's current `DataManager` simply forwards ArrayKit's collection, config, dot, dotenv, env, DTO, helper, pipeline, lazy-config, and shape APIs.

Delete:

```text
src/Data/DataManager.php
src/Data/DataServiceProvider.php
Facades/Data.php
Application::data()
```

Consumers who need ArrayKit should use ArrayKit directly.

### ConfigRepository

A Foundation config repository remains justified only for application-specific behavior such as:

- Foundation precedence/layers.
- default/preset/application/inline policy.
- Foundation-specific environment helpers such as `isProduction()` if retained.
- lazy namespace overlay of Foundation defaults + project source + inline overrides.

Do not recreate ArrayKit lookup, typed getters, dot notation, or lazy-cache mechanics.

### Preserve Foundation's list-replacement merge semantic

Do **not** blindly replace current Foundation merge policy with ArrayKit's generic recursive merge. Foundation currently treats list configuration as an atomic replacement; `array_replace_recursive()` can merge list positions and change semantics.

Keep the list-replacement rule, but prefer a private cohesive merge method in Foundation's config composition instead of a standalone `ConfigMerger` type unless that type has multiple independent owners.

### Preserve ArrayKit's flat config cache

Current Foundation sharded cache warming uses `LazyFileConfig` and then deletes `__flat.php`. Stop discarding that optimization without evidence.

Benchmark:

1. source lazy config;
2. sharded namespace cache with flat leaf index;
3. sharded namespace cache without flat index;
4. single compiled config file.

Measure cold boot, warm common-key lookups, first namespace miss, full `all()`, and representative Web/CLI startup. Keep whichever produces the best real runtime profile while preserving semantics.

### Environment

Use ArrayKit `EnvParser`/`Environment` for parsing/process environment behavior.

Foundation's environment loader remains an application policy owner only for:

- `.env` / `.env.local` file precedence;
- configured file list;
- protecting environment variables that existed before dotenv hydration;
- project base-path resolution.

Delete generic environment wrappers elsewhere.

### Remove global config/path helper state

Foundation currently Composer-autoloads global path/env helper functions backed by a process-global `ConfigRuntime::$basePath`.

For 2.0:

- remove the Composer `autoload.files` entry;
- delete the static base-path runtime holder;
- use `ConfigRepository`, `PathManager`, and ArrayKit Environment directly;
- if host config files need convenience, use explicit imports/objects or an intentionally opt-in helper file—not mandatory process-global state.

This improves multi-application tests and persistent-runtime safety and removes an always-loaded Foundation file.

---

## 10. UID 5.0 — Foundation Should Configure IDs, Not Reimplement Them

UID 5.0 already owns:

- UUID v1/v3/v4/v5/v6/v7/v8.
- ULID.
- Snowflake/Sonyflake/Randflake/TBSL.
- TypeID/ObjectID/NanoID/RandomId/CUID2/KSUID/XID.
- deterministic and opaque IDs.
- value objects/comparators.
- binary/base conversion.
- file/memory/PSR-16/callback sequence providers.

Foundation's current `IdentifierManager` mirrors too much of this public API.

### Foundation should retain only application ID policy

A very small configured ID service is justified for stable application purposes such as:

```text
application.default_id
auth.account_id
auth.session_id
runtime.execution_id
messaging.correlation_id
```

It can map a purpose to a UID generator/configuration.

### Delete pass-through ID APIs

Do not re-expose Foundation methods for every UID algorithm, parse/compare/base-convert operation. Consumers needing a specific ID primitive should call UID directly.

Delete/reduce:

```text
IdentifierManager bulk forwarding API
Facades/Ids.php
Application::ids() as a giant UID gateway
```

Keep only a minimal Foundation configured generator if the application-level default is genuinely useful.

---

## 11. Webrick 4.0.1 — Make Foundation's Web Layer Thin

Webrick 4.0.1 already owns:

- route registration/groups/domains/resources/attributes;
- route cache compilation with sharded/fused/generated artifacts;
- validated/atomic cache publication;
- lazy middleware resolution;
- constructor/method injection through InterMix;
- request scopes;
- signed and temporary URLs;
- central typed HTTP exception/error boundary;
- JSON/text/redirect/stream/file/range/view responses;
- throttling/response cache/negotiation/compression/hardening/telemetry/cookie middleware;
- emitters for FPM, FrankenPHP, LiteSpeed, Unit, Swoole, RoadRunner, Workerman, etc.

### Foundation Web responsibilities

Keep only:

- host route-file/config composition;
- application route presets;
- auth/session/CSRF middleware integration;
- application error/logging customization;
- runtime scope/reset integration;
- app-specific JSON/resource response contract;
- operational route commands that know Foundation's project layout.

### Reduce RouterManager

Current `RouterManager` mostly forwards Webrick methods for routing, URL generation, signed URLs, route collection, and dispatch.

Replace it with the minimum host integration needed to:

- build/access the configured `RouterKernel`;
- register Foundation presets/aliases;
- load project route manifests where appropriate.

Use Webrick's `Registrar`/facade/kernel directly for specialist routing operations.

### HttpKernel

Current Foundation `HttpKernel` mostly delegates to RouterManager then resets runtime state. Keep a Foundation HTTP boundary only if it adds the required request scope/cleanup/error-policy lifecycle. It should not wrap every Webrick kernel method.

### HTTP-specific filesystem behavior

Move request/response upload/download conveniences out of `FilesystemManager`. Webrick owns HTTP request/response. Foundation `Http` may adapt a Webrick upload to a Pathwise storage operation; Pathwise remains HTTP-agnostic.

### Strict Composer-load isolation prerequisite

Webrick 4.0.1 currently uses Composer `autoload.files` for helper functions. Because Webrick is installed in a full Foundation application, Composer includes that file in CLI/worker/scheduler processes even when no Web runtime is activated.

For literal runtime isolation, update Webrick so helper functions are opt-in or otherwise avoid mandatory `autoload.files`. Provider laziness alone cannot prevent Composer from including an autoload-file dependency.

This is an ecosystem prerequisite for the strict statement:

> CLI contains zero Webrick source files unless a selected CLI capability explicitly loads Webrick classes.

---

## 12. CacheLayer 3.1.2 — Delete Cache/Lock Reimplementations

CacheLayer 3.1.2 already owns:

- PSR-6 and PSR-16 caches.
- memory/APCu/file/PHP-file/shared-memory/Redis/Valkey/Redis Cluster/Memcached/PDO/SQLite/MongoDB/Scylla stores.
- native bulk paths.
- tags/generation invalidation.
- stampede-safe `remember()`.
- tiered caches.
- immutable payload/security/failure policy.
- Node Cache (APCu L1 + SQLite L2).
- Cluster Cache with durable invalidation, cursors, replay, retention recovery, outbox.
- atomic counters.
- bounded process-local memoization.
- cache metrics.
- token-owned File/Redis/Valkey/Memcached/PDO/advisory lock providers with acquire/refresh/release semantics.

### Foundation should not own lock mechanics

Foundation/merged Console may own application policies such as:

```text
command without-overlap
schedule without-overlap
schedule one-server
worker ownership/supervision lease policy
```

but acquisition, ownership tokens, refresh, timeout, and release belong to CacheLayer.

Delete generic Foundation/Console lock implementations that duplicate these mechanics. Keep only a narrow policy→CacheLayer lock-provider adapter if the command/schedule contract needs a stable internal policy boundary.

### Reduce CacheManager

A Foundation cache integration is justified for application configuration and named shared instances. It does **not** need to mirror CacheLayer's entire API.

Recommended retained surface:

```text
cache store resolver: store(name/default)
lock provider resolver when Foundation runtime policy requests one
configured node/cluster runtime factory only where Foundation operations need it
```

Everything else—remember, tags, metrics, counters, memoizers, adapter-specific behavior—is used through CacheLayer directly.

### Reduce CacheLayerFactory

Current Foundation factory re-encodes almost every CacheLayer factory and option. Replace that with one configuration→CacheLayer construction recipe, with private backend-specific parsing where unavoidable.

Do not create a Foundation method for every CacheLayer public factory.

### Native cross-package integrations

Wire the same configured CacheLayer instances directly into:

- Webrick response cache/throttling where selected;
- DBLayer query cache;
- InterMix definition cache if enabled;
- Omnibus uniqueness/overlap/rate-limit/circuit policies;
- Foundation browser session cache store/locks;
- Foundation auth lockout/rate-limit/replay state;
- OTP authentication-state cache.

Do not route these through an extra Foundation cache facade if the dependent package already consumes CacheLayer/PSR contracts directly.

### Composer-load isolation

CacheLayer currently has Composer `autoload.files`. For strict installed-but-unused isolation, make helper loading opt-in in the CacheLayer package. This is not required for semantic provider laziness, but it is required if `get_included_files()` must show no CacheLayer source on an unrelated runtime path.

---

## 13. DBLayer 4.0 — Foundation Owns Configuration/Discovery, DBLayer Owns Database Mechanics

DBLayer 4.0 already owns:

- connection configuration/manager/pooling/read replicas;
- replica selection strategies;
- QueryBuilder;
- repositories/TableRepository;
- transactions/savepoints/retries;
- query timeout/deadline/cancellation;
- query result caching through CacheLayer with commit-safe tag invalidation;
- profiling/query logs/telemetry/query-shape reporting/explain;
- offset/keyset/opaque cursor pagination;
- resumable chunks/lazy streaming;
- schema builder;
- migration runner/ledger/leases/dry-run/rollback/reset/refresh/fresh;
- seeding;
- bounded explicit relations;
- production security/TLS controls;
- persistent-runtime reset.

### Reduce DatabaseManager dramatically

Current `DatabaseManager` forwards a very large portion of DBLayer's static/public API. That is a pass-through layer.

Foundation should keep:

- project config → DBLayer connection registration;
- named/default connection selection;
- auth/session/validation integration wiring;
- migration/seeder application manifest orchestration;
- runtime cleanup hook where DBLayer static/runtime state requires it.

Use DBLayer directly for:

```text
query builder/repository/table
transactions
telemetry/query logs/profiler
explain/health/stats
query timeout/deadline/cancellation
pooling/capabilities
query caching
schema operations
```

### Keep DBLayerFactory-style lazy registration

A small Foundation `DBLayerFactory`/connection resolver remains justified because it translates Foundation application config into lazy DBLayer connection registration exactly once.

Avoid then wrapping every resolved operation in another Foundation method.

### Migrations

DBLayer's own documentation explicitly assigns the correct boundary:

- DBLayer owns schema/migration execution.
- Foundation/application owns discovery/compiled explicit migration class list and framework-aware commands.

Keep a small migration orchestration service that:

1. reads Foundation's compiled migration manifest;
2. instantiates the explicit migration classes;
3. resolves the configured connection/lock policy;
4. delegates to DBLayer `MigrationRunner`;
5. formats results in CLI commands.

Do not build another migration ledger/runner/schema engine.

### ReqShield DB validation

Keep a thin `DatabaseProvider` implementation only because ReqShield defines a real cross-package provider contract (`batchExists` / `batchUnique`). It should delegate query construction/physical chunking to DBLayer and remain a genuine interoperability adapter.

---

## 14. Epicrypt 2.1 — Remove the Giant Foundation Crypto Facade

Epicrypt 2.1 already owns focused modules for:

- certificate/PKI/key exchange;
- cryptographic primitives;
- data protection for strings/files;
- key rings/rotation;
- password hashing/verify/rehash;
- secure random/key-material generation;
- integrity verification;
- JWT/JWKS/OAuth access-token primitives;
- opaque/refresh-token lifecycle;
- DPoP;
- CSRF primitives;
- generic signed URLs;
- reset/action tokens;
- secure secret/token workflows.

### Current problem

Foundation's current `SecurityManager` is a broad pass-through surface over Epicrypt. This repeats the specialist package API and produces an extra manager/facade/call hop.

### Foundation should own only application security policy

Retain only integration such as:

- application key/key-ring configuration;
- auth password driver configuration;
- auth token/refresh-token integration;
- encrypted Foundation secret-store workflow;
- application-specific key rotation/secret operational commands;
- secure config/path resolution needed to construct Epicrypt objects.

Consumers needing certificate building, low-level crypto, generic JWT, digest, HMAC, signed URL, etc. should call Epicrypt directly.

### Avoid duplicate signed URL ownership

Webrick owns route signed/temporary URLs. Do not wrap Epicrypt generic signed URLs as another Foundation route URL API.

Use Epicrypt SignedUrl only for a non-routing application capability that truly needs generic signed URL semantics.

### Additional high-value integrations

Foundation can expose application workflows, without reimplementing crypto, for:

- key-ring rotation command;
- secure secret rotation;
- optional OAuth/JWT authentication driver;
- secure refresh-token storage/rotation through DBLayer;
- artifact/release verification only where Foundation itself needs deploy artifact trust.

Do not add these merely to increase feature count; enable them where the Foundation application contract has a concrete use case.

---

## 15. Omnibus 2.1.1 — Make It the Messaging Engine

Omnibus already owns:

- synchronous command/message dispatch;
- PSR-14 events;
- handler/listener/route maps;
- sync/in-memory/DBLayer/Redis/Valkey/AMQP/SQS transport boundaries;
- delayed work;
- durable consumers;
- at-least-once settlement/crash recovery;
- retries/failed messages/poison capture;
- chains/batches/workflows;
- scheduled-message dispatcher;
- broadcasting;
- after-response/provider-neutral hooks;
- telemetry;
- CacheLayer-backed uniqueness/overlap/rate-limit/circuit policies.

### Foundation's boundary

Foundation owns:

- application message/handler/listener route manifests;
- host config → Omnibus construction;
- CLI queue/failure/worker commands;
- Worker runtime that calls bounded Omnibus consumer work;
- Scheduler integration that invokes a stable Omnibus scheduled-message key;
- auth/tenant/request scope integration where needed.

Omnibus owns message semantics and settlement.

### Do not duplicate scheduler responsibilities

Omnibus explicitly does **not** implement cron parsing, timers, process loops, or process supervision. Those remain Foundation Scheduling/Worker responsibilities.

Foundation Scheduling should schedule a stable message key and delegate message creation/dispatch to Omnibus; never serialize mutable message objects or closures into the schedule artifact.

### Reduce MessagingManager

Bind and inject `MessageBus` and `EventDispatcher` directly. Keep a Foundation messaging integration object only if it adds application config/manifest policy—not just `dispatch()` and `event()` pass-through methods.

Use Omnibus's testing sender/fakes directly instead of a Foundation fake state layer unless a Foundation test API materially improves host-project testing.

### Fix module catalog

Messaging is **not** engine-built-in. Foundation's integration/runtime commands are built in, but the messaging engine package is:

```text
infocyph/omnibus ^2.1.1
```

The module catalog must reflect that.

---

## 16. OTP 6.0 — Keep Foundation MFA Workflow, Fix Replay Protection

OTP 6.0 already owns:

- Generic OTP;
- HOTP;
- TOTP;
- OCRA;
- recovery codes;
- provisioning URIs;
- SVG QR provisioning;
- secret rotation planning;
- verification windows;
- atomic replay/state contracts.

### Keep Foundation-owned auth workflow

Foundation legitimately owns:

- account factor enrollment state;
- factor activation/deactivation;
- account/factor storage;
- auth audit events;
- lockout/rate limits;
- notification/delivery policy;
- step-up application policy;
- mapping OTP verification to Foundation MFA results.

### Remove OTP utility pass-throughs

Do not re-export generic HOTP/OCRA constructors, provisioning parsing, secret-normalization, and other standalone OTP utilities through Foundation. Use OTP directly for those capabilities.

### MUST FIX — production TOTP replay protection

OTP 6.0 requires **both**:

- a `CacheLayer\Cache\AuthenticationStateCacheInterface` instance with safe authentication-state semantics; and
- a stable `factorId` identifying one factor + secret generation

for replay-aware `TOTP::verifyWithWindow()`.

Current Foundation's `OtpManager::verifyFactor()` calls `verifyWithWindow()` without the cache and factor ID. That must be corrected before Foundation 2.0 release.

Foundation should:

1. resolve an authentication-state CacheLayer instance lazily only when OTP replay-aware verification is selected;
2. configure it fail-closed (`failOpen: false`), with integrity protection and an authoritative backend as required by OTP;
3. provide a factor-generation-specific ID, not merely an account ID;
4. rotate/change that factor ID when the secret generation changes;
5. pass both into `verifyWithWindow()`;
6. map `replayDetected` to a denied MFA result and audit event;
7. integration-test concurrent duplicate submissions against a real shared backend.

OTP's contract directly uses CacheLayer; do **not** invent another Foundation/OTP cache interface.

### Recovery codes

Foundation's auth recovery-code workflow may use OTP's recovery-code engine, but production storage must provide durable atomic replace/consume semantics. If Foundation uses DBLayer, implement exactly the OTP store contract with a transaction; do not expose raw codes or HMAC keys beyond the OTP boundary.

---

## 17. Pathwise 3.1 — Foundation Filesystem Becomes Configuration + Application Policy

Pathwise 3.1 already owns:

- safe file reads/writes and streaming readers/writers;
- local/Flysystem/mounted storage;
- uploads/downloads/chunk flows/range preparation;
- archives and extraction hardening;
- directory synchronization;
- retention;
- deduplication/indexing;
- audit sinks;
- file watching;
- storage policy;
- local transactions/rollback journal;
- native copy/directory copy/zip/unzip acceleration;
- bounded local file-job queue.

### Reduce FilesystemManager

Current Foundation `FilesystemManager` mirrors a large Pathwise surface and also imports Webrick request/response types. Split ownership:

Foundation Filesystem keeps:

- `PathManager` for host application paths (must remain available without Pathwise);
- configured storage/mount resolution;
- small application upload/storage policy composition where useful;
- workspace/root-containment security policy imported from Console where it is stricter than generic path normalization.

Pathwise keeps all actual storage/file workflows.

### Do not eagerly mount every configured disk

When the filesystem capability is first requested, avoid initializing every configured remote/mounted backend. Resolve/mount the requested disk lazily and cache that mount for the application lifecycle.

### HTTP adaptation belongs under Http

Examples:

```text
Webrick Request upload -> Foundation Http adapter/policy -> Pathwise upload
Pathwise download/range result -> Foundation Http adapter -> Webrick Response
```

Do not make the runtime-neutral filesystem service depend on Webrick request/response classes.

### Workspace security

Retain Console's stricter application workspace policy where it rejects traversal/absolute child forms before normalization and verifies root/symlink containment. Pathwise can perform I/O, but generic path normalization must not weaken the security grammar.

### Do not replace ProcessRunner with Pathwise native execution

Pathwise native execution is specialized for local copy/tree/archive acceleration. Foundation's Process runtime still owns arbitrary subprocess execution, monitoring, termination, signal behavior, inherited/piped IO, and supervision.

---

## 18. ReqShield 3.0 — One Validation Engine for Web, CLI, and Config

ReqShield 3.0 already owns:

- 108 validation rules;
- 46 sanitizers;
- cost-based ordering/fail-fast;
- nested/wildcard paths;
- database validation with batched provider contract;
- strict/strip-unknown behavior;
- casts and typed input bag;
- DTO mapping;
- locale/message packs;
- structured failures/API error formats;
- schema fragments;
- compiled validator wrapper;
- JSON Schema/OpenAPI/introspection export;
- upload hardening rules;
- request-object helpers.

### Reduce FoundationValidator/ValidationManager

Foundation should own only:

- application schema-name registry;
- Foundation config/default overrides for a schema;
- DBLayer provider wiring;
- selected application-specific validation policy;
- optional common schema registration for HTTP/CLI/config.

Return/expose the ReqShield Validator/CompiledValidator directly for advanced behavior.

Do not duplicate:

- request payload extraction if ReqShield already supports the request object;
- fragment operations;
- schema export plumbing;
- locale/sanitizer/cast setter mirrors;
- error-format conversion.

A small `ValidationRegistry` that resolves a named Foundation schema to a configured ReqShield validator is a real application abstraction. A broad manager mirroring every ReqShield API is not.

### Shared schemas

Use the same schema registry for:

- HTTP request payloads;
- CLI command semantic input where appropriate;
- prompt validation;
- application config semantic validation;
- DB-aware uniqueness/existence checks.

This removes duplicate command-vs-request validation logic.

### Schema/OpenAPI opportunity

Add an optional operational command such as:

```text
schema:export <name> --format=json-schema|openapi|introspection
```

This is cheap because ReqShield already owns the export engine. Foundation only resolves the named application schema and output destination.

### Composer-load isolation

ReqShield currently uses Composer `autoload.files`. Make helper loading opt-in if strict installed-but-unused load isolation is required.

---

## 19. TalkingBytes 2.0 — Communication Engine, Not a Foundation Remote-Client Reimplementation

TalkingBytes 2.0 owns:

- HTTP cURL and cURL-multi pipelines;
- HTTP authentication helpers;
- retry/resilience primitives;
- outbound email (SMTP/sendmail/mail/spool);
- inbound email (IMAP/POP3/parser);
- webhook signing/verification/replay/delivery;
- gRPC outbound client;
- gRPC inbound dispatcher;
- gRPC streaming/native adapter/testing;
- shared communication result/event primitives.

### Keep it as an optional Foundation integration

Foundation should support named application communication profiles/configuration and bind actual TalkingBytes clients/services lazily.

### Do not restore Console `RemoteClient` as a compatibility wrapper

A `RemoteClient` class should exist only if Foundation itself has a distinct application semantic—for example a bounded package-artifact download policy that cannot be represented directly by a configured TalkingBytes client. Do not recreate it merely because old Console had one.

### Reduce CommunicationManager

Current `CommunicationManager` re-exports many TalkingBytes HTTP/gRPC/webhook/testing primitives. Replace it with direct client bindings or a tiny named-profile resolver if dynamic named profiles are required.

### Reduce NotificationManager

Keep application notification routing/profile/auth-notification policy. Do not mirror TalkingBytes low-level email parser/DKIM/bounce/factory APIs through Foundation.

### TalkingBytes and Omnibus are complementary

```text
TalkingBytes = protocol communication
Omnibus      = application messages/events/queues/workflows
```

Do not make one replace the other.

### High-value compositions

- Verified inbound webhook -> Foundation request policy -> Omnibus event/message.
- Inbound email polling worker -> TalkingBytes parser -> Omnibus message/event.
- Queued email notification -> Omnibus job -> TalkingBytes sender.
- Scheduler -> Omnibus scheduled message -> handler -> TalkingBytes HTTP/gRPC/email.
- gRPC inbound dispatcher -> application handler/container scope.

---

## 20. WebAuthn 5.3.5 — Keep Protocol Adaptation, Not Protocol Duplication

Foundation's passkey workflow is a legitimate application boundary because it coordinates:

- account/credential storage;
- challenge lifecycle;
- lockout/security policy;
- audit;
- notification;
- authentication result/principal state.

Keep that workflow.

Audit the existing WebAuthn adapter subtree aggressively:

- keep configuration mapping that WebAuthn genuinely requires;
- keep credential repository/provider adapters required by upstream contracts;
- keep Foundation account/credential mapping;
- remove local value/config/result types that only repeat upstream types without enforcing a Foundation invariant.

### Security note

The latest 5.3.5 line includes hardening around fake/decoy credential generation. When Foundation uses that upstream facility, configure the required non-empty secret and add an anti-enumeration integration test.

---

## 21. Foundation-Owned Features That Should Remain

The goal is **not** to delete Foundation's application layer. The following remain proper Foundation responsibilities.

### Application/runtime

- one `Application` composition root;
- runtime selection;
- provider/module registry;
- lazy optional provider activation;
- project path conventions;
- runtime lifecycle and failure boundary;
- runtime-scoped cleanup orchestration.

### Authentication/authorization

Foundation is the application auth owner. Keep:

- account/login orchestration;
- authentication sessions;
- principal resolution;
- password reset/change/passwordless flows;
- email verification;
- remember tokens;
- MFA enrollment/application workflow;
- passkey application workflow;
- roles/permissions/delegation;
- policies/gates;
- devices/impersonation/step-up;
- audit/lockout/notification coordination.

The packages supply crypto, OTP, DB/cache, WebAuthn, communication, and IDs beneath those workflows.

### Browser sessions

Keep Foundation browser-session semantics:

- cookie/application state;
- flash data;
- CSRF integration;
- route-selected activation;
- store selection;
- request lifecycle/commit/reset.

Delegate storage mechanics:

- memory/array: Foundation minimal implementation;
- file: Foundation minimal safe local store or Pathwise if module selected and it materially helps;
- cache: CacheLayer;
- database: DBLayer;
- locking: CacheLayer where configured.

Do not confuse browser sessions with auth session records.

### Logging

Keep Foundation's small structured PSR-3 logging/error-reporting capability. A minimal JSON/file/error_log implementation is legitimate application infrastructure and should not force Pathwise or another full logging library.

### JSON/resource response contract

Foundation's JsonDispatch/resources may remain because they define an application response contract beyond Webrick's generic JSON response primitive. Keep them Web-only and lazy.

Integrate ReqShield errors and Foundation auth/application errors into this contract without duplicating each subsystem's internal validation/error engine.

### Diagnostics/readiness

Readiness is Foundation-specific, but move it out of the root `Application` class into a dedicated diagnostics/readiness service activated only by `app:ready`, health/deployment checks, or an explicit API call.

The root application should not contain a large report implementation that touches/understands every optional module.

---

## 22. Hard Foundation Deletion/Reduction List

### Delete outright

Unless a concrete independent responsibility is discovered during implementation, remove:

```text
src/Data/*
Foundation Data facade
Foundation global Facade base/static application state
pass-through specialist facades: Data, DB, Cache, Comms, Files, Ids, Otp, Route, etc.
Foundation Config global helper autoload file
ConfigRuntime static base-path state
Console standalone Application
Console ApplicationBuilder
Console standalone Configuration composition/provider layer
Console standalone Container factory/compiler layer
old FoundationConsole bridge
old FoundationConsoleRuntime bridge
Foundation src/Console namespace/directory
old Console RemoteClient wrapper
legacy aliases/compatibility proxies/service IDs
```

### Reduce strongly

```text
Application                     -> runtime/composition root, not aggregate facade
CacheManager                    -> configured store/lock resolution only
CacheLayerFactory               -> config-to-CacheLayer construction only
DatabaseManager                 -> connection/application integration only
SecurityManager                 -> app security profile/auth/secret integration only
IdentifierManager               -> configured purpose/default generator only
CommunicationManager            -> named configured clients/profiles only, or remove
NotificationManager             -> app notification routing only
FilesystemManager               -> configured mounts/app policy only
ValidationManager               -> named schema registry/resolver only
FoundationValidator             -> remove duplicated ReqShield configuration/request parsing
MessagingManager                -> remove or reduce to app manifest/config composition
RouterManager                   -> Webrick host composition/presets only
ContainerCacheManager           -> Foundation artifact orchestration, InterMix owns compilation
RuntimeContextTracker           -> external/static state reset only
```

### Review for deletion after manager cleanup

```text
AbstractContainerManager
ResolvesContainerService-style manager traits
ValueNormalizer generic utility
ManagerFacade
trivial one-implementation interfaces
single-method wrappers that add no invariant
```

---

## 23. Target Namespace/Domain Structure

No final `Console` namespace.

A reasonable target shape is:

```text
src/
├── Application/
├── Auth/
├── Bootstrap/
├── Cache/                 # small integration/config layer only
├── Command/               # generic command engine from Console
├── Completion/
├── Communication/         # small TalkingBytes profile composition only
├── Config/
├── Container/
├── Database/              # small DBLayer composition + auth/session/migration adapters
├── Diagnostics/
├── Exception/
├── Filesystem/            # PathManager + small Pathwise integration
├── Http/
├── IO/
├── Input/
├── Logging/
├── Messaging/             # Omnibus composition only
├── Notifications/         # application notification policy only
├── Output/
├── Process/
├── Prompt/
├── Resource/              # or remain under Http if Web-only
├── Routing/               # thin Webrick integration; merge into Http if no independent need
├── Runtime/
├── Scheduling/
├── Security/              # app security integration only
├── Session/
├── Validation/            # named ReqShield schema integration only
├── Worker/
└── Foundation.php
```

Do not create directories simply to mirror package names. If a domain has only one tiny composition function and no meaningful public capability, keep it private/co-located with its owner.

---

## 24. Console `feature/update-26` Migration Map

Treat the branch as implementation source, not API to preserve.

### Root application classes

| Console source | Foundation 2.0 action |
|---|---|
| `Application.php` | REDESIGN into `CommandKernel`/CLI dispatch behavior; do not keep second root Application |
| `ApplicationBuilder.php` | DELETE/MERGE composition into Foundation providers/runtime bootstrap |
| `ApplicationMetadata.php` | MERGE into command/runtime metadata only if independently useful |

### Command

Move generic command semantics into `Foundation\Command`:

- command base/contract;
- definition/descriptor;
- registry/index/route;
- resolver;
- execution context/result/status;
- execution policy;
- command history contract where it represents application history rather than DB mechanics;
- capability metadata;
- manifest/preflight behavior.

Domain-specific commands live with their domain:

```text
Database/Command/*
Cache/Command/*
Routing/Command/*
Scheduling/Command/*
Worker/Command/*
Messaging/Command/*
Security/Command/*
Filesystem/Command/*
Application/Command/*
Module/Command/* (or Bootstrap/Command if Module is not a real domain)
```

### Input / IO / Output / Prompt / Component / Completion

Move the proven terminal/argv implementations into top-level Foundation domains or tightly grouped command infrastructure. Do not introduce a `Cli` namespace only to replace the old `Console` namespace.

Retain the already-fixed behavior from the latest branch:

- preflight list/help/version/completion;
- structured JSON/noninteractive output consistency;
- live task/progress/spinner parity;
- width/glyph behavior;
- prompt keyboard/raw-mode stream awareness;
- buffered IO parity.

### Process

Move into `Foundation\Process`:

- ProcessRunner;
- options/result/modes;
- subprocess environment handling;
- output bounds;
- monitor/terminator;
- signal handling;
- termination reason/exit mapping;
- process ownership/passthrough semantics;
- redaction.

This is a real cross-runtime domain used by CLI, Scheduler, Worker, and possibly app operations.

### Scheduling

Move into `Foundation\Scheduling`:

- schedule DSL;
- cron/due evaluation;
- schedule artifact/cache;
- schedule runner/work loop;
- scheduled command executor;
- runtime limits;
- state repository contract only where schedule policy needs durable state.

Delegate:

- lock mechanics -> CacheLayer;
- persisted state -> DBLayer adapter when configured;
- scheduled message creation/dispatch -> Omnibus;
- command execution -> Foundation Command/Process.

### Worker

Move into `Foundation\Worker`:

- worker definitions/providers;
- supervisor;
- child process management;
- concurrency/scale policy;
- heartbeat/liveness policy;
- workload probe boundary;
- failure/backoff/circuit policy where it is worker-process policy;
- per-job runtime scope/reset.

For Omnibus consumers, the worker provider calls Omnibus; Foundation does not reimplement queue reservation/settlement/retry.

### Cache

Delete generic state-store/pass-through abstractions. Retain only runtime policy adapters that translate command/schedule/worker overlap semantics to CacheLayer lock providers.

### Configuration / Container

Do not move as parallel subsystems. Merge required behavior into Foundation Config and InterMix composition.

### Data

Delete generic ArrayKit forwarding.

### Discovery

Keep only explicit build/optimize-time discovery where Foundation genuinely supports it. Runtime directory scanning remains prohibited. Prefer explicit route/command/provider/manifests.

### Filesystem

Move only application workspace/root security behavior to Foundation Filesystem; delegate I/O to Pathwise when installed.

### Identity

Use UID directly. Keep only execution/correlation/application-purpose identity policy if needed.

### Infrastructure/integration adapters

Review individually using:

```text
KEEP   = real provider/substitution boundary
MERGE  = application policy belongs in existing Foundation owner
DELETE = pure old-package bridge/pass-through
```

Do not retain an `Infrastructure` bucket merely to avoid deciding ownership.

---

## 25. Module Catalog 2.0

The module catalog should include package + constraint + config publication metadata.

Example:

```php
private const array MODULES = [
    'cache' => [
        'package' => 'infocyph/cachelayer',
        'constraint' => '^3.1.2',
        'config' => ['cache.php'],
    ],
    'communication' => [
        'package' => 'infocyph/talkingbytes',
        'constraint' => '^2.0',
        'config' => ['communication.php', 'notifications.php'],
    ],
    'crypto' => [
        'package' => 'infocyph/epicrypt',
        'constraint' => '^2.1',
        'config' => ['security.php'],
    ],
    'db' => [
        'package' => 'infocyph/dblayer',
        'constraint' => '^4.0',
        'config' => ['database.php'],
    ],
    'filesystem' => [
        'package' => 'infocyph/pathwise',
        'constraint' => '^3.1',
        'config' => ['filesystem.php'],
    ],
    'messaging' => [
        'package' => 'infocyph/omnibus',
        'constraint' => '^2.1.1',
        'config' => ['messaging.php'],
    ],
    'otp' => [
        'package' => 'infocyph/otp',
        'constraint' => '^6.0',
        'config' => [],
    ],
    'passkeys' => [
        'package' => 'web-auth/webauthn-lib',
        'constraint' => '^5.3.5',
        'config' => [],
    ],
    'validation' => [
        'package' => 'infocyph/reqshield',
        'constraint' => '^3.0',
        'config' => ['validation.php'],
    ],
];
```

Built-in application features can remain separate catalog entries if `module:install` is also used to publish their config:

```text
logging
session
resources/json-dispatch
```

But do not mark Omnibus messaging as package-less/built-in.

### Install command

`module:install db` should run the versioned package requirement, e.g.:

```text
composer require infocyph/dblayer:^4.0 --with-all-dependencies
```

not an unconstrained `composer require infocyph/dblayer` that changes behavior as future majors appear.

### Config publication

- publish Foundation integration config only;
- never overwrite host files by default;
- invalidate only the affected compiled Foundation config artifact;
- package installation itself must not initialize the provider.

### Removal

Removing a module package should not delete application-owned config automatically. Report orphaned config and allow explicit cleanup.

---

## 26. Cross-Package Composition Matrix

Use native boundaries whenever available:

| Foundation use case | Primary package(s) | Foundation responsibility |
|---|---|---|
| App config/env | ArrayKit | precedence/defaults/project paths |
| DI/scopes | InterMix | runtime/provider composition |
| IDs | UID | purpose/default selection |
| HTTP routing/kernel | Webrick | routes/presets/auth/session host integration |
| Response cache/throttle | Webrick + CacheLayer | selected store config |
| App cache | CacheLayer | named store config |
| Runtime locks | CacheLayer | command/schedule/worker policy/key choice |
| DB query cache | DBLayer + CacheLayer | configure shared cache only |
| DB | DBLayer | connection config + app manifests |
| Migrations | DBLayer | app migration discovery/commands |
| Validation DB rules | ReqShield + DBLayer | narrow DatabaseProvider adapter |
| Validation | ReqShield | named app schema registry/config |
| File/storage | Pathwise | app path/mount policy |
| HTTP upload | Webrick + ReqShield + Pathwise | orchestration/policy |
| Crypto | Epicrypt | app security/key/auth profile |
| TOTP MFA | OTP + CacheLayer | factor workflow + safe replay-state configuration |
| Recovery codes | OTP + DBLayer | auth workflow + store adapter |
| Passkeys | WebAuthn + DBLayer/cache as selected | account/credential workflow |
| Events/queues | Omnibus | app route/handler manifests |
| Queue worker | Foundation Worker + Omnibus | process supervision; Omnibus settlement |
| Queue uniqueness | Omnibus + CacheLayer | policy configuration |
| Scheduled message | Foundation Scheduler + Omnibus | cron/due timing; Omnibus message dispatch |
| HTTP client | TalkingBytes | named profile/secret config |
| Email | TalkingBytes | notification/application policy |
| Webhook | TalkingBytes + Webrick + Omnibus | route/auth/application dispatch |
| gRPC | TalkingBytes | runtime host composition only |
| Notification queue | Omnibus + TalkingBytes | app notification policy |
| InterMix definition cache | InterMix + CacheLayer/PSR-6 | enable/configure at optimize/build time |

---

## 27. High-Value Features Foundation Can Add Cheaply

These are not reasons to duplicate libraries. They are application-level integrations enabled by existing package capabilities.

### MUST for Foundation 2.0 release

1. Four runtime paths with strict provider isolation.
2. Console merge without old namespaces/bridges.
3. Current package version alignment.
4. Webrick 4.0.1 integration.
5. Direct `psr/log` dependency.
6. OTP MFA replay protection with safe CacheLayer authentication state.
7. InterMix scoped request/command/job lifecycle.
8. CacheLayer-native command/schedule/worker locks.
9. Remove Foundation Data pass-through domain.
10. Remove global static Foundation facade/application state.
11. Remove global static ConfigRuntime path/helper requirement.
12. Reduce specialist manager APIs to actual Foundation policy/composition.
13. Module catalog with version constraints and Omnibus as optional package.
14. Runtime-load isolation tests.
15. Full PHPForge release/benchmark gate.

### SHOULD include if implementation remains simple

#### `optimize` / `optimize:clear` / `optimize:report`

Coordinate package-native artifacts:

- Foundation config cache;
- InterMix runtime container compile maps;
- Webrick route cache;
- command manifest;
- schedule manifest;
- worker manifest;
- migration/provider/module manifests where useful.

Do not create another package-native cache format if an engine already has one. `optimize:report` should show eligibility/fallback reasons without loading unrelated providers.

#### Validation schema export

Use ReqShield to export named application schemas to JSON Schema/OpenAPI/introspection.

#### Webhook application pipeline

```text
Webrick route
 -> TalkingBytes signature/replay verification
 -> ReqShield payload validation
 -> Omnibus event/message dispatch
```

#### Inbound email worker

```text
Foundation Worker
 -> TalkingBytes IMAP/POP3 receive + parser
 -> ReqShield optional semantic validation
 -> Omnibus application message/event
```

#### Queued notification

```text
Foundation auth/application event
 -> Omnibus queued notification message
 -> worker handler
 -> TalkingBytes email/webhook/gRPC/HTTP delivery
```

#### Cache cluster invalidation worker

Where Node/Cluster Cache is selected, expose a Worker provider/operational command that runs CacheLayer's bounded invalidation consumer. CacheLayer owns cursor/replay/recovery semantics.

#### Database operational surface

Expose Foundation-aware commands that delegate to DBLayer:

```text
db:show
db:table
db:explain
db:health
migrate:*
db:telemetry/report
```

Do not duplicate DBLayer logic in command service classes.

#### Security operations

Foundation-aware commands may orchestrate Epicrypt:

```text
secret:generate
secret:rotate
security:key:rotate
security:verify-artifact   # only if Foundation deploy workflow needs it
```

### OPTIONAL future capabilities

Only add when product requirements exist:

- OAuth/JWT/refresh-token/DPoP auth profiles using Epicrypt + DBLayer.
- HOTP/OCRA MFA modes using OTP.
- storage sync/retention/dedup/audit operations using Pathwise.
- Omnibus workflow/chains/batches operational commands.
- Omnibus broadcasting provider integration.
- native gRPC server runtime if Foundation later owns an actual listener lifecycle.
- NATS/Kafka only when Omnibus ships the corresponding stable adapters; do not pre-design Foundation broker abstractions now.

---

## 28. Application Root Redesign

Current `Application` is too much of an aggregate facade. It directly exposes many specialist managers and contains a large readiness implementation.

### Target Application responsibility

Keep a small root API around:

```text
config()
container()
runtimeMode()
boot()/lifecycle
make()/has() at composition boundary
paths()/basePath() if desired
provider registration
runtime-specific kernel access where valid
```

Keep `auth()` because authentication is a genuine Foundation application domain and its lazy `AuthServices` gateway represents application workflows rather than one specialist package.

### Remove specialist aggregate accessors where they only mirror packages

Review/remove:

```text
data()
ids() giant gateway
security() giant gateway
communication() giant gateway
files() giant gateway
db() giant gateway
cache() giant gateway
messaging() thin pass-through
validator() broad pass-through
```

Where Foundation still needs a configured capability resolver, inject that resolver into actual application services rather than making the root Application an omnibus facade.

### Move readiness

Create `Diagnostics\ReadinessReport` or a similarly cohesive service and load it only from:

- `app:ready`;
- deployment health checks;
- explicit application call.

Readiness may inspect config/package presence first and resolve optional services only when its configured checks require a live probe.

---

## 29. Facade Policy

Foundation 1.3 has a broad static facade layer and stores one process-global `Application` in the facade base.

For Foundation 2.0, default decision: **remove the Foundation static facade layer**.

Reasons:

- wrapper-over-manager-over-package call chains;
- process-global mutable application pointer;
- multi-application/persistent-worker/test contamination risk;
- specialist packages already expose their intended public APIs;
- InterMix provides direct injected services.

If one or two Foundation-owned facades are later proven materially useful, they must be explicit optional convenience APIs and must not be required for runtime operation. Do not recreate the existing large facade catalog.

---

## 30. Process Architecture

Retain Console's latest process implementation as Foundation's process engine.

Requirements:

- argument-array execution by default; no shell unless explicitly requested;
- bounded captured output;
- inherited/piped/null modes;
- working directory/env override support;
- correct process ownership and passthrough behavior;
- timeout + memory/resource limits where implemented;
- process-group/child termination where platform supports it;
- signal propagation/handling;
- deterministic termination reason and exit-code mapping;
- redaction of sensitive command/environment/output metadata;
- Windows and Unix behavior tests;
- no dependency on Webrick/DBLayer/CacheLayer/etc.

Do not use Pathwise's native execution as the general process engine; Pathwise's native mode is specialized file-workflow acceleration.

---

## 31. Scheduling Architecture

Foundation Scheduler owns:

- cron/date/time due evaluation;
- timezone policy;
- schedule manifest;
- per-entry command/process/message selection;
- work loop/tick cadence;
- limits/timeouts/memory policy;
- application-level success/failure reporting.

Delegate:

- distributed/local lease mechanics -> CacheLayer;
- durable schedule state/history -> DBLayer adapter if configured;
- scheduled application message -> Omnibus `ScheduledMessageDispatcher`;
- command execution -> Command kernel;
- subprocess execution -> Process;
- network -> TalkingBytes only when selected work requests it.

### Schedule artifact

Store stable descriptors only:

```text
command route + scalar args/options
process argv + policy
Omnibus scheduled message key
timing expression/timezone
lock/state policy identifiers
```

Never persist arbitrary service objects, mutable constructed messages, or closures into the compiled schedule.

---

## 32. Worker Architecture

Foundation Worker owns process lifecycle, not queue semantics.

Keep:

- worker manifest/definition;
- selected provider resolution;
- concurrency/process count;
- process supervision;
- graceful stop/restart;
- signal behavior;
- heartbeat/liveness;
- workload probe contract;
- memory/time/restart budgets;
- backoff for crashed child processes;
- per-job InterMix scope and cleanup;
- operational metrics.

### Omnibus worker

An Omnibus consumer worker is one provider:

```text
Foundation Worker Supervisor
 -> Omnibus Consumer bounded receive/run
 -> handler in job scope
 -> Omnibus ack/retry/failure settlement
```

Foundation must not duplicate Omnibus reservation, visibility timeout, retry, failure, chain, batch, or workflow state.

### Long-lived safety

After each job:

1. close InterMix job scope;
2. clear principal/session/request context if any was intentionally used;
3. reset touched DBLayer unit-of-work/runtime state;
4. flush process-local memoizers only according to configured lifetime policy;
5. confirm no transaction/lease/resource remains unintentionally open;
6. record memory growth for soak tests.

---

## 33. CLI Architecture

### Preflight

Preflight must answer, where possible, from compiled scalar metadata only:

```text
version
help
list
completion
command existence/descriptor
runtime hand-off classification
optional package requirement errors
```

No app config provider boot, DB/cache/network, or command construction for simple list/help/version.

### Selected command

Only then:

1. enter CLI runtime;
2. load requested Foundation/app provider graph;
3. enter InterMix command scope;
4. seed input/IO/execution identity;
5. lazily resolve command capabilities;
6. execute;
7. close scope/reset external touched state.

### Command ownership

Generic command infrastructure lives in `Command`, `Input`, `IO`, `Output`, `Prompt`, etc. Domain commands live in their owning domains.

---

## 34. Web Architecture

### Normal Web request

```text
public/index.php
 -> Foundation::web()
 -> minimal Web bootstrap
 -> Webrick cached router/kernel
 -> request scope
 -> matched route middleware/services only
 -> response
 -> scope close + touched external-state reset
```

### Route caches

Use Webrick 4.0.1's route-cache formats and atomic publication. Foundation only determines project route sources/presets and operational paths.

### Optional feature isolation

A public static route should not cause auth/session/DB/cache/validation/network providers to construct unless its middleware/controller requires them.

Add tests for:

```text
plain text route
plain JSON route
validated route
session route
authenticated route
DB route
cached DB route
outbound HTTP route
```

and confirm incremental class/file/service activation.

---

## 35. Strict Load Isolation — Composer `autoload.files`

Provider laziness is insufficient if Composer itself includes helper files for every installed package.

### Current problem packages relevant to Foundation

- Foundation currently autoloads `src/Config/config_helpers.php`.
- Webrick 4.0.1 autoloads `src/functions.php`.
- CacheLayer 3.1.2 autoloads helper functions.
- ReqShield 3.0 autoloads helper functions.
- ArrayKit and UID also have namespaced function autoload files, but both are Foundation core rather than runtime-specific optional modules.

### Release target

For **strict** runtime-path isolation:

- remove Foundation's helper autoload entirely;
- update Webrick helpers to opt-in or class-based access;
- update CacheLayer/ReqShield helper autoloading to opt-in if the packages can be installed but unused on a given process;
- do not add new Foundation helper-file autoloads.

For core ArrayKit/UID, measure their helper include cost. Because they are common core dependencies, they do not violate cross-runtime ownership, but future package revisions should still prefer opt-in helper files if removing auto-load can be done intentionally in their own release policy.

### Test it

Use `get_included_files()` snapshots after each minimal boot/preflight and assert forbidden package/runtime prefixes are absent.

Examples:

```text
CLI list must not include Webrick source
Web plain route must not include Command/Prompt/Worker/Scheduling source
Worker minimal provider must not include Webrick/Prompt/Scheduling
Scheduler list/due scan must not include Webrick/Prompt/Omnibus unless an entry needs Omnibus
plain Web route must not include DBLayer/CacheLayer/ReqShield/TalkingBytes/Omnibus/OTP/Pathwise/WebAuthn
```

Allow Composer/bootstrap files that are truly unavoidable and document them. The goal is measured isolation, not a misleading claim.

---

## 36. Security Finalization

### Mandatory

- OTP production replay protection as described above.
- WebAuthn 5.3.5 and non-empty fake/decoy secret where upstream feature is used.
- no secret leakage in CLI/process/history/log/diagnostic output;
- no arbitrary serialized objects/closures in schedule/queue/config artifacts unless the owning package explicitly secures/supports that representation;
- workspace traversal/symlink containment preserved;
- Pathwise secure archive/upload policies used when those features are selected;
- ReqShield upload hardening for untrusted upload metadata/payloads;
- Epicrypt for encrypted secrets/auth cryptography rather than local crypto implementations;
- CacheLayer authentication-state caches fail closed when authentication correctness depends on them;
- DBLayer transactions/authoritative reads for auth state that cannot tolerate stale replicas;
- command authorization and destructive-operation confirmations remain Foundation application policy;
- no `dropAllTables`/destructive DB operation without Foundation environment/operator authorization.

### Secret ownership

Avoid multiple secrets for the same semantic purpose.

Examples:

- Webrick route-signing secret belongs to routing config.
- auth token/key-ring secrets belong to auth/security config.
- webhook secrets belong to TalkingBytes communication profile config.
- OTP factor secret is factor-specific and encrypted at rest through the selected auth storage/security profile.

Foundation composes them; it does not invent alternate cryptographic formats.

---

## 37. Testing Matrix

### 37.1 Core package matrix

Run with only Foundation runtime requirements installed and prove:

- CLI version/help/list/preflight works;
- Web plain route works;
- command/process/schedule/worker primitives that do not need optional packages work;
- missing optional features fail only when selected and with a precise installation message.

### 37.2 Optional module matrix

Individually install/test:

```text
CacheLayer only
DBLayer (with transitive CacheLayer but Foundation cache module not activated)
Epicrypt (with transitive Pathwise but filesystem module not activated)
Omnibus only
OTP (with transitive CacheLayer)
Pathwise only
ReqShield only
TalkingBytes only
WebAuthn only
```

Then relevant combinations:

```text
DBLayer + CacheLayer
ReqShield + DBLayer
Webrick + CacheLayer
InterMix definition cache + CacheLayer
Omnibus + DBLayer
Omnibus + CacheLayer
Omnibus + DBLayer + CacheLayer
OTP + CacheLayer auth-state cache
Auth + DBLayer + Epicrypt + OTP + TalkingBytes
Auth + WebAuthn + DBLayer/cache
Pathwise + ReqShield + Webrick upload
TalkingBytes + Omnibus worker
```

### 37.3 Runtime isolation matrix

For each runtime assert:

- included files;
- instantiated providers/services;
- container definitions activated;
- filesystem reads during boot;
- optional package classes loaded;
- memory baseline.

### 37.4 Persistent execution

Soak:

- repeated Web requests through a persistent Webrick runtime;
- repeated CLI application construction in tests;
- worker thousands of jobs;
- scheduler thousands of ticks;
- failure/exception paths;
- cancellation/signals;
- DB transaction rollback cleanup;
- CacheLayer memoizer lifecycle;
- auth principal/session leakage;
- OTP duplicate replay races.

### 37.5 Command tests

Preserve/expand Console branch coverage for:

- argv parser;
- command definitions/manifests;
- list/help/completion preflight;
- IO JSON/noninteractive parity;
- progress/task/spinner live rendering;
- process limits/termination;
- signals;
- scheduler cache/due logic;
- worker heartbeat/supervision;
- command history/status/duration;
- capability laziness.

---

## 38. PHPForge Performance Acceptance Plan

Do not accept Foundation 2.0 based on package microbenchmarks alone.

### 38.1 Required measurements

For relevant scenarios record:

- successful RPS;
- successful RPM;
- completed/failed/timeouts;
- response/command correctness failures;
- duration/concurrency;
- p50/p95/p99 latency;
- CPU;
- peak and steady memory;
- worker/process utilization;
- queue depth/growth;
- database connection/query count;
- rows examined/returned where available;
- cache hits/misses;
- external-service calls;
- included-file/class counts for boot-isolation scenarios.

Use repeated production-representative runs and compare **median sustained successful RPM**.

### 38.2 Environment evidence

Record:

```text
PHP version
SAPI
OS/kernel
CPU/hardware
Composer mode
enabled extensions + versions
OPcache state/config
JIT state
native client versions
php --ini=diff
```

Use `memory_reset_peak_usage()` between benchmark phases when supported.

### 38.3 Runtime workload classes

#### Web

- minimal cached route/plain response;
- JSON response;
- JsonDispatch response;
- validated request;
- session route;
- authenticated route;
- DB single read;
- DB multi-row/keyset page;
- cache hit;
- cache miss + fill;
- DB query cache hit/miss;
- outbound TalkingBytes HTTP;
- upload validation/storage;
- failure/error-boundary path.

#### CLI

- binary startup/version;
- help/list/completion;
- manifest lookup at 100/500/1000+ commands;
- selected no-capability command;
- selected DB command;
- selected network command;
- repeated 1/100/1000/10000 dispatches where useful;
- process execution/capture.

#### Scheduler

- empty tick;
- 100/1000 schedule entries due scan;
- one command due;
- one locked command due;
- persisted state path;
- Omnibus scheduled message path;
- repeated work loop memory stability.

#### Worker

- idle probe;
- process supervision;
- Omnibus successful job;
- retry/failure job;
- DB + cache job;
- network job;
- thousands-job soak;
- memory/restart threshold behavior.

### 38.4 Runtime-isolation acceptance

Establish Foundation 1.3 + Console baseline and Foundation 2.0 candidate. Require:

- no unacceptable regression in representative successful RPM;
- materially smaller irrelevant-runtime include/service graph;
- CLI preflight improvement or equivalence;
- no Web request cost from merged command code;
- no worker/scheduler cost from Webrick unless deliberately used;
- no optional module first-use cost paid on requests that never use it.

Use a default regression budget such as 2% only after measuring environmental noise; do not turn a generic number into a false universal threshold.

---

## 39. Implementation Phases

### Phase 0 — Freeze source baselines

- Tag/record Foundation 1.3 commit.
- Record Console `feature/update-26` commit.
- Record all dependency versions above.
- Run current Foundation + Console tests/benchmarks as baseline.
- Save `php --ini=diff`, extension versions, Composer lock, hardware info.

### Phase 1 — Composer/package ownership

- Remove `infocyph/console`.
- Add direct ArrayKit/InterMix/UID requirements.
- Update Webrick to `^4.0.1`.
- Add direct `psr/log`.
- Refresh optional dev dependencies.
- Add Omnibus dev/integration dependency.
- Update suggest descriptions.
- Establish Foundation-owned `infbyte` bootstrap/stub.
- Remove Foundation Composer config-helper autoload file.

### Phase 2 — Runtime enum and root API

- Replace Web/Console runtime enum with Web/CLI/Worker/Scheduler.
- Add `Foundation::cli()`, `worker()`, `scheduler()`.
- remove ambiguous root runtime aliases; represent env/API preset in config.
- enforce HTTP kernel availability only for Web runtime.
- implement runtime-specific provider maps.

### Phase 3 — InterMix unification

- delete Console container/config composition.
- make Foundation ContainerFactory authoritative.
- adopt the explicit singleton-first lifetime policy: reusable/stateless infrastructure and application services are singleton, execution-local mutable state is scoped, transient is exceptional.
- bind explicit reflection-free factories for hot/common services.
- establish InterMix request/command/job/scheduled-execution scopes;
- seed runtime-bound objects that already exist at scope entry (Request, parsed CLI input, IO, Omnibus envelope/job context, scheduled execution descriptor, execution/correlation IDs) directly into the InterMix scope instead of rebinding definitions or creating temporary providers;
- keep context discovered after scope entry, especially authenticated principal state, as scoped services/state populated after resolution rather than fake initial seeds.
- verify singleton infrastructure is lazily constructed only after runtime/capability activation and reused across repeated units of work.
- reduce custom runtime tracker to external-state cleanup.
- produce runtime-specific compiled container artifacts.

### Phase 4 — Config simplification

- remove static ConfigRuntime/global helpers.
- use ArrayKit Environment/EnvParser directly.
- preserve Foundation precedence and list-replacement semantics.
- simplify ConfigRepository around ArrayKit LazyFileConfig.
- retain/benchmark `__flat.php` rather than deleting it.
- benchmark single vs sharded config cache.

### Phase 5 — Merge generic CLI engine

- migrate Command/Input/IO/Output/Prompt/Completion/Component implementation.
- create tiny `infbyte` preflight.
- compile Foundation + app command manifest.
- keep Foundation operational commands but move them to domain namespaces.
- remove existing FoundationConsole/FoundationConsoleRuntime.

### Phase 6 — Process/Scheduling/Worker

- migrate Process first.
- migrate Scheduler using Foundation/CacheLayer/DBLayer/Omnibus ownership boundaries.
- migrate Worker supervisor/provider/probe.
- add CLI preflight hand-off to Worker/Scheduler runtime.
- persistent scope/reset/soak tests.

### Phase 7 — Delete pass-through Foundation domains

- remove Data domain.
- remove facade/static app layer.
- reduce Cache, DB, Security, IDs, Communication, Notification, Filesystem, Validation, Messaging, Routing managers.
- remove manager support base/traits rendered unused.
- remove generic ValueNormalizer where typed config/private parsing suffices.

### Phase 8 — Optional package native composition

- CacheLayer store/lock instances directly shared with Webrick/DBLayer/InterMix/Omnibus/OTP/session/auth as selected.
- DBLayer connection/migration/ReqShield provider adapters.
- Epicrypt auth/secret/key integration.
- Omnibus bus/events/consumer/schedule composition.
- Pathwise mount/storage composition.
- TalkingBytes profiles/notification integration.
- ReqShield schema registry.
- WebAuthn adapter cleanup.

### Phase 9 — Auth/security hardening

- fix OTP replay protection.
- test recovery-code atomic store.
- WebAuthn 5.3.5 hardening.
- verify secret redaction/history/process logs.
- validate persistent principal/session isolation.
- auth production-driver configuration guard.

### Phase 10 — Module management

- version-aware module catalog.
- Omnibus messaging package correction.
- package present vs module configured vs provider activated semantics.
- config publication and cache invalidation.
- module list/readiness reporting.

### Phase 11 — Cross-package feature integrations

Implement only the high-value selected set:

- schema export;
- webhook→Omnibus;
- inbound email worker;
- queued notifications;
- cache cluster worker;
- security/key operations;
- DB operational commands.

Keep future OAuth/OCRA/storage-sync/broadcast features optional if they would delay a stable 2.0 without immediate product need.

### Phase 12 — Autoload isolation

- Foundation helper autoload removed.
- update Webrick helper autoload behavior for strict isolation.
- update CacheLayer/ReqShield helper autoload if strict installed-unused isolation is required.
- add `get_included_files()` isolation tests.

### Phase 13 — Documentation rewrite

Rewrite docs around Foundation 2.0, not a migration compatibility story.

### Phase 14 — Release gates and Console retirement

- complete PHPForge process/tests/static/security/release guard.
- representative benchmarks + worker/scheduler soaks.
- clean install with only core dependencies.
- each optional module matrix.
- Composer archive inspection.
- docs build.
- archive Console repository with README pointing to Foundation 2.0 architecture; no shim package.
- tag Foundation 2.0.

---

## 40. Documentation Set for Foundation 2.0

At minimum rewrite/create:

```text
README.md

docs/architecture.md
    ownership model
    runtime vs capability
    no Console package

docs/runtimes.md
    web / cli / worker / scheduler
    provider maps
    scope/reset semantics

docs/configuration.md
    ArrayKit integration
    dotenv precedence
    lazy/compiled cache

docs/container.md
    InterMix factories/scopes/compiled artifacts

docs/cli.md
    commands/preflight/manifests/IO

docs/processes.md

docs/scheduling.md

docs/workers.md

docs/modules.md
    current package versions
    package/config/provider/use states

docs/http.md
    Webrick 4 integration

docs/authentication.md
    package ownership + OTP replay + passkeys

docs/sessions.md

docs/database.md
    DBLayer 4 ownership

docs/cache.md
    CacheLayer 3.1.2 ownership

docs/filesystem.md
    Pathwise 3.1 ownership

docs/validation.md
    ReqShield 3 ownership

docs/security.md
    Epicrypt 2.1 ownership

docs/messaging.md
    Omnibus 2.1.1 ownership

docs/communication.md
    TalkingBytes 2.0 ownership

docs/performance.md
    PHPForge budgets/benchmark methodology

docs/operations.md
    optimize/readiness/module/queue/scheduler/worker operations

docs/testing.md
    runtime isolation and optional package matrix
```

Every doc should distinguish **Foundation application semantics** from **specialist package APIs** and link users to the package docs for engine-level options instead of copying their full documentation into Foundation.

---

## 41. Console Repository Retirement

After Foundation 2.0 is released:

- mark `infocyph/Console` archived/read-only;
- README: functionality moved into `infocyph/foundation` 2.0;
- do not release a compatibility `2.0` Console that merely depends on Foundation;
- do not add a Composer replacement/shim unless there is an external ecosystem requirement that was not part of this plan;
- preserve historical tags/source for reference;
- Foundation becomes the only maintained CLI/runtime owner.

---

## 42. Final Release Acceptance Checklist

### Architecture

- [ ] No `infocyph/console` requirement.
- [ ] No production `Infocyph\Console` references.
- [ ] No `Infocyph\Foundation\Console` namespace/directory.
- [ ] One Foundation Application/config/container composition root.
- [ ] RuntimeMode = Web/CLI/Worker/Scheduler.
- [ ] Environment/preset is not confused with runtime.
- [ ] No compatibility aliases/bridges.

### Runtime isolation

- [ ] Web minimal path does not load CLI/Worker/Scheduler graphs.
- [ ] CLI preflight does not boot Web/DB/cache/network/etc.
- [ ] Worker minimal path does not load Web/CLI UI/Scheduler.
- [ ] Scheduler minimal path does not load Web/CLI UI/Worker supervisor.
- [ ] Optional packages load only on selected capabilities, subject to documented Composer helper-file constraints.
- [ ] InterMix uses singleton-first lifetime policy for reusable/stateless infrastructure and application services.
- [ ] Singleton services remain lazy: runtime/capability activation happens before construction.
- [ ] Web/CLI/Worker/Scheduler execution-local mutable state uses InterMix scopes.
- [ ] Runtime-bound values already available at scope entry are seeded directly rather than registered through temporary factories/rebindings.
- [ ] Context resolved later inside an execution (especially authenticated principal state) remains scoped and is populated only after resolution.
- [ ] Repeated requests/jobs reuse safe singleton infrastructure while receiving fresh scoped state.
- [ ] `Transient` is used only where fresh construction is a real semantic requirement.
- [ ] External static/runtime package state resets correctly.

### Dependency ownership

- [ ] ArrayKit 5.1.
- [ ] InterMix 9.1.
- [ ] UID 5.0.
- [ ] Webrick 4.0.1.
- [ ] CacheLayer 3.1.2 integration tests.
- [ ] DBLayer 4.0 integration tests.
- [ ] Epicrypt 2.1 integration tests.
- [ ] Omnibus 2.1.1 integration tests.
- [ ] OTP 6.0 integration tests.
- [ ] Pathwise 3.1 integration tests.
- [ ] ReqShield 3.0 integration tests.
- [ ] TalkingBytes 2.0 integration tests.
- [ ] WebAuthn 5.3.5 integration tests.
- [ ] PHPForge dev-main@dev release gates.
- [ ] direct `psr/log` declared.

### Duplication removal

- [ ] DataManager/domain deleted.
- [ ] facade/static application layer removed or explicitly justified exception documented.
- [ ] giant Cache/DB/Security/ID/Communication/Filesystem/Validation pass-through surfaces removed.
- [ ] Console config/container/application bridges removed.
- [ ] native package-to-package integrations used directly.
- [ ] generic utility/base-manager classes left behind by deleted wrappers removed.

### Security

- [ ] TOTP replay cache + factor-generation ID enforced for production OTP MFA.
- [ ] duplicate concurrent OTP replay test passes.
- [ ] recovery-code store atomicity verified.
- [ ] WebAuthn 5.3.5 config/hardening verified.
- [ ] workspace traversal/symlink tests pass.
- [ ] secret/log/process/history redaction passes.
- [ ] destructive DB/file commands require explicit authorization/confirmation policy.
- [ ] persistent request/job state does not leak.

### Build/optimization

- [ ] ArrayKit config lazy/flat cache benchmarked and chosen intentionally.
- [ ] InterMix runtime container compile maps validated/fail closed.
- [ ] Webrick route cache uses its native atomic validated build.
- [ ] command manifest preflight works without app boot.
- [ ] schedule/worker manifests contain scalar/stable descriptors only.
- [ ] optimize report exposes compile/fallback status.

### Performance

- [ ] Foundation 1.3 + Console baseline recorded.
- [ ] Foundation 2.0 cold/warm Web benchmark complete.
- [ ] CLI preflight/dispatch benchmark complete.
- [ ] Scheduler tick/work benchmark complete.
- [ ] Worker soak complete.
- [ ] DB/cache/auth/validation/network representative scenarios complete.
- [ ] sustained successful RPM/RPS and p50/p95/p99 recorded.
- [ ] CPU/memory/query/cache/external call metrics recorded.
- [ ] `memory_reset_peak_usage()` used between benchmark phases where supported.
- [ ] `php --ini=diff` recorded.
- [ ] no progressive queue/memory/connection degradation.

### Release

- [ ] Core-only Composer install passes.
- [ ] Optional-module absence matrix passes.
- [ ] Optional-module integration matrix passes.
- [ ] PHPForge doctor/process/tests/static/security/release guard pass.
- [ ] Composer archive contains no stale Console namespace/bridge.
- [ ] docs build cleanly and list current versions.
- [ ] Console repository retirement README prepared.
- [ ] Foundation 2.0 architecture frozen before tag.

---

## 43. Final Architecture Freeze

The Foundation 2.0 design should be considered complete when this ownership model is true:

```text
FOUNDATION
    application composition
    runtime selection/isolation
    auth/session application workflows
    CLI command runtime
    process runtime
    scheduler runtime
    worker runtime
    project/module/config conventions
    diagnostics/operations

ARRAYKIT
    data/config/env mechanics

INTERMIX
    container/scopes/compiled resolution

UID
    identifiers/sequences

WEBRICK
    HTTP/router/middleware/request/response/emission

CACHELAYER
    cache/tags/locks/counters/node/cluster/memoization

DBLAYER
    database/query/repository/transactions/schema/migrations/telemetry

EPICRYPT
    cryptography/data protection/password/token/key security

OMNIBUS
    events/messages/queues/retries/workflows/broadcasting

OTP
    OTP/HOTP/TOTP/OCRA/recovery/replay contracts

PATHWISE
    filesystem/storage/upload/download/archive/sync/retention/audit

REQSHIELD
    validation/sanitization/typed input/schema export

TALKINGBYTES
    HTTP/email/webhook/gRPC protocol communication

WEBAUTHN
    passkey protocol mechanics
```

If understanding a normal operation requires passing through a Foundation manager whose only action is to call the corresponding specialist package, remove that manager/call hop.

If a Foundation class enforces **application policy, host configuration, runtime lifecycle, security orchestration, or a genuine cross-package boundary**, keep it.

That is the final line for Foundation 2.0: **one application foundation, four isolated runtimes, lazy capabilities, specialist engines used directly, and no historical Console architecture carried forward.**

---

## 44. Review Coverage / Sources Inspected

This final draft was built from more than the two Composer files. The review explicitly used current package metadata, tagged source, README, deeper documentation, and Foundation/Console integration code.

### Foundation / Console

- `infocyph/Foundation` current `main` / 1.3 source tree.
- Foundation `composer.json`, README, architecture/auth/config/database/http/messaging/operations docs.
- Foundation `Application`, `Bootstrapper`, runtime tracking, config loader/repository, container factory/cache, module catalog/manager, cache/database/security/filesystem/validation/communication/notification/identifier/messaging/routing/HTTP/logging/auth/passkey/OTP integration code.
- `infocyph/Console` **`feature/update-26`** source and Composer metadata.
- Console command, IO/input/output/component/completion, configuration/container, filesystem/workspace, process, scheduling, worker, cache/infrastructure, identity, discovery, and application/builder surfaces as migration input.

### Infocyph packages

For each package below, the latest verified tag/Composer metadata and available README/docs/source contracts were used:

- ArrayKit 5.1 — config/lazy config/environment, arrays/dot/shape, collection/DTO APIs and docs.
- InterMix 9.1 — DI/container, factories, compiled resolvers, scopes, definition cache, serializer docs.
- UID 5.0 — identifier algorithms, sequence providers, converters/value APIs and docs.
- Webrick 4.0.1 — router/kernel, route-cache, middleware, signed URLs, response/emitter/runtime docs.
- CacheLayer 3.1.2 — stores, native bulk paths, tagging, remember/stampede behavior, node/cluster cache, counters, memoization, metrics, locking docs.
- DBLayer 4.0.0 — connection/query/repository, cache, telemetry, schema/migration/seeding/relation/streaming docs.
- Epicrypt 2.1 — data protection, password/token/key-ring, certificate/integrity/security/OAuth-related public modules/docs.
- Omnibus 2.1.1 — messaging/events/transport/consumer/retry/failure/workflow/scheduling/broadcasting/policy docs.
- OTP 6.0 — TOTP/HOTP/OCRA/recovery/provisioning/replay source and state-contract docs, including direct CacheLayer authentication-state requirements.
- Pathwise 3.1 — storage/filesystem/upload/download/archive/sync/retention/audit/native-execution docs.
- ReqShield 3.0.0 — validation/sanitization/database batching/typed input/DTO/compiled/schema-export/upload-hardening docs.
- TalkingBytes 2.0.0 — HTTP/email/webhook/gRPC inbound/outbound/streaming/retry/testing docs.
- PHPForge current `main` engineering principles.

### External optional package

- WebAuthn Framework / `web-auth/webauthn-lib` latest stable release 5.3.5 and current Foundation adapter/workflow usage.

### Verification policy during implementation

Before the 2.0 tag, rerun the version check against package tags/releases/Packagist and regenerate the module-version table. If any dependency has released a newer compatible stable version after 2026-08-17, evaluate that release against this ownership plan and update the Foundation baseline before locking `composer.lock`/release evidence.
