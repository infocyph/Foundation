# Foundation 3 — Unified Runtime Development Plan

**Status:** Final implementation baseline / canonical plan**Foundation target:** 3.x**Foundation source baseline:** `main`**InterMix baseline:** `^10.0.4`**Webrick baseline:** `^5.2`**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single source of truth for Foundation 3 runtime development. It merges the complete InterMix 10.0.3 audit plus the released InterMix 10.0.4 intrinsic-container planner correction, the complete Webrick 5.1 audit plus the released Webrick 5.2 prerequisite corrections, and the final joint runtime architecture into one document. There are no separate InterMix/Webrick runtime-plan files to keep synchronized.

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
| constructor deps                              | `FactoryDefinition::construct()` + `ServiceReference`            |
| public static factory                         | `FactoryDefinition::staticFactory()`                               |
| interface/ID points to same service           | real`alias()`                                                      |
| immutable scalar/array build value            | `value()` or exportable recipe arg                                 |
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

**Transient** for cheap/stateful one-use services where sharing is undesirable.

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

Foundation must treat Webrick development and production as intentionally different.

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

WB-1 through WB-4 are available in Webrick 5.2.

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
2. standalone Webrick 5.2 compiled endpoint;
3. Foundation 3 + Webrick compiled endpoint;
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

Remove dynamic Container from Webrick boundary, place controller/middleware definitions before runtime creation and ensure HTTP services resolve from ProductionContainer.

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

After InterMix + Webrick implementation contracts are frozen, run the same dedicated current-version utilization process for:

1. ArrayKit — config/environment parsing, sealed compiled config and scan/cache strategy;
2. UID — execution/correlation ID cost and lazy/reused identities;
3. CacheLayer / DBLayer — capability boundaries, store/connection lifetimes and optional graph cost;
4. ReqShield — remove unnecessary unconditional DB coupling;
5. Omnibus — messaging topology and worker integration;
6. TalkingBytes — HTTP/email/webhook/gRPC profile graph and scope behavior;
7. OTP / Epicrypt / WebAuthn / Pathwise — specialist security/filesystem integration boundaries.

Each pass updates **this same document**. Do not create another standalone runtime-plan file.

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
- [ ] Phase 8 — Unified Foundation release generation — immutable-generation/manifest/activation/trust infrastructure implemented; end-to-end all-runtime generation acceptance remains open
- [ ] Phase 9 — Full regression/performance pass
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
- [ ] Build web bundle through Webrick coordinated release compiler end-to-end.
- [ ] Build CLI InterMix artifact directly end-to-end.
- [ ] Build worker InterMix artifact directly end-to-end.
- [ ] Build scheduler InterMix artifact directly end-to-end.
- [X] Collect and validate InterMix compile/skipped/digest metadata in the unified compiler.
- [X] Fail generation build on unexpected dynamic islands/skipped definitions.
- [ ] Add only useful deterministic command/scheduler/worker topology artifacts.
- [X] Write OPcache-friendly Foundation generation manifest.
- [X] Reference Webrick release manifest without duplicating its owned identity fields.
- [X] Verify runtime environment/config identity belongs to the same Foundation generation before publication.
- [X] Implement atomic active-generation switch.
- [X] Leave previous generation active when any build/verification step fails.
- [X] Implement rollback/incomplete-generation tests for the active pointer/generation infrastructure.
- [X] Implement trusted-prevalidated mode only with immutable external Foundation trust metadata.
- [X] Implement graceful persistent-worker replacement on generation change.
- [X] Keep old-generation cleanup outside request/job hot paths.

Phase 8 implementation evidence: `FoundationReleaseCompiler` stages under `generations/.staging-*`, coordinates Webrick plus non-web artifact metadata, verifies identity/completeness, publishes an immutable generation and switches only after verification; `FoundationReleaseManifest` provides the OPcache-friendly Foundation manifest; `ActiveGeneration` provides the atomic pointer and generation-change detection; `FoundationReleaseRuntime` and `GeneratedRuntime::loadPrevalidated()` require an externally trusted Foundation manifest SHA before using subordinate trusted digests; `FoundationReleaseInfrastructureTest` covers activation, incomplete-target rejection, trust mismatch, traversal rejection and explicit out-of-band pruning. End-to-end all-four-runtime build/load acceptance remains open and is now running against InterMix 10.0.4.

### Phase 9 — Full regression/performance pass

- [ ] Run complete correctness test suite.
- [ ] Run security-sensitive auth/session/CSRF/OTP/WebAuthn regression suites applicable at this stage.
- [ ] Run static analysis/phpforge checks.
- [ ] Run InterMix development/generated-production parity matrix.
- [ ] Run scope/Fiber/coroutine isolation matrix.
- [ ] Run Webrick development vs compiled-production suite.
- [ ] Run artifact corruption/mismatch/stale-generation tests.
- [ ] Run SAPI/FPM integration tests.
- [ ] Run at least one persistent HTTP adapter integration suite.
- [ ] Run long worker/scheduler persistence tests.
- [ ] Benchmark Foundation DI tax against direct InterMix.
- [ ] Benchmark Foundation HTTP tax against standalone compiled Webrick.
- [ ] Repeat representative HTTP benchmark through real Apache/Nginx + PHP-FPM + OPcache.
- [ ] Measure throughput, p50/p95/p99, memory/peak and cold/warm boot.
- [ ] Use Webrick stage profiling only where measured overhead remains.
- [ ] Optimize only attributable Foundation overhead.
- [ ] Record final benchmark evidence in release documentation.

### Phase 10 — Final rescan/release readiness

- [ ] Rescan for direct old dynamic Container construction/mutation.
- [ ] Rescan for `compileTo`, `useCompiled`, old `usePrevalidated` resolver-map production paths.
- [ ] Rescan for closure aliases/deterministic closure factories.
- [ ] Rescan for Application/container capture in generated services.
- [ ] Rescan all dynamic islands and confirm allowlist/reasons.
- [ ] Rescan lifetimes for mutable singleton execution state.
- [ ] Rescan scope naming/cleanup/Fiber/coroutine safety.
- [ ] Rescan for live production Registrar/Collection usage.
- [ ] Rescan for RouteCacheManager/RouteCachePath production duplication.
- [ ] Rescan for production route/provider/module/file discovery.
- [ ] Rescan for unnecessary Request/scope/global middleware creation.
- [ ] Rescan for direct output/native runtime handle use.
- [ ] Rescan for repeated hashing/manifest parsing in hot paths.
- [ ] Rescan for hidden DB/cache capability activation.
- [ ] Rescan cleanup paths for primary-exception masking.
- [ ] Remove stale InterMix 9/Webrick 4 configuration, tests and documentation.
- [ ] Validate all hard implementation gates in section 24.
- [ ] Validate final definition of done in section 25.
- [ ] Publish migration notes and final benchmark evidence.

### Future lower-library utilization tracker

These remain unchecked until their dedicated deep audits are performed and merged into this same plan:

- [ ] ArrayKit current-version utilization pass.
- [ ] UID current-version utilization pass.
- [ ] CacheLayer current-version utilization pass.
- [ ] DBLayer current-version utilization pass.
- [ ] ReqShield current-version utilization pass.
- [ ] Omnibus current-version utilization pass.
- [ ] TalkingBytes current-version utilization pass.
- [ ] OTP current-version utilization pass.
- [ ] Epicrypt current-version utilization pass.
- [ ] WebAuthn integration pass.
- [ ] Pathwise current-version utilization pass.

When a later library pass changes architecture or implementation order, update both its detailed section and the applicable checkboxes here in the same commit.