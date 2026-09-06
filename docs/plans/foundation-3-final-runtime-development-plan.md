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
- Foundation config fingerprint may stay SHA-256 if desired, but it is distinct from xxh128 artifact identities;
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
- real HTTP and non-web benchmarks show attributable Foundation overhead;
- complete source tree rescanned after implementation;
- InfByte consumes Foundation's final build/runtime lifecycle directly instead of recreating another framework runtime.

---

## 26. Subsequent lower-library passes

After InterMix + Webrick implementation contracts are frozen, run the same dedicated current-version utilization process for each lower library. Every pass must distinguish lower-layer primitives from Foundation policy, measure any proposed hot-path change, update this same document, and leave its progress-tracker box unchecked until code/tests/benchmarks prove the pass complete.

### 26.1 ArrayKit 5.1.1 utilization pass

**Baseline**

* package: `infocyph/arraykit` `^5.1.1`;
* audited release: ArrayKit 5.1.1;
* tag commit: `dd0eb07a9f623fbbb9235cd9e4302f0f588bcf23`.

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

Current Foundation direction already partially follows this boundary: `EnvironmentLoader` uses ArrayKit `EnvParser` and `Environment`, while `ConfigRepository` extends ArrayKit `Config` and uses `LazyFileConfig`/`DotNotation`. The pass must finish the duplication audit rather than replace working lower-layer use with another Foundation abstraction.

**Audit and implementation checklist**

* [ ] Rescan Foundation for any manual `.env` syntax parsing, interpolation, quoting, reference expansion, BOM/NUL handling or file-line parsing that duplicates `EnvParser`.
* [ ] Rescan Foundation for direct/raw environment lookup that bypasses ArrayKit `Environment` without a documented runtime-boundary reason.
* [ ] Verify Foundation's global `env()` helper delegates raw lookup to ArrayKit and retains only intentionally framework-specific value coercion.
* [ ] Review `EnvironmentLoader` for policy-only responsibilities: source selection/precedence, host-value protection and process hydration; do not move those framework choices into ArrayKit.
* [ ] Review `ConfigRepository` against ArrayKit `Config`, `DotNotation` and `LazyFileConfig`; remove duplicated generic get/set/path/cache behavior while retaining Foundation fallback/override/compiled-release semantics.
* [ ] Review `ConfigLoader` so source file discovery remains development/build-plane only and delegates generic lazy file mechanics to ArrayKit where appropriate.
* [ ] Review `ConfigCacheManager` against ArrayKit lazy namespace-cache facilities; retain only Foundation-owned artifact/deployment coordination that ArrayKit cannot correctly own.
* [ ] Review `ConfigMerger` against ArrayKit merge primitives. Reuse ArrayKit only when precedence/list/associative semantics are exactly equivalent; do not trade correctness for API uniformity.
* [ ] Verify the trusted generated `config.php` path constructs a compiled `ConfigRepository` without `.env`, config-directory, provider or module source discovery.
* [ ] Ensure no request/job/schedule hot path scans environment/config files or reparses `.env`.
* [ ] Audit `ConfigExportValidator`/release config normalization for generic ArrayKit functionality before retaining Foundation-local traversal logic.
* [ ] Preserve a small Foundation façade only where it expresses application semantics or protects the release trust boundary.

**Correctness acceptance**

* [ ] Test `.env` and `.env.local` precedence plus configured `app.env_files` order.
* [ ] Test protection of host-provided `$_ENV`, `$_SERVER` and process variables from unintended file override.
* [ ] Test ArrayKit interpolation/reference behavior through Foundation, including raw/literal-dollar cases where Foundation exposes them.
* [ ] Test BOM/NUL rejection through the Foundation load path.
* [ ] Test missing optional environment files and explicit environment-loading disablement.
* [ ] Test lazy namespace fallback/source/override precedence and cache-corruption fallback behavior.
* [ ] Test source config versus compiled release config observable parity.
* [ ] Poison `.env`, config directories and provider/module source discovery after release compilation and prove production generation boot remains source-independent.

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

### 26.2 UID 5.0 utilization pass

**Baseline**

* package: `infocyph/uid` `^5.0`;
* audited release: UID 5.0;
* tag commit: `4a95eb8058e73c72e74e44fedd25755198899eae`.

UID 5.0 provides the Foundation-relevant identifier primitives directly: UUID v1-v8 generation/validation/parsing, monotonic/fork-aware UUIDv7 state, NanoID, ObjectID, RandomId, deterministic IDs and opaque IDs. Foundation must not recreate those algorithms.

**Ownership decision**

UID owns identifier generation, encoding, validation/parsing and algorithm-specific monotonic/fork-safe state.

Foundation owns identifier **lifecycle**:

* deciding whether a logical execution needs a correlation identity at all;
* choosing the algorithm/format according to semantics rather than API uniformity;
* preserving and reusing an incoming request/message/job/execution identity where one already exists;
* generating at most one fallback execution ID per logical execution;
* propagating that identity through InterMix scope seeds, history, logging/events and nested execution boundaries;
* keeping DI aliases and scope names semantic/stable rather than random;
* keeping release/artifact trust identities as cryptographic digests/fingerprints rather than random UIDs;
* distinguishing persisted/business/public IDs from transient staging/temp entropy.

Current Foundation centralizes non-web correlation generation in `Runtime\ExecutionId`, backed by `Id::uuid7()`. This is the correct architectural boundary. The utilization pass should optimize **when** that generation occurs and how identities are reused before considering a different UID algorithm.

**Current reuse findings to preserve**

* Omnibus-backed `InterMixExecutionScope` normalizes and reuses the message ID instead of generating a second Foundation correlation ID.
* `WorkerRuntime` accepts a supplied execution identity and passes it through unchanged.
* scheduler execution creates one identity per scheduled entry and reuses it for the scope/history/event lifecycle.
* nested command execution inherits the active `ExecutionId` where available instead of creating a new one.
* stable InterMix scope labels remain `foundation.cli`, `foundation.worker`, `foundation.scheduler`; execution IDs are seeds/correlation data and must never return to scope-name construction.
* the minimal Webrick path does not need a Foundation-generated UUID merely to serve a request; do not add universal web correlation-ID generation unless an explicit feature requires it.

**Algorithm/performance baseline**

UID 5.0's published PHP 8.4 hotspot benchmark records approximately:

| Generator |  Average |
| --------- | -------: |
| ObjectID  | 0.662 µs |
| NanoID    | 0.777 µs |
| UUID v4   | 1.136 µs |
| UUID v7   | 2.067 µs |
| RandomId  | 4.358 µs |

Therefore `RandomId` is **not** a faster fallback than UUIDv7 in UID 5.0. NanoID/ObjectID are faster in that benchmark but change representation/semantics. Foundation must not switch algorithms solely for shorter output or microbenchmark ranking.

UUIDv7 remains the default candidate where standard UUID interoperability, temporal ordering, operator familiarity and one correlation representation across subsystems are valuable. A compact alternative is acceptable only if the relevant Foundation boundary has no UUID/time-order contract and Foundation-specific measurement shows a meaningful gain.

**Audit and implementation checklist**

* [ ] Inventory every Foundation identifier/randomness creation and classify it as execution correlation, domain/public identity, release-generation identity, artifact hash/fingerprint, lock/token/security randomness, or transient staging/temp suffix.
* [ ] Route semantic ID generation through UID primitives; do not route hashes, cryptographic secrets, nonces or pure temp entropy through UID merely for consistency.
* [ ] Verify every request/message/job/command/schedule boundary reuses an upstream identity when one is already authoritative.
* [ ] Preserve Omnibus message-ID reuse in `InterMixExecutionScope`.
* [ ] Preserve supplied worker execution IDs without normalization into a second generated value.
* [ ] Preserve nested command execution-ID inheritance.
* [ ] Verify scheduler creates exactly one execution ID per logical scheduled run and reuses it across scope/history/events.
* [ ] Review generic `ExecutionScope::run()` eager UUIDv7 fallback. Keep eager creation if its callback/history contract always requires an ID; make it lazy only if semantics remain clean and measurements justify additional complexity.
* [ ] Review release generation IDs separately. Timestamp + cryptographic entropy may remain Foundation-owned build-plane formatting; do not replace transient `.staging-*` random suffixes merely to eliminate `random_bytes()` calls.
* [ ] Ensure artifact/config/router/container trust continues to use digest/fingerprint identities, never UID randomness.
* [ ] Rescan for accidental random IDs in DI aliases, provider IDs, scope names or other deterministic graph topology.
* [ ] Check whether any Foundation-owned public/persisted IDs should deliberately use UID deterministic/opaque/NanoID/ObjectID primitives; document semantics before changing format.

**Correctness acceptance**

* [ ] Test supplied execution-ID preservation byte-for-byte.
* [ ] Test fallback generation uniqueness and UUIDv7 validity for the default execution path.
* [ ] Test nested execution/command reuse rather than ID multiplication.
* [ ] Test Omnibus message-ID propagation into Foundation execution state.
* [ ] Test scheduler scope/history/event identity equality for the same run.
* [ ] Test sequential and interleaved Fiber executions do not cross-contaminate IDs.
* [ ] Test persistent worker reuse does not retain a previous job's identity.
* [ ] Exercise UID UUIDv7 fork-state behavior where `pcntl_fork` is available and ensure Foundation adds no process-local wrapper state that defeats UID's fork reset.

**Performance acceptance**

Benchmark the actual Foundation execution boundary rather than only raw generators:

1. `ExecutionScope` with a caller-supplied ID;
2. `ExecutionScope` with generated UUIDv7 fallback;
3. raw UID UUIDv7 generation as attribution baseline;
4. NanoID/ObjectID candidates only when their semantics are acceptable for the same boundary;
5. repeated worker/scheduler executions under persistent runtime;
6. nested command/message execution where identity is reused.

Attribute the existing Phase 9 execution-boundary overhead before optimizing ID generation. If UUIDv7 contributes only a small absolute fraction, preserve the simpler standard identity model.

**Completion gate**

The UID tracker can be checked only after all Foundation ID/randomness sites are classified, upstream identity reuse is proven, any eager-generation change is benchmark-driven, isolation/persistence tests pass and the final identity lifecycle is documented. No format change is required merely to complete the pass.

### 26.3 CacheLayer 3.2.0 utilization pass

**Baseline**

* package: `infocyph/cachelayer` `^3.2.0`;
* audited release: CacheLayer 3.2.0;
* tag commit: `481c664e7431fb1f901346046e34b31beb722854`.

**Ownership decision**

CacheLayer owns generic cache/storage mechanics and their correctness contracts. Foundation must compose them and add application/security policy rather than maintain competing cache, lock, counter, invalidation or cluster runtimes.

CacheLayer owns:

* PSR cache/simple-cache behavior and adapter implementation;
* key/namespace validation;
* per-cache serialization/compression/integrity policy through `CacheOptions`;
* native bulk cache operations;
* cache-native lock providers and lease handles;
* atomic counter stores;
* tiering and memoization primitives;
* Node Cache and Cluster Cache invalidation/outbox behavior;
* `AuthenticationStateCacheInterface` and its effective security-capability contract;
* backend-specific fail-open/authoritative/integrity/coordination semantics.

Foundation owns:

* named application cache/store configuration;
* explicit runtime/capability activation;
* store selection per subsystem;
* application-level shared-state topology validation;
* security policy deciding which cache may hold authentication state;
* logical-to-physical key encoding where Foundation owns the logical namespace;
* DI lifetime and release-generation participation;
* deciding whether a subsystem may use generic cache semantics, authoritative security-state semantics, or a durable non-cache store.

Generic caches must remain flexible. Do **not** globally force every Foundation cache to be fail-closed, authoritative, signed or object-free merely because authentication state requires stricter semantics.

**Confirmed current findings**

1. Foundation's CacheLayer auth adapters currently use physical prefixes such as `foundation:auth:ttl:` and `foundation:auth:counter:`. CacheLayer 3.2.0 accepts only 1–64 character keys matching `[A-Za-z0-9_.-]+`; `:` is invalid and raw Foundation logical keys can also exceed the length limit. This is a real compatibility bug.
2. `CacheLayerTtlStore::pull()` performs `get()` followed by `delete()`. That is not safe as one-time consumption when two executions race.
3. `CacheLayerFactory` already exposes the important CacheLayer 3.2 options and can validate an explicitly marked authentication-state store, but `AuthCacheRegistrar` consumes plain `CacheInterface`. Production auth must therefore prove that the **selected** auth-state cache satisfies the native capability contract rather than assuming the application's default cache is safe.
4. Foundation MFA challenge storage currently serializes an `MfaChallenge` object through the TTL store. A hardened auth-state cache should be able to run with native object payloads disabled; Foundation should prefer a scalar/array record plus rehydration unless there is a measured reason to require object serialization.
5. Native CacheLayer atomic counters, locks, Node/Cluster cache and invalidation/outbox facilities are already the correct lower-layer ownership. Do not mirror them in Foundation.

**Audit and implementation checklist**

* [ ] Introduce one canonical Foundation auth-state physical-key encoder shared by TTL and counter adapters.
* [ ] Encode/hash arbitrary Foundation logical auth keys into legal deterministic CacheLayer keys no longer than 64 characters; do not scatter ad-hoc character replacement across adapters.
* [ ] Preserve domain separation in the physical namespace, for example short legal prefixes for TTL state versus counters followed by a full collision-resistant digest.
* [ ] Require the production auth-state cache to implement `AuthenticationStateCacheInterface` and prove `isFailOpen() === false`, `hasPayloadIntegrity() === true`, `isAuthoritative() === true`, and a same-domain `authenticationStateLock()` is available where one-time/replay semantics require coordination.
* [ ] Fail insecure auth-cache topology during composition/release validation, not on the first authentication request.
* [ ] Allow an explicit dedicated auth-state cache/store so ordinary application caching does not inherit unnecessary security cost; the default cache may be reused only when it satisfies the full contract.
* [ ] Make one-time `pull()`/consume operations race-safe through CacheLayer's native authentication-state lock or a stronger native atomic primitive if CacheLayer exposes one; do not build another Foundation lock runtime.
* [ ] Require `AtomicCounterStoreInterface` for production security counters/rate/replay state. Keep the get+set counter adapter only for semantics where non-atomic behavior is explicitly acceptable.
* [ ] Replace object-valued MFA challenge payloads with an exportable scalar/array record where practical so the auth-state cache can keep `allowObjects=false`.
* [ ] Verify generic cache stores remain process/generation-safe singletons and do not capture request/job execution state.
* [ ] Keep CacheLayer process memoizers/static convenience state out of Foundation execution-scoped DI unless an explicit subsystem contract requires it.
* [ ] Use CacheLayer native bulk APIs for Foundation bulk get/set/delete work instead of per-key loops where semantics match.
* [ ] Keep Node Cache / Cluster Cache invalidation, cursor/outbox and poison-event behavior lower-layer-owned; Foundation only supplies topology/configuration and application invalidation policy.
* [ ] Keep optional CacheLayer capability activation explicit in `FoundationBuildContext`; package installation alone must not activate cache services in a production graph.
* [ ] Recheck local/file/php-files/Redis/Valkey/PDO and tiered-store configuration against CacheLayer 3.2 security guidance without duplicating adapter-specific validation in Foundation.

**Correctness and security acceptance**

* [ ] Test legal physical key shape and <=64-character bound for long, Unicode-adjacent, colon-heavy and attacker-controlled logical auth keys.
* [ ] Test deterministic mapping and collision resistance across TTL/counter domains.
* [ ] Test production auth-cache rejection for fail-open, unsigned, non-authoritative and missing-lock profiles.
* [ ] Test a valid dedicated authentication-state cache boots successfully while an unrelated default cache remains permissive.
* [ ] Test concurrent one-time consumption so at most one execution receives the value.
* [ ] Test lock release on success/failure/cancellation and ensure lock-cleanup errors cannot mask the primary auth failure.
* [ ] Test atomic counter races under the production counter adapter.
* [ ] Test hardened challenge serialization with native object payloads disabled.
* [ ] Test sequential/Fiber/persistent-worker reuse does not leak cache coordination state between executions.
* [ ] Test disabled CacheLayer capability leaves unrelated runtime graphs free of CacheLayer services/connections.
* [ ] Test Cluster/Node integration through CacheLayer's own invalidation contracts rather than Foundation-specific replicas of them.

**Performance acceptance**

Benchmark at minimum:

1. production graph with CacheLayer capability absent versus present-but-unused;
2. first named-store construction and warm named-store lookup;
3. direct CacheLayer get/set/delete versus the Foundation manager/adapter boundary;
4. native CacheLayer bulk operations versus any previous Foundation loop;
5. authentication-state key encoding overhead;
6. coordinated one-time pull versus plain get/delete;
7. atomic counter increment/reset;
8. repeated cache operations in persistent worker/scheduler runtimes with memory measurement;
9. Node/Cluster invalidation only where Foundation actually exposes that topology.

Do not optimize away CacheLayer's security checks for auth state. Any hot-path optimization must be measured against direct CacheLayer and must preserve the lower-layer capability contract.

**Completion gate**

The CacheLayer tracker can be checked only when physical auth-key compatibility is fixed, the selected production auth-state cache is validated against CacheLayer's native capability contract, one-time state/counters have proven concurrency semantics, optional-capability cost remains cold, persistent-runtime tests pass and attribution benchmarks record the final Foundation overhead.

### 26.4 OTP 6.0 utilization pass

**Baseline**

* package: `infocyph/otp` `^6.0` (Foundation development/integration dependency);
* audited release: OTP 6.0;
* tag commit: `524a94d7ac71d5d385f35596a89c472c8e1ba33f`;
* OTP 6.0 requires PHP >=8.4 and integrates with CacheLayer `^3.1.1`; Foundation's CacheLayer floor is already newer at `^3.2.0`.

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
2. Production TOTP and non-counter OCRA depend on CacheLayer's authoritative/fail-closed/integrity/lock contract. That contract must become a release/composition gate through the CacheLayer pass rather than depending on first-use verification failure.
3. `MfaFactor` carries the OTP secret as a string value. That does not prove plaintext persistence, but every production factor-store adapter must be audited to prove MFA secrets are protected at rest and never exposed through logs/cache keys/errors. Exact cryptographic/key-lifecycle design remains coordinated with the Epicrypt pass.
4. Recovery-code HMAC currently derives a domain-separated key from the auth token secret. Keep the domain separation, but explicitly decide whether Foundation 3 should use a dedicated recovery-code key or a proper subkey derivation from a master secret; finalize this with the Epicrypt pass rather than silently coupling unrelated secret lifecycles.
5. OTP rotation primitives can plan/describe secret rotation, but Foundation still owns atomic persistence/activation and concurrent verification policy during a rotation window.
6. MFA challenge storage intersects the CacheLayer pass: logical challenge keys require the new legal physical key encoder, and a hardened auth-state cache should not require native object payloads merely because Foundation currently stores an object.

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

### 26.6 DBLayer utilization pass

This slot is reserved for the dedicated DBLayer audit in the next three-library batch.

The DBLayer pass must follow the same complete format as 26.1–26.5:

* pin the latest released DBLayer tag and exact commit directly from `infocyph/DBLayer`;
* establish DBLayer-versus-Foundation ownership;
* audit every Foundation database adapter and repository;
* audit connection and transaction lifetimes;
* audit generated-runtime behavior;
* audit execution-local touched/transaction/fresh-connection state;
* audit query-cache integration with CacheLayer;
* audit migration/schema lifecycle;
* audit atomic compare-and-swap requirements used by OTP/MFA;
* audit atomic passkey credential-state persistence required by section 26.11;
* add correctness/concurrency/persistent-runtime acceptance;
* add direct-DBLayer-versus-Foundation attribution benchmarks;
* keep the tracker unchecked until implementation/tests/benchmarks are complete.

### 26.7 ReqShield utilization pass

This slot is reserved for the dedicated ReqShield audit in the next three-library batch.

The ReqShield pass must follow the same complete format:

* pin the latest released ReqShield tag and exact commit directly from `infocyph/ReqShield`;
* establish validation/sanitization/schema ownership;
* audit `ValidatorFactory`, `ValidationSchemaRegistry`, `FormRequest` and every ReqShield integration surface;
* audit `ReqShieldDatabaseProvider` and remove unnecessary unconditional DB coupling;
* ensure validation-only applications do not activate DBLayer;
* audit request/execution lifetimes and persistent-runtime safety;
* keep request parsing/HTTP concerns in Webrick where appropriate;
* preserve ReqShield-native validation and sanitization primitives rather than recreate them in Foundation;
* add correctness/security acceptance;
* add capability-absent and direct-ReqShield attribution benchmarks;
* keep the tracker unchecked until implementation/tests/benchmarks are complete.

### 26.8 Omnibus utilization pass

This slot is reserved for the dedicated Omnibus audit in the next three-library batch.

The Omnibus pass must follow the same complete format:

* pin the latest released Omnibus tag and exact commit directly from `infocyph/Omnibus`;
* establish Omnibus-versus-Foundation messaging ownership;
* audit `ConsumerFactory`, `InterMixExecutionScope`, `MessagingRuntimeResolver`, `OmnibusWorkerFactory` and handler middleware;
* preserve Omnibus message-ID propagation as the authoritative Foundation execution identity when available;
* audit queue/topic/RPC/fanout/retry/delivery semantics;
* audit handler/listener/middleware resolution inside the active execution scope;
* audit worker cancellation/restart/retry behavior;
* ensure Foundation does not create a parallel message/queue runtime;
* coordinate scheduler-to-message dispatch without merging scheduler and worker runtime ownership;
* add correctness/concurrency/persistent-worker acceptance;
* add direct-Omnibus-versus-Foundation attribution benchmarks;
* keep the tracker unchecked until implementation/tests/benchmarks are complete.

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
4. `CacheLayerWebhookReplayStore` currently constructs physical cache keys with `foundation:webhook:replay:`. CacheLayer 3.2.0 forbids `:` and limits keys to 64 characters. Reuse the canonical legal Foundation security-state key encoder from section 26.3 instead of inventing another webhook-specific encoder.
5. The current replay claim uses lock → `has()` → `set()` and fails closed when coordination or persistence fails. Preserve atomic claim semantics, but prove the lock and cache belong to the same authoritative security-state domain and that cleanup cannot mask the primary verification failure.
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
* [ ] Replace webhook replay physical keys with the section-26.3 security-state key encoder and keep TTL/replay namespaces domain-separated.
* [ ] Validate production webhook replay topology during composition/release build, including authoritative store and usable same-domain lock.
* [ ] Preserve fail-closed replay claiming and deterministic lock release.
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
8. Any CacheLayer-backed challenge consume path must use the atomic security-state claim/consume mechanics from section 26.3 rather than plain `get()` + `delete()`.
9. Foundation has local Base64URL-style conversion at passkey boundaries.
10. Compare its exact behavior against WebAuthn 5.3.8's own codec/utilities and remove the Foundation helper when genuinely redundant.
11. Do not change persisted credential-ID representation solely for code deduplication.
12. Validator/logger/event-dispatcher wiring is process configuration.
13. If validator instances are shared, finalize mutable setters before traffic and never mutate logger/event-dispatcher state per request.
14. Challenge/options/ceremony request state remains execution-local.
15. Backup-eligible/backed-up credentials can legitimately affect signature-counter behavior. Foundation must trust WebAuthn's successful ceremony result rather than impose a simplistic Foundation counter rule.

**Audit and implementation checklist**

* [ ] Raise Foundation's WebAuthn dependency floor to `^5.3.8` after confirming the complete dependency/test matrix.
* [ ] Rescan every Foundation WebAuthn class/import against 5.3.8 tagged APIs.
* [ ] Remove stale/deprecated `PublicKeyCredentialSource` assumptions where the 5.3.8 `CredentialRecord` contract is the correct boundary.
* [ ] Keep creation/assertion validation entirely in WebAuthn validators/ceremony logic.
* [ ] Keep Foundation responsible only for repositories, options/policy, lifecycle and persistence.
* [ ] Make challenge issuance/consume state short-lived, execution-safe and one-time under concurrency using section 26.3's hardened CacheLayer contract.
* [ ] Ensure challenge namespace keys use the legal Foundation security-state physical-key encoder.
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
- [X] Rescan for hidden DB/cache capability activation.
- [X] Rescan cleanup paths for primary-exception masking.
- [X] Remove stale InterMix 9/Webrick 4 configuration, tests and documentation.
- [ ] Validate all hard implementation gates in section 24.
- [ ] Validate final definition of done in section 25.
- [X] Publish migration notes and final benchmark evidence.

Phase 10 rescan evidence: the final three audit batches removed hidden development-container mutation, made release runtime configuration generation-owned and source-discovery-free, kept optional capability activation explicit/minimal, hardened scheduler/worker cleanup so secondary cleanup failures cannot replace primary failures, removed stale resolver/cache switches, and refreshed the runtime/migration documentation. The two aggregate closure gates remain open only for final current-head CI confirmation and the InfByte skeleton handoff to the trusted Foundation 3 runtime lifecycle.

### Future lower-library utilization tracker

These remain unchecked until their dedicated deep audits are performed and merged into this same plan:

- [ ] ArrayKit 5.1.1 current-version utilization pass.
- [ ] UID 5.0 current-version utilization pass.
- [ ] CacheLayer 3.2.0 current-version utilization pass.
- [ ] DBLayer current-version utilization pass.
- [ ] ReqShield current-version utilization pass.
- [ ] Omnibus current-version utilization pass.
- [ ] TalkingBytes current-version utilization pass.
- [ ] OTP 6.0 current-version utilization pass.
- [ ] Epicrypt current-version utilization pass.
- [ ] WebAuthn integration pass.
- [ ] Pathwise 3.1 current-version utilization pass.

When a later library pass changes architecture or implementation order, update both its detailed section and the applicable checkboxes here in the same commit.