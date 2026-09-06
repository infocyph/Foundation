# Foundation 3 — Unified Runtime Development Plan

**Status:** Final implementation baseline / canonical plan
**Foundation target:** 3.x
**Foundation source baseline:** `main`
**InterMix baseline:** `^10.0.4`
**Webrick baseline:** `^5.3`
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single source of truth for Foundation 3 runtime development. It merges the complete InterMix 10.0.3 audit plus the released InterMix 10.0.4 intrinsic-container planner correction, the complete Webrick 5.1 audit plus the released Webrick 5.2 prerequisite corrections, the Webrick 5.3 SAPI/streaming correctness floor consumed by Foundation, and the final joint runtime architecture into one document. There are no separate InterMix/Webrick runtime-plan files to keep synchronized.

---

## 1. Purpose and review method

Foundation 3 is a deep internal runtime revision, not a feature-expansion release. The goal is to make Foundation a thin, deterministic application composition/integration layer that fully consumes optimized lower-level Infocyph runtimes rather than rebuilding equivalent runtime machinery above them.

Public application-facing behavior should remain stable where useful and inexpensive to preserve, but Foundation 2 internal runtime/container compatibility is not a design constraint for this major release.

The codebase was reviewed with direct focus on:

- InterMix imports and concrete container types;
- `ContainerBuilder`, `LifetimeEnum`, `FactoryDefinition`, `ServiceReference` and scope usage;
- live-container registration/mutation and old resolver-map compilation/activation;
- application/provider/bootstrap lifecycle;
- all four execution paths: web, CLI, worker and scheduler;
- auth registrar/container coupling;
- messaging execution-scope bridges;
- every HTTP/routing/Webrick bootstrap surface;
- middleware aliases/groups/presets and parameterized middleware;
- matcher/route-cache ownership;
- exception rendering and maintenance mode;
- URL generation/frozen registries;
- filesystem upload/download/streaming bridges;
- runtime adapters and persistent-worker behavior;
- tests/benchmarks that encode Foundation 2 runtime assumptions.

The connected Foundation repository did not expose a complete code-search index during the audit, so the review used repository-tree enumeration plus direct source inspection. During implementation, add local/CI sanity scans for at least:

```text
Infocyph\\InterMix
Container
ContainerBuilder
LifetimeEnum
FactoryDefinition
ServiceReference
compileTo
useCompiled
usePrevalidated
onMissing
enterScope
leaveScope
Infocyph\\Webrick
RouterKernel
CompiledRouterKernel
Registrar
Collection
RouteCache
MiddlewareAliases
Request
Response
php://output
```

---

## 2. Final architectural decision: four runtime paths

Foundation has **four independent runtime paths**:

1. `web`;
2. `cli`;
3. `worker`;
4. `scheduler`.

InterMix is the DI/runtime foundation for **all four**.

Webrick owns only the **web** HTTP path. It must never become the build/runtime owner of CLI, worker or scheduler execution.

```text
                         one Foundation graph/composition source
                                      |
          +---------------------------+---------------------------+
          |                           |                           |
          v                           v                           v
        web                         cli                        worker                 scheduler
          |                           |                           |                       |
 fresh ContainerBuilder       fresh ContainerBuilder       fresh ContainerBuilder   fresh ContainerBuilder
          |                           |                           |                       |
 Foundation web graph         Foundation CLI graph          Foundation worker graph  Foundation scheduler graph
          |
 Webrick::contributeTo(...)
          |
 Webrick coordinated          direct InterMix               direct InterMix          direct InterMix
 web release compilation      compilation                   compilation              compilation
          |                           |                           |                       |
 InterMix + Webrick           InterMix artifact             InterMix artifact        InterMix artifact
 web release bundle
```

There is **one graph source of truth**, but four fresh builders and four runtime-specific artifacts. Runtime/capability inputs decide which definitions are included. Do not copy the graph into four separate implementations.

Foundation remains application composition and release coordinator. InterMix owns DI/runtime behavior. Webrick owns HTTP routing/execution/transport only for `web`.

---

## 3. Hard ownership boundaries

### 3.1 InterMix owns

Use InterMix directly for:

- `ContainerBuilder` graph composition;
- environment-specific graph selection;
- singleton/scoped/transient lifetimes;
- aliases, values, contextual bindings and tags;
- compilation-safe constructor/static-factory recipes;
- strict validation;
- generated production containers;
- artifact verification/prevalidation;
- runtime `resolveNow()`;
- execution scopes and seeds;
- Fiber/Swoole/OpenSwoole execution-context isolation;
- lifecycle/scope-leave hooks where semantics fit;
- compile reports and dynamic-island visibility.

Foundation must not add a second DI builder, second scope runtime, or second generated-container runtime.

### 3.2 Webrick owns for `web`

Use Webrick directly for:

- route registration/build;
- handler inspection;
- execution-plan generation;
- matcher compilation/runtime matching;
- lazy Request materialization;
- middleware pipeline dispatch;
- HTTP request-scope decisions;
- routing-control responses;
- runtime adapters;
- runtime capabilities;
- native response writing/streaming;
- URL-generator runtime registry;
- compiled/frozen HTTP registries;
- coordinated web release metadata.

Foundation must not add a second HTTP runtime/transport layer above Webrick.

### 3.3 Foundation owns

Foundation owns:

- normalized application configuration;
- runtime/capability selection;
- application graph contribution;
- package/provider composition policy;
- application-facing integrations;
- CLI/worker/scheduler orchestration;
- Foundation-specific auth/session/database/filesystem policy;
- release-generation coordination across all four runtimes;
- deployment activation/trust policy;
- diagnostics, migration guidance and benchmarks;
- thin application-facing convenience APIs.

---

## 4. Exact InterMix 10.0.4 contract

Foundation must design against the InterMix 10 builder/runtime split, not the InterMix 9 resolver-map model.

```text
ContainerBuilder::development()
    -> dynamic development Container

ContainerBuilder::validate(strict: true)

ContainerBuilder::compile($path)
    -> {
         compiled: list<string>,
         skipped: array<string,string>,
         digest: string
       }

ContainerBuilder::production($path)
    -> verified ProductionContainer

ContainerBuilder::productionPrevalidated($path, $trustedDigest)
    -> trusted/prevalidated ProductionContainer
```

Rules:

- `setEnvironment()` selects graph metadata; it does not select the production runtime;
- every independently active runtime uses a fresh builder;
- normal production graph mutation after finalization is prohibited;
- deoptimization is a correctness fallback, never Foundation's deployment mechanism;
- every new `skipped` definition is release-significant and must be checked against an explicit allowlist;
- do not blanket-call `validate(resolveFactories: true)` because dynamic factories can perform side effects/open connections;
- deterministic constructor/static-factory definitions and aliases use compilation-safe primitives instead of closures;
- scope seeds carry execution objects/values; definitions are never rebound per request/job;
- separate builders are mandatory for simultaneously active runtime artifacts because loading another production runtime from the same builder deoptimizes the previous runtime;
- safe default is `production()`; use `productionPrevalidated()` only with a digest from trusted immutable deployment metadata;
- the artifact directory cannot serve as the trust source for its own expected digest.

Compilation-safe recipe capabilities in 10.0.4 are exactly:

```text
FactoryDefinition::construct(...)
FactoryDefinition::staticFactory(...)
ServiceReference(...)
```

Recipe args must be service references or exportable scalar/null/array values. Do not design around nonexistent `FactoryDefinition::service()`, `function()` or `invokable()` helpers.

A closure/`DirectFactory` may be reflection-free but is still a dynamic production island.

**Phase 7 integration correction — resolved:** InterMix 10.0.4 compiles its intrinsic `Psr\Container\ContainerInterface` binding to the generated `ProductionContainer` itself instead of treating the development-container value as a dynamic definition. Foundation therefore requires `^10.0.4` and can resume strict generated-runtime acceptance without a Foundation proxy, service-locator bridge, or dynamic-fallback workaround. Any future regression that again reports this intrinsic binding or its dependent deterministic services as skipped is a lower-layer release blocker.

---

## 5. Integrated InterMix direct-usage audit

### 5.1 `src/Application/Application.php`

Current coupling:

- concrete dynamic `Infocyph\InterMix\DI\Container` constructor/property/return type;
- Application is created only after the dynamic container already exists;
- binds Application, RuntimeMode, ConfigRepository, concrete Container, RuntimeContextTracker and ExecutionScope as live singleton instances;
- installs broad `Container::onMissing()` provider/service activation;
- `make()` performs provider activation before container lookup;
- `has()` consults Bootstrapper/provider discovery;
- optionally activates a compiled resolver map after dynamic Application construction.

Foundation 3 action:

- remove dynamic Container as architectural Application owner;
- compose first through `ContainerBuilder`;
- choose development/production runtime only after composition;
- make Application a thin runtime façade/coordinator;
- remove broad production provider activation from `make()`/`has()`;
- avoid binding live Application when it makes otherwise compilable services dynamic;
- expose runtime-neutral/PSR-style lookup publicly rather than promise mutable dynamic-container APIs in production.

### 5.2 `src/Container/ContainerFactory.php`

Current:

- constructs `new Container(...)` directly;
- random UUID7 alias every boot;
- environment/lazy/debug options are dynamic-container runtime configuration.

Action:

- retire in favor of ContainerBuilder composition;
- stable deterministic aliases: `foundation.web`, `foundation.cli`, `foundation.worker`, `foundation.scheduler` (optionally deterministic app prefix);
- use builder environment selection;
- debug tracing development-only;
- reevaluate/remove obsolete `app.container.lazy_loading` semantics when production uses generated resolvers.

### 5.3 `src/Container/ContainerCacheManager.php`

Current:

- reconstructs a full dynamic Application for each runtime;
- calls `Container::compileTo()`;
- consumes resolver-map compilation report;
- later calls `usePrevalidated()` on the same dynamic container;
- models compiled activation as optional runtime mode;
- expects a 64-hex Foundation fingerprint.

This is a **hard architectural incompatibility**, not an API rename.

Action:

- retire old compile/activate model;
- build with builder `validate()` + `compile()`;
- boot directly with ProductionContainer;
- consume InterMix xxh128 `digest` directly;
- if an artifact coordinator remains, it coordinates files/reports only and never activates a resolver map on a live container.

### 5.4 `src/Runtime/ExecutionScope.php`

Current:

- concrete dynamic Container dependency;
- manual `enterScope()`/`leaveScope()`;
- unique runtime+UUID scope names;
- manually resets external runtime context before leave.

Action:

- runtime-neutral dev/prod container boundary;
- prefer `withinScope()`;
- use seeds instead of rebinding;
- semantic stable non-web labels (`foundation.cli`, `foundation.worker`, `foundation.scheduler`);
- preserve primary application exception over cleanup failures;
- retain explicit Foundation execution boundaries for CLI/worker/scheduler;
- do not impose this outer scope on every HTTP request.

### 5.5 `src/Application/ServiceProvider.php`

Current:

- helper methods target live Container;
- direct LifetimeEnum use;
- `bindRecipe()` already points in the correct `FactoryDefinition::construct()` direction;
- closure `bindFactory()` is described as reflection-free but remains dynamic in InterMix 10.

Action:

- provider contribution targets ContainerBuilder;
- keep only a very small helper layer around exact InterMix primitives;
- expand recipes/aliases and sharply reduce closures;
- distinguish reflection-free from compilation-safe.

### 5.6 `src/Application/ServiceProviderInterface.php`

Current:

```php
register(Application $app): void
boot(Application $app): void
```

Target conceptually:

```php
register(ContainerBuilder $builder, FoundationBuildContext $context): void
```

with an optional separate process-boot contract for true side effects after runtime selection. Boot may not mutate a finalized production graph.

Do not simply adopt InterMix's own provider interface because it receives the dynamic Container. Foundation needs graph composition before runtime selection.

### 5.7 `src/Application/ServiceRegistry.php`

Current deferred providers may register/boot after Application start.

Action:

- production provider/capability topology determined before compilation;
- dev may retain controlled convenience;
- process boot may perform side effects only;
- no normal production resolution via late provider activation.

### 5.8 `src/Bootstrap/Bootstrapper.php`

Current:

- large service→provider map;
- `class_exists()` capability discovery;
- broad on-missing activation;
- configured/provider-file discovery during preparation.

Action:

- capability/provider selection becomes composition/build topology;
- production enabled provider set finalized before production runtime load;
- broad discovery restricted to dev/tooling;
- any production fallback must be narrow, explicit, tested and visible in skipped-definition diagnostics.

### 5.9 `src/Routing/WebrickRouterFactory.php`

Current direct InterMix coupling:

- accepts concrete dynamic Container;
- passes it to Webrick;
- disables Webrick request scope because Foundation owns an outer scope;
- creates routing against live runtime state.

Action:

- remove dynamic-container assumption;
- controller/middleware/service definitions contributed before production compilation;
- no late graph mutation during route creation;
- production class removed from architecture (see Webrick sections).

### 5.10 `src/Routing/RoutingServiceProvider.php`

Current mixes recipes and closure factories and passes live container into WebrickRouterFactory.

Action:

- builder definitions/recipes;
- no live-container capture;
- route/controller topology visible before final web compilation.

### 5.11 `src/Http/HttpServiceProvider.php`

Current has useful recipes but also Application-capturing closures for MaintenanceManager, ErrorHandler, RouterKernel and aliases.

Action:

- preserve/expand recipes;
- remove unnecessary Application capture;
- Webrick production kernel construction moves outside old provider model.

### 5.12 `src/Http/JsonDispatch/JsonDispatchServiceProvider.php`

Current is closure-built singleton + closure alias.

Action:

- export normalized config to recipe/static factory;
- use a real alias/reference.

### 5.13 Auth subsystem

`AuthServiceProvider`, `AuthOtpServiceProvider`, `AbstractAuthRegistrar` and registrar family are a major direct InterMix surface.

Current patterns:

- live dynamic Container stored/passed through registrars;
- direct definition checks;
- aliases implemented as singleton closures;
- closure factories around `$app->make()`;
- runtime-mode/config branching while mutating live graph.

Migrate core, stores, cache, password, token, MFA, passkey, notification, manager, authorization, runtime and OAuth registrars together.

Rules:

- auth feature/driver choices occur during composition;
- registrars target builder/build context;
- deterministic services use recipes/aliases;
- only truly runtime-dependent secrets/callables remain narrow dynamic inputs;
- every auth singleton gets persistent/concurrency lifetime review;
- security behavior may not be weakened merely to improve compilation;
- OTP/Epicrypt/WebAuthn exact package semantics remain for their specialist passes.

### 5.14 Provider families requiring compileability migration

All of these currently use InterMix lifetimes/live registration and must be audited binding-by-binding:

- CacheServiceProvider;
- DatabaseServiceProvider;
- MessagingServiceProvider;
- CommunicationServiceProvider;
- FilesystemServiceProvider;
- PathServiceProvider;
- LoggingServiceProvider;
- NotificationServiceProvider;
- SecurityServiceProvider;
- SessionServiceProvider;
- ValidationServiceProvider;
- RoutingServiceProvider;
- HttpServiceProvider;
- JsonDispatchServiceProvider;
- auth provider/registrar family.

Common problematic patterns:

- closure construction around `$app->make()`;
- closure aliases;
- manager-method closures (`store()`, `disk()`, `connection()`, etc.);
- runtime `class_exists()` feature branching;
- binding already-created object instances;
- live Application capture in singleton factories;
- correct scoped lifetime intent implemented through dynamic closures;
- optional capability placeholders/throwing closures that keep unrelated runtime graph edges alive.

### 5.15 Messaging execution-scope bridge

`src/Messaging/InterMixExecutionScope.php` correctly uses scope seeds for Envelope/message values, but underlying execution must move to `withinScope()` with deterministic leave/cleanup. Message/envelope ID remains correlation/seed data, not the semantic scope name. Exact Omnibus design waits for its dedicated pass.

### 5.16 Build command and tests

`src/Command/System/ApplicationSystemCommand.php` currently compiles through ContainerCacheManager and reports activation state.

Target per runtime:

```text
fresh deterministic ContainerBuilder
 -> validate(strict: true)
 -> compile(path)
 -> collect compiled/skipped/digest
 -> enforce skipped allowlist
 -> publish transactionally
```

`tests/Feature/ContainerCacheIntegrationTest.php` encodes the old resolver-map model and should be replaced rather than adapted.

---

## 6. InterMix provider migration and lifetime policy

Every binding must be classified:

| Current pattern                               | Foundation 3 form                                                    |
| --------------------------------------------- | -------------------------------------------------------------------- |
| class/new service                             | builder singleton/scoped/transient                                   |
| constructor deps                              | `FactoryDefinition::construct()` + `ServiceReference`                 |
| public static factory                         | `FactoryDefinition::staticFactory()`                                 |
| interface/ID points to same service           | real `alias()`                                                       |
| immutable scalar/array build value            | `value()` or exportable recipe arg                                   |
| Request/job/envelope/current execution object | scope seed                                                           |
| feature/capability branch                     | build-context graph decision                                         |
| user runtime callable/closure                 | explicit narrow dynamic island                                       |
| manager instance method factory               | explicit static recipe where worthwhile, otherwise documented island |
| optional package absent                       | omit capability graph / record unavailable capability                |

Mandatory rules:

- aliases are never closure factories;
- deterministic constructor graphs are never closures;
- provider factories do not call `$app->make()` merely for constructor injection;
- normalize config before recipe generation where practical;
- capability `class_exists()` checks happen during composition, not hot resolution;
- bind already-created instances only when runtime identity genuinely requires it.

### Lifetime policy

**Singleton** only when process-wide immutable/concurrency-safe, retaining no request/job/command/schedule state and no first-scoped dependency.

Audit especially controllers, DB/default connections, auth/session managers, transports/consumers, registries, communication clients and middleware that touches mutable current context.

**Scoped** for state shared inside one execution but isolated across requests/jobs/commands/schedules.

**Transient** for cheap/stateful one-use objects where sharing is undesirable.

Every migrated provider requires an explicit lifetime review. “It was singleton in Foundation 2” is not justification.

### Dynamic-island build gate

For every runtime artifact:

1. strict validate;
2. compile;
3. read exact `compiled`, `skipped`, `digest`;
4. compare skipped IDs/reasons with explicit allowlist;
5. fail CI/release on new unexpected skipped entries;
6. report each intentional island with subsystem/reason.

Foundation core target: zero **avoidable** islands.

---

## 7. Exact Webrick 5.2 contract

Foundation must treat Webrick development and production as intentionally different. Webrick 5.3 retains this release/runtime contract and is Foundation's current minimum because it also carries the SAPI/streaming correctness fixes required by Phase 8 acceptance.

### Development

```text
same Foundation graph
 -> ContainerBuilder::development()
 -> Webrick contribution
 -> RouterKernel::bootWithRegistrar(...)
```

`RouterKernel` is development/registrar infrastructure. Development may use live route registration, mutable aliases and diagnostics.

### Production

```text
ProductionContainer
      +
CompiledRouterKernel
      +
boot-selected RuntimeAdapter
      |
      v
RuntimeServer
```

Native hot path:

```text
native request
 -> RuntimeAdapter context
 -> lightweight RoutingInput
 -> compiled matcher
 -> ExecutionPlan
 -> Request only if required
 -> InterMix scope only if required
 -> middleware only if required
 -> handler
 -> Webrick Response
 -> RuntimeAdapter writer
```

Foundation must preserve requestless/scopeless routes where their execution plans allow it.

### Host-owned InterMix graph

Foundation owns the builder/environment. Webrick contributes to that exact graph:

```php
Webrick::contributeTo($builder, $providers);
```

Never create a separate Webrick container.

### Webrick release manifest

Tagged 5.2 source uses release format **2** with fields including:

```text
format
environment
config_fingerprint
intermix.path
intermix.digest
intermix.compiled
intermix.skipped
webrick.path
webrick.meta
webrick.digest
webrick.fingerprint
webrick.routes
```

It publishes JSON plus an OPcache-friendly PHP runtime manifest; runtime loading prefers PHP and falls back to JSON.

Do not conflate identities:

- `intermix.digest` — xxh128 InterMix artifact identity;
- `webrick.digest` — xxh128 router artifact file digest;
- `webrick.fingerprint` — xxh128 semantic artifact fingerprint for trusted loading;
- `config_fingerprint` — host-owned opaque deterministic config identity.

Follow tagged source if stale documentation examples use older SHA-256 terminology.

### Production loading

Normal verified:

```text
ProductionContainer
 -> CompiledRouterKernel::fromCompiledArtifact(...)
```

Trusted immutable deployment:

```text
ProductionContainer with trusted InterMix digest
 -> CompiledRouterKernel::fromPrevalidatedArtifact(trusted Webrick fingerprint, ...)
```

### Execution/runtime behavior

- `CompiledRouterKernel` can boot matcher directly from cache where supported, otherwise hydrates compiled route metadata;
- each route has an ExecutionPlan capability set such as REQUEST, SCOPE, MIDDLEWARE, DOMAIN, CORS, PRODUCES, ROUTE_ARGS;
- RuntimeRequestContext keeps lightweight RoutingInput + lazy Request factory + RuntimeCapabilities + native handles;
- Request is cached/materialized only if required;
- runtime capabilities are attached to Request when created;
- Webrick enters request scope only when execution plan or runtime-backed middleware requires it;
- RuntimeServer selects SAPI/RoadRunner/Swoole/Workerman adapter once at boot, not per request;
- exactly one layer writes native response: Webrick RuntimeAdapter;
- compiled kernel freezes URL/middleware/constraint/header/trusted-proxy/method-override registries before traffic.

---

## 8. Integrated Webrick direct-usage audit

### 8.1 `src/Http/HttpKernel.php`

Current:

```text
Request already exists
 -> Foundation ExecutionScope
 -> MaintenanceManager::status()
 -> RouterKernel::handle(Request)
```

Target:

- remove from native production hot path;
- no HTTP scope ownership;
- no native emission;
- no pre-created Request;
- no universal maintenance I/O;
- optionally retain a thin embedded/testing API delegating an already-created Request.

### 8.2 `src/Http/HttpServiceProvider.php`

Current binds live RouterKernel, custom ErrorHandler and Foundation HttpKernel.

Target:

- development may expose RouterKernel;
- production loads CompiledRouterKernel from release metadata;
- split exception services from kernel construction;
- custom error behavior is deliberate boot configuration;
- no Foundation outer HTTP scope dependency.

### 8.3 `src/Routing/WebrickRouterFactory.php`

Disposition: remove from production, likely delete after migration.

It currently owns matcher selection, matcher cache boot, route replay, live InterMix container passing and request-scope disabling—all obsolete in compiled production.

### 8.4 `src/Routing/RoutingServiceProvider.php`

Current production-like graph exposes WebrickRouterFactory, Registrar, Collection, RouteFileLoader and mutable `foundation.router`.

Target:

- DI contains application/controller/middleware services;
- route-registration tooling is development/build-only;
- Registrar/Collection are dev/build-only;
- production URL generation comes from compiled/frozen Webrick runtime;
- mutable router facade is not a normal production dependency.

### 8.5 `src/Routing/RouteCacheManager.php`

Remove from production optimize flow. A matcher cache is not the complete Webrick production release artifact. Delete unless a separate dev/diagnostic use is demonstrably useful.

### 8.6 `src/Routing/RouteCachePath.php`

Current owns matcher-cache path, Foundation SHA-256 freshness metadata and matcher reconstruction for warm-state detection.

Production action:

- retire from readiness/boot;
- use Webrick release/router artifact identity;
- Foundation-owned route/config freshness fingerprints are non-security identities and **must use XXH128**; SHA3-256 is reserved for Foundation-owned hash derivation where cryptographic collision resistance is part of a security boundary;
- any dev matcher cache is isolated from production readiness.

### 8.7 `src/Routing/RouteFileLoader.php`

Keep discovery only in development or release build. Production requests never scan route directories, require route files, reflect controllers or load route attributes.

### 8.8 `src/Routing/RoutePresetRegistrar.php`

Keep policy presets such as `web`/`web-auth` as thin build-time route policy. Presets contribute descriptors/route attributes; they do not activate middleware services.

### 8.9 `src/Routing/OAuthRouteRegistrar.php`

Current class-method descriptors are a good compiled shape. Execute registration only during dev/build, never into a live production router after boot.

### 8.10 `src/Routing/RouteMiddlewareRegistrar.php`

Rewrite around artifact-safe descriptors:

- deterministic aliases registered before route compilation;
- never resolve service graphs merely to serialize alias results;
- non-parameterized DI middleware uses class/method descriptors;
- parameterized aliases use the Webrick descriptor correction below or explicit route attributes;
- alias registries finalize/freeze before traffic;
- old warm matcher-cache requirements do not control production alias registration.

### 8.11 `src/Routing/WebrickMiddlewareFactory.php`

Continue reusing Webrick built-ins rather than duplicating them.

Change construction boundary:

- normalize config at build/graph time;
- DI-backed middleware where practical;
- no serialized objects capturing live Foundation services;
- disabled middleware omitted from graph/topology;
- optional cache/database middleware activates those capabilities only when configured;
- rely on RuntimeCapabilities to avoid duplicate transport-native compression/request-limit work;
- global middleware stays empty by default.

### 8.12 Auth middleware

Keep Webrick-native `__invoke(Request $request, callable $next): Response` contracts for principal/auth/guest/verified/MFA/recent/role/permission/policy/OAuth audience/scope middleware.

Ensure:

- only declaring routes pay Request/scope cost;
- runtime-backed middleware resolves through InterMix;
- parameters are artifact data, not serialized service objects;
- principal state is scoped and Fiber/coroutine-safe;
- order deterministic.

### 8.13 Session/CSRF middleware

Keep Foundation session semantics and Webrick Request/Response/Cookie types, but route/preset-specific only. No universal session/CSRF stack by default. Cleanup occurs inside Webrick-selected request scope.

### 8.14 Filesystem HTTP bridge

Upload request handling already uses Webrick UploadedFile/Request without transport emission and is conceptually correct.

Download/stream output must change: Foundation response factories may not write directly to `php://output` or runtime-native handles. Webrick RuntimeAdapter owns writing.

### 8.15 Testing HTTP client

Keep fake Request + embedded handler convenience. Add production-native compiled-kernel/runtime-adapter tests so the test client does not define the architecture being benchmarked.

---

## 9. Final joint InterMix + Webrick decisions

### J-1 — Compile web InterMix exactly once

Webrick `ReleaseCompiler` already performs strict InterMix validation/compile. Foundation composes a fresh web builder, Webrick contributes to the same builder, and Webrick's coordinated release compilation compiles the web InterMix artifact **once**.

Foundation must not compile web separately before invoking Webrick.

CLI/worker/scheduler still compile directly through InterMix.

### J-2 — Semantic stable scope names

InterMix `onScopeLeave($scope, ...)` matches exact scope labels. Current unique names containing request/execution IDs make compile-time hooks unusable.

Use:

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

InterMix execution-context isolation already separates concurrent Fibers/coroutines using the same semantic label. Request/job/execution/envelope IDs remain seeds/correlation values.

### J-3 — RuntimeContextTracker cannot remain a mutable process singleton

Current tracker stores per-execution DB touched state, dirty principal/session contexts and fresh connections in one mutable singleton. Concurrent Fibers/coroutines can corrupt each other's cleanup bookkeeping.

Redesign around scoped execution state or eliminate it.

Preferred direction:

- `CurrentPrincipalContext` becomes scoped ordinary state rather than maintaining another Fiber WeakMap when InterMix owns execution isolation;
- active browser-session context becomes scoped while session store/locking remains separate reusable infrastructure;
- DB configuration/registries may stay process-wide when safe, but touched/transaction/fresh-connection cleanup bookkeeping is execution-local;
- memoizers/caches are explicitly classified as process-safe, generation-bound or execution-cleared;
- no singleton captures the first scoped dependency resolved into it.

Use one concurrency model: InterMix scopes.

### J-4 — Removing Foundation HTTP outer scope must preserve cleanup

Webrick decides whether an HTTP execution needs scope. Foundation request-scoped services clean up on `webrick.request` leave. Direct routes remain scopeless and pay no Foundation cleanup tax. Do not reintroduce a universal Foundation scope merely to preserve legacy cleanup.

### J-5 — Route topology must enrich DI before InterMix compile

Webrick 5.2 provides the route-first graph-enrichment point required by Foundation: finalized route topology is available before InterMix validation/compile so route-referenced controller/middleware definitions can be added without repeating route discovery.

Required release order:

```text
RouteCompiler::compile(...)
 -> RouterBuildResult / ExecutionPlans
 -> host graph-enrichment callback
 -> add deterministic route-referenced DI definitions
 -> ContainerBuilder::validate(strict: true)
 -> ContainerBuilder::compile(...)
 -> RouterArtifactCompiler::compile(...)
 -> release manifest
```

No duplicated Foundation controller scan.

### J-6 — Declarative parameterized middleware

Aliases such as:

```text
role:admin
permission:invoice.approve
policy:document,update
oauth-scope:payments.write
oauth-audience:merchant-api
```

must not materialize middleware service graphs during route registration.

Webrick 5.2 provides an artifact-safe runtime middleware descriptor carrying a resolver spec plus exportable parameters. At runtime Webrick merges request/next invocation data and delegates resolution/invocation through InterMix.

Foundation must use that descriptor rather than creating a second generic middleware runtime.

### J-7 — Empty global middleware means empty tags too

Webrick defaults can include tag-driven global middleware. Foundation must explicitly default all of these empty:

```text
preGlobal: []
postGlobal: []
preGlobalTags: []
postGlobalTags: []
```

Tag-driven globals are explicit opt-in only. This preserves minimal Request-free routes.

### J-8 — Router artifacts may not hide Foundation service graphs

Foundation-owned production routes prefer declarative class/method/invokable/static/function descriptors. Do not persist resolved controller/middleware objects or closures capturing Application/container/auth/session/DB managers.

User closures remain supported where Webrick can serialize them, but build diagnostics must report closure/callable-object usage and flag Foundation runtime captures. Unsafe Foundation-owned captures fail the build.

### J-9 — Separate routing-control errors from application exceptions

Foundation requires logging/mapping for application exceptions, but this should not automatically force custom Request-based rendering for routine 404/405.

Webrick 5.2 keeps direct routing-control responses as the default and makes routing 404/405 through the application ErrorHandler explicit opt-in while preserving routing-control logging/security semantics.

Correctness/security/observability remain higher priority than the optimization.

### J-10 — Maintenance leaves old HttpKernel

Do not perform Foundation per-request file/cache status work after Request/scope creation.

Initial solution: Webrick maintenance middleware/state with worker-local refresh caching where semantics fit.

Optional future optimization: a generic pre-routing gate over RoutingInput/runtime context only if benchmarks prove meaningful value.

### J-11 — Filesystem streaming stays inside Webrick writer contract

Never write directly to `php://output` inside Webrick response producers.

- local files: prefer Webrick FileBody/download/inline/ranged/stream-download APIs;
- non-local/custom Pathwise: BodyStream or chunk-yielding iterable;
- Foundation/Pathwise authorization/policy still happens first;
- X-Sendfile/X-Accel-Redirect stays policy-driven;
- test SAPI plus persistent adapters.

### J-12 — One compiled web application per production process

Webrick freezes process-level registries at compiled-kernel boot. Treat a production process as hosting one compiled web application/release generation. Do not add normal production unfreeze/reset merely to run unrelated compiled web apps in one process. Use process isolation for such tests.

### J-13 — Four runtime artifacts are one Foundation release generation

Webrick owns atomic publication of its web bundle; Foundation owns cross-runtime coherence.

```text
release/<generation>/
    foundation.php
    config.php                  optional
    capabilities.php            optional/useful only
    web/
        Webrick release manifest
        InterMix web artifact
        Webrick router artifact
    cli/
        InterMix artifact + metadata
    worker/
        InterMix artifact + metadata
    scheduler/
        InterMix artifact + metadata
    command/scheduler/worker maps  only when they remove measurable discovery
```

Build a new immutable generation, verify all four paths, then atomically switch one small trusted/read-only active-generation pointer. Any failure leaves previous generation active. Persistent workers replace gracefully onto the new generation. Old cleanup occurs outside hot paths.

### J-14 — Prevalidated loading requires a real trust boundary

Normal verified loading is default. Trusted/prevalidated loading is allowed only when expected digests/fingerprints come from immutable deployment metadata outside the writable artifact/cache trust boundary. Same policy for all four runtimes.

---

## 10. Final graph, provider and Application model

Foundation needs a small graph coordinator, not another DI framework.

```php
function foundationGraph(
    ContainerBuilder $builder,
    FoundationBuildContext $context,
): ContainerBuilder {
    // Foundation core
    // runtime-specific definitions
    // selected capabilities/providers
    // package contributions
    // web only: Webrick::contributeTo($builder, ...)

    return $builder;
}
```

`FoundationBuildContext` is immutable build data only, for example:

- normalized environment;
- runtime mode;
- paths;
- normalized config/capability selections;
- enabled modules/providers;
- release generation identity if needed.

It is **not** a service locator.

Provider lifecycle splits into:

1. graph contribution;
2. optional process-level boot side effects after runtime exists;
3. execution behavior inside request/job/command/scheduler scopes.

`Application` remains a thin application-facing façade/coordinator:

- no broad provider activation in normal production `make()`/`has()`;
- generated core services do not capture Application solely to resolve dependencies;
- prefer narrow constructor dependencies;
- production web native handling goes through Webrick RuntimeServer;
- `Application::handle(Request)` may remain as embedded/testing convenience.

---

## 11. Development lifecycle

### 11.1 Web development

```text
load/normalize config
 -> fresh ContainerBuilder('foundation.web')
 -> compose Foundation web graph
 -> Webrick::contributeTo(same builder)
 -> register build-safe aliases/descriptors
 -> development()
 -> RouterKernel::bootWithRegistrar(...)
 -> development Request handling
```

Development can use live route registration and diagnostics but must follow the same logical capability/graph decisions as production.

### 11.2 CLI/worker/scheduler development

Each runtime:

```text
fresh builder
 -> same Foundation graph function with runtime context
 -> development()
 -> runtime-specific execution
```

Never reuse one mutable builder across independently active runtimes.

---

## 12. Production build lifecycle

### 12.1 Web

With Webrick 5.2 route-first graph coordination:

```text
normalized build context
 -> fresh ContainerBuilder('foundation.web')
 -> Foundation web graph
 -> Webrick::contributeTo(same builder)
 -> deterministic middleware aliases/descriptors
 -> compile routes once
 -> RouterBuildResult / ExecutionPlans
 -> enrich builder with deterministic route-referenced DI definitions
 -> strict InterMix validation
 -> compile web InterMix artifact ONCE
 -> compile Webrick router artifact
 -> publish Webrick format-2 release manifest
 -> enforce skipped allowlist
 -> verify web bundle
```

No second Foundation web container compile.

### 12.2 CLI

```text
fresh ContainerBuilder('foundation.cli')
 -> FoundationGraph(cli)
 -> validate(strict: true)
 -> compile(cli artifact)
 -> enforce skipped allowlist
```

### 12.3 Worker

```text
fresh ContainerBuilder('foundation.worker')
 -> FoundationGraph(worker)
 -> validate(strict: true)
 -> compile(worker artifact)
 -> enforce skipped allowlist
```

Only selected worker/messaging capabilities belong in this graph.

### 12.4 Scheduler

```text
fresh ContainerBuilder('foundation.scheduler')
 -> FoundationGraph(scheduler)
 -> validate(strict: true)
 -> compile(scheduler artifact)
 -> enforce skipped allowlist
```

Only scheduler/command/dispatch capabilities belong here.

---

## 13. Production runtime lifecycle

### 13.1 Web

```text
load active Foundation generation
 -> load Webrick release metadata
 -> reconstruct same web builder graph
 -> verified ProductionContainer
      or trusted productionPrevalidated(...)
 -> verified/prevalidated CompiledRouterKernel
 -> select RuntimeAdapter once
 -> RuntimeServer once
 -> serve traffic
```

Per request:

```text
RuntimeAdapter context
 -> RoutingInput
 -> compiled match
 -> ExecutionPlan
 -> Request only if required
 -> webrick.request scope only if required
 -> middleware/handler
 -> Response
 -> RuntimeAdapter write
```

### 13.2 CLI

```text
load active generation
 -> reconstruct CLI graph
 -> ProductionContainer
 -> foundation.cli scope per invocation when needed
 -> deterministic cleanup
```

### 13.3 Worker

```text
load active generation once per worker process
 -> reconstruct worker graph
 -> ProductionContainer reused
 -> foundation.worker scope per job/message
 -> seed envelope/job/execution values
 -> deterministic cleanup success/failure/cancel
 -> graceful replacement on generation change
```

### 13.4 Scheduler

```text
load active generation once per scheduler process
 -> reconstruct scheduler graph
 -> ProductionContainer reused safely
 -> foundation.scheduler scope per invocation
 -> deterministic cleanup
```

---

## 14. Required Webrick lower-layer corrections

These are provided by Webrick 5.2 rather than worked around in Foundation.

### WB-1 — Parameterized runtime-backed middleware descriptor

Artifact-safe resolver spec + exportable parameters, supported end-to-end by alias resolution, handler normalization/capability logic, artifact codec and compiled pipeline invocation.

### WB-2 — Separate routing-control errors from application exception handling

Allow custom application exception mapping/logging without automatically losing direct default 404/405 handling. Custom routing-error rendering becomes explicit opt-in.

### WB-3 — Stable request scope label

Use `webrick.request` in development and production. Rely on InterMix execution-context isolation for concurrency; request identity is seed/context data.

### WB-4 — Route-first graph-enrichment point

Expose finalized RouterBuildResult/execution descriptors before InterMix compile so hosts can add deterministic route-referenced controller/middleware definitions without repeating route discovery.

### WB-5 — Optional pre-routing gate, benchmark-driven only

Potentially useful for maintenance or another universal operational gate, but not a Foundation correctness prerequisite. The Phase 6 compiled-runtime microbenchmark crossed the review threshold and therefore justifies a Webrick-owned WB-5 design/representative-benchmark follow-up. Foundation does not add a competing pre-routing abstraction or lower-layer workaround.

WB-1 through WB-4 are available in Webrick 5.2. Foundation's current floor is Webrick 5.3 for the later SAPI/streaming corrections used by the final runtime path.

---

## 15. Foundation class/subsystem disposition

### Remove/replace

- ContainerFactory as runtime DI factory;
- old ContainerCacheManager compile/activate semantics;
- production WebrickRouterFactory;
- independent production RouteCacheManager/RouteCachePath orchestration;
- universal Foundation HttpKernel execution scope;
- broad production `onMissing()` provider activation;
- runtime provider/module discovery in normal production resolution;
- direct `php://output` streaming producers.

### Keep but redesign

- Application — façade/coordinator, no graph ownership;
- ExecutionScope — non-web helper using InterMix `withinScope()` + semantic labels;
- RuntimeContextTracker — execution-local redesign or removal;
- provider infrastructure — builder-first graph contribution;
- ServiceRegistry/Bootstrapper — build-time topology instead of lazy production activation;
- RouteFileLoader/attribute scanning — dev/build only;
- route presets/OAuth registration — dev/build policy;
- Foundation middleware — Webrick Request/Response contracts, DI-safe descriptors;
- filesystem HTTP integration — Foundation policy + Webrick body/writer contract;
- HttpTestClient — thin embedded Request path.

### Continue lower-layer ownership already correct

Use Webrick Request/Response, cookies, conditional/range handling where appropriate, middleware conventions, RuntimeCapabilities, URL generation and response writing rather than creating Foundation alternatives.

---

## 16. Runtime state/lifetime redesign checklist

Process singleton only when immutable or explicitly concurrency-safe, containing no execution state and no first-scoped dependency capture.

Scoped when state belongs to one request/job/command/scheduler invocation, must be shared inside that execution, or owns execution cleanup.

Transient for cheap/stateful one-use objects.

Mandatory review targets:

- principal/auth context;
- active session context;
- DB transaction/runtime state;
- memoizers;
- logging correlation/context;
- cache locks;
- communication clients;
- messaging current envelope/message state;
- notification state;
- filesystem temp/upload state;
- WebAuthn/OTP request state;
- static registries/facades.

---

## 17. Release-generation manifest and trust

Foundation may publish a small OPcache-friendly generation descriptor, conceptually:

```php
return [
    'format' => 1,
    'generation' => 'immutable-generation-id',
    'environment' => 'production',
    'config_fingerprint' => 'host-defined-deterministic-identity',

    'web' => [
        'release_manifest' => 'web/release.json',
    ],

    'cli' => [
        'intermix_path' => 'cli/container.php',
        'digest' => '...',
    ],

    'worker' => [
        'intermix_path' => 'worker/container.php',
        'digest' => '...',
    ],

    'scheduler' => [
        'intermix_path' => 'scheduler/container.php',
        'digest' => '...',
    ],
];
```

Rules:

- reference Webrick's release manifest rather than duplicating Webrick-owned digest/fingerprint fields;
- generation-relative paths where practical;
- one active generation pointer;
- no request/job scans directories to discover active artifacts;
- publish only after every runtime artifact/report validates;
- rollback/incomplete builds leave previous generation active;
- trusted/prevalidated mode requires an immutable trust source external to writable artifact storage.

### 17.1 Canonical Foundation hashing policy

Foundation 3 chooses the hash primitive by semantic requirement rather than using a cryptographic hash everywhere:

- Foundation-owned **security-sensitive** hash derivation uses **SHA3-256** when collision resistance is part of the security boundary or attacker-influenced logical identities must not be able to alias the same security state. Replay, nonce, challenge and authentication-state physical-key derivation are examples.
- Foundation-owned **non-security** deterministic fingerprints, freshness identities, structural identities and key compaction use **XXH128**.
- Do not introduce new Foundation-owned SHA-256 hashing. Existing Foundation-owned SHA-256 sites are migration targets: classify the purpose and replace them with SHA3-256 or XXH128.
- Use explicit domain separation before security-sensitive hashing whenever the same primitive serves multiple Foundation namespaces.
- For CacheLayer security-state physical keys, use the complete SHA3-256 digest and an encoding that satisfies CacheLayer's key grammar/length limit. Unpadded Base64URL is preferred when a readable legal prefix must fit within the limit; do not truncate the digest merely to make room for a prefix.
- Lower-layer, protocol, interoperability and persisted-format digest choices remain owned by their defining contracts. Foundation must not silently replace InterMix/Webrick/third-party digest formats.
- SHA3-256/XXH128 selection does not replace MACs, signatures, encryption or KDFs; those remain with Epicrypt or the owning lower layer.

---

## 18. Testing matrix

### 18.1 InterMix graph parity across all four runtimes

Test development vs generated production observable behavior for:

- singleton/scoped/transient;
- aliases/contextual/environment bindings;
- tags where used;
- lifecycle hooks;
- scope seeds;
- intentional dynamic islands;
- optional capability graphs;
- mutation/stale artifact rejection;
- verified and trusted-prevalidated loading.

### 18.2 Scope/concurrency/isolation

Required:

- same semantic scope label sequentially yields fresh scoped state;
- concurrent Fibers with same semantic label remain isolated;
- Swoole/OpenSwoole coroutine isolation when available;
- nested scopes restore parent;
- seeds disappear after leave;
- cleanup on success and exception;
- cleanup failure does not mask primary failure;
- no principal/session/DB/memoizer/message leakage;
- no singleton retains first scoped service;
- long-running workers do not retain scoped instances.

### 18.3 Webrick execution-plan coverage

Representative routes:

1. zero-arg direct Request-free/scope-free;
2. route-arg direct Request-free/scope-free;
3. Request-only handler;
4. controller DI handler;
5. one DI middleware;
6. parameterized DI middleware;
7. session/CSRF route;
8. auth route;
9. global middleware opt-in;
10. 404/405/OPTIONS;
11. application exception;
12. domain/signed-URL/URL-generation cases.

Assert Request, SCOPE and MIDDLEWARE capabilities explicitly.

### 18.4 Webrick development vs compiled production tests

Replace old live-router tests with separate suites.

Development verifies route files/facade registration, mutable dev aliases, diagnostics and embedded fake Requests.

Compiled production verifies:

- release compiler output;
- PHP runtime manifest preference;
- verified load;
- environment/config mismatch failures;
- wrong InterMix/Webrick digest/fingerprint failures;
- exact trusted prevalidated loading;
- matcher cached boot behavior;
- frozen registries;
- production route mutation failure.

### 18.5 Release/artifact correctness

Required failure cases:

- missing artifact;
- wrong InterMix digest;
- wrong Webrick digest/fingerprint;
- config/environment mismatch;
- stale graph after mutation;
- unexpected skipped definition;
- partial Foundation generation;
- active pointer to incomplete release;
- mutable/untrusted prevalidation metadata.

### 18.6 Runtime adapters

At minimum:

- SAPI/FPM integration;
- one persistent adapter path in CI/integration;
- RoadRunner/Swoole/Workerman suites as environments permit;
- RuntimeCapabilities propagation;
- lazy Request materialization;
- native streaming/file behavior;
- transport-native compression/request-limit bypass;
- repeated request isolation.

### 18.7 Maintenance/filesystem tests

Maintenance:

- refresh caching;
- enable/disable transition;
- message/retry metadata;
- no state leakage in long-running workers;
- no per-request unchanged-state disk read.

Filesystem:

- local file;
- range;
- HEAD;
- 304/412 conditional behavior;
- inline/attachment;
- X-Sendfile/X-Accel;
- streaming;
- persistent-adapter compatibility;
- no direct SAPI side effects from portable code.

### 18.8 Non-web persistent execution

Worker/scheduler tests run hundreds/thousands of sequential executions plus concurrency where supported, checking bounded memory, no transaction/context carryover, released locks/temp resources, cancellation/error cleanup and generation replacement.

---

## 19. Benchmark plan

### DI attribution

Compare:

1. direct InterMix 10.0.4 development graph;
2. direct InterMix generated production graph;
3. equivalent Foundation runtime graph.

Measure:

- builder composition;
- strict validation;
- compile time as build-only metric;
- verified production load;
- trusted-prevalidated load;
- singleton/scoped/transient get;
- `withinScope()` enter/leave;
- compiled constructor/static-factory chains;
- compiled↔dynamic bridge;
- Application façade resolution overhead;
- CLI/worker scope/seed overhead;
- repeated-scope memory;
- Fiber-interleaved isolation overhead.

Primary DI attribution:

```text
Foundation DI tax
= Foundation resolution/scope cost
- equivalent direct InterMix production cost
```

### HTTP attribution

Compare:

1. raw PHP;
2. standalone Webrick 5.3 compiled endpoint;
3. Foundation 3 + Webrick 5.3 compiled endpoint;
4. minimal InfByte endpoint.

```text
Foundation HTTP tax
= Foundation compiled minimal request
- standalone Webrick compiled minimal request
```

Measure cold boot, warm persistent requests, throughput, p50/p95/p99, memory/peak, RoutingInput, matching, ExecutionPlan lookup, Request materialization, scope, first/warm middleware pipeline, handler dispatch, 404/405, exception path, response write, maintenance enabled/disabled, local/ranged/streaming response.

Repeat representative measurements through the real Apache/Nginx + PHP-FPM + OPcache environment used by InfByte benchmarks; in-process microbenchmarks alone are not acceptance evidence.

No arbitrary percentage budget before new baselines exist.

---

## 20. Implementation order

### Phase 0 — Freeze baselines

- direct InterMix 10.0.3 DI benchmarks;
- standalone Webrick 5.1 compiled HTTP benchmarks;
- current Foundation representative benchmark;
- pin exact source/tag commits in benchmark metadata;
- capture semantic tests that must remain correct.

### Phase 1 — Webrick prerequisites

Implement/test WB-1 through WB-4. WB-5 only if measured. Release/tag Webrick and raise Foundation minimum to the first version carrying required contracts.

### Phase 2 — Foundation composition root

- dependency floors;
- immutable build context;
- one builder-first Foundation graph;
- deterministic four-runtime aliases;
- remove ContainerFactory architecture;
- Application runtime-neutral/thin.

### Phase 3 — Provider graph migration

- builder-first provider contract;
- deterministic recipes/aliases;
- remove `$app->make()` constructor factories;
- classify compileability/lifetime for every binding;
- build-time capability topology;
- remove broad production onMissing activation;
- migrate simple providers first, then auth/messaging.

### Phase 4 — Runtime state/scope redesign

- stable semantic non-web scopes;
- stable Webrick request scope;
- redesign/remove RuntimeContextTracker singleton state;
- principal/session/DB execution state moves to scoped ownership;
- cleanup hooks/tests;
- Fiber/coroutine/persistent isolation proof.

### Phase 5 — Webrick build/runtime integration

- remove production WebrickRouterFactory;
- route-first coordinated build;
- DI enrichment from RouterBuildResult;
- artifact-safe middleware descriptors;
- explicitly empty global arrays/tags by default;
- CompiledRouterKernel + RuntimeAdapter + RuntimeServer;
- frozen URL/runtime registries;
- embedded Request path separate.

### Phase 6 — Error/maintenance/filesystem cleanup

- direct routing-control vs app-exception split;
- maintenance out of Foundation outer kernel;
- runtime-compatible file/stream responses;
- RuntimeCapabilities integration;
- no duplicate response emission.

### Phase 7 — Non-web generated runtimes

- CLI artifact/build/load;
- worker artifact/build/load/reuse;
- scheduler artifact/build/load/reuse;
- command/job/schedule topology artifacts only when they remove measurable discovery;
- no graph rebuild per worker/scheduler item.

### Phase 8 — Unified Foundation release generation

- generation directory;
- build/verify all four artifacts;
- skipped-definition gates;
- generation manifest;
- atomic activation pointer;
- rollback/incomplete behavior;
- persistent-runtime graceful replacement.

### Phase 9 — Full regression/performance pass

- correctness/security/static analysis;
- runtime isolation;
- real HTTP comparison;
- four-runtime DI/runtime benchmarks;
- Webrick stage profiling only where measured overhead remains;
- optimize only attributable Foundation cost.

### Phase 10 — Final rescan/release readiness

Rescan every class/function for:

- old InterMix dynamic container mutation;
- compileTo/useCompiled/usePrevalidated resolver-map production calls;
- closure aliases/constructor factories;
- Application/container capture;
- unexpected dynamic islands;
- duplicate scopes;
- mutable singleton execution state;
- Fiber/coroutine leakage;
- production route/provider/module discovery;
- live Registrar/Collection production use;
- route-cache duplication;
- unnecessary Request creation;
- runtime filesystem scans;
- direct output/emission;
- repeated hashing/manifest parsing;
- Foundation-owned SHA-256 that should be SHA3-256 or XXH128 under section 17.1;
- hidden DB/cache activation;
- cleanup masking primary exceptions;
- stale old docs/tests/config.

---

## 21. Detailed migration batches

### InterMix batches

**IM-1 — Composition root/core**

- InterMix `^10.0.4`;
- builder-first composition;
- deterministic aliases;
- runtime/build context;
- compile-friendly ConfigRepository core construction;
- RuntimeMode metadata;
- Application façade boundary;
- remove/replace ContainerFactory.

**IM-2 — Provider contract**

- builder-first ServiceProviderInterface;
- compile-friendly helpers;
- split graph contribution from boot side effects;
- migrate PathServiceProvider/simple providers first;
- explicit-binding checks through builder definitions.

**IM-3 — Bootstrap/capabilities**

- service→provider discovery becomes build topology;
- reshape ServiceRegistry;
- stop broad production onMissing;
- explicit optional capability absence.

**IM-4 — Core providers**

JSON dispatch, logging, security, filesystem, cache, database, validation, communication, notifications and session: classify every binding, replace closure aliases/factories, review lifetime, record remaining islands.

**IM-5 — Auth graph**

Migrate AuthServiceProvider, AuthOtpServiceProvider, AbstractAuthRegistrar and all registrars together.

**IM-6 — Messaging/worker graph**

MessagingServiceProvider, InterMixExecutionScope→withinScope, deterministic Foundation-owned handler/listener topology; Omnibus-specific choices later.

**IM-7 — Routing/HTTP InterMix boundary**

Remove dynamic Container from Webrick boundary, place controller/middleware/service definitions before runtime creation and ensure HTTP services resolve from ProductionContainer.

**IM-8 — Optimize/artifacts**

Retire resolver-map ContainerCacheManager, build all runtime artifacts, add skipped gate, publish digest metadata, strict production load.

**IM-9 — Tests/bench/rescan**

Parity, scopes/concurrency, artifact/mutation, compile reports, direct InterMix scan, remove obsolete InterMix 9 config/tests/docs.

### Webrick batches

**WB-M1 — Dependency/boot split**

- target released Webrick carrying WB-1..WB-4;
- explicit development vs compiled production boot;
- same host-owned InterMix builder.

**WB-M2 — Build/release compiler**

- replace old production RouteCacheManager/container compilation for web;
- one deterministic route-registration pass;
- one config fingerprint;
- exact format-2 manifest handling.

**WB-M3 — Production kernel/runtime adapter**

- load CompiledRouterKernel;
- select SAPI/RoadRunner/Swoole/Workerman adapter once;
- RuntimeServer;
- embedded `$app->handle(Request)` only as delegated convenience;
- Foundation native emission removed.

**WB-M4 — Remove old router runtime classes**

- delete/reduce WebrickRouterFactory;
- no live production Registrar/Collection;
- retire production RouteCachePath freshness logic;
- RouteFileLoader build/dev only;
- compiled URL runtime.

**WB-M5 — Middleware compilation**

- normalize built-in middleware config;
- global arrays/tags empty by default;
- lightweight aliases/descriptors;
- parameterized descriptor support;
- optional cache/DB capabilities cold when disabled.

**WB-M6 — Error/maintenance**

- direct routing errors vs app exceptions;
- remove old HttpKernel maintenance I/O;
- cached maintenance state or measured pre-routing gate.

**WB-M7 — Scope/cleanup**

- no Foundation outer web scope;
- request cleanup attached to Webrick-selected InterMix scope;
- principal/session/DB/log cleanup audit;
- Fiber/concurrent/persistent isolation.

**WB-M8 — HTTP specialist bridges**

- filesystem stream ownership;
- native Webrick file/range/conditional APIs;
- uploads;
- audit all SAPI/native-output assumptions.

**WB-M9 — Tests**

Release/kernel/artifact, execution-plan capability, runtime-adapter, error/maintenance/filesystem and dev/prod parity tests.

**WB-M10 — Benchmarks/rescan**

Standalone Webrick vs Foundation compiled, attribute Foundation-only overhead, full direct-Webrick rescan, remove stale 4.x/cache architecture.

---

## 22. Configuration cleanup

Review/remove obsolete runtime switches such as:

```text
router.cache
app.container.compiled_*
app.container.lazy_loading   # if no longer meaningful for generated production runtime
```

Keep settings that directly map to current Webrick behavior: matcher choice, route files, attribute routes, slash policy, URL/signed URL configuration, explicit middleware definitions/groups and runtime-specific options.

Add release/artifact paths only where Foundation owns path selection. Do not duplicate Webrick manifest fields as configuration.

---

## 23. Breaking-change policy

Foundation 3 is a major release. Preserve useful application APIs only when they do not preserve the old runtime architecture.

Likely intentional breaks:

- APIs guaranteeing mutable concrete InterMix development Container in production;
- providers mutating bindings from `boot()`;
- late provider activation semantics;
- old container-cache activation switches;
- live production route registration;
- mutable production Registrar/Collection assumptions;
- assumption that every HTTP request has a Foundation ExecutionScope;
- treating `Application::handle(Request)` as native production transport entrypoint;
- middleware alias factories depending on preconstructed service objects;
- dependence on old synthesized unique scope names.

Compatibility bridges may not force production deoptimization or restore universal request overhead.

---

## 24. Hard implementation gates

### InterMix gate

- one graph source of truth;
- four fresh runtime builders;
- generated ProductionContainer normal in production;
- no old resolver-map activation path;
- no broad production graph mutation;
- deterministic aliases/recipes compilation-safe;
- every lifetime reviewed;
- unexpected skipped definitions fail build;
- semantic scopes pass sequential/concurrent tests;
- dynamic islands documented/allowlisted;
- deoptimization not deployment strategy.

### Web gate

- WB-1 through WB-4 resolved in Webrick;
- web InterMix compiled exactly once;
- no production live RouterKernel/Registrar architecture;
- no Foundation universal HTTP scope;
- default plain route has no explicit or tagged global middleware;
- requestless/scopeless route remains requestless/scopeless;
- route-referenced classes visible to InterMix before compile;
- no serialized Foundation service graph in router artifact;
- parameterized middleware declarative;
- app exception handling does not unnecessarily destroy direct routing-control path;
- RuntimeAdapter exclusively owns native response write;
- persistent request state cleanup deterministic.

### Release-generation gate

- web/cli/worker/scheduler belong to one immutable generation;
- all four verified;
- all skipped reports accepted;
- generation manifest complete;
- atomic activation;
- previous generation remains usable until successful switch;
- prevalidated mode has real immutable trust source;
- persistent workers have safe replacement strategy.

---

## 25. Final definition of done

Foundation 3 runtime architecture is complete only when:

- InterMix is fully utilized across web, CLI, worker and scheduler;
- Webrick owns only web HTTP runtime responsibilities;
- ContainerBuilder is the sole DI graph builder;
- all runtimes receive fresh builders from the same composition source;
- web compilation uses Webrick coordinated path exactly once;
- non-web runtimes compile directly through InterMix;
- production graphs are finalized before execution;
- route topology and DI compile order prevent silent route-handler dynamic fallback;
- router artifacts contain descriptors/data, not hidden Foundation service graphs;
- Request and HTTP scope are created only when execution plan requires them;
- stable semantic scopes enable cleanup + concurrency isolation;
- no mutable singleton execution tracker leaks concurrent state;
- auth/session/DB execution state is isolated;
- Webrick adapters exclusively own native response writing;
- production route/provider/module discovery is absent from hot paths;
- optional integrations have no meaningful cost when absent/disabled;
- no silent artifact fallback;
- every dynamic island intentional/reported;
- all four runtime artifacts publish as one atomic generation;
- persistent runtimes do not leak state and replace safely on deploy;
- Foundation-owned hashing follows section 17.1: SHA3-256 for security-sensitive derivation and XXH128 for non-security fingerprinting/compaction;
- real HTTP and non-web benchmarks show attributable Foundation overhead;
- complete source tree rescanned after implementation;
- InfByte consumes Foundation's final build/runtime lifecycle directly instead of recreating another framework runtime.

---

## 26. Subsequent lower-library passes

After InterMix + Webrick implementation contracts are frozen, run the same dedicated current-version utilization process for each lower library. Every pass must distinguish lower-layer primitives from Foundation policy, measure any proposed hot-path change, update this same document, and leave its progress-tracker box unchecked until code/tests/benchmarks prove the pass complete.

### 26.1 ArrayKit 5.2.0 utilization pass

**Baseline**

* package: `infocyph/arraykit` `^5.2`;
* audited release: ArrayKit 5.2.0;
* tag commit: `053440b61071a17332b18879b12026f54a0ad144`.

**Ownership decision**

ArrayKit owns generic data/configuration primitives. Foundation must consume these directly rather than maintain parallel implementations.

ArrayKit owns:

* `.env` syntax parsing through `Config\EnvParser`;
* variable-reference/interpolation resolution and raw parsing modes;
* BOM/NUL safety validation for environment files/lines;
* raw environment access through `Config\Support\Environment` over `$_ENV`, non-HTTP `$_SERVER`, and `getenv()`;
* `EnvReference` deferred environment references;
* `Config` storage/access primitives;
* `LazyFileConfig` lazy namespace/file loading and its namespace-cache primitives;
* `DotNotation` path lookup/mutation behavior;
* generic array merge/path/config mechanics where their semantics match Foundation requirements.

Foundation owns only framework/application policy above those primitives:

* whether environment-file loading is enabled (`app.load_env`);
* the configured environment-file list and default `.env` / `.env.local` precedence;
* relative/absolute path resolution for application environment files;
* protection of already-present host/process environment variables;
* hydration of parsed values into `$_ENV`, `$_SERVER`, and `putenv()` when Foundation elects to load files;
* Foundation-specific typed environment conveniences (`env_bool`, `env_int`, `env_string`) only where their coercion semantics are intentionally part of the framework API;
* normalized application configuration, capability/module topology and validation policy;
* Foundation release-generation `config.php`, its immutable trust identity and production source-discovery boundary;
* deployment/diagnostic policy rather than low-level parsing/storage.

Foundation now follows this boundary directly: `EnvironmentLoader` uses ArrayKit `EnvParser`/`Environment` while retaining only Foundation file-order, host-protection and hydration policy; `ConfigRepository` remains the thin Foundation façade over ArrayKit `Config` and delegates layered lazy source/fallback/override resolution plus resilient namespace-cache behavior to ArrayKit 5.2 `LayeredLazyFileConfig`; `ConfigLoader` uses ArrayKit `ConfigMerge`; the duplicate Foundation `ConfigMerger` has been removed; and `ConfigExportValidator` remains Foundation-owned because release exportability is part of Foundation's immutable generation trust boundary rather than generic ArrayKit config storage.

**Audit and implementation checklist**

* [X] Rescan Foundation for any manual `.env` syntax parsing, interpolation, quoting, reference expansion, BOM/NUL handling or file-line parsing that duplicates `EnvParser`.
* [X] Rescan Foundation for direct/raw environment lookup that bypasses ArrayKit `Environment` without a documented runtime-boundary reason.
* [X] Verify Foundation's global `env()` helper delegates raw lookup to ArrayKit and retains only intentionally framework-specific value coercion.
* [X] Review `EnvironmentLoader` for policy-only responsibilities: source selection/precedence, host-value protection and process hydration; do not move those framework choices into ArrayKit.
* [X] Review `ConfigRepository` against ArrayKit `Config`, `DotNotation` and `LazyFileConfig`; remove duplicated generic get/set/path/cache behavior while retaining Foundation fallback/override/compiled-release semantics.
* [X] Review `ConfigLoader` so source file discovery remains development/build-plane only and delegates generic lazy file mechanics to ArrayKit where appropriate.
* [X] Review `ConfigCacheManager` against ArrayKit lazy namespace-cache facilities; retain only Foundation-owned artifact/deployment coordination that ArrayKit cannot correctly own.
* [X] Review `ConfigMerger` against ArrayKit merge primitives. Reuse ArrayKit only when precedence/list/associative semantics are exactly equivalent; do not trade correctness for API uniformity.
* [X] Verify the trusted generated `config.php` path constructs a compiled `ConfigRepository` without `.env`, config-directory, provider or module source discovery.
* [X] Ensure no request/job/schedule hot path scans environment/config files or reparses `.env`.
* [X] Audit `ConfigExportValidator`/release config normalization for generic ArrayKit functionality before retaining Foundation-local traversal logic.
* [X] Preserve a small Foundation façade only where it expresses application semantics or protects the release trust boundary.

**Correctness acceptance**

* [X] Test `.env` and `.env.local` precedence plus configured `app.env_files` order.
* [X] Test protection of host-provided `$_ENV`, `$_SERVER` and process variables from unintended file override.
* [X] Test ArrayKit interpolation/reference behavior through Foundation, including raw/literal-dollar cases where Foundation exposes them.
* [X] Test BOM/NUL rejection through the Foundation load path.
* [X] Test missing optional environment files and explicit environment-loading disablement.
* [X] Test lazy namespace fallback/source/override precedence and cache-corruption fallback behavior.
* [X] Test source config versus compiled release config observable parity.
* [X] Poison `.env`, config directories and provider/module source discovery after release compilation and prove production generation boot remains source-independent.

**Performance acceptance**

Benchmark at minimum:

1. source configuration composition from application files;
2. first lazy namespace lookup;
3. warm lazy namespace lookup;
4. full materialization when `all()` is required;
5. trusted compiled `config.php` load;
6. representative warm `ConfigRepository::get()`/dot-path access;
7. environment bootstrap with no env files, one env file and `.env` + `.env.local`.

Do not add another cache layer merely because ArrayKit exposes one. Adopt a lower-layer primitive only when it reduces duplication or measured cost without weakening Foundation's immutable production-generation model.

**Completion gate**

The ArrayKit tracker can be checked only when the full config/environment source tree has been rescanned, any duplicate generic behavior has been removed or explicitly justified, production remains source-discovery-free, correctness tests pass and benchmark evidence records the final boundary.

**ArrayKit 5.2.0 completion evidence:** Foundation requires `^5.2` and consumes the released `053440b61071a17332b18879b12026f54a0ad144` tag. `ConfigRepository` delegates layered lazy configuration to ArrayKit `LayeredLazyFileConfig`; `ConfigLoader` uses `ConfigMerge`; the local `ConfigMerger` and Foundation-owned cache-corruption reconstruction path are gone; raw `APP_CONFIG_CACHE` access uses ArrayKit `Environment`; and Foundation retains only application/release policy. `ArrayKit52ConfigIntegrationTest` covers list/scalar ancestor shadowing, fallback/source/override precedence, environment ordering/interpolation, process-only host protection, BOM/NUL rejection, optional files and corrupt-cache recovery, while existing release source-isolation tests prove generated production remains independent of `.env`, config/provider/module source discovery. `benchmarks/arraykit-config-utilization.php` records source composition, first/warm lazy lookup, full materialization, trusted compiled load, warm dot-path access and zero/one/two-file environment bootstrap. PHPForge `Security & Standards` run `34023093003` on implementation commit `76b80daca5d0f79268594862fe021422fe3eb12d` passed PHP 8.4/8.5 stable and prefer-lowest QA, PHPStan/Psalm, clean-install validation and the ArrayKit 5.2 benchmark contract on both PHP versions.

### 26.2 UID 5.0 utilization pass

**Baseline**

* package: `infocyph/uid` `^5.0`;
* audited release: UID 5.0;
* tag commit: `4a95eb8058e73c72e74e44fedd25755198899eae`.

UID 5.0 owns the generic identifier mechanics Foundation needs: UUIDs, ULID, TypeID/ObjectID, random/opaque identifiers, deterministic identifiers, validation/parsing/encoding, and algorithm-specific monotonic/fork-aware state. Foundation must not recreate those mechanics.

**Ownership decision**

UID owns identifier generation and generic identifier mechanics. Foundation owns identifier **meaning and lifecycle**:

* deciding whether a logical execution needs a correlation identity;
* choosing the UID algorithm according to that semantic boundary;
* preserving an authoritative incoming request/message/job/execution identity verbatim;
* generating at most one fallback execution ID per logical execution;
* propagating it through InterMix seeds, history, logging/events and nested command/message boundaries;
* keeping DI aliases and scope names deterministic/semantic rather than random;
* keeping release/artifact trust identities as cryptographic digests rather than random UIDs;
* keeping security secrets/nonces and pure staging/temp entropy with their owning security/native primitives.

Foundation centralizes generated non-web correlation identity in `Runtime\ExecutionId`. The final Foundation 3 fallback is UID's default monotonic ULID through `Id::ulid()`. The semantic wrapper remains Foundation-owned, and its public constructor continues accepting any non-empty externally supplied correlation string.

ULID is preferred here over UUIDv7 because this boundary needs a compact sortable correlation value rather than UUID interoperability: the generated representation is 26 characters, lexicographically sortable, monotonic and fork-aware without machine/sequence coordination. UUIDv7 remains a benchmark comparator and may still be selected by other Foundation domain-ID policies. Sonyflake/TBSL are not introduced for `ExecutionId` because their coordinated machine/sequence model is unnecessary for this correlation boundary.

**Current reuse findings to preserve**

* Omnibus-backed `InterMixExecutionScope` normalizes and reuses the message ID (`omnibus:<message-id>`) instead of generating a second Foundation ID.
* `WorkerRuntime` and `SchedulerRuntime` preserve a caller-supplied `ExecutionId` unchanged and generate a fallback only when none is supplied.
* scheduler execution creates one identity per scheduled entry and reuses it across scope/history/event lifecycle.
* nested command execution inherits the active `ExecutionId` instead of multiplying IDs.
* stable InterMix scope labels remain `foundation.cli`, `foundation.worker`, `foundation.scheduler`; execution IDs are correlation seeds, never scope names.
* the minimal Webrick path does not generate a Foundation execution ID merely to serve a request.
* release generation names and `.staging-*` suffixes remain Foundation/native build-plane formatting where generic UID semantics are not the correct abstraction.
* artifact/config/router/container trust remains digest/fingerprint based, and security randomness remains owned by its security subsystem.

**Audit and implementation checklist**

* [X] Inventory every Foundation identifier/randomness creation and classify it as execution correlation, domain/public identity, release-generation identity, artifact hash/fingerprint, lock/token/security randomness, or transient staging/temp suffix.
* [X] Route semantic ID generation through UID primitives; do not route hashes, cryptographic secrets, nonces or pure temp entropy through UID merely for consistency.
* [X] Verify every request/message/job/command/schedule boundary reuses an upstream identity when one is already authoritative.
* [X] Preserve Omnibus message-ID reuse in `InterMixExecutionScope`.
* [X] Preserve supplied worker execution IDs without normalization into a second generated value.
* [X] Preserve nested command execution-ID inheritance.
* [X] Verify scheduler creates exactly one execution ID per logical scheduled run and reuses it across scope/history/events.
* [X] Review generic `ExecutionScope::run()` fallback generation. Keep one eager ULID fallback for a logical non-web execution because the execution callback/history contract requires the identity and measurement does not justify a second lazy-generation mechanism.
* [X] Review release generation IDs separately. Timestamp + cryptographic entropy remains valid Foundation-owned build-plane formatting; transient `.staging-*` suffixes remain native entropy.
* [X] Ensure artifact/config/router/container trust continues to use digest/fingerprint identities, never UID randomness.
* [X] Rescan for accidental random IDs in DI aliases, provider IDs, scope names or other deterministic graph topology.
* [X] Review Foundation-owned public/persisted ID policies. Existing auth ID policy continues delegating UUIDv7/ULID generation to UID; no additional format migration is required for this pass.

**Correctness acceptance**

* [X] Test supplied execution-ID preservation byte-for-byte.
* [X] Test fallback generation uniqueness, 26-character ULID validity and monotonic lexical ordering for the default execution path.
* [X] Test nested execution/command reuse rather than ID multiplication.
* [X] Test Omnibus message-ID propagation into Foundation execution state.
* [X] Test scheduler scope/history/event identity equality for the same run, including `pending -> running -> succeeded`.
* [X] Test sequential and interleaved Fiber executions do not cross-contaminate IDs.
* [X] Test persistent worker reuse does not retain a previous job's identity.
* [X] Exercise UID ULID fork-state behavior where `pcntl_fork` is available and ensure Foundation adds no process-local wrapper state that defeats UID's fork reset.

**Performance acceptance**

The final attribution benchmark is `benchmarks/uid-runtime-utilization.php` / `composer benchmark:uid`. It records:

1. raw UID UUIDv7 generation as the previous-format comparator;
2. raw UID monotonic ULID generation;
3. Foundation `ExecutionId::generate()` ULID wrapper cost;
4. worker execution with caller-supplied identity;
5. worker execution with generated ULID fallback;
6. scheduler execution with caller-supplied identity;
7. scheduler execution with generated ULID fallback.

Nested command/message reuse remains a correctness/lifecycle contract rather than an invalid recursive `ExecutionScope` microbenchmark; InterMix correctly rejects duplicate entry of the same active scope.

**Completion gate — satisfied**

All Foundation ID/randomness sites are classified, upstream identity reuse is proven, generated execution fallback is finalized as UID monotonic ULID, release/security/temp randomness ownership is documented, isolation/persistent/fork tests pass, and the final Foundation-vs-UID attribution benchmark is recorded in `docs/plans/foundation-3-uid-5-utilization-evidence.md`.

**UID 5.0 completion evidence:** `Runtime\ExecutionId::generate()` delegates to `Id::ulid()` while arbitrary supplied correlation IDs remain byte-for-byte authoritative. `UidRuntimeBoundaryTest`, `ExecutionScopeIsolationTest`, `PersistentExecutionStateIsolationTest`, existing command/message lifecycle coverage and scheduler history coverage prove fallback uniqueness/ULID validity, supplied-ID preservation, Fiber/persistent isolation, Omnibus propagation, nested reuse, successful scheduler lifecycle reuse and fork safety. `benchmarks/uid-runtime-utilization.php` records the direct UID-versus-Foundation attribution boundary and emits `build/uid-5-runtime-benchmark.json`. PHPForge `Security & Standards` run `34027855290` on implementation commit `4bf85922b845510fa96105d8d2af9d8ec2a4a43c` passed PHP 8.4/8.5 stable and prefer-lowest QA, PHPStan/Psalm analysis, clean production install, UID benchmark execution and PHPForge benchmark-schema validation on both PHP versions. Section 26.2 is complete; no UID 5.0 library change is required.

### 26.3 CacheLayer 3.2.0 audit / 3.3.0 atomic-capability prerequisite

**Baseline**

* current Foundation package target: `infocyph/cachelayer` `^3.2.0`;
* audited release: CacheLayer 3.2.0;
* tag commit: `481c664e7431fb1f901346046e34b31beb722854`;
* required lower-layer follow-up: CacheLayer 3.3.0 additive atomic-capability release;
* Foundation raises its package floor to `^3.3` only after that release is available and verified.

**Ownership decision**

CacheLayer owns generic cache/storage mechanics and their correctness contracts. Foundation must compose them and add application/security policy rather than maintain competing cache, lock, conditional-write, atomic-consume, compare-and-set, counter, invalidation or cluster runtimes.

CacheLayer owns:

* PSR cache/simple-cache behavior and adapter implementation;
* key/namespace validation;
* per-cache serialization/compression/integrity policy through `CacheOptions`;
* native bulk cache operations;
* cache-native lock providers and lease handles;
* generic atomic cache capabilities and backend-specific correctness;
* atomic counter stores;
* tiering and memoization primitives;
* Node Cache and Cluster Cache invalidation/outbox behavior;
* `AuthenticationStateCacheInterface` and its security-capability contract;
* backend-specific fail-open/authoritative/integrity/coordination semantics.

Foundation owns:

* named application cache/store configuration;
* explicit runtime/capability activation;
* store selection per subsystem;
* application-level shared-state topology validation;
* security policy deciding which cache may hold authentication state;
* logical-to-physical key encoding where Foundation owns the logical namespace;
* the SHA3-256-versus-XXH128 choice under section 17.1;
* DI lifetime and release-generation participation;
* deciding whether a subsystem may use generic cache semantics, authoritative security-state semantics, or a durable non-cache store.

Generic caches remain flexible. Do **not** globally force every Foundation cache to be fail-closed, authoritative, signed or object-free merely because authentication state requires stricter semantics.

**Required CacheLayer 3.3.0 atomic capability**

Prefer one coherent optional `AtomicCacheInterface` (or equivalent) exposing:

```php
interface AtomicCacheInterface
{
    public function setIfAbsent(
        string $key,
        mixed $value,
        null|int|\DateInterval|\DateTimeInterface $ttl = null,
    ): bool;

    public function getAndDelete(
        string $key,
        mixed $default = null,
    ): mixed;

    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $replacement,
        null|int|\DateInterval|\DateTimeInterface $ttl = null,
    ): bool;
}
```

Semantics:

* `setIfAbsent()` inserts only when absent and applies TTL as part of the same atomic operation; exactly one contender wins.
* `getAndDelete()` reads/consumes one value atomically; concurrent consumers cannot both receive it.
* `compareAndSet()` updates only when the current value matches the expected value under one atomic backend operation/transaction.
* Do not advertise fake atomicity through `has()+set()`, `get()+delete()` or `get()+compare+set()` fallbacks.
* Use backend-native primitives or a lower-layer coordination mechanism with equivalent documented semantics. An adapter that cannot satisfy the contract must not claim the capability.
* Capability discovery belongs in CacheLayer; Foundation must not branch on concrete adapter classes.
* Keep `AuthenticationStateCacheInterface`; it adds fail-open/integrity/authoritative/security semantics beyond generic atomicity.
* Keep increment/decrement with the existing atomic-counter contract unless another real consumer proves a broader abstraction is needed.

**Confirmed current Foundation findings**

1. Foundation logical cache/auth/webhook identifiers can violate CacheLayer 3.2.0's physical key grammar/length. Foundation therefore needs one canonical Foundation-owned logical-to-physical key encoder rather than raw colon-prefixed keys.
2. Security-sensitive physical-key derivation follows section 17.1: use explicit domain separation + **SHA3-256**, never SHA-256. Preserve the complete digest; unpadded Base64URL is preferred where it permits the full digest plus a short legal prefix to fit CacheLayer's key limit.
3. Non-security cache-key compaction/fingerprinting uses **XXH128** instead of paying for cryptographic hashing without a cryptographic requirement.
4. `CacheLayerTtlStore::pull()` currently performs `get()` followed by `delete()` and is race-prone for one-time state. After CacheLayer 3.3 it should use `getAndDelete()`.
5. `CacheLayerWebhookReplayStore` currently composes a generic lock around `has()` + `set()` to manufacture an atomic claim and uses SHA-256 for key hashing. After CacheLayer 3.3 it should use `setIfAbsent()` directly, remove the replay-store lock dependency, and derive the physical replay key with domain-separated SHA3-256.
6. A generic Foundation lock and a configured cache can use different coordination authorities. Foundation must not imply cache atomicity merely because a lock surrounded non-atomic cache calls. CacheLayer owns the atomicity guarantee and capability truth.
7. Production auth must prove the selected auth-state cache satisfies `AuthenticationStateCacheInterface` and any required generic atomic capability.
8. Hardened auth-state caches should not require native object payloads merely because Foundation currently stores an `MfaChallenge` object; prefer scalar/array records plus rehydration where practical.
9. Native CacheLayer counters, locks, Node/Cluster cache and invalidation/outbox remain lower-layer-owned.
10. Do not add a generic key encoder/namespace abstraction to CacheLayer merely for Foundation; CacheLayer's restrictive physical-key contract remains deliberate and Foundation adapts its richer logical identities at its boundary.

**Canonical Foundation cache-key policy**

For Foundation-owned security state, use a stable semantic domain and hash the domain-separated logical identity with SHA3-256. A conceptual implementation is:

```php
$input = $domain . "\0" . $logicalKey;
$digest = hash('sha3-256', $input, true);
$encoded = rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
$physicalKey = $shortLegalPrefix . '.' . $encoded;
```

Rules:

* `$domain` participates in the digest input; the readable prefix is only an operational hint.
* preserve the complete SHA3-256 digest; do not truncate it merely for naming convenience.
* neither logical keys nor security secrets should leak into security-state physical keys.
* non-security equivalent helpers use XXH128 according to section 17.1.
* final keys must satisfy the currently supported CacheLayer grammar and length contract.

**Audit and implementation checklist**

* [ ] Implement/release CacheLayer 3.3.0 with true optional atomic `setIfAbsent()`, `getAndDelete()` and `compareAndSet()` capability.
* [ ] Add adapter conformance, TTL, contention and failure-path tests; do not expose fake read-then-write atomic fallbacks.
* [ ] Raise Foundation's CacheLayer floor to `^3.3` only after the release and adapter matrix are verified.
* [ ] Introduce one canonical Foundation physical-key encoder for Foundation-owned cache namespaces where the semantics match.
* [ ] Use domain-separated SHA3-256 for security-state key derivation and XXH128 for non-security compaction/fingerprinting.
* [ ] Remove Foundation-owned SHA-256 from these key paths.
* [ ] Preserve distinct semantic domains for TTL state, counters, webhook replay, WebAuthn challenge and other security state.
* [ ] Require production auth-state caches to satisfy `AuthenticationStateCacheInterface` with fail-closed, payload-integrity and authoritative semantics.
* [ ] Require generic atomic capability wherever Foundation needs atomic consume/claim/CAS semantics.
* [ ] Fail insecure/non-atomic auth or replay topology during composition/release validation, not first request.
* [ ] Replace `CacheLayerTtlStore::pull()` read+delete with `getAndDelete()`.
* [ ] Replace `CacheLayerWebhookReplayStore` lock+has+set with `setIfAbsent()` and remove its generic lock dependency.
* [ ] Use generic CAS only where Foundation has genuine CAS semantics; retain specialized domain contracts where they add stronger meaning.
* [ ] Require `AtomicCounterStoreInterface` for production security counters where atomicity matters.
* [ ] Prefer scalar/array MFA challenge records so hardened auth caches can keep `allowObjects=false`.
* [ ] Keep optional CacheLayer activation explicit and cold when unused.

**Correctness and security acceptance**

* [ ] Test SHA3-256 security physical-key derivation is deterministic, domain-separated, legal and within the CacheLayer key limit for long/attacker-controlled logical keys.
* [ ] Test distinct security domains cannot alias the same physical key for the same logical input.
* [ ] Test XXH128 non-security paths separately and prove they are not used for security-sensitive alias resistance.
* [ ] Test production auth-cache rejection for fail-open, unsigned, non-authoritative and missing-required-atomic-capability profiles.
* [ ] Test concurrent `setIfAbsent()` replay claims so exactly one execution wins.
* [ ] Test concurrent `getAndDelete()` one-time consumption so at most one execution receives the value.
* [ ] Test `compareAndSet()` contention/stale expected values.
* [ ] Test TTL belongs to the atomic insertion/update and cannot leave an unintended immortal value after partial failure.
* [ ] Test hardened challenge serialization with native object payloads disabled.
* [ ] Test persistent/Fiber execution does not leak cache coordination state.
* [ ] Add a final source scan proving no Foundation-owned SHA-256 remains except explicit protocol/persisted/lower-layer compatibility references.

**Performance acceptance**

Benchmark at minimum:

1. CacheLayer capability absent versus present-but-unused;
2. first named-store construction and warm lookup;
3. direct CacheLayer get/set/delete versus Foundation boundary;
4. native CacheLayer bulk operations versus previous Foundation loops;
5. SHA3-256 security-key encoding;
6. XXH128 non-security key/fingerprint encoding;
7. direct `setIfAbsent()` versus Foundation lock+has+set replay claim;
8. direct `getAndDelete()` versus prior pull path;
9. `compareAndSet()` and atomic counters;
10. persistent worker/scheduler cache operations with memory measurement.

Do not optimize away CacheLayer security checks. Prefer XXH128 over SHA3-256 only when the hash is not carrying a cryptographic restriction/security-aliasing requirement.

**Completion gate**

The CacheLayer tracker can be checked only when CacheLayer 3.3's atomic capability is released and consumed, Foundation physical keys follow the SHA3-256/XXH128 policy, selected production auth state satisfies CacheLayer security + atomic contracts, replay/one-time state/counters have proven concurrency semantics, the Foundation replay lock workaround is removed, optional-capability cost remains cold, and attribution benchmarks record the final boundary.

### 26.4 OTP 6.0 utilization pass

**Baseline**

* package: `infocyph/otp` `^6.0` (Foundation development/integration dependency);
* audited release: OTP 6.0;
* tag commit: `524a94d7ac71d5d385f35596a89c472c8e1ba33f`;
* OTP 6.0 requires PHP >=8.4 and integrates with CacheLayer `^3.1.1`; Foundation's current CacheLayer floor is newer at `^3.2.0` and will move to `^3.3` after section 26.3's atomic-capability prerequisite is released.

**Ownership decision**

OTP owns OTP algorithms and their algorithm-specific security mechanics. Foundation owns application auth policy, persistence and capability composition around them.

OTP owns:

* TOTP/HOTP/OCRA algorithm implementation;
* verification windows, periods/counters and result objects;
* provisioning URI/enrollment payload primitives;
* replay-protection mechanics exposed by OTP over a secure CacheLayer authentication-state cache;
* recovery-code generation/verification primitives and usage-store contract;
* secret-rotation planning/result primitives;
* GenericOtp one-time-code behavior when a true generic-code workflow is required;
* OTP-specific encoding/parsing/validation details.

Foundation owns:

* whether OTP MFA is enabled and which OTP mode is selected;
* normalized auth configuration and build-time validation;
* factor persistence and authoritative compare-and-swap semantics;
* selection/validation of the CacheLayer authentication-state backend;
* challenge/session/application lifecycle around OTP verification;
* operational logging/metrics and safe external error mapping;
* protection/rotation lifecycle of persisted MFA secrets;
* authorization/account policy after successful verification;
* release/runtime capability activation so applications not using OTP pay no meaningful cost.

Foundation must not reimplement TOTP/HOTP/OCRA math, time-window scanning, provisioning URI logic, recovery-code algorithms or OTP replay-key algorithms.

**Current integration findings to preserve**

* `AuthOtpServiceProvider` contributes OTP services only when the explicit OTP capability is present.
* `OtpMfaVerifier` correctly uses OTP's cache-backed replay protection for TOTP and challenge/time OCRA.
* HOTP disables redundant cache replay state and advances the authoritative factor counter through compare-and-swap; counter-based OCRA follows the same ownership. This split is correct and must be preserved.
* `OtpRecoveryCodeStore` adapts OTP recovery-code usage state onto Foundation factor-store compare-and-swap rather than inventing a second recovery-code state database.
* `OtpProvisioningService` already consumes OTP's TOTP/HOTP/OCRA/enrollment/recovery/rotation primitives instead of duplicating the algorithms.
* `OtpConfigValidator` and `SharedStateTopology` already push important OTP/topology mistakes toward build/config validation rather than request-time surprises.

**Confirmed current issues / required audits**

1. `OtpMfaVerifier` catches broad `Throwable` failures and maps them to the generic external reason `invalid_configuration`. This is fail-closed but destroys the operational distinction between bad user input/configuration and unavailable security state, lock failure, cache/backend outage, CAS failure or an unexpected OTP runtime error.
2. Production TOTP and non-counter OCRA depend on CacheLayer's authoritative/fail-closed/integrity/atomic security-state contract. That contract must become a release/composition gate through the CacheLayer pass rather than depending on first-use verification failure.
3. `MfaFactor` carries the OTP secret as a string value. That does not prove plaintext persistence, but every production factor-store adapter must be audited to prove MFA secrets are protected at rest and never exposed through logs/cache keys/errors. Exact cryptographic/key-lifecycle design remains coordinated with the Epicrypt pass.
4. Recovery-code HMAC currently derives a domain-separated key from the auth token secret. Keep the domain separation, but explicitly decide whether Foundation 3 should use a dedicated recovery-code key or a proper subkey derivation from a master secret; finalize this with the Epicrypt pass rather than silently coupling unrelated secret lifecycles.
5. OTP rotation primitives can plan/describe secret rotation, but Foundation still owns atomic persistence/activation and concurrent verification policy during a rotation window.
6. MFA challenge storage intersects the CacheLayer pass: logical challenge keys require the canonical Foundation security-state encoder using domain-separated SHA3-256, and a hardened auth-state cache should not require native object payloads merely because Foundation currently stores an object.

**Audit and implementation checklist**

* [ ] Keep OTP graph activation explicit and absent when `auth.drivers.mfa` does not select OTP/TOTP/HOTP/OCRA behavior.
* [ ] Make secure CacheLayer authentication-state capability a build/release prerequisite for OTP modes that need replay/challenge state.
* [ ] Preserve TOTP and non-counter OCRA replay ownership in OTP + CacheLayer; do not add duplicate Foundation replay bookkeeping.
* [ ] Preserve HOTP and counter-OCRA monotonic state in the authoritative Foundation factor store with atomic compare-and-swap; do not maintain a second cache counter for the same semantic counter.
* [ ] Verify every production `MfaFactorCompareAndSwapStoreInterface` implementation provides authoritative reads and truly atomic CAS under concurrency.
* [ ] Keep OTP `RecoveryCodes` as the recovery-code algorithm/usage primitive and prove Foundation's adapter satisfies OTP's authoritative committed-count + atomic consumption contract.
* [ ] Replace broad `catch (Throwable)` classification with a safe failure taxonomy that distinguishes invalid code/replay/configuration from security-state backend unavailable/lock failure/CAS exhaustion/unexpected internal failure for logs/metrics, while still returning a non-sensitive external authentication failure.
* [ ] Ensure backend/lock failures stay fail-closed and are never converted into successful or retry-unbounded verification.
* [ ] Audit every production MFA factor-store adapter for secret-at-rest protection, read/write rotation behavior and accidental secret disclosure. Do not assume the value object's string property describes storage format.
* [ ] Keep OTP secrets out of cache keys, exception messages, logs, metrics labels and generated runtime artifacts.
* [ ] Decide the recovery-code HMAC key lifecycle explicitly; keep domain separation and coordinate final cryptographic derivation with Epicrypt.
* [ ] Use OTP's rotation planner/result types for OTP-specific rotation rules while Foundation owns durable CAS/transactional activation and account-policy transitions.
* [ ] Prove concurrent secret rotation cannot lose a newer factor state or accept an unintended stale counter/recovery-code state.
* [ ] Use OTP clock/window semantics directly; do not add Foundation manual TOTP period/skew calculations.
* [ ] Keep provisioning URI/enrollment payload construction lower-layer-owned and Foundation mapping/persistence-only.
* [ ] Adopt `GenericOtp` only for an actual generic one-time-code feature; do not route TOTP/HOTP/OCRA through it simply to increase API utilization.
* [ ] Review OTP result objects and failure reasons so Foundation preserves useful structured data internally instead of reducing everything to booleans/strings too early.
* [ ] Keep OTP service objects stateless/process-safe where possible; replay/counter/challenge state belongs in external authoritative stores, not mutable singletons.

**Correctness and security acceptance**

* [ ] Test TOTP valid/invalid verification, configured skew/window and deterministic test-clock behavior.
* [ ] Test TOTP replay rejection and a concurrent replay race against the hardened authentication-state cache.
* [ ] Test enrollment verification separately from steady-state replay semantics.
* [ ] Test HOTP next-counter persistence and concurrent CAS races; exactly one valid state advance must win.
* [ ] Test counter-based OCRA with durable CAS and challenge/time OCRA with OTP replay state.
* [ ] Test recovery-code success, reuse rejection and concurrent consumption.
* [ ] Test cache/backend outage, authentication-state lock failure, factor-store CAS exhaustion and unexpected OTP exceptions all fail closed while preserving the correct internal operational category.
* [ ] Test production release/build rejects insecure or absent auth-state cache for OTP modes that require it.
* [ ] Test secret rotation success, stale-CAS rejection, rollback/failure behavior and concurrent verification policy.
* [ ] Test persisted secret protection for every production factor-store adapter without exposing secret material in test diagnostics.
* [ ] Test sequential and interleaved Fiber verifications plus persistent-worker reuse for state isolation.
* [ ] Test OTP capability absence leaves non-OTP auth graphs free of OTP/CacheLayer replay-state overhead unless CacheLayer is independently required.

**Performance acceptance**

Benchmark Foundation against direct OTP for the same semantic operation:

1. graph/boot cost with OTP capability absent versus enabled;
2. direct TOTP verification versus `OtpMfaVerifier` without replay I/O attribution hidden;
3. replay-protected TOTP with CacheLayer lock/read/write;
4. HOTP verification + factor-store CAS;
5. representative OCRA counter and challenge/time suites;
6. recovery-code verification/consumption;
7. provisioning and secret rotation as administrative/build-plane paths rather than request-hot-path targets;
8. repeated verification under a persistent runtime with memory measurement.

Published OTP microbenchmarks are lower-layer evidence only. Foundation acceptance must measure the actual Foundation adapter/state-store boundary and attribute cache/CAS cost separately before optimizing wrapper code.

**Completion gate**

The OTP tracker can be checked only when OTP modes preserve the correct state-owner split, secure CacheLayer replay state is a release gate, all production factor stores prove authoritative atomic counter/recovery semantics and protected secret persistence, operational failure taxonomy is no longer collapsed, rotation/concurrency tests pass, optional activation stays cold and Foundation-vs-direct-OTP benchmarks are recorded.

### 26.5 Pathwise 3.1 utilization pass

**Baseline**

* package: `infocyph/pathwise` `^3.1`;
* audited release: Pathwise 3.1;
* tag commit: `8226cf42747ae131486063cad39335d6dfc1c7f7`.

**Ownership decision**

Pathwise/Flysystem own filesystem/storage mechanics. Foundation owns application storage/security policy and Webrick owns HTTP transport/output.

Pathwise owns:

* filesystem creation through `StorageFactory` and Flysystem adapters;
* path/mount resolution helpers;
* file/directory copy/move/read/write/stream mechanics;
* upload and download processors;
* typed/readonly transfer result objects such as `DownloadPreparation` and `ChunkUploadState`;
* upload validation, chunk handling, naming and optional malware-scanner invocation;
* lower-level archive/path/symlink/traversal protections;
* static mount/custom-driver registries as Pathwise process-level infrastructure.

Foundation owns:

* `filesystem.disks` application configuration and default-disk selection;
* application-root relative path policy;
* download allowed roots/extensions/size/attachment policy;
* upload allowed types/extensions/size/image/chunk policy;
* scanner capability/service composition;
* ownership/cleanup of temporary files Foundation itself creates;
* deciding which configured disks belong in each runtime graph;
* offload policy such as X-Sendfile/X-Accel eligibility;
* the bridge from Pathwise transfer results to Webrick response bodies.

Webrick exclusively owns native HTTP response emission. Pathwise and Foundation may produce file/stream semantics but must never become competing SAPI/persistent-adapter writers.

**Current integration findings to preserve**

* `FilesystemResponseFactory` uses Pathwise's typed download result and emits Webrick `FileBody` for appropriate local files or portable stream/chunk bodies for mounted/non-local storage.
* The current branch already returns `ChunkUploadState` directly; do not regress to array normalization/wrappers around Pathwise result objects.
* `StorageRegistry` lazily builds configured filesystems and gives Foundation-scoped mount names. Process-level mount state is acceptable under Foundation's one compiled application/generation per production process rule.
* `FilesystemTransferFactory` correctly applies Foundation policy to transient Pathwise upload/download processors rather than moving storage mechanics into Foundation.

**Confirmed current issues**

1. `FilesystemUploadRequestHandler` materializes a Webrick uploaded file into a Foundation-owned `foundation-upload-*` temporary path before passing it to Pathwise. If Pathwise validation/processing throws before consuming/moving that source, Foundation currently has no `finally` cleanup and can leak the temp file.
2. Foundation exposes `filesystem.uploads.require_malware_scan` and sets `UploadProcessor::setRequireMalwareScan()`, but the normal graph does not inject a scanner with `setMalwareScanner()`. Pathwise correctly fails closed when scanning is required without a scanner, so enabling the Foundation flag currently leaves no complete composition path.
3. Pathwise's mount and custom-driver registries are static process state. Foundation must treat them as generation/process boot state, never per-request/job mutable state; deployment generation changes should replace the process rather than hot-remount generation A into generation B.

**Audit and implementation checklist**

* [ ] Make ownership of Foundation-materialized upload temp files explicit from `moveTo()` until Pathwise has consumed/moved them.
* [ ] Wrap normal and chunk-upload ingestion in deterministic cleanup; in `finally`, remove the Foundation temp file if it still exists.
* [ ] Use the same primary-exception preservation semantics as `CleanupGuard`: cleanup failure may surface only when no primary upload/validation failure already exists.
* [ ] Add explicit malware-scanner capability/service composition. When scanning is disabled, omit scanner graph/cost; when required, scanner availability must be validated before traffic.
* [ ] Adapt the configured scanner to Pathwise's callable contract at a narrow compile-friendly boundary; do not create a parallel malware-scanning framework.
* [ ] Preserve Pathwise fail-closed behavior when scanning is required and scanner execution fails/denies the file.
* [ ] Keep `StorageRegistry` process/generation-scoped and initialize mounts once; prohibit request/job code from replacing global mounts or custom drivers.
* [ ] Make custom Pathwise driver registration a build/process-boot concern with deterministic configuration and explicit package capability checks.
* [ ] Treat generation replacement as process replacement for Pathwise static registries rather than adding production reset/unfreeze APIs merely for deploys.
* [ ] Continue using Pathwise readonly result/domain objects directly where they express the operation; Foundation wrappers should exist only for real application policy.
* [ ] Preserve local-file capability detection: Webrick `FileBody`/range/conditional handling for true local paths, portable streaming for mounted/non-local storage.
* [ ] Permit X-Sendfile only for a true local file known to the web server; keep X-Accel explicit and configuration/policy driven.
* [ ] Propagate unsupported storage-operation errors instead of silently pretending remote/mounted stores support local-path behavior.
* [ ] Preserve Pathwise archive/traversal/symlink/bomb protections and ensure Foundation normalization never bypasses them.
* [ ] Treat Pathwise local file-job/process helpers as local filesystem tooling, not as a replacement for Omnibus or Foundation distributed worker orchestration.
* [ ] Audit all stream ownership so each resource is closed by exactly one documented layer and Webrick remains the sole native response writer.

**Correctness and security acceptance**

* [ ] Test Foundation temp cleanup after successful normal upload when the temporary source remains, and after every validation/storage exception path.
* [ ] Test chunk-upload materialization cleanup for success, rejected chunk, invalid metadata and finalize failure.
* [ ] Test cleanup failure cannot mask the primary Pathwise validation/storage exception.
* [ ] Test `require_malware_scan=false` requires no scanner and `true` fails during composition/boot when scanner capability is absent.
* [ ] Test scanner allow/deny/error behavior through the Foundation bridge without weakening Pathwise fail-closed semantics.
* [ ] Test local, mounted and remote-style download paths including HEAD/range/conditional semantics through Webrick.
* [ ] Test X-Sendfile rejection for mounted/non-local storage and explicit X-Accel policy.
* [ ] Test typed `DownloadPreparation`/`ChunkUploadState` contracts are preserved.
* [ ] Test repeated persistent-runtime access does not mutate/leak mount/driver topology between executions.
* [ ] Test configured multi-disk mounts initialize deterministically and cannot be silently replaced at request/job runtime.
* [ ] Test traversal/archive/symlink security cases through the Foundation-configured path rather than only standalone Pathwise.
* [ ] Test stream/resource closure on success, partial read, exception and client-abort-style paths where the adapter permits simulation.

**Performance acceptance**

Benchmark at minimum:

1. filesystem capability absent versus present-but-unused graph/boot cost;
2. first `StorageRegistry` initialization and warm disk lookup;
3. warm mounted/local path resolution;
4. direct Pathwise upload versus Foundation request-materialization + Pathwise ingestion;
5. failure-path temp cleanup overhead;
6. chunk upload and finalize;
7. direct Pathwise download preparation versus Foundation response bridge;
8. remote/mounted stream setup and iteration through Webrick body abstractions;
9. one-time mount/custom-driver boot cost;
10. repeated persistent-runtime filesystem operations with memory measurement.

Do not add another filesystem cache or response-stream abstraction unless the attribution shows measurable Foundation overhead that Pathwise/Webrick cannot already eliminate.

**Completion gate**

The Pathwise tracker can be checked only when Foundation-owned upload temps are deterministically cleaned, required malware scanning has a real build-time composition path, static mount/driver lifecycle is proven generation-safe, local/non-local Webrick response ownership remains correct, security regression tests pass and Foundation-vs-direct-Pathwise benchmarks record the final bridge overhead.

### 26.6 DBLayer 5.0 utilization pass

**Baseline**

* package: `infocyph/dblayer` `^5.0`;
* audited release: DBLayer 5.0;
* tag commit: `0a599814b09f9d922d017a9c2ef80d99726061a2`;
* DBLayer 5.0 requires ArrayKit `^5.1.1` and CacheLayer `^3.1.3`; Foundation already targets newer compatible CacheLayer 3.2.x behavior.

**Ownership decision**

DBLayer owns database mechanics and database-specific runtime behavior. Foundation owns application database topology, capability composition and execution-lifecycle policy around DBLayer.

DBLayer owns:

* `ConnectionConfig` and database connection/security primitives;
* `Connection` lifecycle mechanics;
* PDO/write/read-replica handling;
* transactions, nested transactions/savepoints and `afterCommit()` behavior;
* query execution and `QueryBuilder`;
* prepared-statement caching;
* driver capability detection;
* retry/deadline/cancellation behavior implemented by DBLayer;
* connection pooling through `Pool` / `PoolManager`;
* connection-state sanitation through `resetRuntimeStateForReuse()`;
* query-result caching semantics and query/table cache-tag behavior;
* query-cache invalidation semantics;
* repositories, result processing and pagination;
* schema manipulation;
* migration and seeding primitives;
* database security validation;
* database query monitoring/profiling/telemetry primitives;
* lower-level batch/query optimization.

Foundation owns:

* `database.connections` application configuration and default-connection selection;
* application-relative SQLite path resolution;
* deciding which runtime graphs need database capability;
* InterMix lifetime/scope policy for checked-out connections;
* execution-local ownership of a checked-out connection;
* deterministic release/build validation of database topology;
* selecting whether connection pooling is enabled;
* selecting whether DBLayer query caching is enabled and which CacheLayer store is used;
* application/auth schema definitions;
* application migration/seeder class topology;
* migration locking policy;
* application-facing repository/adaptor composition;
* cleanup integration with Foundation execution scopes;
* account/auth concurrency policy such as MFA compare-and-swap and passkey credential persistence.

Foundation must not create a second query builder, transaction manager, connection pool, result cache, database retry runtime, schema engine or migration engine above DBLayer.

Foundation should also avoid adopting DBLayer's process-static `DB` façade as its normal runtime database boundary. Foundation already has InterMix execution isolation; normal application connections should remain explicit instance-owned objects rather than process-global mutable connection state.

**Current integration findings to preserve**

1. `DatabaseConnectionResolver` correctly keeps Foundation-specific configuration policy outside DBLayer, including named connections, default selection and application-relative SQLite paths.
2. `DBLayerFactory` caches normalized `ConnectionConfig` objects rather than repeatedly rebuilding configuration.
3. `Connection::class` is execution-scoped rather than a process singleton.
4. `RuntimeExecutionState` currently owns each connection used during an execution and rolls back active transactions before cleanup.
5. Fresh connections are explicitly tracked separately from ordinary named connections.
6. `DatabaseMigrationManager` already delegates actual migration and seed execution to DBLayer's `MigrationRunner` / `SeedRunner` rather than reimplementing migration mechanics.
7. `DBLayerMfaFactorStore::compareAndSwap()` already performs real optimistic concurrency using the factor revision and must remain the authoritative path for HOTP/counter-OCRA/recovery-state updates.

**Confirmed current issues / required decisions**

1. Foundation currently creates a new DBLayer `Connection` for an execution and disconnects it during scope cleanup. This is extremely safe for transaction/sticky-state isolation, but it prevents PDO, prepared-statement and lower-layer connection reuse between persistent executions.
2. DBLayer 5.0 already provides `Pool` / `PoolManager`. Pool release calls DBLayer's connection sanitation before reuse and rejects unsafe/unhealthy/expired connections. Foundation should therefore benchmark DBLayer-native pooling before retaining unconditional per-execution disconnect behavior in persistent runtimes.
3. A pooled connection must still belong to exactly one active Foundation execution at a time. Pooling is a reuse strategy between executions, not permission to make `Connection` a shared singleton.
4. DBLayer's pool itself is mutable process state. Fiber/coroutine/concurrent checkout safety must be proven before Foundation enables one shared pool under persistent concurrent runtimes. If the lower-layer pool needs stronger concurrency semantics, fix DBLayer rather than adding a Foundation pool wrapper.
5. DBLayer's process-static `DB` façade deliberately maintains shared connections, cache state, pool state, query logs, listeners, profiler and other process-global state. Switching Foundation's normal connection service to that façade would conflict with Foundation's InterMix execution-isolation model.
6. DBLayer result caching is already sophisticated: caching is disabled for locking queries, managed transactions, sticky-write state and unresolved complex/raw dependency graphs. Foundation must preserve those lower-layer safety decisions.
7. DBLayer 5.0 query-write invalidation currently reaches `DB::invalidateCacheTagsAfterCommit(...)`. Foundation normally creates `Connection` instances directly instead of registering them through the static `DB` façade. Before Foundation enables DBLayer query caching, verify that cache lookup and post-commit invalidation are fully correct for this instance-owned connection model. If they are not, add an instance-oriented lower-layer DBLayer cache/invalidation contract rather than registering Foundation execution connections into process-global `DB` merely as a workaround.
8. CacheLayer being a DBLayer package dependency does not mean Foundation's application cache capability should activate whenever database capability activates. Query caching and migration locking remain explicitly selected features.
9. `DBLayerPasskeyCredentialStore::updateUsage()` currently performs an ordinary update of passkey usage/sign-count state without an expected version/counter condition. This does not meet the atomic persistence requirement established by WebAuthn section 26.11.
10. MFA factor compare-and-swap is already properly conditional. Do not replace that path with a generic `save()` merely for repository uniformity.
11. Read/write-replica sticky state, transaction state, query comments/context, deadline/cancellation state and statement-cache state all belong to DBLayer's connection runtime and must be clean before a pooled connection crosses an execution boundary.
12. Migration/schema execution is administrative/build/CLI work. It must not add discovery or lock/cache work to normal request/job hot paths.

**Audit and implementation checklist**

* [ ] Rescan every Foundation `Infocyph\DBLayer` usage against DBLayer 5.0 tagged APIs.
* [ ] Keep `DatabaseConnectionResolver` limited to Foundation application configuration/topology policy.
* [ ] Keep normalized `ConnectionConfig` construction outside execution hot paths.
* [ ] Preserve execution ownership: one ordinary named `Connection` per Foundation execution/name unless an explicitly safe lower-layer mechanism proves otherwise.
* [ ] Benchmark current create/use/disconnect behavior against DBLayer-native pool checkout/use/release.
* [ ] If pooling wins materially, introduce one process/generation-level DBLayer pool and make `RuntimeExecutionState` own checked-out connections until scope cleanup.
* [ ] Release pooled connections through DBLayer's native pool sanitation path rather than manually reproducing transaction/sticky/prepared-state cleanup in Foundation.
* [ ] Keep `freshConnection()` semantics dedicated/non-pooled unless an explicit caller contract says otherwise.
* [ ] Ensure a connection with an active/unrecoverable transaction can never be returned to the pool as healthy reusable state.
* [ ] Prove the selected pool implementation is safe for the concurrency model in which it is enabled; do not share one connection concurrently between Fibers/coroutines.
* [ ] Do not make Foundation's normal `foundation.db` service resolve through DBLayer's static `DB::connection()` process-global registry.
* [ ] Use DBLayer-native transactions, savepoints, retry/deadline/cancellation and `afterCommit()` behavior directly.
* [ ] Audit every Foundation manual transaction/retry helper for duplicate DBLayer functionality.
* [ ] Keep DBLayer's read/write sticky behavior lower-layer-owned and prove pool release clears execution-specific sticky state.
* [ ] Keep DBLayer prepared-statement caching lower-layer-owned; pooling may preserve its benefit where safe.
* [ ] Keep query-result caching opt-in.
* [ ] Before enabling query caching, prove DBLayer 5.0's cache reads and post-commit tag invalidation work with Foundation's instance-owned connections.
* [ ] If tagged DBLayer query-cache invalidation assumes static `DB` registration, implement/release the missing instance-level lower-layer contract in DBLayer rather than introducing Foundation global-state coupling.
* [ ] When query caching is selected, use DBLayer's `cacheFor()`, cache key, tags and invalidation mechanics rather than a Foundation query-cache wrapper.
* [ ] Preserve DBLayer's automatic disabling of unsafe result-cache cases.
* [ ] Require explicit tags for complex/raw dependency graphs where DBLayer requires them.
* [ ] Keep application CacheLayer capability separate from database capability unless a selected DB feature actually needs CacheLayer.
* [ ] Preserve DBLayer-backed MFA factor compare-and-swap and verify every counter-sensitive auth path uses it.
* [ ] Replace passkey plain usage updates with atomic credential-record persistence satisfying section 26.11.
* [ ] Prefer optimistic compare-and-swap/version conditions for passkey state where practical; alternatively use DBLayer-native transaction/row-lock semantics when the store contract requires them.
* [ ] A stale passkey credential update must fail rather than overwrite a newer authenticator state.
* [ ] Keep DBLayer Schema/MigrationRunner/SeedRunner as the lower-layer implementation for Foundation-owned auth/application schema policy.
* [ ] Normalize migration/seeder topology at build/release/CLI boot instead of discovering application classes on request paths.
* [ ] Keep migration locking optional and use the selected CacheLayer coordination primitive rather than a database-specific Foundation lock subsystem.
* [ ] Recheck DBLayer security/TLS/raw-SQL/identifier configuration against Foundation production defaults without duplicating DBLayer's validator.
* [ ] Keep database logging/telemetry integrations process-safe and bounded; do not activate DBLayer's global static query log merely because Foundation logging exists.
* [ ] Ensure database capability remains absent from web/CLI/worker/scheduler graphs that do not require it.

**Correctness and security acceptance**

* [ ] Test named/default connection resolution and SQLite relative-path policy.
* [ ] Test no database connection is opened merely because the database graph was compiled.
* [ ] Test normal transaction commit and rollback.
* [ ] Test nested transactions/savepoints.
* [ ] Test `afterCommit()` behavior.
* [ ] Test scope cleanup rolls back every remaining active transaction before connection reuse/disconnect.
* [ ] Test primary execution exceptions are not masked by database cleanup failures.
* [ ] Test sequential executions never inherit transaction state, sticky-write state, query comment/context, deadlines or cancellation state.
* [ ] Test interleaved Fiber executions never receive the same active checked-out connection unless DBLayer explicitly supports that exact concurrency model.
* [ ] If pooling is enabled, test checkout/release, unhealthy connection removal, idle/lifetime expiry and reconnection.
* [ ] If pooling is enabled, test a connection with incomplete transaction state is rejected/sanitized before reuse.
* [ ] Test persistent workers across hundreds/thousands of jobs for bounded connection/pool memory.
* [ ] Test read/write replica behavior and sticky reads across writes without sticky state leaking into the next execution.
* [ ] Test query caching only after its instance-owned cache/invalidation contract is proven.
* [ ] Test cached SELECT hit/miss behavior.
* [ ] Test INSERT/UPDATE/DELETE invalidation only after successful surrounding transaction commit.
* [ ] Test rolled-back writes do not invalidate as committed writes.
* [ ] Test locking/transaction/sticky-write/complex-query cases bypass caching exactly as DBLayer specifies.
* [ ] Test MFA factor compare-and-swap under concurrency so exactly one stale-state transition wins.
* [ ] Test passkey credential-state atomic update under concurrent assertions so a stale write cannot overwrite a newer counter/backup-state record.
* [ ] Test stale passkey persistence failure remains an authentication failure rather than being silently ignored.
* [ ] Test migration locking, migration failure rollback semantics and concurrent migration attempts.
* [ ] Test production database security/TLS/raw-query policy across supported drivers.
* [ ] Test database capability absence leaves unrelated runtime graphs free of connections, pools and cache/query infrastructure.

**Performance acceptance**

Benchmark at minimum:

1. database capability absent versus present-but-unused graph/boot cost;
2. normalized `ConnectionConfig` lookup;
3. current first connection construction/open;
4. current execution create/use/disconnect lifecycle;
5. DBLayer pool checkout/use/release lifecycle;
6. warm pooled connection versus new connection;
7. prepared-statement reuse with and without safe pooling;
8. simple direct DBLayer query versus Foundation scoped connection/query;
9. transaction begin/commit/rollback through Foundation;
10. direct DBLayer query caching versus Foundation-selected DBLayer query caching;
11. result-cache hit/miss/invalidation;
12. MFA compare-and-swap;
13. passkey atomic credential persistence;
14. migration/seeder boot as an administrative path;
15. repeated persistent web/worker database executions with memory and connection-count measurement.

Do not enable pooling merely because it exists. Adopt it only when the persistent-runtime benchmark shows meaningful benefit and its concurrency/isolation guarantees are proven.

Do not create a Foundation query-cache layer merely to avoid fixing an instance-level DBLayer cache/invalidation gap.

**Completion gate**

The DBLayer tracker can be checked only when:

* every Foundation database integration has been classified against DBLayer 5.0 ownership;
* normal runtime code does not depend on the process-static `DB` façade for execution state;
* the final connection lifecycle is benchmarked and explicitly chosen;
* any pooling path is proven execution-isolated and concurrency-safe;
* transaction/sticky/deadline/cancellation state cannot cross executions;
* query caching, if exposed, has a correct instance-owned post-commit invalidation path;
* DBLayer-native query-cache semantics are used rather than duplicated;
* MFA CAS remains atomic;
* WebAuthn credential-state persistence is atomic;
* migrations/schema remain administrative and lower-layer-backed;
* optional database/cache capabilities remain cold when unused;
* direct-DBLayer versus Foundation attribution benchmarks record the final database bridge overhead.

### 26.7 ReqShield 3.1 utilization pass

**Baseline**

* package: `infocyph/reqshield` `^3.1`;
* audited release: ReqShield 3.1;
* tag commit: `07e9e0a2465409e33c140b0cee920f821ca49c79`;
* ReqShield's production package does not require DBLayer; DBLayer is a development/integration dependency and database validation is exposed through ReqShield's small `DatabaseProvider` contract.

**Ownership decision**

ReqShield owns validation, sanitization, schema/rule compilation and validation execution. Foundation owns named application schemas, application policy/configuration and framework integration around ReqShield.

ReqShield owns:

* validation rule parsing/compilation;
* built-in validation rules;
* `ValidationPlan` and rule execution;
* validation result/error/failure objects;
* sanitization;
* input casting;
* nested/wildcard validation mechanics;
* validation limits;
* field aliases/messages/locale behavior;
* strict/unknown-field behavior;
* DTO/result mapping where exposed by ReqShield;
* schema composition;
* JSON-schema export behavior;
* process-level rule/plan caching supplied by ReqShield;
* `CompiledValidator`;
* database-rule definitions;
* `DatabaseBatchRule`;
* `DatabaseProvider` contract;
* batching/grouping/execution of expensive database rules through `BatchExecutor`.

Foundation owns:

* named application validation schemas;
* built-in Foundation auth request schemas;
* application schema extension policy;
* `validation.defaults` / named overrides;
* choosing whether validation is enabled in a runtime graph;
* Webrick request/input adaptation;
* FormRequest/application convenience APIs;
* selection of an optional ReqShield database provider;
* selection of the DBLayer connection used by database rules;
* mapping validation failures to application/HTTP behavior;
* DI lifetime and build/runtime composition;
* deciding which configured custom callbacks/rules/sanitizers are acceptable dynamic inputs.

Foundation must not reimplement rule parsing, rule execution, sanitization, schema compilation, wildcard expansion, validation batching or JSON-schema generation above ReqShield.

**Current integration findings to preserve**

1. `ValidationServiceProvider` only installs validation when the validation capability is selected.
2. Validation does not automatically create a database graph.
3. Foundation checks whether `DBLayerFactory` is already present and only then contributes `ReqShieldDatabaseProvider`.
4. `ValidatorFactory` accepts `?DatabaseProvider`; ordinary validation therefore remains valid without DBLayer.
5. `ReqShieldDatabaseProvider` resolves its DBLayer connection only when a database rule is actually executed.
6. `ValidatorFactory::make()` and `makeRules()` produce a new ReqShield `Validator`, avoiding shared mutable validator state between executions.
7. `ValidationSchemaRegistry::extend()` delegates generic schema composition to `Validator::composeSchemas()` instead of maintaining another schema-composition algorithm.
8. Foundation's DB adapter already batches values using DBLayer's safe parameter limits instead of issuing one query per field/value.

**Confirmed current issues / required decisions**

1. ReqShield 3.1 deliberately keeps DBLayer out of its production dependencies. Foundation must preserve this modular boundary; validation-only applications must not gain DBLayer simply because ReqShield supports `exists` / `unique`.
2. The current Foundation graph provides a DB adapter to all validators when the database capability is already present. This is acceptable because connection resolution remains lazy, but do not add per-validation DB initialization merely for API uniformity.
3. ReqShield itself detects whether a validation plan actually contains database rules and only needs `DatabaseProvider` for that expensive batch. Preserve that behavior.
4. Database `exists` / `unique` checks are validation-time observations, not database constraints. They cannot guarantee uniqueness against a concurrent write. Foundation persistence code must still rely on real DB constraints/transactions and correctly map constraint violations.
5. `CompiledValidator` is readonly, but in ReqShield 3.1 it wraps a closure capturing a `Validator` and delegates repeated validation to that captured object. Foundation must not assume this automatically makes one compiled validator safe as a process-wide concurrent singleton.
6. ReqShield already maintains bounded process-level plan caching internally. Do not add a Foundation validation-plan cache until benchmarks prove a real missing layer.
7. If Foundation needs an immutable/exportable precompiled validation artifact for generated releases and ReqShield does not expose one, add that general capability to ReqShield rather than serializing Foundation's captured validator closure.
8. `ValidationSchemaRegistry` is mutable through `define()` / `extend()`. In generated production, configured/Foundation schemas should be finalized before traffic; request/job code should not mutate one process-wide schema registry.
9. Application-defined callable rules, conditions and sanitizers can be legitimate dynamic inputs. They must remain explicit dynamic islands rather than being silently serialized into generated artifacts.
10. Validation limits such as depth, field count, wildcard expansions and flattened paths are security/DoS controls. Foundation must not disable or inflate them simply to avoid validation failures.
11. ReqShield already batches expensive database rules by operation/table. Foundation must not regress to field-by-field `exists`/`unique` queries.
12. Foundation's DB adapter performs its own query construction because ReqShield intentionally exposes a framework-neutral contract. Keep that adapter narrow.
13. HTTP request construction/parsing remains Webrick-owned. Foundation should feed ReqShield normalized application input rather than introduce a second generic HTTP request parser through validation.
14. File/content validation and file storage remain different responsibilities: ReqShield validates input; Pathwise owns storage/upload processing. Foundation should not merge those runtimes.

**Audit and implementation checklist**

* [ ] Rescan every Foundation `Infocyph\ReqShield` use against ReqShield 3.1 tagged APIs.
* [ ] Keep named schema/application policy in `ValidationSchemaRegistry`; keep rule execution in ReqShield.
* [ ] Keep Foundation schema extension based on `Validator::composeSchemas()` rather than generic array merging where ReqShield semantics differ.
* [ ] Finalize configured production validation schemas before traffic.
* [ ] Prevent normal production request/job code from mutating the shared schema registry.
* [ ] Retain development/tooling schema mutability only where genuinely useful.
* [ ] Keep `ValidatorFactory` as a thin configuration/profile mapper.
* [ ] Audit every `ValidatorFactory` setter/config option against ReqShield's native API and remove Foundation transformations that add no application semantics.
* [ ] Keep per-call Validator construction unless a lower-layer immutable/reentrant compiled form is proven safe and measurably faster.
* [ ] Do not cache `CompiledValidator` process-wide merely because its wrapper is readonly.
* [ ] Benchmark ReqShield's own bounded plan cache before adding any Foundation cache.
* [ ] If generated immutable validation plans would materially improve boot/hot-path cost, first add/release a generic exportable plan contract in ReqShield.
* [ ] Keep database validation optional.
* [ ] Do not activate DBLayer merely because validation is enabled.
* [ ] Keep DB connection acquisition lazy until a validation plan actually executes DB-backed rules.
* [ ] Preserve ReqShield's native `DatabaseProvider` contract as the only coupling from ReqShield into Foundation's database adapter.
* [ ] Preserve ReqShield `BatchExecutor` grouping/batching semantics.
* [ ] Keep DBLayer batch-size calculation in the Foundation adapter for actual backend parameter limits.
* [ ] Review `ReqShieldDatabaseProvider` query grouping for `exists`, `unique`, ignored IDs, nullable values and soft-delete policy.
* [ ] Treat database validation as advisory validation only; enforce authoritative uniqueness/integrity at database write time.
* [ ] Preserve validation depth/field/wildcard/path limits and fail safely when limits are exceeded.
* [ ] Keep custom callable rules/sanitizers/conditions as explicit dynamic configuration where needed.
* [ ] Avoid capturing request/principal/container state into long-lived validator instances.
* [ ] Feed ReqShield arrays/normalized values from the existing Webrick request boundary; do not add a second HTTP parsing layer.
* [ ] Preserve ReqShield's structured `ValidationResult`, failures and validated-input objects internally rather than reducing everything to booleans prematurely.
* [ ] Keep JSON-schema generation lower-layer-owned when Foundation exposes it.
* [ ] Ensure validation capability is absent from runtime graphs that do not select it.

**Correctness and security acceptance**

* [ ] Test named Foundation schemas and application-defined schemas.
* [ ] Test schema extension/composition behavior against direct ReqShield.
* [ ] Test required/type/string/numeric/date/array/conditional representative rules through Foundation.
* [ ] Test sanitizers and casts.
* [ ] Test nested/wildcard validation.
* [ ] Test strict/strip/allow-unknown behavior.
* [ ] Test aliases/custom messages/locales.
* [ ] Test DTO/result behavior where Foundation exposes it.
* [ ] Test max depth.
* [ ] Test max fields.
* [ ] Test max wildcard expansions.
* [ ] Test max flattened paths.
* [ ] Test malformed or attacker-controlled deeply nested input fails within bounded resource usage.
* [ ] Test a validation-only application with no DBLayer validates successfully and contains no database definitions/connections.
* [ ] Test an application with database capability but a non-DB schema performs zero DB connection/query work during validation.
* [ ] Test a schema using `exists` requires a database provider.
* [ ] Test a schema using `unique` requires a database provider.
* [ ] Test batched `exists` across repeated values/columns.
* [ ] Test batched `unique`, ignored IDs and soft-delete options.
* [ ] Test nullable database-rule values.
* [ ] Test database provider failure is surfaced as a validation infrastructure failure rather than silently converted to successful validation.
* [ ] Test DB uniqueness validation cannot replace an authoritative database unique constraint in persistence tests.
* [ ] Test repeated validation through a persistent worker does not retain prior validated data/errors.
* [ ] Test interleaved Fiber validations remain isolated.
* [ ] If compiled validators are ever shared, add explicit concurrency/reentrancy tests before adopting that lifetime.
* [ ] Test production schema registry topology remains unchanged across executions.
* [ ] Test custom callable rule/sanitizer dynamic islands do not leak execution state.
* [ ] Test disabled validation capability adds no ReqShield services to unrelated graphs.

**Performance acceptance**

Benchmark at minimum:

1. validation capability absent versus enabled-but-unused graph/boot cost;
2. direct ReqShield Validator construction versus Foundation `ValidatorFactory::make()`;
3. first named-schema validation;
4. warm repeated named-schema validation using ReqShield's internal plan cache;
5. Foundation `compile()` / `CompiledValidator` path without unsafe singleton caching;
6. representative scalar schema;
7. nested/wildcard schema;
8. sanitization/casting-heavy schema;
9. strict/unknown-field handling;
10. direct ReqShield DB batch versus Foundation ReqShield→DBLayer adapter;
11. multiple `exists` checks showing batched behavior;
12. multiple `unique` checks showing batched behavior;
13. non-DB validation while database capability is present, proving zero DB I/O;
14. repeated validations under persistent runtime with memory measurement.

Do not introduce a Foundation validation cache simply because repeated schema construction appears in profiles. First attribute cost against ReqShield's own bounded compiled-plan cache.

Do not replace per-call mutable validators with shared compiled validators without concurrency proof.

**Completion gate**

The ReqShield tracker can be checked only when:

* all validation/sanitization/schema mechanics remain ReqShield-owned;
* Foundation contains only application schema/profile/request-boundary policy;
* validation does not activate DBLayer on its own;
* non-DB schemas perform no database I/O even when DB capability exists;
* DB rules retain ReqShield-native batching;
* validation-time uniqueness is not mistaken for authoritative database integrity;
* production schema topology is finalized;
* mutable validators cannot leak state across executions;
* any compiled/shared validator optimization is explicitly proven reentrant;
* validation limits/security behavior remain intact;
* direct-ReqShield versus Foundation attribution benchmarks record the final validation bridge overhead.

### 26.8 Omnibus 2.5 utilization pass

**Baseline**

* package: `infocyph/omnibus` `^2.5`;
* audited release: Omnibus 2.5;
* tag commit: `7686de11b75ec4e02cbebd2080d6c470c1c314cf`;
* Omnibus 2.5 directly requires UID 5.0 and exposes optional CacheLayer/DBLayer integrations for coordination, durable queues, failure stores, workflows and after-commit behavior.

**Ownership decision**

Omnibus owns messaging/event/queue/worker mechanics. Foundation owns application messaging topology, service resolution and integration of Omnibus workers with the Foundation release/execution lifecycle.

Omnibus owns:

* `Envelope` and stamps;
* `MessageIdStamp` message identity;
* `MessageBus`;
* message routing and `RouteMap`;
* transport contracts and `TransportRegistry`;
* synchronous and in-memory transports;
* DBLayer durable transport;
* Redis/Valkey transport;
* broker transport abstraction;
* AMQP/SQS integration boundaries;
* reservation/visibility/acknowledge/release/reject semantics;
* `Consumer`;
* retry strategy behavior;
* failed-message behavior and failure-store contracts;
* `Worker`, `WorkerOptions`, `WorkerLifecycle` and built-in `WorkerPool`;
* `HandlerMap`;
* `HandlerInvoker`;
* Omnibus `HandlerMiddleware` pipeline;
* event dispatch/listener maps and queued listeners;
* envelope/message/stamp serialization;
* uniqueness/overlap/rate-limit/circuit-breaker behavior supplied by Omnibus + CacheLayer;
* DBLayer-backed failure storage;
* DBLayer-backed workflow storage;
* DBLayer queue schema and transport mechanics;
* workflow coordination;
* after-commit messaging integration;
* Omnibus telemetry wrappers;
* scheduled-message dispatch primitives;
* message transport/consumer soak/runtime semantics.

Foundation owns:

* `messaging.handlers` application service IDs;
* listener service IDs;
* handler/job middleware service IDs;
* application message routes;
* selected transport profiles and queue names;
* application retry configuration;
* selected durable failure-store profile;
* selected Omnibus workflow/coordination capabilities;
* mapping configured application service IDs into the finalized InterMix runtime;
* Foundation worker graph inclusion;
* Foundation release-generation worker topology;
* graceful generation replacement policy;
* Foundation execution scope and cleanup around one delivered message;
* propagation of Omnibus message identity into Foundation logging/correlation state;
* selection of the DBLayer/CacheLayer instances used by Omnibus integrations;
* application operational defaults and build-time validation.

Foundation must not create a competing event bus, queue runtime, retry engine, reservation protocol, failure queue, uniqueness system, overlap lock system, workflow engine or worker message loop above Omnibus.

**Current integration findings to preserve**

1. `MessagingServiceProvider` already builds native Omnibus `HandlerMap`, `HandlerInvoker`, `ListenerMap`, `RouteMap`, `TransportRegistry`, `MessageBus`, `EventDispatcher`, `Consumer`, worker and scheduled-message services rather than wrapping Omnibus behind a second Foundation messaging abstraction.
2. `MessagingRuntimeResolver` is an explicit dynamic island for application-configured service IDs. The surrounding messaging graph remains generated.
3. Handler and listener service instances are resolved from the finalized container during actual execution rather than being captured when the process singleton messaging topology is built.
4. `ResolvingHandlerMiddleware` resolves application middleware inside the active execution scope.
5. Omnibus `HandlerInvoker` itself prebuilds the middleware pipeline structure once, so Foundation does not need another middleware pipeline runtime.
6. `InterMixExecutionScope` correctly reuses `MessageIdStamp` as the Foundation execution correlation identity instead of generating an unrelated second execution ID.
7. `ConsumerFactory` correctly delegates retry decisions to Omnibus `ExponentialRetryStrategy`.
8. `OmnibusWorkerFactory` correctly maps application worker configuration into native `WorkerOptions` / `Worker`.
9. Scheduler-to-message behavior already uses Omnibus `ScheduledMessageDispatcher`.

**Confirmed current issues / required expansion**

1. Foundation's default `TransportRegistry` currently contains only `sync` and `memory`. Omnibus 2.5 exposes substantially more lower-layer transport functionality, including DBLayer durable queues and native Redis/Broker boundaries. Foundation should expose selected Omnibus transports through configuration rather than leave users to rebuild integration outside Foundation.
2. The default `FailureStore` is `InMemoryFailureStore`. That is appropriate for local/testing/synchronous/in-memory use but is not a durable production failed-message store for an asynchronous durable queue.
3. A production durable worker should require an explicitly suitable failure-store policy, normally Omnibus's own durable integration when failure retention/retry operations are required.
4. DBLayer and CacheLayer are optional Omnibus integrations. Selecting `sync`/`memory` must not activate either dependency graph.
5. Selecting DBLayer transport/workflow/failure storage should consume the already-selected Foundation DBLayer capability and its safe execution/connection lifecycle from section 26.6.
6. Selecting uniqueness/overlap/rate-limit/circuit policies should consume Omnibus's CacheLayer integration rather than Foundation-auth cache adapters or a new Foundation policy implementation.
7. Omnibus already provides its own bounded/legal CacheLayer policy key encoder. Do not reuse Foundation's auth-state physical-key namespace for messaging coordination.
8. Omnibus DBLayer `AfterCommitDispatcher` binds to an actual `Connection`. Under Foundation's execution-scoped DB model this integration must resolve/use the current execution connection and must never be a process singleton that captures the first scoped database connection.
9. Omnibus `Consumer` already owns the receive → execute → retry/release → failure/reject → acknowledge lifecycle. Foundation must not add a second retry/settlement decision outside it.
10. Failure/retry and transport settlement errors have different semantics. Foundation logging/observability may classify them, but it must not acknowledge a message Omnibus decided should be released/rejected.
11. Handler/listener/middleware topology is known during Foundation composition. Service IDs should be validated/enriched into the generated graph before release, while actual scoped service resolution remains execution-time behavior.
12. `MessagingRuntimeResolver` currently accepts arbitrary callables in addition to service IDs. User-provided callables are legitimate dynamic islands, but Foundation-owned configured class/service handlers should prefer deterministic service IDs.
13. Foundation currently parses worker configuration repeatedly in `OmnibusWorkerFactory::all()` / `options()`. This is not per-message work, but Foundation already has generation-owned worker topology. Normalize worker topology at build/process boot rather than making source configuration discovery part of worker hot lifecycle.
14. Foundation owns cross-generation worker replacement; Omnibus owns worker execution mechanics. Do not create two independent supervisors competing over restart/shutdown semantics.
15. Omnibus's built-in `WorkerPool` is process orchestration for same-generation worker concurrency. Foundation must explicitly decide how it composes beneath Foundation generation supervision instead of independently supervising the same worker twice.
16. Omnibus workflows, chains/batches and durable state already include atomic/claim semantics. If Foundation exposes them, use Omnibus contracts/stores directly rather than creating Foundation workflow records.
17. Omnibus 2.5 includes serializer registries/codecs. Foundation must require explicit message/stamp serialization topology for durable transports instead of serializing arbitrary service/runtime objects.
18. Message envelopes may contain application data that is sensitive. Foundation diagnostics must not dump complete serialized envelopes indiscriminately.
19. The messaging capability is optional. A web-only/application runtime that does not select messaging must not pay for transports, consumers, DB queue tables, CacheLayer policies or worker topology.

**Audit and implementation checklist**

* [ ] Rescan every Foundation `Infocyph\Omnibus` usage against Omnibus 2.5 tagged APIs.
* [ ] Keep `MessageBus`, route maps, handler maps, consumer, workers, retries, failure stores and transport settlement Omnibus-owned.
* [ ] Keep Omnibus `MessageIdStamp` as the authoritative Foundation execution correlation identity when present.
* [ ] Preserve one `foundation.worker` execution scope per delivered message.
* [ ] Continue seeding the Omnibus `Envelope` and message into the InterMix execution scope.
* [ ] Ensure scope cleanup completes before Omnibus acknowledges/releases/rejects according to the consumer result path.
* [ ] Preserve primary handler failure over Foundation cleanup failures while still allowing Omnibus to perform the correct retry/failure settlement.
* [ ] Prevalidate configured handler/listener/middleware service IDs during graph/release build without eagerly instantiating execution-scoped services.
* [ ] Prefer service IDs for Foundation-owned messaging topology; retain raw callables only as documented dynamic islands.
* [ ] Keep `ResolvingHandlerMiddleware`/equivalent resolution inside the active execution so scoped dependencies remain scoped.
* [ ] Verify singleton `HandlerInvoker` only captures immutable topology/resolver wrappers, never an execution-scoped handler instance.
* [ ] Expand Foundation transport configuration around Omnibus-native transports instead of implementing transport-specific queue code in Foundation.
* [ ] Keep `sync` and `memory` as zero-external-dependency transports.
* [ ] Add DBLayer durable transport composition only when explicitly configured.
* [ ] Add native Redis/Valkey transport composition only when explicitly configured and the required extension/client is available.
* [ ] Add broker/AMQP/SQS integrations through Omnibus's published boundaries when selected; do not embed vendor protocol logic into Foundation.
* [ ] Validate transport capabilities such as receive/delay/visibility at build/process boot where possible.
* [ ] Keep DBLayer/CacheLayer graphs absent unless the selected Omnibus features actually require them.
* [ ] For DBLayer durable transport, consume section-26.6 connection lifecycle rather than registering a separate process-global database connection.
* [ ] For Omnibus DBLayer after-commit dispatch, bind the current execution connection safely; do not capture a scoped Connection in a process singleton.
* [ ] Use Omnibus DBLayer failure/workflow stores directly when those capabilities are selected.
* [ ] Keep Omnibus QueueSchema ownership for its durable queue tables; Foundation only chooses deployment/migration policy.
* [ ] Keep `InMemoryFailureStore` for appropriate development/local/non-durable profiles.
* [ ] Require an explicit durable failure-store choice for production durable queues where failed-message retention/retry is expected.
* [ ] Use Omnibus CacheLayer uniqueness, overlap, rate-limit and circuit-breaker integrations directly.
* [ ] Use Omnibus's own policy-key/storage-key semantics for messaging coordination.
* [ ] Do not route messaging coordination through Foundation's authentication-state cache adapters.
* [ ] Keep Omnibus retry strategy authoritative; Foundation only maps retry configuration.
* [ ] Keep transport acknowledgement/release/reject decisions inside Omnibus Consumer.
* [ ] Preserve Omnibus visibility/reservation semantics and cancellation/deadline behavior.
* [ ] Normalize worker topology into Foundation generation metadata/build output and avoid source configuration discovery during steady worker execution.
* [ ] Define exact ownership between Foundation generation supervision and Omnibus `Worker` / optional `WorkerPool`.
* [ ] Foundation owns generation A→B replacement; Omnibus owns execution and same-generation worker loop semantics.
* [ ] Do not run a Foundation process supervisor and Omnibus WorkerPool as independent owners of the same child processes.
* [ ] If process concurrency uses Omnibus WorkerPool, treat it as an implementation beneath one Foundation generation-owned worker provider.
* [ ] Keep scheduled-message factories as build-known service IDs resolved only when a scheduled dispatch executes.
* [ ] Keep scheduler runtime and worker runtime separate even when a scheduler dispatches an Omnibus message.
* [ ] Use Omnibus workflow/chain/batch facilities directly if Foundation exposes them.
* [ ] Keep workflow durable state, claims, retries and failure recovery Omnibus-owned.
* [ ] Define durable serializer/message-codec/stamp-codec topology explicitly for each non-memory transport.
* [ ] Prohibit serialization of live container/Application/Connection/request/principal/service objects into durable envelopes.
* [ ] Keep message/failure diagnostics redaction-aware.
* [ ] Use Omnibus telemetry sinks/wrappers where the semantics fit instead of wrapping every transport/consumer with another Foundation telemetry runtime.
* [ ] Keep messaging services entirely absent from runtime graphs that do not select messaging.

**Correctness and reliability acceptance**

* [ ] Test synchronous dispatch.
* [ ] Test in-memory asynchronous transport.
* [ ] Test configured route/default route behavior.
* [ ] Test handler resolution.
* [ ] Test listener/event dispatch.
* [ ] Test handler middleware ordering.
* [ ] Test job middleware ordering.
* [ ] Test execution-scoped handler/middleware dependencies are fresh between messages.
* [ ] Test Omnibus `MessageIdStamp` maps to the same Foundation execution identity throughout handler/logging/history state.
* [ ] Test missing message IDs receive exactly one Foundation fallback execution identity.
* [ ] Test sequential messages do not retain the previous envelope/message/principal/DB state.
* [ ] Test interleaved Fiber execution where supported.
* [ ] Test handler success acknowledges exactly once.
* [ ] Test retryable failure releases with Omnibus's retry delay.
* [ ] Test terminal failure records the failure and rejects according to Omnibus semantics.
* [ ] Test decode failure follows Omnibus undecodable-message failure semantics.
* [ ] Test Foundation cleanup failure cannot cause an already-successful/failed message to be settled incorrectly.
* [ ] Test cancellation and worker shutdown.
* [ ] Test visibility timeout behavior.
* [ ] Test worker `max_messages`, runtime and memory limits.
* [ ] Test graceful Foundation generation replacement while Omnibus worker execution is active.
* [ ] Test optional WorkerPool shutdown/restart ownership without double supervision.
* [ ] Test durable DBLayer transport enqueue/reserve/ack/release/reject.
* [ ] Test DB queue contention using Omnibus's native DBLayer transport.
* [ ] Test DBLayer after-commit dispatch sends only after successful outer commit and does not send after rollback.
* [ ] Test durable failure-store retry claims/concurrent retry handling.
* [ ] Test workflow atomic claims/transitions if workflow support is enabled.
* [ ] Test Redis/Valkey transport when enabled.
* [ ] Test broker transport capability checks when enabled.
* [ ] Test unique-message protection.
* [ ] Test overlap protection and lost-lease behavior.
* [ ] Test rate-limit behavior.
* [ ] Test circuit-breaker behavior.
* [ ] Test Omnibus messaging policy keys remain legal under the selected CacheLayer backend.
* [ ] Test `sync`/`memory` topology activates neither DBLayer nor CacheLayer solely because those packages are installed.
* [ ] Test DB-backed messaging activates only the required DB graph.
* [ ] Test CacheLayer-backed policy features activate only the required cache graph.
* [ ] Test durable serialization round trips for message + core stamps.
* [ ] Test unknown/malformed message types fail safely.
* [ ] Test durable envelopes cannot deserialize arbitrary Foundation runtime service objects.
* [ ] Test failed-message logs/telemetry avoid unintended sensitive payload disclosure.
* [ ] Run long persistent-consumer soak tests proving bounded memory and no scoped state leakage.

**Performance acceptance**

Benchmark Foundation against direct Omnibus for the same semantic workload:

1. messaging capability absent versus enabled-but-unused graph/boot cost;
2. direct synchronous Omnibus dispatch versus Foundation `foundation.messaging`;
3. handler-map resolution;
4. zero-middleware invocation;
5. one and representative multiple handler middleware;
6. service-ID resolution through the Foundation execution scope;
7. execution-scope/message-ID bridge overhead;
8. in-memory send/receive/ack cycle;
9. retryable failure/release path;
10. terminal failure-store path;
11. durable DBLayer enqueue/reserve/ack;
12. DBLayer queue contention;
13. Redis/Valkey send/receive when enabled;
14. uniqueness/overlap/rate-limit/circuit policy overhead separately;
15. durable serialization encode/decode;
16. worker construction/boot from generation-owned topology;
17. steady-state worker message throughput;
18. optional WorkerPool same-generation concurrency;
19. repeated persistent worker execution with memory measurement;
20. workflow/chain/batch paths only when Foundation actually exposes them.

Use Omnibus's own `benchmark`, handler-middleware, DB contention and soak workloads as lower-layer attribution baselines where they match Foundation behavior.

Do not bypass Foundation execution scope merely to improve message throughput; the scope is required for DB/auth/principal/temp-resource cleanup and state isolation.

Do not create faster Foundation-specific queue paths that skip Omnibus retry/reservation/failure semantics.

**Completion gate**

The Omnibus tracker can be checked only when:

* Foundation remains a thin topology/service-resolution/lifecycle adapter around Omnibus;
* Omnibus owns routing, transport, retry, settlement, failure and workflow mechanics;
* message IDs are reused as Foundation execution identity;
* handler/middleware resolution remains execution-scoped without singleton capture;
* Foundation exposes required Omnibus-native durable transports rather than reimplementing them;
* DBLayer/CacheLayer integrations activate only when selected;
* durable workers have durable failure semantics;
* DB after-commit dispatch uses the correct current execution connection;
* Foundation generation supervision and Omnibus worker/WorkerPool ownership do not conflict;
* serialization boundaries contain data rather than live runtime services;
* persistent consumer isolation and cancellation/replacement tests pass;
* direct-Omnibus versus Foundation attribution benchmarks record the final messaging bridge overhead.

### 26.9 TalkingBytes 2.0.0 utilization pass

**Baseline**

* package: `infocyph/talkingbytes` `^2.0`;
* audited release: TalkingBytes 2.0.0;
* tag commit: `86d0e9dde8124ddeacea8ba7f81911af584b879b`.

**Ownership decision**

TalkingBytes owns communication protocol execution. Foundation owns application profile selection/composition and execution-lifecycle policy around those protocol objects.

TalkingBytes owns:

* HTTP request/response execution and client configuration;
* retry, rate-limit, circuit-breaker, idempotency and cookie behavior supplied by TalkingBytes;
* inbound/outbound email protocol, parsing/serialization and transport behavior;
* webhook signing, verification, sender/receiver behavior and the `WebhookReplayStore` contract;
* gRPC client invocation, generated-stub/native invocation, streaming and inbound dispatch contracts;
* protocol-specific result/error objects and lower-layer transport semantics.

Foundation owns:

* named HTTP/email/webhook/gRPC application profiles;
* build-time capability/profile selection and validation;
* mapping configured gRPC/email/webhook handlers to application service IDs;
* InterMix lifetime/scope selection for profile objects;
* selecting the CacheLayer-backed webhook replay implementation;
* production secret/reference policy for credentials, API keys and signing secrets;
* deciding whether inbound gRPC/email processing participates in the existing worker execution boundary;
* observability/correlation policy without duplicating TalkingBytes protocol logic.

Foundation must not create another HTTP client, mail parser/transport, webhook signature runtime or gRPC protocol layer above TalkingBytes.

**Confirmed current findings**

1. `CommunicationProfiles::http()` correctly rejects production profiles that disable TLS peer or host verification. Preserve this fail-closed policy.
2. `HttpClient` and `WebhookSender` are currently execution-scoped, while `CommunicationProfiles` is process-safe configuration state. This is safer than a blanket singleton because cookie jars and some resilience components are mutable, but the pass must explicitly classify profile state: cookie/session state must never leak across executions, while rate-limit/circuit-breaker semantics that are intended to span calls must not be accidentally reset every execution.
3. If TalkingBytes needs a shareable resilience-state primitive, fix/consume that lower-layer primitive rather than introducing Foundation global mutable state.
4. `CacheLayerWebhookReplayStore` must use the canonical Foundation security-state key encoder from section 26.3: explicit domain separation + SHA3-256, not SHA-256 or ad-hoc character replacement.
5. After CacheLayer 3.3, replay claim must use CacheLayer's true atomic `setIfAbsent()` capability. Remove Foundation's lock → `has()` → `set()` orchestration rather than retaining two atomicity mechanisms.
6. Production `WebhookReceiver` correctly requires replay protection by default. Do not weaken this merely to make webhook composition optional.
7. gRPC inbound dispatch is a narrow configured-service-ID boundary. Handler service IDs should be fixed during graph composition; actual handler resolution remains inside the active execution.
8. An inbound gRPC server/process belongs to the existing worker runtime/lifecycle rather than creating a fifth Foundation runtime graph.
9. Foundation currently exposes the HTTP/webhook/gRPC profile graph directly. Add TalkingBytes email profile integration where application functionality requires inbound/outbound email; do not recreate MIME, mail-chain or transport behavior in Foundation.
10. Communication credentials/signing secrets are configuration secrets, not release identities. Do not log them, place them in cache/replay keys or bake raw secret material into generated runtime metadata. Coordinate the final secret-source/protection policy with section 26.10.

**Audit and implementation checklist**

* [ ] Rescan every Foundation `Infocyph\TalkingBytes` usage against the 2.0.0 tagged API and remove wrappers that add no application policy.
* [ ] Keep `CommunicationProfiles` as the thin profile mapper and normalize/validate profile topology before production runtime load.
* [ ] Classify every TalkingBytes-backed binding as immutable process state, execution-scoped state or intentionally shared concurrency-safe resilience state.
* [ ] Keep cookie-bearing/session-bearing HTTP clients execution isolated; prove no cookie/header/auth mutation leaks between requests/jobs/Fibers.
* [ ] Determine whether configured `RateLimiter`/`CircuitBreaker` state is intended to span executions. If yes, consume a lower-layer safe sharing mechanism; do not solve it with an unsafe Foundation singleton client.
* [ ] Replace webhook replay physical keys with the section-26.3 domain-separated SHA3-256 security-state encoder.
* [ ] Require CacheLayer 3.3 atomic `setIfAbsent()` for production webhook replay and remove the Foundation replay-store lock dependency.
* [ ] Validate production replay topology during composition/release build, including authoritative security-state store and atomic capability.
* [ ] Preserve fail-closed replay claiming.
* [ ] Keep gRPC retry/streaming/native/generated-stub behavior lower-layer-owned.
* [ ] Run each inbound gRPC call/stream execution through the existing worker execution scope/correlation lifecycle when Foundation owns the server process.
* [ ] Add build-time validation for configured gRPC handler service IDs without eagerly instantiating handlers during graph compilation.
* [ ] Add named inbound/outbound email profiles through TalkingBytes native email APIs.
* [ ] Keep inbound/outbound email message-chain and protocol behavior in TalkingBytes.
* [ ] Keep outbound webhook HTTP-profile selection and retry/idempotency behavior delegated to TalkingBytes.
* [ ] Ensure communication profile secrets remain redaction-safe at Foundation logging and exception boundaries.
* [ ] Keep communication capability entirely absent from runtime graphs that do not select it.

**Correctness and security acceptance**

* [ ] Test production TLS peer/host verification cannot be disabled through a named HTTP profile.
* [ ] Test HTTP profile auth modes, retry, idempotency, rate-limit/circuit behavior and cookie isolation through the Foundation bridge.
* [ ] Test sequential and interleaved Fiber HTTP executions do not leak cookie/auth/request state.
* [ ] Test webhook signature verification, age validation, replay acceptance once and concurrent duplicate rejection.
* [ ] Test long/attacker-controlled webhook delivery IDs map to legal deterministic CacheLayer physical keys.
* [ ] Test replay lock/cache failures fail closed and lock cleanup does not mask the primary failure.
* [ ] Test production composition rejects replay-enabled webhook topology without the required secure CacheLayer capability.
* [ ] Test gRPC unary/native/generated-stub and supported streaming paths without Foundation protocol duplication.
* [ ] Test inbound gRPC service resolution occurs inside the correct execution scope and does not retain the previous call's state in a persistent worker.
* [ ] Test inbound/outbound email profiles preserve TalkingBytes parsing/transport/message-chain behavior and execution isolation.
* [ ] Test secrets are absent from logs, exceptions, cache keys, generated route/container diagnostics and benchmark output.

**Performance acceptance**

Benchmark at minimum:

1. communication capability absent versus enabled-but-unused graph/boot cost;
2. direct TalkingBytes HTTP request construction/execution versus the Foundation profile bridge;
3. warm stateless profile lookup and scoped client creation;
4. cookie-enabled profile execution;
5. retry/rate-limit/circuit-enabled profile execution with state attribution;
6. direct webhook verification versus Foundation + CacheLayer replay claim;
7. gRPC unary dispatch;
8. representative gRPC streaming dispatch through the Foundation scope boundary;
9. inbound email processing;
10. outbound email sending;
11. repeated communication operations in a persistent worker with memory/state-isolation measurement.

Do not cache or singletonize a mutable TalkingBytes client merely to improve a microbenchmark. Any lifetime optimization must preserve isolation and intended resilience-state semantics.

**Completion gate**

The TalkingBytes tracker can be checked only when:

* profile lifetimes are explicitly safe;
* webhook replay keys/claims conform to the hardened CacheLayer contract;
* production replay remains fail-closed;
* HTTP client state does not leak across executions;
* resilience state has an intentional lifetime;
* gRPC integrates through the existing worker lifecycle;
* inbound/outbound email and message-chain behavior are consumed from TalkingBytes rather than recreated;
* communication secrets are proven safe;
* direct-TalkingBytes versus Foundation attribution benchmarks record the final bridge overhead.

### 26.10 Epicrypt 2.1 utilization pass

**Baseline**

* package: `infocyph/epicrypt` `^2.1`;
* audited release: Epicrypt 2.1;
* tag commit: `f80092978328cccaef0d2233b08ce95b453dd90a`.

**Ownership decision**

Epicrypt owns cryptographic primitives and high-level data-protection/key-derivation behavior. Foundation owns application key purpose, secret sourcing, persistence boundaries and auth-policy integration.

Epicrypt owns, where semantics match the Foundation requirement:

* purpose-isolated key derivation through `Generate\KeyMaterial\KeyDeriver`/HKDF;
* key-material generation helpers;
* high-level string/file/envelope data protection;
* `StringProtector`;
* `FileProtector`;
* `EnvelopeProtector`;
* protection algorithms/options/results and protected-payload encoding;
* password-hashing primitives/policy helpers;
* MAC/signature/integrity primitives;
* token/JOSE/certificate/key-exchange primitives when Foundation actually needs those protocols;
* crypto-specific validation/error behavior and key/algorithm details.

Foundation owns:

* which application secrets exist and their semantic purposes;
* mapping secret references to externally supplied key material at process boot;
* purpose labels/domain separation between token signing, MFA-secret protection, recovery-code HMAC and other auth uses;
* storage schema/key-version metadata and rotation rollout policy;
* when protected values are decrypted/reprotected;
* authorization/account behavior after cryptographic verification;
* redaction/observability policy;
* keeping secrets out of generated release artifacts;
* build-time validation that configured keys/algorithms are usable without performing sensitive request-time work during graph compilation.

Foundation must not maintain parallel HKDF, encryption-envelope, MAC or password-hashing implementations when Epicrypt already provides the required contract.

**Confirmed current findings**

1. OTP recovery-code authentication currently domain-separates an HMAC key from the auth token secret. Domain separation is good, but unrelated security functions should not silently share one root-secret lifecycle.
2. Foundation 3 should use either a dedicated recovery key or derive purpose-specific subkeys from a deliberate master key through Epicrypt `KeyDeriver`.
3. MFA factors expose OTP secrets as strings at the application boundary. Every durable store must protect those secrets at rest.
4. Epicrypt's high-level data-protection layer is the canonical protection implementation; Foundation should not add a bespoke AES/Sodium wrapper.
5. Token-signing keys, recovery-code HMAC keys and MFA-encryption keys must have different purpose labels/derived material even when one externally managed master secret is intentionally used.
6. Release-generation config/artifacts are not a secret vault. Prefer stable secret references/identifiers in generated metadata and resolve key material from the deployment secret boundary at process boot.
7. Key rotation must support decrypt/verify with still-valid previous key material while new writes use the active key.
8. Durable key/version metadata must be sufficient for deterministic decryption/reprotection.
9. Do not rely on trial-decrypting arbitrary unrelated keys without a bounded explicit key ring.
10. Use Epicrypt high-level DataProtection services before assembling lower-level crypto primitives. Foundation policy should remain thin and purpose-oriented.

**Audit and implementation checklist**

* [ ] Inventory every Foundation cryptographic operation: password hashing, token signing/verification, recovery-code HMAC, MFA-secret protection, configuration/domain-value protection, integrity checks and random key generation.
* [ ] Classify each operation as Epicrypt-owned primitive/high-level service or Foundation-owned policy.
* [ ] Remove duplicate lower-level implementations only when semantics are exactly equivalent.
* [ ] Introduce explicit purpose labels/versioning for every derived key.
* [ ] Keep separate purposes such as `foundation.auth.mfa-secret.v1`, `foundation.auth.recovery-hmac.v1` and token-signing purposes.
* [ ] Use Epicrypt `KeyDeriver` for deliberate subkey derivation instead of ad-hoc HMAC-based derivation scattered across auth classes.
* [ ] Decide whether recovery-code HMAC uses a dedicated externally supplied key or a derived subkey from one intentional auth master key.
* [ ] Document recovery-key rotation semantics.
* [ ] Protect OTP MFA secrets at rest with Epicrypt high-level data protection.
* [ ] Retain plaintext MFA secrets only for the narrow verification/provisioning execution window.
* [ ] Carry key/version/purpose metadata sufficient for deterministic decryption and rotation without exposing raw key material.
* [ ] Support active + bounded previous decryption/verification keys during rotation.
* [ ] Require new writes to use only the active key.
* [ ] Keep secret material out of InterMix generated artifacts, Foundation generation manifests, cache keys, logs, exception messages and metrics labels.
* [ ] Resolve external secret references once at process/runtime boot where practical; do not perform file/env/secret-provider discovery on request hot paths.
* [ ] Keep cryptographic service objects singleton only when immutable/concurrency-safe and free of mutable per-execution secret state.
* [ ] Prefer Epicrypt password APIs for password hashing/rehash policy where they match Foundation's public contract.
* [ ] Never use reversible encryption for passwords.
* [ ] Use Epicrypt token/JOSE/certificate primitives only for Foundation features that genuinely require those protocols.
* [ ] Do not increase dependency surface merely to maximize package usage.
* [ ] Preserve structured Epicrypt exceptions internally while mapping them to non-sensitive application/auth failures externally.
* [ ] Coordinate OTP secret/recovery decisions with section 26.4.
* [ ] Coordinate persisted secret and passkey-adjacent application-key decisions with section 26.11 where relevant.

**Correctness and security acceptance**

* [ ] Test purpose-derived keys are deterministic for the same master/purpose and distinct across every Foundation security purpose.
* [ ] Test recovery-code verification remains stable across the intended rotation window and rejects use of a key outside that window.
* [ ] Test MFA secrets persist only in protected form for every production store.
* [ ] Test MFA secrets decrypt correctly for authorized verification/provisioning flows.
* [ ] Test active-key writes plus previous-key reads/reprotection during rotation.
* [ ] Test interrupted rotation and rollback behavior.
* [ ] Test tampered protected payloads fail closed with no plaintext disclosure.
* [ ] Test wrong purpose/key/version cannot decrypt or authenticate another Foundation domain's payload.
* [ ] Test password hash/verify/rehash behavior through Foundation matches the selected Epicrypt policy.
* [ ] Test generated runtime/release artifacts contain no raw configured key or decrypted MFA secret.
* [ ] Test normal logs contain no raw configured key or decrypted MFA secret.
* [ ] Test sequential/Fiber/persistent-worker auth operations do not retain one execution's plaintext secret in reusable mutable state.
* [ ] Test missing/malformed/unsupported production key configuration fails before traffic where composition-time validation is possible.

**Performance acceptance**

Benchmark at minimum:

1. crypto capability absent versus enabled-but-unused graph/boot cost;
2. direct Epicrypt key derivation versus Foundation purpose-key lookup/derivation;
3. direct `StringProtector` protect versus the Foundation MFA-secret storage bridge;
4. direct `StringProtector` unprotect versus the Foundation MFA-secret storage bridge;
5. active-key decrypt path;
6. previous-key decrypt/reprotect path;
7. password verify/rehash through direct Epicrypt versus Foundation auth adapter;
8. recovery-code HMAC derivation/verification after the final key-lifecycle decision;
9. repeated auth operations under persistent runtime with memory measurement.

Cryptographic work is intentionally more expensive than ordinary application plumbing. Optimize Foundation wrapper/config lookup overhead, not away authentication, integrity, KDF or encryption guarantees.

**Completion gate**

The Epicrypt tracker can be checked only when:

* every Foundation crypto site is classified;
* duplicated key-derivation/data-protection code is removed or explicitly justified;
* MFA/recovery/token purposes have explicit independent derivation/lifecycle;
* durable MFA secrets are protected at rest;
* bounded rotation works;
* redaction/secret-boundary tests pass;
* persistent-runtime secret isolation is proven;
* direct-Epicrypt versus Foundation attribution benchmarks record the final adapter overhead.

### 26.11 WebAuthn 5.3.8 integration pass

**Baseline**

* package: `web-auth/webauthn-lib`;
* current Foundation constraint line: `^5.3.5`;
* audited compatible release: WebAuthn 5.3.8;
* tag commit: `85d8ae791c87a34be91a7ff5cdb5606f67dedaab`;
* target: raise the Foundation 3 minimum to the audited 5.3.8 patch while preserving dependency compatibility.

**Ownership decision**

WebAuthn owns WebAuthn ceremony/protocol validation. Foundation owns challenge/credential persistence, account mapping, application policy and runtime composition around the library.

WebAuthn owns:

* public-key credential creation/request option objects and protocol parsing;
* attestation response validation;
* assertion response validation;
* RP ID validation;
* origin validation;
* challenge validation;
* user-presence/user-verification checks;
* extension handling;
* authenticator signature-counter validation rules;
* credential-record mutation resulting from a successful assertion;
* updated sign counter;
* backup eligibility/status state;
* WebAuthn-specific codecs/value objects/events/exceptions.

Foundation owns:

* generating/storing/expiring/one-time-consuming ceremony challenge state;
* mapping authenticated Foundation users/accounts to WebAuthn user handles;
* durable credential-record repository/schema;
* atomically persisting the credential record returned by a successful ceremony;
* registration/login/account policy;
* passkey naming/management and authorization;
* CacheLayer/DBLayer topology;
* InterMix lifetime selection;
* redaction/observability policy;
* application-facing error mapping.

Foundation must not reimplement assertion-counter, origin/RP, attestation, signature or challenge-validation algorithms already owned by WebAuthn.

**Confirmed current findings**

1. WebAuthn 5.3.8 `AuthenticatorAssertionResponseValidator::check()` performs ceremony validation and then updates the verified credential record with authenticator state before returning it.
2. The returned record includes the new authenticator `signCount` and relevant backup/user-verification state.
3. Foundation must persist that returned record correctly; it must not independently recreate WebAuthn counter rules.
4. The current Foundation DB-backed passkey usage path performs an ordinary update.
5. Two valid concurrent assertions against the same old credential state can therefore lose ordering/state or overwrite a newer record.
6. Section 26.6 must provide an atomic passkey credential-state persistence primitive using compare-and-swap, a version/counter condition, transaction/row locking, or an equivalent DBLayer-native mechanism.
7. WebAuthn challenge state is one-time authentication state.
8. Any CacheLayer-backed challenge consume path must use section 26.3's atomic `getAndDelete()` security-state mechanics rather than plain `get()` + `delete()`.
9. Foundation challenge physical keys use the section-26.3 security-state encoder: domain-separated SHA3-256 under CacheLayer's key grammar/length contract.
10. Foundation has local Base64URL-style conversion at passkey boundaries.
11. Compare its exact behavior against WebAuthn 5.3.8's own codec/utilities and remove the Foundation helper when genuinely redundant.
12. Do not change persisted credential-ID representation solely for code deduplication.
13. Validator/logger/event-dispatcher wiring is process configuration.
14. If validator instances are shared, finalize mutable setters before traffic and never mutate logger/event-dispatcher state per request.
15. Challenge/options/ceremony request state remains execution-local.
16. Backup-eligible/backed-up credentials can legitimately affect signature-counter behavior. Foundation must trust WebAuthn's successful ceremony result rather than impose a simplistic Foundation counter rule.

**Audit and implementation checklist**

* [ ] Raise Foundation's WebAuthn dependency floor to `^5.3.8` after confirming the complete dependency/test matrix.
* [ ] Rescan every Foundation WebAuthn class/import against 5.3.8 tagged APIs.
* [ ] Remove stale/deprecated `PublicKeyCredentialSource` assumptions where the 5.3.8 `CredentialRecord` contract is the correct boundary.
* [ ] Keep creation/assertion validation entirely in WebAuthn validators/ceremony logic.
* [ ] Keep Foundation responsible only for repositories, options/policy, lifecycle and persistence.
* [ ] Make challenge issuance/consume state short-lived, execution-safe and one-time under concurrency using section 26.3's CacheLayer 3.3 atomic `getAndDelete()` capability.
* [ ] Ensure challenge namespace keys use the section-26.3 domain-separated SHA3-256 security-state physical-key encoder.
* [ ] Persist the exact credential record returned after successful assertion.
* [ ] Persist sign counter, backup state and user-verification state required by the returned record.
* [ ] Implement atomic credential-state persistence through the DBLayer 26.6 pass.
* [ ] Require stale concurrent updates to fail rather than silently overwrite.
* [ ] Define a bounded stale-update/reload policy; do not create an unbounded retry loop.
* [ ] Keep credential repositories process-safe while user/challenge/ceremony request state remains scoped/transient.
* [ ] Review discoverable/resident-credential handling.
* [ ] Verify user-handle lookup cannot bind a credential to the wrong Foundation principal.
* [ ] Compare Foundation Base64URL helpers with WebAuthn's tagged codec behavior.
* [ ] Delete only genuinely redundant conversion helpers.
* [ ] Freeze validator logger/event-dispatcher configuration before production traffic.
* [ ] Do not use mutable shared validator state as request context.
* [ ] Preserve WebAuthn structured events/exceptions internally while returning non-sensitive authentication errors externally.
* [ ] Keep credential public-key material, challenge values and complete attestation/assertion payloads out of ordinary logs unless an explicit safe diagnostic representation exists.
* [ ] Keep WebAuthn capability absent from graphs when passkeys are disabled.

**Correctness and security acceptance**

* [ ] Test registration ceremony through Foundation against WebAuthn 5.3.8.
* [ ] Test assertion ceremony through Foundation against WebAuthn 5.3.8.
* [ ] Test valid/invalid origin.
* [ ] Test valid/invalid RP ID.
* [ ] Test valid/invalid challenge.
* [ ] Test user presence.
* [ ] Test user verification.
* [ ] Test challenge expiry.
* [ ] Test challenge one-time consumption.
* [ ] Test concurrent duplicate assertion so only one execution consumes the ceremony state.
* [ ] Test successful assertion persists the returned sign counter.
* [ ] Test successful assertion persists returned backup eligibility/status.
* [ ] Test successful assertion persists relevant user-verification state.
* [ ] Test concurrent assertions against the same stored credential cannot lose a newer counter/state update.
* [ ] Test stale atomic-update failure follows the documented bounded policy.
* [ ] Test a stale state race never converts a failed persistence condition into successful authentication.
* [ ] Test backup-eligible/backed-up credential scenarios through WebAuthn rather than a Foundation-invented counter shortcut.
* [ ] Test discoverable credential/user-handle mapping cannot cross principals.
* [ ] Test Base64URL/credential-ID round trips before removing any Foundation helper.
* [ ] Test validator configuration remains immutable during persistent traffic.
* [ ] Test sequential/Fiber executions do not leak challenge/principal state.
* [ ] Test disabled passkey capability adds no WebAuthn challenge/repository services to unrelated runtime graphs.
* [ ] Test logs/errors contain no raw challenge, private secret or unnecessarily complete assertion payload.

**Performance acceptance**

Benchmark at minimum:

1. passkey capability absent versus enabled-but-unused graph/boot cost;
2. direct WebAuthn creation-option construction versus the Foundation issuance bridge;
3. direct WebAuthn assertion validation versus Foundation validation + challenge consume;
4. credential lookup + successful atomic persistence;
5. contention/stale-update path under representative concurrent assertions;
6. discoverable-credential lookup where supported;
7. challenge issue/consume overhead through the hardened CacheLayer path;
8. repeated assertions in persistent runtime with memory/state-isolation measurement.

Do not optimize by bypassing WebAuthn ceremony checks or weakening one-time challenge/counter persistence. Attribute Foundation cache/DB/policy overhead separately from the cryptographic/protocol cost already owned by WebAuthn.

**Completion gate**

The WebAuthn tracker can be checked only when:

* Foundation is pinned and tested against WebAuthn 5.3.8;
* challenge consumption is atomic;
* challenge keys conform to the hardened CacheLayer contract;
* credential state returned by WebAuthn is persisted atomically;
* Foundation contains no duplicate signature-counter logic;
* concurrent credential updates cannot silently overwrite newer state;
* stale/deprecated integration assumptions are removed;
* validator/request lifetimes are persistent-runtime safe;
* passkey-disabled graphs remain cold;
* direct-WebAuthn versus Foundation integration benchmarks record the final overhead.

Each lower-library pass updates **this same canonical document**. Do not create another standalone runtime-plan file.

---

## 27. Working principles

For every Foundation DI/runtime change ask:

1. Does InterMix already own this responsibility?
2. Can the definition be generated instead of dynamic?
3. Can a real alias/recipe/ServiceReference replace a closure?
4. Can Application/container lookup become a narrow constructor dependency?
5. Is lifetime safe for persistent/concurrent runtimes?
6. Can work happen at build time instead of boot?
7. Can it happen once at boot instead of per execution?
8. Is every dynamic island truly necessary and visible?
9. Can a scope seed carry runtime context without graph mutation?
10. Is lower-layer-vs-Foundation cost measured?
11. If Foundation owns a hash, is cryptographic collision resistance part of the requirement? Use SHA3-256 only when it is; otherwise use XXH128.

For every web/routing change also ask:

1. Does Webrick already own the behavior?
2. Can it happen in coordinated release compilation instead of request runtime?
3. Does it force Request creation unnecessarily?
4. Does it force InterMix scope unnecessarily?
5. Does it force global middleware unnecessarily?
6. Does it serialize live service state into a route artifact?
7. Can RuntimeCapabilities/adapter already provide the transport feature?
8. Is Foundation attempting to write a response Webrick should write?
9. Is a mutable registry being exposed after production freeze?
10. Is the cost measured against standalone compiled Webrick?

If the lower layer already provides the correct mechanism, Foundation uses it directly. If a genuinely general capability is missing, fix the lower layer once rather than building a Foundation-only runtime workaround.

---

## 28. Development starting checklist

1. use InterMix 10.0.4 as the current DI baseline and keep the intrinsic `ContainerInterface` planner regression covered rather than introducing any Foundation workaround;
2. implement/release Webrick WB-1 through WB-4;
3. capture lower-layer benchmark baselines;
4. update Foundation dependency floors to exact released versions;
5. begin Foundation composition-root work;
6. do not port ContainerFactory/ContainerCacheManager/WebrickRouterFactory architecture as temporary production scaffolding;
7. keep all four runtime paths in every composition, lifetime and release decision from the first implementation commit;
8. keep this file as the only runtime-development plan and merge every later library pass into it.

---

## 29. Development progress tracker

Use this section as the implementation ledger. A checked **overall phase** means the planned development implementation for that phase is complete and source-audited. Deferred QA, static analysis, benchmarks, and cross-runtime acceptance stay explicitly open in their own checkboxes and remain release gates under Phases 9–10; they no longer make an already-implemented phase appear unstarted. Item-level boxes in an in-progress phase are checked only when the current source/tests provide direct evidence.

### Overall phase status

- [X] Phase 0 — Freeze baselines
- [X] Phase 1 — Webrick prerequisites
- [X] Phase 2 — Foundation composition root — implementation and QA/static-analysis closure complete
- [X] Phase 3 — Provider graph migration — implementation and QA/static-analysis closure complete
- [X] Phase 4 — Runtime state/scope redesign — implementation and lifecycle/cache audits complete; coroutine acceptance is explicitly conditional on an available Swoole/OpenSwoole runtime
- [X] Phase 5 — Webrick build/runtime integration — development implementation complete; broader release/regression closure remains under Phases 9–10
- [X] Phase 6 — Error/maintenance/filesystem cleanup — implementation and benchmark-driven WB-5 decision complete
- [X] Phase 7 — Non-web generated runtimes — generated CLI/worker/scheduler production-container loading, scoped execution, graph-free reuse, persistence and cleanup acceptance complete
- [X] Phase 8 — Unified Foundation release generation — immutable all-runtime generation build/load, trust, activation, rollback, generation-owned worker topology and graceful worker replacement accepted end-to-end
- [X] Phase 9 — Full regression/performance pass — correctness, parity, static analysis, attribution benchmarks and real PHP-FPM acceptance complete
- [ ] Phase 10 — Final rescan/release readiness

### Phase 0 — Freeze baselines

- [X] Record direct InterMix 10.0.3 development and generated-production DI baselines.
- [X] Record standalone Webrick 5.1 compiled HTTP baselines.
- [X] Record current Foundation representative baseline before architecture changes.
- [X] Pin exact InterMix/Webrick/Foundation source/tag/commit identities in benchmark output.
- [X] Capture semantic behavior tests that Foundation 3 must preserve.
- [X] Record baseline memory, throughput, latency and cold/warm boot data needed for later attribution.

Baseline evidence: `docs/baselines/foundation-3-phase-0/README.md`.

### Phase 1 — Webrick prerequisites

- [X] WB-1: implement artifact-safe parameterized runtime-backed middleware descriptor.
- [X] WB-1: cover alias parsing, artifact encode/decode, capability calculation and runtime `resolveNow()` parameters.
- [X] WB-2: separate direct 404/405 routing-control handling from application exception handling.
- [X] WB-2: preserve logging/security semantics and add direct routing-error tests.
- [X] WB-3: use stable `webrick.request` scope label in development and compiled production.
- [X] WB-3: prove sequential/Fiber/coroutine isolation with the stable scope label.
- [X] WB-4: expose route-first graph-enrichment point before InterMix validation/compile.
- [X] WB-4: prove route-referenced controllers/middleware can be added without duplicate route discovery.
- [X] Re-run standalone Webrick correctness/static-analysis suites.
- [X] Re-run standalone Webrick compiled benchmarks and confirm no unexplained regression.
- [X] Release/tag the Webrick version carrying WB-1 through WB-4.
- [X] Update this plan's Webrick baseline to that exact released version.
- [X] Keep WB-5 outside the prerequisite release and defer its disposition to measured Phase 6 evidence.

Release evidence: Webrick 5.2, tag commit `b095efbad5e0284fb92d463d1616a0780667d3f2`.

### Phase 2 — Foundation composition root

- [X] Raise Foundation InterMix floor to `^10.0.4`.
- [X] Raise Foundation Webrick floor to the release carrying WB-1 through WB-4.
- [X] Introduce immutable `FoundationBuildContext`.
- [X] Implement one builder-first Foundation graph/composition source.
- [X] Use fresh builders for `web`, `cli`, `worker`, and `scheduler`.
- [X] Replace random container aliases with deterministic runtime aliases.
- [X] Make normalized environment/runtime/capability input explicit in graph composition.
- [X] Make ConfigRepository construction compilation-friendly where possible.
- [X] Refactor Application into a runtime-neutral façade/coordinator.
- [X] Remove Application as an unnecessary service-locator dependency from generated core services.
- [X] Retire/remove `ContainerFactory` architecture.
- [X] Add development-vs-production graph parity tests for the composition root.
- [X] Run deferred Phase 2 QA/static-analysis closure against the final development state.

### Phase 3 — Provider graph migration

#### Provider infrastructure

- [X] Change `ServiceProviderInterface` to builder-first graph contribution.
- [X] Separate graph contribution from process-level boot side effects.
- [X] Replace closure aliases with real aliases.
- [X] Replace deterministic closure factories with constructor/static recipes.
- [X] Remove `$app->make()` factories used only for constructor injection.
- [X] Move capability/package discovery to build composition.
- [X] Remove broad production `onMissing()` provider activation.
- [X] Reshape `ServiceRegistry` for finalized production topology.
- [X] Reshape `Bootstrapper` so normal production resolution does not discover/activate providers.

#### Provider-by-provider migration

- [X] PathServiceProvider.
- [X] JsonDispatchServiceProvider.
- [X] LoggingServiceProvider.
- [X] SecurityServiceProvider.
- [X] FilesystemServiceProvider.
- [X] CacheServiceProvider.
- [X] DatabaseServiceProvider.
- [X] ValidationServiceProvider.
- [X] CommunicationServiceProvider.
- [X] NotificationServiceProvider.
- [X] SessionServiceProvider.
- [X] RoutingServiceProvider InterMix definitions.
- [X] HttpServiceProvider InterMix definitions.
- [X] MessagingServiceProvider.
- [X] AuthServiceProvider.
- [X] AuthOtpServiceProvider.
- [X] AbstractAuthRegistrar.
- [X] Auth core registrar(s).
- [X] Auth store/cache registrars.
- [X] Auth password/token registrars.
- [X] Auth MFA/passkey registrars.
- [X] Auth notification/manager registrars.
- [X] Auth authorization/runtime/OAuth registrars.

#### Binding/lifetime gates

- [X] Every Foundation binding classified as singleton/scoped/transient/value/alias/recipe/seed/dynamic island for the Phase 3 graph.
- [X] Every Phase 3 singleton reviewed for persistent/concurrent safety; execution-state redesigns identified by that review remain assigned to Phase 4.
- [X] No Phase 3 singleton captures a scoped dependency from the first execution; auth notifier consumers are scoped and configured messaging middleware resolves inside execution.
- [X] Every remaining Foundation-owned dynamic island has an explicit reason and phase handoff.
- [X] Unexpected `skipped` definitions fail generation build/CI gates.
- [X] Optional absent capabilities are omitted rather than represented by unnecessary throwing factories.
- [X] Run deferred Phase 3 QA/static-analysis closure against the final development state.

Phase 3 development audit / dynamic-boundary ledger:

- Deterministic provider construction now uses `FactoryDefinition::construct()`, `FactoryDefinition::staticFactory()`, real aliases, explicit lifetimes, and build-context branches instead of constructor-only `$app->make()` factories.
- `ConfigRepository` uses an exportable recipe when possible; non-exportable application configuration remains an explicit value/dynamic boundary rather than being silently serialized.
- Development `Application`/mutable-container identity plus the legacy `ContainerCacheManager`/non-web execution bridge remain temporary development/transition boundaries for the Phase 4 and Phase 8 redesigns; they are not provider-activation mechanisms.
- `RoutingServiceProvider` intentionally retains live `WebrickMiddlewareFactory`, `WebrickRouterFactory`, `Registrar`, and `Collection` factories until Phase 5 replaces the live production router path with coordinated Webrick compilation.
- `HttpServiceProvider` intentionally retains `MaintenanceManager`, `ErrorHandler`, and live `RouterKernel` factories until Phases 5–6 move production HTTP ownership to compiled Webrick/runtime-adapter paths.
- `MessagingRuntimeResolver` is the explicit application-configured handler/listener/middleware/scheduled-message service-ID boundary. Its surrounding Omnibus graph is generated, and configured middleware service IDs resolve inside the active execution instead of being captured by singleton topology.
- `DatabaseMigrationManager` is recipe-built and uses the finalized PSR container only for application-configured migration/seeder class IDs at execution time.
- gRPC inbound dispatch is recipe-built/scoped and uses the finalized PSR container only for application-configured handler service IDs at execution time.
- The lifetime review intentionally handed `RuntimeContextTracker`, principal/current-auth state, active browser-session state, DB execution bookkeeping, and the Foundation/Omnibus execution-scope bridge to Phase 4. The current Phase 4 implementation has removed/redesigned those core execution-state boundaries; remaining Phase 4 work is tracked below as lifecycle/cache audit and isolation proof.

### Phase 4 — Runtime state/scope redesign

- [X] Adopt semantic non-web scope names: `foundation.cli`, `foundation.worker`, `foundation.scheduler`.
- [X] Consume stable Webrick `webrick.request` for web state.
- [X] Move execution/request/job IDs to scope seeds/correlation data instead of scope names.
- [X] Convert non-web `ExecutionScope` to `withinScope()` semantics.
- [X] Preserve primary application exception over cleanup failures.
- [X] Redesign or remove mutable-singleton `RuntimeContextTracker`.
- [X] Make principal/current-auth state execution-scoped.
- [X] Make active browser-session state execution-scoped while keeping reusable store/lock infrastructure separate.
- [X] Make DB touched/transaction/fresh-connection cleanup bookkeeping execution-local.
- [X] Audit logging correlation/context lifetime.
- [X] Audit memoizers/caches for process-safe vs generation-bound vs execution-cleared state.
- [X] Add deterministic scope-leave cleanup where lifecycle semantics fit.
- [X] Prove sequential scope isolation.
- [X] Prove Fiber isolation.
- [X] Record Swoole/OpenSwoole coroutine isolation as conditional; neither extension is available in the Phase 0–6 closure environment, while the executable Fiber isolation proof remains green.
- [X] Prove cleanup on success, exception and cancellation.

Phase 4 audit evidence: `JsonLogger` keeps no execution context; `ExceptionReporter` retains only a bounded process-local throttle-signature window. Cache stores and explicit memoizers are process-level/generation-safe reusable state, while DB connections, principal/session state and other mutable execution bookkeeping live in scoped `RuntimeExecutionState`. `ExecutionScopeIsolationTest` and `PersistentExecutionStateIsolationTest` cover sequential isolation, interleaved Fiber isolation, scope cleanup, primary-error preservation, aborted Fiber cleanup, transaction rollback and context reset.

### Phase 5 — Webrick build/runtime integration

- [X] Remove production `WebrickRouterFactory` path.
- [X] Make route registration a development/build concern only.
- [X] Build routes once before InterMix compile.
- [X] Enrich InterMix graph from finalized RouterBuildResult/ExecutionPlans.
- [X] Compile web InterMix exactly once through the coordinated Webrick release path.
- [X] Use artifact-safe middleware descriptors for Foundation middleware.
- [X] Use parameterized runtime-backed descriptor for role/permission/policy/OAuth middleware.
- [X] Default `preGlobal`, `postGlobal`, `preGlobalTags`, and `postGlobalTags` to empty.
- [X] Ensure Foundation-owned route artifacts contain no captured Application/container/service graphs.
- [X] Remove live production Registrar/Collection dependencies.
- [X] Move URL generation to compiled/frozen Webrick URL runtime.
- [X] Load the compiled Webrick router with `Router::fromCompiled()` and construct `ServerKernel` in production (Webrick 5.2 runtime path).
- [X] Select RuntimeAdapter once at production runtime/process boot.
- [X] Use Webrick RuntimeServer for native serving.
- [X] Keep `$app->handle(Request)` only as embedded/testing convenience.
- [X] Assert a minimal route remains Request-free.
- [X] Assert a minimal route remains scope-free.
- [X] Assert middleware/request/scope capabilities match compiled ExecutionPlans.

Phase 5 proof: `WebReleaseRuntimeTest` verifies frozen compiled URL generation, Request-free/scope-free minimal execution plans, middleware Request/scope capabilities and production route/runtime behavior.

### Phase 6 — Error/maintenance/filesystem cleanup

- [X] Integrate Webrick WB-2 application exception path without forcing custom routing-control rendering.
- [X] Preserve production-safe exception rendering/logging.
- [X] Remove maintenance work from the old universal Foundation HttpKernel path.
- [X] Implement Webrick maintenance middleware/state with bounded worker-local refresh where semantics fit.
- [X] Benchmark maintenance enabled/disabled overhead.
- [X] Decide WB-5 pre-routing gate only from benchmark evidence.
- [X] Remove direct `php://output`/native-output writes from Foundation portable response producers and guard the production tree against regressions.
- [X] Use Webrick FileBody/download/inline/ranged APIs for local files where appropriate.
- [X] Expose non-local/custom Pathwise response bodies as BodyStream or chunk iterables.
- [X] Preserve X-Sendfile/X-Accel policy correctly.
- [X] Use RuntimeCapabilities instead of Foundation transport detection.
- [X] Verify exactly one layer owns native response emission.
- [X] Add SAPI plus persistent-runtime file/stream response tests.

Phase 6 implementation evidence: the embedded `HttpKernel` is a thin Webrick delegate; `WebReleaseRuntimeTest` proves direct 404/405 ownership, safe application-exception rendering/logging and compiled maintenance behavior; `FilesystemResponseFactory` emits Webrick `FileBody` or portable chunk iterables; `FilesystemHttpBridgeTest` covers local range/HEAD/conditional and non-local stream semantics; `FilesystemOffloadPolicyTest` proves default portable bodies plus explicit X-Sendfile/X-Accel behavior and rejects non-local X-Sendfile use; `PortableResponseOutputBoundaryTest` performs the tree-wide direct-output guard; `WebRuntimeEmissionTest` proves SAPI/persistent adapter ownership and exactly one native write. The PHP 8.4.25 compiled-persistent-runtime maintenance microbenchmark (25,000 operations/sample, 2,500 warmups, five repetitions) measured a 7,637.1 ns/op disabled median with zero Request materializations and a 57,319.1 ns/op enabled median with 127,500 Request materializations, a 650.54% increase against a 5% review threshold. That evidence marks a Webrick-owned WB-5 pre-routing-gate investigation as justified; it does not justify a duplicate Foundation abstraction, and representative Apache/Nginx + PHP-FPM + OPcache acceptance remains correctly assigned to Phase 9.

### Phase 7 — Non-web generated runtimes

**Lower-layer gate resolved:** InterMix 10.0.4 contains the intrinsic `Psr\Container\ContainerInterface` static-runtime correction required by Foundation. The Foundation dependency floor is now `^10.0.4`; strict generated-runtime acceptance can proceed directly, and no Foundation proxy/service-locator workaround is present or permitted.

#### CLI

- [X] Build CLI graph with a fresh `foundation.cli` builder.
- [X] Compile/load CLI ProductionContainer.
- [X] Enter CLI scope only when scoped execution state is required.
- [X] Remove unrelated web/worker capabilities from minimal CLI graph.

#### Worker

- [X] Build worker graph with a fresh `foundation.worker` builder.
- [X] Compile/load one worker ProductionContainer per worker process.
- [X] Reuse production runtime across jobs/messages.
- [X] Enter one worker scope per job/message.
- [X] Seed envelope/job/execution context.
- [X] Ensure success/failure/cancellation cleanup.
- [X] Ensure no graph rebuild per item.

#### Scheduler

- [X] Build scheduler graph with a fresh `foundation.scheduler` builder.
- [X] Compile/load scheduler ProductionContainer.
- [X] Enter one scheduler scope per scheduled invocation when needed.
- [X] Keep scheduler graph limited to needed command/dispatch capabilities.
- [X] Ensure no graph rebuild per invocation.

#### Non-web persistence

- [X] Run long sequential worker/scheduler execution tests.
- [X] Verify bounded memory.
- [X] Verify no transaction/context/message carry-over.
- [X] Verify locks/temp resources release deterministically.

Phase 7 acceptance evidence: `NonWebGraphFactory` composes fresh deterministic runtime graphs and `GeneratedRuntimeCompiler` strictly compiles them with `skipped=[]`, staged atomic publication and last-good preservation. Trusted `GeneratedRuntime::loadPrevalidated()` verifies external Foundation metadata plus the InterMix artifact ABI/digest without rebuilding the source graph; generated CLI acceptance poisons source-provider discovery after compilation and proves scoped state is entered only when explicitly requested. `WorkerRuntime` reuses one generated ProductionContainer across jobs/messages while `GeneratedNonWebRuntimeTest` and `GeneratedWorkerCancellationTest` prove job/envelope/execution seeding plus success/failure/restart cleanup with no source-graph rebuild. The existing `ScheduleManager`/`SchedulerRuntime` path now owns one complete `foundation.scheduler` scope per scheduled entry, including history, overlap/single-server lock ownership and process execution; generated scheduler acceptance proves cached topology, source-discovery poisoning, hot-container reuse and deterministic lock release. `GeneratedPhase7RuntimeClosureTest` runs long sequential generated worker/scheduler loops, verifies bounded memory, transaction rollback, job/message/schedule context isolation and deterministic deferred temp/lock cleanup. Phase 7 generated-runtime acceptance passes on the lowest supported dependency matrix; remaining suite failures are pre-existing web/session compatibility items assigned outside Phase 7.

### Phase 8 — Unified Foundation release generation

- [X] Define immutable generation directory layout.
- [X] Build web bundle through Webrick coordinated release compiler end-to-end.
- [X] Build CLI InterMix artifact directly end-to-end.
- [X] Build worker InterMix artifact directly end-to-end.
- [X] Build scheduler InterMix artifact directly end-to-end.
- [X] Collect and validate InterMix compile/skipped/digest metadata in the unified compiler.
- [X] Fail generation build on unexpected dynamic islands/skipped definitions.
- [X] Add only useful deterministic command/scheduler/worker topology artifacts.
- [X] Write OPcache-friendly Foundation generation manifest.
- [X] Reference Webrick release manifest without duplicating its owned identity fields.
- [X] Verify runtime environment/config identity belongs to the same Foundation generation before publication.
- [X] Implement atomic active-generation switch.
- [X] Leave previous generation active when any build/verification step fails.
- [X] Implement rollback/incomplete-generation tests for the active pointer/generation infrastructure.
- [X] Implement trusted-prevalidated mode only with immutable external Foundation trust metadata.
- [X] Implement graceful persistent-worker replacement on generation change.
- [X] Keep old-generation cleanup outside request/job hot paths.

Phase 8 acceptance evidence: `FoundationReleaseCompiler` stages under `generations/.staging-*`, compiles the Webrick web bundle plus direct InterMix CLI/worker/scheduler artifacts, records and validates runtime identities/compile metadata, compiles the deterministic `worker/providers.php` topology, verifies its manifest SHA before publication, publishes one immutable generation and switches the active pointer only after complete verification. `FoundationReleaseManifest`, `ActiveGeneration`, `FoundationReleaseRuntime` and `GeneratedRuntime::loadPrevalidated()` enforce the Foundation trust boundary and subordinate artifact identities; infrastructure tests cover activation, incomplete targets, rollback/trust mismatch, traversal rejection and out-of-band pruning. `FoundationReleaseEndToEndTest` builds and boots all four runtimes from one generation, then poisons source web routes, provider discovery and worker topology files to prove release-owned runtime independence. `WorkerTopologyTrustTest` rejects missing/tampered topology, and worker replacement acceptance proves generation A→B graceful restart behavior. Webrick is now floored at `^5.3` so the accepted SAPI/streaming path includes the lower-layer correctness fixes rather than a Foundation workaround.

### Phase 9 — Full regression/performance pass

- [X] Run complete correctness test suite.
- [X] Run security-sensitive auth/session/CSRF/OTP/WebAuthn regression suites applicable at this stage.
- [X] Run static analysis/phpforge checks.
- [X] Run InterMix development/generated-production parity matrix.
- [X] Run scope/Fiber/coroutine isolation matrix.
- [X] Run Webrick development vs compiled-production suite.
- [X] Run artifact corruption/mismatch/stale-generation tests.
- [X] Run SAPI/FPM integration tests.
- [X] Run at least one persistent HTTP adapter integration suite.
- [X] Run long worker/scheduler persistence tests.
- [X] Benchmark Foundation DI tax against direct InterMix.
- [X] Benchmark Foundation HTTP tax against standalone compiled Webrick.
- [X] Repeat representative HTTP benchmark through real Apache/Nginx + PHP-FPM + OPcache.
- [X] Measure throughput, p50/p95/p99, memory/peak and cold/warm boot.
- [X] Use Webrick stage profiling only where measured overhead remains.
- [X] Optimize only attributable Foundation overhead.
- [X] Record final benchmark evidence in release documentation.

Phase 9 acceptance evidence: corrected-head CI run `33946071733` on commit `7ce759d36e71e469314fdfa0ca54f75f24e78d9c` is green across PHP 8.4/8.5 stable and prefer-lowest QA with skipped tests forbidden, PHP 8.4/8.5 analyzers, clean install, representative benchmarks, maintenance benchmark, DI attribution, HTTP attribution, and the real PHP-FPM server gate. `Phase9RuntimeParityTest` compares identical explicit capability topology between development and generated production for CLI/worker/scheduler plus Webrick development/compiled production; the production auth guard remains unchanged and the parity fixture no longer activates unrelated discovered auth capabilities. Existing scope/isolation, release-trust/corruption, persistent adapter, and long worker/scheduler suites provide the remaining correctness gates; Swoole/OpenSwoole coroutine execution remains conditional on extension availability as recorded in Phase 4. The PHP 8.4 DI attribution run measured direct InterMix resolution at 221.8 ns median, Foundation generated-container resolution at 218.67 ns, `Application::make()` at 240.67 ns, and the Foundation execution boundary at 5,113.29 ns versus 1,404.21 ns for bare InterMix `withinScope()`—about 3.709 µs absolute additional execution-boundary cost. The PHP 8.5 compiled HTTP attribution measured standalone Webrick at 4,570.43 ns/request and Foundation at 4,614.67 ns/request, a 44.24 ns / 0.97% warm-request tax with equal 8 MiB final / 10 MiB peak process memory; the larger compile and runtime-boot differences are build/boot-plane costs, not persistent request hot-path cost. Real PHP 8.4.25 `fpm-fcgi` with OPcache enabled handled 20,000 requests at concurrency 32 with zero failures: Nginx 1.24 delivered 2,089.04 req/s at p50/p95/p99 15/22/26 ms, while Apache 2.4.58 delivered 1,782.89 req/s at 17/25/29 ms; cold-first and warm-sequential latency plus aggregate FPM-pool RSS were recorded in the uploaded benchmark artifact, with RSS explicitly treated as aggregate per-process RSS rather than unique physical memory. Because the attributable hot-path tax is approximately 1%, DI façade cost is tens of nanoseconds, and the execution-boundary tax is only a few microseconds, no production-code optimization or Webrick stage profiling is justified; preserving the simpler lifecycle/scope semantics is the accepted optimization decision.

### Phase 10 — Final rescan/release readiness

- [X] Rescan for direct old dynamic Container construction/mutation.
- [X] Rescan for `compileTo`, `useCompiled`, old `usePrevalidated` resolver-map production paths.
- [X] Rescan for closure aliases/deterministic closure factories.
- [X] Rescan for Application/container capture in generated services.
- [X] Rescan all dynamic islands and confirm allowlist/reasons.
- [X] Rescan lifetimes for mutable singleton execution state.
- [X] Rescan scope naming/cleanup/Fiber/coroutine safety.
- [X] Rescan for live production Registrar/Collection usage.
- [X] Rescan for RouteCacheManager/RouteCachePath production duplication.
- [X] Rescan for production route/provider/module/file discovery.
- [X] Rescan for unnecessary Request/scope/global middleware creation.
- [X] Rescan for direct output/native runtime handle use.
- [X] Rescan for repeated hashing/manifest parsing in hot paths.
- [ ] Enforce section 17.1 across final Foundation source: migrate Foundation-owned security-sensitive hashes to SHA3-256, non-security fingerprints/compaction to XXH128, and retain SHA-256 only for explicit protocol/persisted/lower-layer compatibility.
- [X] Rescan for hidden DB/cache capability activation.
- [X] Rescan cleanup paths for primary-exception masking.
- [X] Remove stale InterMix 9/Webrick 4 configuration, tests and documentation.
- [ ] Validate all hard implementation gates in section 24.
- [ ] Validate final definition of done in section 25.
- [X] Publish migration notes and final benchmark evidence.

Phase 10 rescan evidence: the final three audit batches removed hidden development-container mutation, made release runtime configuration generation-owned and source-discovery-free, kept optional capability activation explicit/minimal, hardened scheduler/worker cleanup so secondary cleanup failures cannot replace primary failures, removed stale resolver/cache switches, and refreshed the runtime/migration documentation. The two aggregate closure gates remain open only for final current-head CI confirmation and the InfByte skeleton handoff to the trusted Foundation 3 runtime lifecycle.

### Future lower-library utilization tracker

Each item remains unchecked until its dedicated deep audit is performed and merged into this same plan:

- [X] ArrayKit 5.2.0 current-version utilization pass.
- [X] UID 5.0 current-version utilization pass.
- [ ] CacheLayer 3.2.0 audit / CacheLayer 3.3.0 atomic-capability prerequisite and Foundation integration pass.
- [ ] DBLayer current-version utilization pass.
- [ ] ReqShield current-version utilization pass.
- [ ] Omnibus current-version utilization pass.
- [ ] TalkingBytes current-version utilization pass.
- [ ] OTP 6.0 current-version utilization pass.
- [ ] Epicrypt current-version utilization pass.
- [ ] WebAuthn integration pass.
- [ ] Pathwise 3.1 current-version utilization pass.

When a later library pass changes architecture or implementation order, update both its detailed section and the applicable checkboxes here in the same commit.