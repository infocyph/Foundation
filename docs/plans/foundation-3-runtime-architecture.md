# Foundation 3 — Runtime Architecture, Boot, DI & Performance Plan

**Status:** Active implementation plan / library pass 1 complete  
**Base:** `main`  
**Target:** Foundation 3  
**Current deep-review baseline:** InterMix `^10.0.3`  
**Next library passes:** Webrick, ArrayKit, UID, then optional specialist integrations  
**Priority order:** correctness → performance → scalability → ergonomics

## 1. Purpose

Foundation 3 is a deep internal runtime revision, not a feature-expansion release. The goal is to make Foundation a thin, deterministic application composition/integration layer that fully consumes the optimized lower-level Infocyph runtimes instead of rebuilding another runtime above them.

The first dedicated library pass is InterMix 10.0.3. This document now treats InterMix's exact development/production DI model as a hard architectural contract for Foundation 3.

Public application-facing behavior should remain stable where it is useful and inexpensive to preserve, but internal compatibility with the Foundation 2 boot/container architecture is not a design constraint for this major release.

## 2. Review method and scope

The Foundation repository was reviewed from the current `main` tree with a direct-usage focus on:

- InterMix imports and concrete container types;
- `LifetimeEnum`, `FactoryDefinition`, and `ServiceReference` usage;
- live-container registration and mutation;
- container compilation/activation logic;
- application/service-provider lifecycle;
- execution scopes and scope seeds;
- routing/container coupling;
- auth registrar/container coupling;
- command optimization/build logic;
- provider families across auth, cache, database, messaging, communication, filesystem, logging, notifications, security, session, validation, HTTP, routing, and JSON dispatch;
- tests and benchmarks that encode the current container model.

The connected GitHub repository does not currently expose a complete code-search index for Foundation, so this pass uses repository-tree enumeration plus direct source inspection rather than depending on indexed search results. During implementation, add a local/CI sanity scan for `Infocyph\\InterMix`, `Container`, `LifetimeEnum`, `FactoryDefinition`, `ServiceReference`, `compileTo`, `useCompiled`, `usePrevalidated`, `onMissing`, `enterScope`, and `leaveScope` so no direct usage is missed as files move.

## 3. Foundation 2 InterMix usage found in the codebase

### 3.1 Core application/runtime

#### `src/Application/Application.php`

Current coupling:

- concrete `Infocyph\InterMix\DI\Container` constructor/property/return type;
- creates the application only after a dynamic container already exists;
- binds `Application`, `RuntimeMode`, `ConfigRepository`, the concrete `Container`, `RuntimeContextTracker`, and `ExecutionScope` as live singleton instances;
- installs broad `Container::onMissing()` behavior to activate Foundation-managed services/providers;
- `Application::make()` performs provider activation before `Container::get()`;
- `Application::has()` consults Foundation provider discovery before container lookup;
- optionally activates a compiled resolver map after the dynamic application has already been constructed.

Foundation 3 action:

- remove the dynamic `Container` as the architectural owner of `Application`;
- compose definitions first through `ContainerBuilder`;
- select development or production runtime explicitly after composition;
- make `Application` a thin runtime façade/coordinator rather than a live graph mutator;
- remove broad provider activation from normal `make()`/`has()` resolution;
- avoid binding a live `Application` object where doing so forces otherwise compilable services into dynamic fallback.

#### `src/Container/ContainerFactory.php`

Current coupling:

- constructs `new Container(...)` directly;
- generates a random UUID7 default container alias;
- configures environment through dynamic-container options;
- exposes lazy-loading/debug-tracing configuration as runtime container configuration.

Foundation 3 action:

- retire this factory in favor of the InterMix `ContainerBuilder` composition root;
- use a deterministic builder/container alias per application/runtime instead of generating a UUID on every boot;
- use `ContainerBuilder::setEnvironment()` for environment-specific graph selection;
- keep debug tracing development-only;
- review whether the old `app.container.lazy_loading` setting still has a meaningful public role once production uses generated resolvers; remove obsolete configuration rather than preserving a switch that no longer controls the production runtime.

#### `src/Container/ContainerCacheManager.php`

Current coupling:

- reconstructs an entire dynamic `Application` for each runtime;
- calls `Container::compileTo()`;
- reads the dynamic-container resolver-map compilation report;
- later calls `usePrevalidated()` on the same dynamic container;
- models compiled activation as an optional runtime mode;
- stores/validates a 64-hex `fingerprint` in Foundation's optimize manifest.

Foundation 3 action:

- do not port this class as-is;
- retire the old compile/activate model;
- build production artifacts through `ContainerBuilder::validate()` + `ContainerBuilder::compile()`;
- boot directly into `ProductionContainer` through `production()` or trusted `productionPrevalidated()`;
- use the InterMix 10.0.3 xxh128 `digest` contract rather than the Foundation 2 64-hex fingerprint assumption;
- if a Foundation artifact coordinator remains, it coordinates files/reports only and never activates a compiled resolver on a live dynamic container.

This is a hard incompatibility, not a cosmetic API rename.

#### `src/Runtime/ExecutionScope.php`

Current coupling:

- concrete dynamic `Container` dependency;
- manually calls `enterScope()` and `leaveScope()`;
- creates scope names from runtime + execution UUID;
- manually resets external runtime context before leaving the scope.

Foundation 3 action:

- make execution scope compatible with both InterMix development and production containers;
- use `withinScope()` for exception-safe scope restoration;
- seed runtime objects through the scope seed map rather than rebinding definitions;
- use named `onScopeLeave($scope, ...)` hooks for cleanup that genuinely belongs to the InterMix scope;
- preserve the rule that the primary application exception wins over cleanup failures;
- keep explicit Foundation-owned execution boundaries for CLI/jobs/scheduler/workers;
- remove the assumption that every HTTP request needs this outer Foundation scope when the later Webrick pass can let the compiled HTTP execution plan decide whether scope state is needed.

### 3.2 Service-provider infrastructure

#### `src/Application/ServiceProvider.php`

Current coupling:

- accepts the live dynamic `Container` in helper methods;
- uses `LifetimeEnum` directly;
- has `bindRecipe()` using `FactoryDefinition::construct()` + `ServiceReference`, which is the correct compilation-friendly direction;
- has `bindFactory()` for closures and describes them as reflection-free, but reflection-free closure factories are still dynamic production islands in InterMix 10.

Foundation 3 action:

- change provider graph contribution to target `ContainerBuilder`, not a live `Container`;
- retain a very small helper layer over exact InterMix primitives only;
- expand declarative recipes/aliases and sharply reduce closure factories;
- explicitly distinguish “reflection-free dynamic factory” from “compilation-safe generated definition.”

#### `src/Application/ServiceProviderInterface.php`

Current contract:

```php
register(Application $app): void
boot(Application $app): void
```

Foundation 3 action:

Split graph composition from process boot. Preferred shape is conceptually:

```php
register(ContainerBuilder $builder, FoundationBuildContext $context): void
```

with an optional separate boot contract for true post-container process side effects.

Do **not** simply adopt InterMix's current `ServiceProviderInterface` as the Foundation production contract: that interface receives the dynamic `Container`, while Foundation 3 needs composition to finish before development/production runtime selection.

`FoundationBuildContext` should stay a small immutable value object containing only build-time data needed for graph composition, such as normalized config, environment, runtime mode, paths/capabilities, and feature selections. It must not become another service locator.

#### `src/Application/ServiceRegistry.php`

Current behavior dynamically stores deferred providers and may register/boot them after the application has already started.

Foundation 3 action:

- development may retain controlled deferred-provider convenience;
- production provider/capability topology is determined before compilation;
- process boot may perform side effects but cannot mutate a finalized production graph;
- late provider activation in normal production resolution is prohibited.

#### `src/Bootstrap/Bootstrapper.php`

Current behavior:

- owns a large service→provider map;
- uses `class_exists()` package/capability discovery;
- dynamically activates deferred providers through the Application `onMissing()` path;
- performs configured/provider-file discovery during application preparation.

Foundation 3 action:

- convert provider/capability selection into composition/build-time topology;
- compile or otherwise finalize the enabled provider set before production runtime loading;
- retain broad discovery only for development/tooling where it is useful;
- any production `onMissing()` fallback must be narrow, intentional, documented, tested, and visible in the InterMix skipped-definition report.

### 3.3 Routing/HTTP direct InterMix coupling

#### `src/Routing/WebrickRouterFactory.php`

Current coupling:

- constructor requires the concrete dynamic InterMix `Container`;
- passes that container into Webrick;
- current design explicitly disables Webrick request scope because Foundation `HttpKernel` owns an outer InterMix scope;
- router construction happens against a live runtime container.

InterMix-pass action:

- remove the concrete dynamic-container assumption;
- controller/middleware/service definitions must be contributed before production compilation rather than bound while creating the runtime router;
- do not use route creation as a late graph-mutation stage;
- keep the exact Webrick 5.1 integration redesign for the dedicated Webrick pass, but the InterMix boundary must already be builder-first and production-container compatible.

#### `src/Routing/RoutingServiceProvider.php`

Current coupling:

- imports `LifetimeEnum`/`ServiceReference`;
- mixes compilation-friendly recipes with many closure factories;
- passes the live container into `WebrickRouterFactory`.

Action:

- move all deterministic bindings to builder definitions/recipes;
- remove live-container capture;
- make route/controller topology available during build composition.

#### `src/Http/HttpServiceProvider.php`

Current coupling:

- imports `LifetimeEnum`/`ServiceReference`;
- already has useful recipe-based bindings;
- closure factories capture the Application for `MaintenanceManager`, `ErrorHandler`, `RouterKernel`, and aliases.

Action:

- preserve/expand recipe-based construction;
- remove unnecessary Application captures;
- leave exact Webrick error/router runtime wiring to the next library pass.

#### `src/Http/JsonDispatch/JsonDispatchServiceProvider.php`

Current coupling is a simple closure-built singleton plus closure alias.

Action:

- convert normalized config values to exportable recipe/static-factory arguments;
- replace the alias closure with a real alias/reference.

### 3.4 Auth direct InterMix coupling

#### `src/Auth/AuthServiceProvider.php`

Current behavior:

- imports `LifetimeEnum`;
- obtains a live container and passes it through a large registrar chain;
- creates numerous closure factories for resolvers and middleware;
- performs runtime-mode/config branching while registering live services.

#### `src/Auth/Internal/AbstractAuthRegistrar.php`

This class makes the auth subsystem a major direct InterMix integration surface:

- stores a concrete dynamic `Container`;
- checks definitions directly;
- implements aliases as singleton closures;
- registers closures through `container->factory()`;
- binds concrete values through `container->bind()`.

All auth registrars derived from this model must be audited and migrated as one graph-composition unit, including core, stores, cache, password, tokens, MFA, passkeys, notifications, managers, authorization, runtime, and OAuth.

#### `src/Auth/AuthOtpServiceProvider.php`

Current behavior passes `Application` + live container into `AuthMfaRegistrar` and mutates the graph after OTP validation.

Foundation 3 action for auth:

- auth feature/driver decisions happen in build context before compilation;
- registrar classes target builder/build context rather than runtime container;
- use recipes/aliases for deterministic auth services;
- keep only truly runtime-dependent secrets/callables as narrow dynamic inputs;
- remove closure aliasing;
- audit every auth singleton for persistent-runtime safety;
- exact OTP/Epicrypt/WebAuthn package behavior remains for their later dedicated library passes.

### 3.5 Other Foundation provider families using InterMix lifetimes/factories

The following current providers directly use InterMix `LifetimeEnum` and live-container registration and therefore require compilation-eligibility migration:

- `CacheServiceProvider`;
- `DatabaseServiceProvider`;
- `MessagingServiceProvider`;
- `CommunicationServiceProvider`;
- `FilesystemServiceProvider`;
- `PathServiceProvider`;
- `LoggingServiceProvider`;
- `NotificationServiceProvider`;
- `SecurityServiceProvider`;
- `SessionServiceProvider`;
- `ValidationServiceProvider`;
- `RoutingServiceProvider`;
- `HttpServiceProvider`;
- `JsonDispatchServiceProvider`;
- the auth provider/registrar family.

Key patterns found across them:

- closure construction around `$app->make(...)`;
- closure aliases that simply return another service;
- service-manager method closures (`store()`, `disk()`, `connection()`, etc.);
- runtime `class_exists()` feature branching;
- direct `container->bind()` of already-created object instances;
- live Application capture in singleton factories;
- scoped services correctly identified in some places but still constructed through dynamic closures;
- optional capability closures that unintentionally keep unrelated dependencies in the runtime graph.

Every provider must receive a compileability classification during migration; changing only `src/Container` is not sufficient to utilize InterMix 10.

### 3.6 Messaging scope bridge

`src/Messaging/InterMixExecutionScope.php` delegates Omnibus consumer execution into Foundation `ExecutionScope` and seeds envelope/message values.

InterMix action:

- keep the useful seed model;
- migrate the underlying scope execution to `withinScope()`;
- preserve message IDs as stable scope/execution identities where appropriate;
- ensure long-running worker scopes are left deterministically;
- audit concurrency with Fibers/Swoole/OpenSwoole execution contexts.

The Omnibus-specific contract is deferred to the Omnibus pass.

### 3.7 Build commands/tests

#### `src/Command/System/ApplicationSystemCommand.php`

Current optimize flow compiles all runtime containers through `ContainerCacheManager` and reports “activation” state.

Foundation 3 action:

- build a fresh deterministic `ContainerBuilder` per runtime;
- call `validate(strict: true)` before compilation;
- call `compile($path)` and collect exact `{compiled, skipped, digest}` reports;
- publish artifacts and metadata transactionally;
- replace “activation” reporting with generated-production artifact status, digest, compiled count, and dynamic-island details.

#### `tests/Feature/ContainerCacheIntegrationTest.php`

This test encodes the Foundation 2 resolver-map compile/activate model and must be replaced rather than adapted.

New InterMix parity/artifact tests are defined later in this document.

## 4. Exact InterMix 10.0.3 contract Foundation must use

Foundation must design against the actual 10.0.3 API, not the InterMix 9 resolver-map model.

### 4.1 Builder/runtime split

Use one application-owned graph composition function, then explicitly choose:

```text
ContainerBuilder::development()
    -> dynamic Container

ContainerBuilder::compile($path)
    -> generated production artifact + .meta.json

ContainerBuilder::production($path)
    -> ProductionContainer with normal verification

ContainerBuilder::productionPrevalidated($path, $trustedDigest)
    -> ProductionContainer with trusted immutable digest
```

`setEnvironment('production')` selects environment-specific graph metadata; it does **not** select the production runtime.

### 4.2 Validation API

Use the real signature:

```php
$builder->validate(strict: true);
```

`resolveFactories: true` must not be a blanket release requirement because resolving arbitrary dynamic factories may open connections or perform side effects. If used at all, expose it only as an explicit deeper validation mode for factories known to be side-effect-free.

### 4.3 Compilation report

The exact report shape is:

```text
compiled: list<string>
skipped: array<string,string>
digest: string
```

The `digest` is the InterMix xxh128 artifact identity.

Foundation must consume this report directly. Do not wrap it into a legacy 64-character fingerprint model.

### 4.4 Production loading and trust

Safe default:

```php
$container = $builder->production($path);
```

Use `productionPrevalidated()` only when the expected digest comes from trusted immutable deployment metadata. Reading the digest from the same runtime-writable cache/artifact directory is not a trust boundary.

### 4.5 Mutation/deoptimization semantics

InterMix 10 deliberately protects correctness if the builder is mutated after finalization, but that deoptimization behavior is a safety fallback, not a deployment architecture.

Foundation production rules:

- no normal graph mutation after compile/load;
- any late builder mutation is treated as a Foundation bug or explicit compatibility path;
- mutation requires a fresh compile before stale artifact loading is allowed again;
- active production deoptimization must never be silently used to implement provider/module activation;
- runtime workers are replaced with a newly built immutable release when graph/config topology changes.

### 4.6 Separate builder instances for separate active runtimes

InterMix 10 deoptimizes a previously loaded production runtime when another production runtime is loaded from the same builder.

Therefore Foundation must create a separate `ContainerBuilder` instance for each independently active runtime artifact:

```text
web builder       -> web artifact/runtime
cli builder       -> cli artifact/runtime
worker builder    -> worker artifact/runtime
scheduler builder -> scheduler artifact/runtime
```

All builders call the **same graph-composition function** with different runtime/capability inputs; they are not separate sources of truth.

### 4.7 Scopes

InterMix 10 scopes are explicit named labels and support execution-context isolation for Fibers and Swoole/OpenSwoole coroutines.

Foundation rules:

- prefer `withinScope()` to manual `enterScope()`/`leaveScope()`;
- use scope seeds for Request/job/envelope/execution context values;
- never rebind definitions for per-request/per-job context;
- use short-lived request/job/command/scheduler scopes;
- register cleanup with `onScopeLeave($scope, ...)` only when cleanup genuinely belongs to that named scope;
- shared singleton services must themselves be concurrency-safe.

### 4.8 FactoryDefinition exact capabilities

InterMix 10.0.3 compilation-safe recipes provide:

```text
FactoryDefinition::construct(...)
FactoryDefinition::staticFactory(...)
ServiceReference(...)
```

Recipe arguments must be service references or exportable scalar/null/array values.

Do not design Foundation around nonexistent `FactoryDefinition::service()`, `function()`, or `invokable()` helpers.

### 4.9 DirectFactory/closure factories

A closure/direct factory may avoid reflection, but it still remains a dynamic production island. Foundation should use it only when the service is genuinely runtime-dynamic and cannot be represented declaratively.

## 5. Foundation 3 InterMix target architecture

### 5.1 One composition function

Foundation needs one deterministic graph-contribution path, conceptually:

```text
normalized config/build context
        |
        v
ContainerBuilder::create(stableAlias)
        |
        +-- Foundation core definitions
        +-- runtime-specific definitions
        +-- enabled capability/provider definitions
        +-- package contributions
        +-- later: Webrick contribution
        |
        v
development() OR validate()+compile() OR production()
```

Working implementation names may differ, but avoid creating a second DI builder abstraction. A small `FoundationGraph`/`FoundationComposition` coordinator is acceptable only if it contributes directly to an existing `ContainerBuilder`.

### 5.2 Stable aliases

Replace `foundation.<uuid7>` default aliases with deterministic aliases such as:

```text
foundation.web
foundation.cli
foundation.worker
foundation.scheduler
```

or a deterministic application-defined prefix + runtime suffix.

Tests needing isolated concurrent builders may use distinct explicit aliases. Production boot must not generate a UUID merely to name the container.

### 5.3 Application object boundary

Reduce `Application` as a dependency inside service construction.

Current closures frequently capture `$app`, which makes otherwise simple services dynamic and turns Application into a service-locator bridge.

Target:

- constructor dependencies use narrow services (`ConfigRepository`, `PathManager`, loggers, managers, clocks, etc.);
- build-time constants/config values are exported directly into recipes where appropriate;
- runtime resolution remains available through `Application::make()` for application-facing convenience, but core generated services should not require the Application façade merely to resolve their own dependencies;
- public container exposure should be read-only/runtime-neutral where possible, e.g. PSR container semantics, not a guarantee of mutable dynamic `Container` APIs;
- internal scope execution may use `Container|ProductionContainer` or an equally small runtime-neutral boundary rather than inventing another DI container framework.

Goal: a service that can be generated should not become dynamic solely because its factory captured `Application`.

### 5.4 Compile-friendly ConfigRepository

A compiled `ConfigRepository` can be constructed from an exportable normalized array + compiled flag. Foundation should exploit that when the later ArrayKit/config pass finalizes the production configuration model.

For the InterMix pass, the rule is already fixed: do not bind an already-created mutable config object if doing so unnecessarily prevents generated construction.

## 6. Provider migration matrix

Every registration in every Foundation provider must be classified into one of these forms.

| Current pattern | Foundation 3 InterMix form |
| --- | --- |
| `new Service()` / class binding | `singleton`, `scoped`, or `transient` on `ContainerBuilder` |
| Constructor with service dependencies | `FactoryDefinition::construct()` + `ServiceReference` |
| Public static factory | `FactoryDefinition::staticFactory()` + exportable args / `ServiceReference` |
| ID/interface points to exact same service | `alias()` |
| Scalar/array immutable build value | `value()` or exportable recipe argument |
| Runtime Request/job/envelope object | scope seed |
| Config branch enabling/disabling whole capability | build-context graph decision |
| Dynamic user callable/runtime closure | narrow `bindFactory()` dynamic island |
| Manager instance method creates another service | prefer a small explicit static factory recipe when worthwhile; otherwise document the dynamic island |
| Optional package unavailable | omit capability graph / record unavailable capability, not dozens of throwing placeholder factories |

### Mandatory conversion rules

- aliases must not be implemented as closures;
- deterministic constructor graphs must not be implemented as closures;
- provider factories must not call `$app->make()` merely to perform constructor injection;
- config values needed only at construction should be normalized before recipe generation where practical;
- runtime `class_exists()` checks move to capability composition, not individual hot-path service resolution;
- already-created object instances are only bound when runtime identity truly requires it and their dynamic status is intentional.

## 7. Lifetime policy

Foundation 3 must audit every current `LifetimeEnum` choice rather than mechanically preserving it.

### Singleton

Use only for services that are process-wide, immutable or concurrency-safe, and do not retain request/job state.

Audit carefully:

- controllers currently defaulted/bound as singleton;
- database connection/default connection services;
- session/auth managers;
- consumers/transports;
- notification/channel registries;
- communication clients;
- middleware that references mutable current-principal/session state.

### Scoped

Use for request/job/execution state that must be reused within one scope but isolated across scopes.

Existing scoped intentions such as `BrowserSession`, HTTP communication clients, mail sender/receiver services, and gRPC dispatchers must be revalidated against the actual package semantics.

### Transient

Use for cheap per-resolution or stateful one-use services where sharing is undesirable.

### Gate

Each migrated provider must have a lifetime review record. “It was singleton in Foundation 2” is not sufficient justification.

## 8. Dynamic-island policy

Generated production DI is the primary reason for the InterMix 10 migration. Therefore skipped definitions are release-significant data.

### Build gate

For every runtime artifact:

1. `validate(strict: true)`;
2. `compile($path)`;
3. read `compiled`, `skipped`, `digest`;
4. compare `skipped` against a checked-in/explicit allowlist;
5. fail CI/release on every new unexpected skipped service;
6. print the reason for each intentional island in `optimize:report`/equivalent diagnostics.

### Policy

- Foundation core target: zero **avoidable** dynamic islands;
- user-defined callables and package APIs that are inherently runtime-dynamic may remain islands;
- every Foundation-owned island needs a reason and owner/subsystem;
- closure count should fall significantly as provider migration progresses;
- a skipped definition must never be hidden by generic “compiled successfully” output.

## 9. Hooks and lifecycle policy

### `onMissing()`

Current Foundation broadly uses it as a service-provider activation system. That is incompatible with a finalized generated graph as the normal production model.

Target:

- development-only convenience or very narrow compatibility fallback;
- no broad production capability/provider discovery;
- any production fallback is visible as a deliberate dynamic island.

### `onResolving()` / `onResolved()`

Use only when the lifecycle behavior cannot be expressed directly in construction/boot logic. Hooks that force generated definitions into dynamic behavior require explicit review.

### `onScopeLeave($scope, ...)`

Preferred for scope-owned cleanup such as request/job context trackers where the cleanup maps exactly to that named InterMix scope.

Do not register global cleanup indiscriminately for scopes that never create the related resource.

## 10. Runtime-specific graph/artifact strategy

Use the same composition function with runtime inputs, but separate builders/artifacts.

### Web

Initially contribute only web-relevant Foundation services/capabilities. Exact Webrick contribution and HTTP scope ownership will be finalized in the Webrick 5.1 pass.

### CLI

Use command capability metadata to avoid loading unrelated optional graphs. A tiny command should not need the full web/auth/database graph.

### Worker

Build the messaging/worker graph needed for that worker configuration. Reuse one finalized production runtime across jobs and create per-job InterMix scopes.

### Scheduler

Build scheduler/command/message-dispatch capabilities only. Use one scope per scheduled execution when scoped services are needed.

## 11. Optimize/build redesign for InterMix

The old container activation model disappears.

Per runtime, build logic becomes conceptually:

```text
normalize build context
    -> create fresh ContainerBuilder
    -> compose Foundation graph
    -> validate(strict: true)
    -> compile(runtimeArtifactPath)
    -> inspect compiled/skipped/digest
    -> enforce dynamic-island allowlist
    -> publish artifact + .meta.json + trusted release metadata
```

Production becomes:

```text
recreate same builder graph
    -> production(artifact)
       OR productionPrevalidated(artifact, trustedDigest)
    -> resolve runtime/application entrypoint
```

No production request or worker item may trigger compilation.

No normal production bootstrap may:

```text
new dynamic Container
-> build Application
-> discover providers
-> compile/activate resolver map
```

### Foundation optimize report

Replace the old activation-oriented report with:

- runtime;
- artifact path;
- environment;
- InterMix digest;
- compiled definition count/list where useful;
- skipped definition count;
- skipped ID + reason;
- allowlisted vs unexpected islands;
- artifact/meta presence;
- whether trusted-prevalidated loading is configured;
- graph/config release identity.

## 12. Scope and persistent-runtime test requirements

Add tests for both development and generated production containers.

Required cases:

- scoped service identity is stable within one scope;
- same scoped service differs across sequential scopes;
- nested `withinScope()` restores previous scope;
- scope seeds override only inside that scope and disappear afterward;
- cleanup executes on success and failure;
- primary application failure is preserved when cleanup also fails;
- Fiber interleaving does not leak scope/seeds/services;
- Swoole/OpenSwoole coroutine isolation tests when the runtime is available in CI/integration environments;
- singleton state is intentionally shared and concurrency-safe;
- long-running worker jobs do not retain scoped instances;
- Omnibus envelope/message seeds are isolated per message.

## 13. Development/production parity tests

For representative Foundation graphs:

1. compose development builder and call `development()`;
2. compose a fresh equivalent builder;
3. `validate(strict: true)`;
4. `compile()`;
5. boot `production()`/test-only trusted `productionPrevalidated()`;
6. compare observable behavior, not object identity across containers.

Parity coverage is required for graphs using:

- singleton/scoped/transient services;
- aliases;
- tags if used;
- contextual/environment bindings;
- lifecycle hooks;
- scope seeds;
- user-defined dynamic islands;
- optional capability graphs.

## 14. Artifact/mutation tests

Replace old `ContainerCacheIntegrationTest` coverage with tests that prove:

- compile emits artifact and `.meta.json`;
- report contains `compiled`, `skipped`, `digest`;
- normal `production()` verifies the artifact;
- trusted `productionPrevalidated()` accepts the exact build digest;
- incorrect trusted digest fails;
- environment/ABI/metadata mismatch fails;
- builder mutation after finalization invalidates the previous compiled state;
- stale artifact reload after mutation is refused until recompile;
- active deoptimization behavior is covered only as an exceptional correctness test, not used by normal Foundation boot;
- two active runtime containers use separate builders;
- unexpected skipped definitions fail the Foundation release gate.

## 15. Provider migration order for InterMix

Migrate in dependency order so compilation reports become progressively useful.

### Batch IM-1 — Composition root/core

- composer floor `infocyph/intermix: ^10.0.3`;
- new builder-first composition function;
- deterministic aliases;
- runtime/build context;
- ConfigRepository core recipe/value model;
- RuntimeMode/runtime metadata;
- Application runtime façade boundary;
- remove/replace `ContainerFactory`.

### Batch IM-2 — Provider contract

- builder-first `ServiceProviderInterface`;
- compile-friendly `ServiceProvider` helpers;
- separate graph contribution from boot side effects;
- migrate `PathServiceProvider` and other simple core providers first;
- change explicit-binding checks to builder definitions.

### Batch IM-3 — Bootstrap/capabilities

- convert Bootstrapper service→provider discovery into build topology;
- reshape ServiceRegistry;
- stop broad `onMissing()` activation in production;
- make optional capability absence explicit at composition time.

### Batch IM-4 — Core provider graph conversion

Convert simple-to-medium providers:

- JSON dispatch;
- logging;
- security;
- filesystem;
- cache;
- database;
- validation;
- communication;
- notifications;
- session.

For each provider:

- classify every binding;
- replace aliases;
- replace deterministic closures;
- review lifetime;
- record remaining islands.

### Batch IM-5 — Auth graph

Migrate `AuthServiceProvider`, `AuthOtpServiceProvider`, `AbstractAuthRegistrar`, and all auth registrars to builder-first graph contribution.

This is a dedicated batch because auth currently has a large live-container mutation surface and security correctness cannot be weakened for compilation convenience.

### Batch IM-6 — Messaging/worker graph

- migrate `MessagingServiceProvider`;
- migrate `InterMixExecutionScope` to `withinScope()`;
- compile deterministic handler/listener/route service topology where Foundation owns it;
- keep Omnibus-specific design decisions for the later Omnibus pass.

### Batch IM-7 — Routing/HTTP InterMix boundary

- remove dynamic `Container` from `WebrickRouterFactory` boundary;
- move controller/middleware DI definitions before runtime creation;
- ensure HTTP services can resolve from `ProductionContainer`;
- stop Foundation outer-scope assumptions from constraining the later Webrick integration.

Do not finalize Webrick-specific matcher/release/runtime architecture in this batch; that is the next dedicated library pass.

### Batch IM-8 — Optimize/artifacts

- retire `ContainerCacheManager` resolver-map compilation/activation;
- implement build-time builder compilation for all runtime modes;
- add skipped-definition gate;
- publish digest/artifact metadata;
- implement strict production loader.

### Batch IM-9 — Tests/benchmarks/rescan

- dev/production parity suite;
- scope/concurrency suite;
- artifact/mutation suite;
- runtime-specific compile reports;
- repeat direct-usage scan;
- remove obsolete InterMix 9 compatibility configuration/tests/docs.

## 16. InterMix-specific performance benchmarks

Before Webrick attribution, benchmark Foundation's DI layer directly.

Measure development and generated production separately:

- builder composition time;
- strict validation time;
- compile time (build metric only);
- verified production artifact load;
- trusted-prevalidated production artifact load;
- singleton get;
- scoped get inside `withinScope()`;
- transient get;
- compiled constructor chain;
- compiled static-factory chain;
- compiled→dynamic island bridge;
- dynamic island→compiled dependency bridge;
- Application façade `make()` overhead versus direct production-container `get()`;
- CLI/worker scope enter/leave/seed overhead;
- memory after repeated scopes/jobs;
- Fiber-interleaved scope overhead/isolation.

Do not optimize build-time milliseconds at the expense of request/job hot paths. Compilation quality and production resolution are the priority.

## 17. InterMix pass completion gate

The InterMix 10.0.3 pass is complete only when:

- `composer.json` targets `^10.0.3`;
- no Foundation production composition path constructs the old dynamic container directly;
- one deterministic composition function is the source of truth;
- each runtime uses a fresh builder instance from that same composition function;
- development uses `development()`;
- production uses `ProductionContainer` from `production()` or trusted `productionPrevalidated()`;
- `Container::compileTo()`, `useCompiled()`, and old resolver-map activation are absent from the Foundation production path;
- `ContainerCacheManager` is removed or reduced to a non-runtime artifact coordinator with no old activation semantics;
- no Foundation provider registers normal production services by mutating a live finalized graph;
- aliases are real aliases, not closure factories;
- deterministic constructor/static-factory services use compilation-safe recipes;
- all current `LifetimeEnum` choices have been reviewed;
- broad production `onMissing()` provider activation is gone;
- Foundation-owned avoidable dynamic islands are eliminated;
- every remaining `skipped` entry is explicit and allowlisted with a reason;
- scope execution uses InterMix named-scope semantics correctly;
- production scopes pass sequential/Fiber/persistent-worker isolation tests;
- builder mutation/deoptimization is not used as a normal deployment mechanism;
- old InterMix 9 container-cache tests/config/docs are removed or migrated;
- direct InterMix usage is rescanned after the migration;
- InterMix DI benchmarks show the generated production runtime is actually being exercised.

Only after this gate should the Webrick-specific pass be considered architecturally final.

## 18. Subsequent library passes

The overall Foundation 3 plan still includes dedicated reviews for:

1. **Webrick 5.1** — graph contribution, release compiler, `CompiledRouterKernel`, runtime adapters/server, route execution plans, Request/scope avoidance, route/controller/middleware integration;
2. **ArrayKit** — environment/config parsing, sealed compiled config, dynamic source scans, cache strategy;
3. **UID** — execution/correlation ID cost and lazy/reused IDs;
4. **CacheLayer / DBLayer** — capability boundaries, connection/store lifetimes, optional graph costs;
5. **ReqShield** — remove unconditional DB graph coupling;
6. **Omnibus** — messaging topology and worker execution integration;
7. **TalkingBytes** — HTTP/email/webhook/gRPC profile graph and scoped runtime behavior;
8. **OTP / Epicrypt / WebAuthn / Pathwise** — specialist auth/security/filesystem integration boundaries.

Each pass must update this master plan with exact current-library APIs rather than relying on assumptions from older versions.

## 19. Foundation-wide performance strategy

Required attribution layers remain:

1. raw PHP baseline;
2. standalone lower-layer runtime benchmark (Webrick for HTTP, InterMix for DI);
3. Foundation 3 compiled runtime;
4. minimal InfByte application.

For HTTP later:

```text
Foundation tax = Foundation compiled minimal request - standalone Webrick compiled minimal request
```

For DI now:

```text
Foundation DI tax = Foundation service-resolution/scope cost - equivalent direct InterMix 10.0.3 production graph cost
```

No arbitrary percentage budget should be invented before new baselines exist. Establish the lower-layer baseline, then enforce evidence-based regression budgets.

## 20. Full Foundation source audit checklist

After all library-specific passes, rescan every class/function for:

- reflection in production hot paths;
- repeated `class_exists()`/`interface_exists()` capability probing;
- `glob()`/directory scans/file metadata walks in request/job paths;
- config normalization that could happen once;
- closure-heavy DI definitions;
- service-locator/Application capture where constructor injection can be generated;
- global/static mutable state unsafe for persistent workers;
- request/job state stored in singleton services;
- unnecessary Request construction;
- nested/duplicated scopes;
- exception throw/catch for expected control flow;
- repeated hashing/manifest parsing;
- duplicated lower-library behavior;
- hidden DB/cache coupling;
- cleanup failures masking primary failures;
- persistent-worker resource leaks;
- Fiber/coroutine safety issues;
- schema/migration checks in boot/request paths;
- provider/module/command discovery that can be finalized at build time;
- eager logging/context allocation when unused.

## 21. Foundation 3 global definition of done

Foundation 3 is complete only when:

- each lower-level library has had a dedicated current-version utilization pass;
- InterMix `ContainerBuilder` is the sole graph builder;
- generated `ProductionContainer` is the normal production DI runtime;
- production provider/module/capability graphs are finalized before runtime handling;
- Webrick production uses its compiled runtime without a redundant Foundation HTTP runtime above it;
- no route/provider/module graph is rebuilt in a production request;
- no full Request/scope is created unless the execution path requires it;
- persistent runtimes do not leak request/job state;
- optional integrations add no meaningful overhead when absent/disabled;
- build artifacts are immutable, verified, and deployed atomically with matching code/dependencies;
- all dynamic islands are intentional and reported;
- representative real-HTTP and non-HTTP benchmarks meet evidence-based budgets;
- the complete source tree has been rescanned after implementation;
- InfByte can consume Foundation's composition/build/runtime lifecycle directly instead of recreating another framework-level boot layer.

## 22. Working principle

For every Foundation abstraction or binding, ask:

1. Does InterMix 10.0.3 already own this responsibility?
2. Can this definition be generated instead of left dynamic?
3. Can a real alias/recipe/ServiceReference replace a closure?
4. Can an Application/container lookup be replaced by a narrow constructor dependency?
5. Is the lifetime correct for persistent/concurrent runtimes?
6. Can this work happen at build time instead of process boot?
7. Can it happen once at process boot instead of per request/job?
8. Is a dynamic island truly necessary, and is it visible in the compile report?
9. Does a scope seed solve runtime context without graph mutation?
10. Is the real lower-layer-vs-Foundation cost measured?

If InterMix already provides the correct mechanism, Foundation should use it directly rather than wrap, duplicate, or weaken it.
