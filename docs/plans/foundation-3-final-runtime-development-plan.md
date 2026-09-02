# Foundation 3 — Final Runtime Development Plan

**Status:** Final implementation baseline  
**Foundation target:** 3.x  
**Foundation source baseline:** `main`  
**InterMix baseline:** `^10.0.3`  
**Webrick baseline:** `^5.1` plus the lower-layer corrections listed in this document  
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the canonical runtime-development plan for Foundation 3. It incorporates the final joint InterMix 10.0.3 + Webrick 5.1 sweep and supersedes any architectural or execution-order ambiguity in the earlier dedicated audit plans. The earlier plans remain useful source-audit evidence.

Related audits:

- `docs/plans/foundation-3-runtime-architecture.md` — detailed InterMix/Foundation source audit;
- `docs/plans/foundation-3-webrick-5.1-integration.md` — detailed Webrick/Foundation source audit.

---

## 1. Final architectural decision

Foundation 3 has **four independent runtime paths**:

1. `web`;
2. `cli`;
3. `worker`;
4. `scheduler`.

InterMix is the DI/runtime foundation for **all four** paths.

Webrick owns only the **web** HTTP path. It must not become the build/runtime owner of CLI, worker, or scheduler execution.

The final ownership model is therefore:

```text
                         one Foundation graph/composition source
                                      |
              +-----------------------+-----------------------+
              |                       |                       |
              v                       v                       v
            web                     cli                   worker                 scheduler
              |                       |                       |                       |
              |                       |                       |                       |
   fresh ContainerBuilder   fresh ContainerBuilder   fresh ContainerBuilder   fresh ContainerBuilder
              |                       |                       |                       |
   Foundation web graph      Foundation CLI graph      Foundation worker graph   Foundation scheduler graph
              |
   Webrick::contributeTo(...)
              |
   Webrick coordinated        direct InterMix           direct InterMix           direct InterMix
   release compilation        compilation               compilation               compilation
              |                       |                       |                       |
   InterMix + Webrick          InterMix artifact          InterMix artifact          InterMix artifact
   web release bundle
```

There is **one graph source of truth**, but there are **four fresh builders and four runtime-specific artifacts**. Runtime/capability inputs decide which definitions are included; the graph must not be copied into four separate implementations.

Foundation remains the application composition and release coordinator. InterMix owns DI graph/runtime behavior. Webrick owns HTTP routing/execution/transport only for `web`.

---

## 2. Hard ownership boundaries

### 2.1 InterMix owns

Foundation must use InterMix directly for:

- `ContainerBuilder` graph composition;
- environment-specific graph selection;
- singleton/scoped/transient lifetimes;
- aliases, values, contextual bindings and tags;
- compilation-safe constructor/static-factory recipes;
- strict validation;
- generated production containers;
- production artifact verification/prevalidation;
- runtime `resolveNow()`;
- execution scopes and scope seeds;
- Fiber/Swoole/OpenSwoole execution-context isolation;
- lifecycle/scope-leave hooks where their semantics fit;
- compile reports and dynamic-island visibility.

Foundation must not add a second DI builder, second scope implementation, or second generated-container runtime.

### 2.2 Webrick owns for `web`

Foundation must use Webrick directly for:

- route registration/build;
- handler inspection;
- execution-plan generation;
- matcher compilation/runtime matching;
- lazy Request materialization;
- middleware pipeline dispatch;
- HTTP request scope decisions;
- routing-control responses;
- runtime HTTP adapters;
- runtime capabilities;
- native response writing/streaming;
- URL-generator runtime registry;
- compiled/frozen HTTP registries;
- coordinated web release metadata.

Foundation must not add a second HTTP transport/runtime above Webrick.

### 2.3 Foundation owns

Foundation owns:

- normalized application configuration;
- runtime/capability selection;
- application graph contribution;
- package/provider composition policy;
- application-facing service integrations;
- CLI/worker/scheduler execution orchestration;
- Foundation-specific auth/session/database/filesystem policy;
- Foundation release-generation coordination across all four runtime paths;
- deployment activation/trust policy;
- diagnostics, migration guidance and benchmarks;
- thin application-facing convenience APIs.

---

## 3. Exact InterMix 10.0.3 contract

Foundation 3 must design against the actual InterMix 10.0.3 builder/runtime split:

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

- `setEnvironment()` selects graph metadata; it is not production-runtime selection;
- every independently active runtime uses a **fresh builder instance**;
- normal production graph mutation after finalization is prohibited;
- InterMix deoptimization is a correctness fallback, never Foundation's deployment mechanism;
- every new `skipped` definition is release-significant and must be checked against an explicit allowlist;
- `resolveFactories: true` is not a blanket build requirement because dynamic factories may have side effects;
- aliases and deterministic constructor/static-factory definitions must use compilation-safe primitives rather than closures;
- scope seeds carry ready execution objects/values; graph definitions are never rebound per request/job.

No InterMix 10.0.3 code change is currently required for Foundation 3's intended architecture. The necessary builder, production-container, scope and execution-context mechanisms already exist.

---

## 4. Exact Webrick 5.1 contract

Foundation must treat Webrick's development and production HTTP paths as intentionally different.

### Development

```text
RouterKernel::bootWithRegistrar(...)
```

Development may use a dynamic InterMix container, live registrar and development diagnostics.

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

The production hot path is:

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

Foundation must preserve Webrick's direct requestless/scopeless path for routes that do not need framework/runtime state.

The Webrick release manifest format remains Webrick-owned. Foundation references it rather than reimplementing its InterMix/Webrick digest fields.

---

## 5. Final joint-sweep gaps and decisions

### J-1 — Web InterMix must be compiled exactly once

The earlier plans could be read as if Foundation should compile the web InterMix artifact itself and then invoke Webrick's `ReleaseCompiler`.

That is incorrect.

Webrick 5.1 `ReleaseCompiler` already performs strict InterMix validation and calls `ContainerBuilder::compile()`.

Final rule:

```text
Foundation composes fresh web builder
 -> Webrick contributes to that same builder
 -> Webrick coordinated release compiler compiles web InterMix exactly once
```

Foundation must **not** perform an earlier independent web `builder->compile()`.

This rule applies only to the web path. CLI, worker and scheduler directly compile their own InterMix builders.

### J-2 — Scope names must be semantic and stable

Current Foundation scopes synthesize execution IDs into the scope name, while current Webrick scopes synthesize request object/sequence IDs.

InterMix `onScopeLeave($scope, ...)` is keyed by the **exact scope name**. A hook registered for `request` cannot match `webrick.request.123` or `web:<uuid>`.

InterMix already isolates same-named scopes by Fiber/coroutine execution context, so unique scope names are unnecessary for concurrency.

Foundation 3 target labels:

```text
webrick.request       web request scope, owned by Webrick
foundation.cli        command invocation scope
foundation.worker     job/message invocation scope
foundation.scheduler  scheduled invocation scope
```

Execution IDs, request IDs, job IDs and envelope IDs remain **scope seeds/correlation values**, not scope names.

This allows compile-time scope cleanup hooks and consistent lifecycle reasoning.

Webrick should change its development and production request-scope naming to the stable `webrick.request` label before Foundation relies on scope-leave integration.

### J-3 — `RuntimeContextTracker` cannot remain a mutable process singleton

Current `RuntimeContextTracker` stores mutable per-execution data such as:

- whether database state was touched;
- dirty principal/session contexts;
- fresh database connections.

That singleton is not execution-context-local. Under Fiber/coroutine concurrency, one request can mark or reset another request's cleanup state.

Simply moving the current `reset()` call into a Webrick scope-leave hook is therefore insufficient.

Foundation 3 must redesign runtime state around InterMix scopes:

- per-execution cleanup state becomes scoped or disappears;
- `CurrentPrincipalContext` should preferably be scoped and use ordinary state rather than maintaining its own Fiber `WeakMap` if InterMix owns isolation;
- active browser-session context should be scoped; process-wide session store/lock infrastructure remains separately reusable;
- database configuration/connection registries may remain process-wide where safe, but transaction/touched/fresh-connection cleanup bookkeeping must be execution-local;
- process-wide memoizers/caches must be explicitly classified as safe, generation-bound, or execution-cleared;
- a singleton must never capture the first scoped dependency resolved into it.

The goal is to use one concurrency model—InterMix execution scopes—not InterMix plus multiple Foundation-specific Fiber-local state systems.

### J-4 — Removing Foundation's outer HTTP scope must not remove cleanup

The current outer `ExecutionScope` happens to provide cleanup for principal/session/database state. Removing it is required for Webrick performance, but cleanup must move to the correct Webrick-owned request scope.

Final rule:

- Webrick decides whether a request needs an InterMix scope;
- only routes that touch runtime-backed Foundation state enter that scope;
- Foundation request-scoped services clean up on `webrick.request` leave;
- truly direct requestless/scopeless routes pay no Foundation cleanup cost;
- no Foundation universal HTTP scope is reintroduced merely to preserve legacy cleanup.

### J-5 — Webrick's current release order can leave route handlers as dynamic islands

Current Webrick 5.1 coordinated compilation validates/compiles InterMix **before** route compilation.

But the finalized route topology is what reveals class-based controller and middleware specs that Webrick later resolves through `InterMixRuntime::resolveNow()`.

If a route-referenced class was not already registered in the builder, the router can be fully compiled while that handler/middleware still falls into InterMix's dynamic fallback at runtime.

Foundation should not duplicate controller/middleware scans just to guess the route graph before Webrick builds it.

Preferred Webrick correction:

```text
RouteCompiler::compile(...)
 -> RouterBuildResult / ExecutionPlans
 -> optional host graph-enrichment callback using finalized route descriptors
 -> ContainerBuilder::validate(strict: true)
 -> ContainerBuilder::compile(...)
 -> RouterArtifactCompiler::compile(...)
 -> release manifest
```

The graph-enrichment callback must only add deterministic route-referenced DI definitions. It must not resolve services or mutate an already-compiled graph.

This preserves one route discovery pass and improves InterMix production coverage.

### J-6 — Parameterized middleware needs a declarative runtime-backed descriptor

Foundation currently supports middleware aliases such as:

```text
role:admin
permission:invoice.approve
policy:document,update
oauth-scope:payments.write
oauth-audience:merchant-api
```

Current Foundation alias resolution may build middleware objects during route registration. Those objects can capture live Foundation services and must not become serialized router-artifact service graphs.

Preferred Webrick addition:

```text
ParameterizedMiddlewareDescriptor / MiddlewareReference
    spec: class-string | class/method descriptor
    parameters: exportable scalar/list/map values
```

At runtime Webrick merges descriptor parameters with:

```text
request
next
```

and delegates to `InterMixRuntime::resolveNow()`.

This keeps constructor/service dependencies inside InterMix and keeps route artifacts declarative.

Fallback if Webrick is not changed: Foundation may encode some policy parameters into route attributes and use a DI-backed middleware that reads them. That is acceptable for Foundation-owned policies but is less general and should not become a second alias runtime.

### J-7 — Empty middleware config does not automatically mean no global middleware

Webrick's current compiler/kernel defaults include tag-driven global middleware:

```text
webrick.middleware.pre
webrick.middleware.post
```

Therefore Foundation's current explicit `pre=[]`, `post=[]` configuration is not enough to guarantee the minimal route stays middleware-free.

Foundation must pass:

```text
preGlobal: []
postGlobal: []
preGlobalTags: []
postGlobalTags: []
```

by default.

Tag-driven global middleware becomes explicit opt-in Foundation behavior.

This is a hard performance invariant because global middleware forces every route into Request/pipeline execution.

### J-8 — Route artifacts must not hide Foundation service graphs in closures/objects

Webrick supports serialized closures/callables for legitimate dynamic application routes. Foundation must not misuse that flexibility to bypass InterMix compilation.

Foundation-owned production route policy:

- prefer class/method, invokable class, static method or stable function descriptors;
- do not serialize resolved controller/middleware objects containing Foundation services;
- do not serialize closures capturing `Application`, mutable container/runtime objects, auth/session/database managers, or other framework service graphs;
- parameterized middleware uses a declarative descriptor, not a constructed object;
- user closures remain supported when Webrick can serialize them, but build diagnostics must report closure/callable-object counts and flag framework-runtime captures;
- Foundation-owned unsafe captures fail the production build.

### J-9 — Application exception handling and routing-control errors must be separable

Webrick's direct 404/405 fast path currently depends on the custom `ErrorHandler` being null.

Foundation needs application exception mapping/logging, but that should not automatically force custom Request-based rendering for routine route-not-found/method-not-allowed control flow.

Preferred Webrick refinement:

- retain direct routing-control rendering by default;
- allow an independent custom dispatch/application exception handler;
- opt into custom routing-error rendering only when explicitly requested.

Correctness and observability remain mandatory; direct 404/405 behavior is an optimization only when semantics remain equivalent.

### J-10 — Maintenance mode leaves the Foundation outer kernel

Current Foundation maintenance checking occurs before every RouterKernel dispatch and can perform runtime file work.

That position is incompatible with the compiled Webrick hot path.

Initial Foundation 3 solution:

- use Webrick's maintenance middleware/state model where it satisfies semantics;
- back dynamic file state with worker-local refresh caching;
- do not perform per-request Foundation filesystem discovery.

Potential later Webrick optimization:

- a generic lightweight pre-routing gate operating on `RoutingInput`/runtime context and capable of returning a Response without Request/scope creation.

Do not add this abstraction before a benchmark shows meaningful value.

### J-11 — Filesystem streaming must remain inside Webrick's response writer contract

Foundation currently has a streaming path that writes directly to `php://output` inside a Webrick `Response::stream()` producer.

That bypasses Webrick's runtime adapter and is unsafe for RoadRunner/Swoole/Workerman.

Foundation 3 rules:

- never write directly to `php://output` from a Webrick producer;
- local files prefer Webrick `FileBody`/download/inline/ranged/stream-download facilities;
- non-local/custom Pathwise sources expose a Webrick-compatible `BodyStream` or chunk-yielding iterable;
- Pathwise/Foundation security/policy checks still run before response construction;
- X-Sendfile/X-Accel-Redirect behavior remains policy-driven;
- SAPI and persistent adapter behavior must be tested.

### J-12 — One compiled web application per production process

Webrick intentionally freezes process-level URL/middleware/constraint/header/request configuration registries at compiled-kernel boot.

Foundation must treat a production process as hosting one compiled web application/release generation.

Do not add a normal production unfreeze/reset mechanism merely to support multiple unrelated compiled kernels in one process.

Tests requiring different compiled production web applications should use process isolation where registry state differs.

### J-13 — The four runtime artifacts form one Foundation release generation

Webrick publishes its own web files atomically, but Foundation owns deployment coherence across all four runtime paths.

Foundation build must publish a complete immutable generation:

```text
release/<generation>/
    foundation.php
    config.php                       optional compiled config
    capabilities.php                 if useful
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
    command/scheduler/worker maps    when they remove runtime discovery
```

Build sequence:

1. create a new unique generation directory;
2. build web through the coordinated Webrick release path;
3. build CLI directly with InterMix;
4. build worker directly with InterMix;
5. build scheduler directly with InterMix;
6. validate all compile/skipped/digest reports;
7. generate only useful Foundation deterministic metadata;
8. write an OPcache-friendly Foundation generation manifest;
9. verify the complete generation;
10. atomically switch one small trusted/read-only release pointer only after every runtime succeeds;
11. leave the previous generation active on any failure;
12. persistent workers restart/replace gracefully onto the new generation;
13. old-generation cleanup happens outside request/job hot paths.

Foundation metadata references Webrick's release metadata; it must not duplicate Webrick-owned InterMix/Webrick digest fields unnecessarily.

### J-14 — Prevalidated loading requires a real trust boundary

Safe default for all runtime paths is normal verified loading.

Use prevalidated loading only when expected digest/fingerprint values come from trusted immutable deployment metadata, for example a read-only release-generation descriptor supplied by deployment tooling.

The same mutable cache/artifact directory that contains the artifact is not a trust source for its own expected digest.

This policy applies equally to web, CLI, worker and scheduler artifacts.

---

## 6. Final graph/composition model

Foundation needs one small graph coordinator, not another DI framework.

Conceptual API:

```php
function foundationGraph(
    ContainerBuilder $builder,
    FoundationBuildContext $context,
): ContainerBuilder {
    // Foundation core
    // runtime-specific definitions
    // selected package capabilities/providers
    // web only: Webrick::contributeTo($builder, ...)

    return $builder;
}
```

`FoundationBuildContext` is immutable build data only. It may include:

- normalized environment;
- runtime mode;
- application paths;
- normalized config/capability selections;
- enabled modules/providers;
- release generation identity where needed.

It must **not** become a service locator.

Each runtime creates a fresh builder with a deterministic alias:

```text
foundation.web
foundation.cli
foundation.worker
foundation.scheduler
```

An application-defined stable prefix may be added if multiple app identities genuinely share tooling, but random UUID aliases are not part of normal boot.

---

## 7. Provider/application redesign

### 7.1 Provider registration

The Foundation 2 contract:

```php
register(Application $app): void
boot(Application $app): void
```

mixes graph mutation with runtime boot.

Foundation 3 separates:

1. graph contribution before runtime creation;
2. process boot after selected runtime exists;
3. execution behavior inside request/job/command scopes.

Preferred registration shape:

```php
register(ContainerBuilder $builder, FoundationBuildContext $context): void
```

A separate optional boot contract may perform true process-level side effects, but cannot mutate a finalized production graph.

### 7.2 Application

`Application` remains an application-facing façade/coordinator, not a dynamic graph owner.

Rules:

- no broad production provider activation in `make()`/`has()`;
- public service resolution convenience may remain;
- core generated services must not capture `Application` merely to call `make()`;
- prefer narrow constructor dependencies;
- public APIs should not promise a mutable dynamic InterMix `Container` in production;
- production web native handling goes through Webrick RuntimeServer, not `Application::handle(Request)`.

`Application::handle(Request)` may remain as a thin embedded/testing path when useful.

### 7.3 Dynamic islands

For every runtime artifact:

1. `validate(strict: true)`;
2. compile;
3. collect exact `compiled`, `skipped`, `digest`;
4. compare `skipped` with explicit allowlist;
5. fail build on unexpected Foundation-owned dynamic islands;
6. report each intentional island with subsystem and reason.

Foundation core target: zero **avoidable** islands.

---

## 8. Development lifecycle

Development optimizes iteration/diagnostics, not production-hot-path parity.

### 8.1 Web development

```text
load/normalize config
 -> fresh ContainerBuilder('foundation.web')
 -> compose Foundation web graph
 -> Webrick::contributeTo(same builder)
 -> register build-safe middleware aliases
 -> development()
 -> RouterKernel::bootWithRegistrar(...)
 -> development request handling
```

Development may use live route registration and diagnostics.

It must still follow the same logical graph/capability decisions as production so behavior does not drift.

### 8.2 CLI/worker/scheduler development

Each runtime:

```text
fresh builder
 -> same Foundation graph function with runtime context
 -> development()
 -> runtime-specific execution
```

Do not reuse one mutable builder across independently active runtime paths.

---

## 9. Production build lifecycle

### 9.1 Web build

After the Webrick route/graph coordination correction:

```text
normalized Foundation build context
 -> fresh ContainerBuilder('foundation.web')
 -> Foundation web graph
 -> Webrick::contributeTo(same builder)
 -> deterministic middleware alias/descriptors
 -> build routes once
 -> obtain RouterBuildResult / ExecutionPlans
 -> enrich builder with deterministic route-referenced DI classes
 -> strict InterMix validation
 -> compile web InterMix artifact ONCE
 -> compile Webrick router artifact
 -> publish Webrick format-2 release manifest
 -> enforce InterMix skipped allowlist
 -> verify bundle
```

No second Foundation web container compilation.

### 9.2 CLI build

```text
fresh ContainerBuilder('foundation.cli')
 -> FoundationGraph(cli)
 -> validate(strict: true)
 -> compile(cli artifact)
 -> enforce skipped allowlist
```

### 9.3 Worker build

```text
fresh ContainerBuilder('foundation.worker')
 -> FoundationGraph(worker)
 -> validate(strict: true)
 -> compile(worker artifact)
 -> enforce skipped allowlist
```

Only selected worker/messaging capabilities belong in the worker graph.

### 9.4 Scheduler build

```text
fresh ContainerBuilder('foundation.scheduler')
 -> FoundationGraph(scheduler)
 -> validate(strict: true)
 -> compile(scheduler artifact)
 -> enforce skipped allowlist
```

Only scheduler/command/dispatch capabilities belong in this graph.

---

## 10. Production runtime lifecycle

### 10.1 Web

```text
load active Foundation release generation
 -> load Webrick release metadata
 -> reconstruct same web ContainerBuilder graph
 -> verified ProductionContainer
      or trusted productionPrevalidated(...)
 -> verified/prevalidated CompiledRouterKernel
 -> select RuntimeAdapter once
 -> construct RuntimeServer once
 -> serve traffic
```

Per request:

```text
RuntimeAdapter context
 -> RoutingInput
 -> compiled match
 -> ExecutionPlan
 -> Request only when required
 -> webrick.request scope only when required
 -> middleware/handler
 -> Response
 -> RuntimeAdapter write
```

### 10.2 CLI

```text
load active generation
 -> reconstruct CLI graph
 -> ProductionContainer
 -> foundation.cli scope per command invocation when scoped state is required
 -> deterministic cleanup
```

### 10.3 Worker

```text
load active generation once per worker process
 -> reconstruct worker graph
 -> ProductionContainer reused across jobs/messages
 -> foundation.worker scope per item
 -> seed envelope/job/execution values
 -> deterministic cleanup on success/failure/cancel
 -> graceful worker replacement on generation change
```

### 10.4 Scheduler

```text
load active generation once per scheduler process
 -> reconstruct scheduler graph
 -> ProductionContainer reused safely
 -> foundation.scheduler scope per invocation
 -> deterministic cleanup
```

---

## 11. Webrick lower-layer corrections required before Foundation web integration is considered final

### WB-1 — Parameterized runtime-backed middleware descriptor

Add an artifact-safe descriptor carrying a resolver spec plus exportable parameters and resolve it through InterMix at runtime.

Required for Foundation's role/permission/policy/OAuth parameterized middleware without serialized service graphs.

### WB-2 — Separate direct routing-control errors from custom dispatch exceptions

Allow Foundation to install application exception mapping/logging without automatically losing Webrick's direct 404/405 control path.

### WB-3 — Stable request scope label

Use a stable semantic scope such as:

```text
webrick.request
```

in development and production. Rely on InterMix execution-context isolation for concurrent requests; request identity remains a seed/context value.

This makes compile-time `onScopeLeave('webrick.request', ...)` integration possible.

### WB-4 — Route-first graph-enrichment point in coordinated release compilation

Expose the finalized `RouterBuildResult`/execution descriptors before InterMix compilation so a host can contribute deterministic route-referenced controller/middleware definitions to the same builder.

Do not require Foundation to repeat route discovery.

### WB-5 — Optional pre-routing gate only if measured

A generic lightweight pre-routing operational gate may be useful for maintenance mode, but only add it if benchmarks prove that Webrick's cached middleware solution is materially insufficient.

WB-5 is **not** a prerequisite for Foundation 3 development.

The first four items should be resolved in Webrick before Foundation's production web runtime is considered final. They should remain small lower-layer changes rather than Foundation workarounds.

---

## 12. Foundation class/subsystem disposition

### Remove/replace

- `ContainerFactory` as the runtime DI factory;
- old `ContainerCacheManager` compile/activate semantics;
- production use of `WebrickRouterFactory`;
- independent production route-cache orchestration through `RouteCacheManager`/`RouteCachePath`;
- universal Foundation `HttpKernel` execution scope;
- broad production `onMissing()` provider activation;
- runtime provider/module discovery in normal resolution;
- direct `php://output` Webrick streaming producers.

### Keep but redesign

- `Application` — façade/coordinator, no graph ownership;
- `ExecutionScope` — non-web Foundation execution helper for CLI/worker/scheduler, based on InterMix `withinScope()` and stable semantic labels;
- `RuntimeContextTracker` — redesign into execution-local cleanup state or eliminate;
- `ServiceProvider` infrastructure — builder-first graph contribution;
- `ServiceRegistry`/`Bootstrapper` — build-time/capability topology rather than production lazy activation;
- `RouteFileLoader`/attribute route discovery — development/build only;
- route presets/OAuth route registration — build/development policy only;
- Foundation middleware — Webrick-native Request/Response contracts retained, construction moved to DI-safe descriptors;
- filesystem HTTP integration — preserve policy, use Webrick body/response writer contracts;
- testing HTTP client — thin embedded Request path remains useful.

### Keep ownership where already correct

Foundation should continue using Webrick HTTP primitives rather than creating alternatives:

- Request/Response;
- cookies;
- conditional/range handling where appropriate;
- middleware conventions;
- runtime capabilities;
- URL generation;
- response writing.

---

## 13. Runtime state/lifetime redesign checklist

Before carrying any Foundation 2 lifetime into v3, classify it for all four runtime paths.

### Process singleton only if

- immutable or explicitly concurrency-safe;
- no request/job/command/schedule mutable state retained;
- no first-scoped-dependency capture;
- safe across repeated persistent-runtime executions.

### Scoped if

- state belongs to a request/job/command/scheduled invocation;
- it must be shared within one execution but isolated from the next;
- it carries cleanup ownership.

### Transient if

- cheap/stateful one-use object where sharing is undesirable.

Mandatory review targets include:

- principal/auth context;
- session active context;
- DB transaction/runtime state;
- memoizers;
- logging correlation/context;
- cache locks;
- communication clients;
- messaging envelope/current-message state;
- notification state;
- filesystem temp/upload state;
- WebAuthn/OTP request state;
- static registries/facades.

---

## 14. Foundation release-generation manifest

Foundation may publish a small OPcache-friendly generation descriptor conceptually containing:

```php
return [
    'format' => 1,
    'generation' => 'immutable-generation-id',
    'environment' => 'production',
    'config_fingerprint' => 'host-defined deterministic identity',

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

The exact schema can evolve during implementation.

Rules:

- do not duplicate Webrick's router/intermix digest/fingerprint fields into Foundation metadata when a reference to the Webrick release manifest is enough;
- paths should be generation-relative where practical;
- production activation points to one complete generation;
- no request/job scans multiple directories to determine active artifacts.

---

## 15. Testing matrix

### 15.1 InterMix graph parity

For representative graphs across all four runtimes:

- development vs generated production behavior;
- singleton/scoped/transient semantics;
- aliases/contextual/environment bindings;
- scope seeds;
- lifecycle hooks;
- expected intentional dynamic islands;
- mutation/stale-artifact rejection;
- verified and trusted-prevalidated loading.

### 15.2 Scope/isolation

Required:

- same semantic scope label across sequential invocations produces fresh scoped state;
- concurrent Fibers using the same semantic scope label remain isolated;
- Swoole/OpenSwoole coroutine isolation where available;
- cleanup on success;
- cleanup on application exception;
- cleanup failure does not mask the primary application failure;
- nested scopes restore their parent correctly;
- request/job/command/scheduler seeds disappear after leave;
- no principal/session/DB/memoizer/context leakage;
- no singleton retains a scoped service from the first execution.

### 15.3 Webrick execution-plan coverage

Test representative routes:

- direct zero-arg requestless/scopeless;
- direct route-argument requestless/scopeless;
- Request-only;
- DI controller requiring scope;
- non-parameterized DI middleware;
- parameterized DI middleware;
- global middleware opt-in;
- 404/405/OPTIONS;
- exception path;
- auth/session/CSRF routes;
- domain routes;
- signed URL generation;
- URL generation after compiled boot.

Assert whether each route creates:

- Request;
- InterMix scope;
- middleware pipeline.

### 15.4 Release/artifact correctness

Required failures:

- missing artifact;
- wrong InterMix digest;
- wrong Webrick digest/fingerprint;
- config/environment mismatch;
- stale graph after mutation;
- unexpected skipped definition;
- partial Foundation generation;
- generation pointer referencing incomplete release;
- untrusted/mutable prevalidation metadata.

### 15.5 Runtime adapters

At minimum:

- SAPI/FPM integration;
- one persistent adapter path in CI/integration testing;
- RoadRunner/Swoole/Workerman adapter-specific suites as their environments are available;
- native streaming/file response behavior;
- transport-native compression/request-limit capability bypass;
- repeated request isolation.

### 15.6 Non-web persistent execution

Worker/scheduler tests must cover hundreds/thousands of sequential executions and concurrent execution where supported, verifying:

- bounded memory;
- no transaction carry-over;
- no principal/session/message context leak;
- locks/temp resources released;
- proper cancellation/error cleanup;
- worker replacement on new release generation.

---

## 16. Benchmark plan

Benchmarks must attribute cost by layer rather than optimize synthetic Foundation code in isolation.

### DI layers

1. direct InterMix 10.0.3 development graph;
2. direct InterMix generated production graph;
3. equivalent Foundation runtime graph.

Measure:

- production artifact load;
- trusted prevalidated load;
- singleton get;
- scoped get;
- `withinScope()` enter/leave;
- compiled constructor/static factory chains;
- compiled↔dynamic-island bridges;
- Foundation façade resolution overhead;
- memory across repeated scopes.

### HTTP layers

1. raw PHP;
2. standalone Webrick 5.1 compiled endpoint;
3. Foundation 3 + Webrick compiled endpoint;
4. minimal InfByte endpoint.

Primary attribution:

```text
Foundation HTTP tax
= Foundation compiled minimal request
- standalone Webrick compiled minimal request
```

Measure:

- cold process boot;
- warm requests;
- real Apache/Nginx + PHP-FPM + OPcache;
- persistent runtime;
- throughput;
- p50/p95/p99;
- memory/peak;
- routing input;
- matching;
- Request materialization;
- scope enter/leave;
- pipeline dispatch;
- handler dispatch;
- exception path;
- 404/405;
- response write;
- maintenance enabled/disabled;
- streaming/download paths.

No arbitrary percentage regression budget is fixed before these new baselines exist.

---

## 17. Implementation order

Development should proceed in this order so lower-layer contracts are stable before Foundation builds around them.

### Phase 0 — Freeze baselines

- record InterMix 10.0.3 direct DI benchmarks;
- record standalone Webrick 5.1 compiled HTTP benchmarks;
- record current Foundation representative benchmark;
- pin exact source/tag commits in benchmark metadata;
- capture current Foundation tests and behavior that must remain semantically correct.

### Phase 1 — Webrick prerequisite corrections

Implement/test WB-1 through WB-4 in Webrick.

Do not add WB-5 unless maintenance measurements justify it.

After release/tagging, raise Foundation's minimum Webrick patch version to the first release containing the required contracts.

### Phase 2 — Foundation composition root

- update InterMix/Webrick dependency floors;
- introduce immutable build context;
- implement one builder-first Foundation graph function;
- deterministic aliases for web/cli/worker/scheduler;
- remove `ContainerFactory` architecture;
- make Application runtime-neutral/thin.

### Phase 3 — Provider graph migration

- builder-first provider contract;
- deterministic recipes/aliases;
- remove `$app->make()` constructor factories;
- compileability/lifetime classification for every provider;
- build-time capability topology;
- eliminate broad production `onMissing()`.

Migrate simple providers first, then auth/messaging.

### Phase 4 — Runtime state/scope redesign

- stable semantic Foundation non-web scopes;
- consume stable Webrick request scope;
- redesign/remove `RuntimeContextTracker` singleton state;
- migrate principal/session/DB execution state to scoped ownership;
- add cleanup hooks/tests;
- prove Fiber/coroutine/persistent isolation.

### Phase 5 — Webrick build/runtime integration

- remove production `WebrickRouterFactory` path;
- route-first coordinated web build;
- graph enrichment from route build result;
- build-safe middleware descriptors;
- explicit empty global tags by default;
- production `CompiledRouterKernel` + RuntimeAdapter + RuntimeServer;
- frozen URL/runtime registries;
- retain embedded/testing Request path separately.

### Phase 6 — Error/maintenance/filesystem HTTP cleanup

- direct routing-control vs app exception split;
- move maintenance out of Foundation outer kernel;
- runtime-compatible file/stream responses;
- transport-capability integration;
- remove any duplicate response emission.

### Phase 7 — Non-web generated runtimes

- CLI artifact/build/load;
- worker artifact/build/load/reuse;
- scheduler artifact/build/load/reuse;
- command/job/schedule topology artifacts only where they remove measurable discovery;
- no full graph rebuild per worker/scheduler item.

### Phase 8 — Unified Foundation release generation

- generation directory layout;
- build all four runtime artifacts;
- enforce all skipped-definition gates;
- generation manifest;
- atomic activation pointer;
- rollback/incomplete-build behavior;
- persistent-runtime graceful replacement.

### Phase 9 — Full regression/performance pass

- all correctness/security/static-analysis suites;
- runtime isolation suites;
- real HTTP comparison;
- four-runtime DI/runtime benchmarks;
- stage profiling only where measured overhead remains;
- optimize only attributable Foundation cost.

### Phase 10 — Final source rescan/release readiness

Rescan every class/function for:

- direct old InterMix container mutation;
- old resolver-map compile/activation calls;
- route-cache duplication;
- live production Registrar/Collection use;
- closure aliases/constructor factories;
- Application/container capture;
- unexpected dynamic islands;
- Request creation outside required plans;
- duplicated scopes;
- mutable singleton execution state;
- Fiber/coroutine leaks;
- runtime filesystem discovery;
- direct output/emission;
- repeated hashing/manifest parsing;
- hidden DB/cache activation;
- cleanup/error masking;
- stale old docs/tests/config switches.

Then publish migration docs and final benchmark evidence.

---

## 18. Breaking-change policy

Foundation 3 is a major release. Preserve useful application-facing APIs only when doing so does not preserve the old runtime architecture.

Likely intentional breaks include:

- APIs exposing a mutable concrete InterMix development `Container` as a production guarantee;
- service providers that mutate bindings from `boot()`;
- late provider activation semantics;
- old container-cache activation switches;
- live production route registration;
- code assuming `Registrar`/`Collection` are mutable production services;
- code assuming every HTTP request has a Foundation `ExecutionScope`;
- code treating `Application::handle(Request)` as the native production transport entry point;
- middleware alias factories that depend on preconstructed service objects;
- code relying on exact old synthesized scope names.

Compatibility bridges, if any, must not force production deoptimization or restore universal request overhead.

---

## 19. Hard implementation gates

### InterMix gate

Before declaring the DI/runtime migration complete:

- one graph source of truth;
- four fresh runtime builders;
- generated ProductionContainer normal in production;
- no old `compileTo()`/resolver-map activation path;
- no broad production graph mutation;
- aliases/recipes compilation-safe where deterministic;
- all lifetimes reviewed;
- unexpected skipped definitions fail build;
- semantic scope model passes sequential/concurrent tests;
- dynamic islands documented and justified.

### Web gate

Before declaring web migration complete:

- WB-1 through WB-4 resolved;
- web InterMix compiled exactly once through coordinated build;
- no production live RouterKernel/Registrar architecture;
- no Foundation universal request scope;
- default plain route has no global middleware tags;
- requestless/scopeless route remains requestless/scopeless;
- route-referenced DI classes are visible to InterMix before compile;
- no serialized Foundation service graph in router artifact;
- parameterized middleware remains declarative;
- custom app errors do not unnecessarily destroy direct routing-control path;
- runtime adapter owns response writing;
- persistent request state cleanup is deterministic.

### Release-generation gate

Before production deploy:

- web/cli/worker/scheduler artifacts all belong to one immutable generation;
- all four are verified;
- all skipped-definition reports accepted;
- Foundation generation manifest complete;
- activation is atomic;
- previous generation remains usable until successful switch;
- prevalidated mode, if used, has a real immutable trust source;
- persistent workers have a safe replacement strategy.

---

## 20. Final definition of done

Foundation 3 runtime architecture is done only when all of the following are true:

- InterMix 10.0.3+ is fully utilized across `web`, `cli`, `worker`, and `scheduler`;
- Webrick owns only the `web` HTTP runtime and does not leak into ownership of non-web paths;
- `ContainerBuilder` is the sole DI graph builder;
- each runtime receives a fresh builder from the same Foundation composition source;
- web compilation uses Webrick's coordinated path exactly once;
- non-web runtimes compile directly through InterMix;
- production graphs are finalized before execution;
- route topology and DI compilation are coordinated so route classes do not silently become runtime islands;
- compiled router artifacts contain descriptors/data, not hidden Foundation service graphs;
- production Request construction occurs only when the Webrick execution plan requires it;
- production HTTP scope occurs only when the execution path requires scoped/runtime-backed state;
- stable semantic scopes support compile-time cleanup and concurrent isolation;
- no mutable singleton cleanup tracker leaks state between concurrent requests/jobs;
- auth/session/database state is isolated correctly;
- Webrick runtime adapters exclusively own native response writes;
- production route/provider/module discovery is absent from request/job hot paths;
- no silent artifact fallback exists;
- all dynamic islands are explicit and reported;
- four runtime artifacts publish as one atomic Foundation release generation;
- persistent workers do not leak state and replace safely on deployment;
- representative real-HTTP and non-web benchmarks show measured, attributable Foundation overhead;
- the complete source tree is rescanned after implementation;
- InfByte can consume Foundation's final build/runtime lifecycle directly without recreating another framework runtime above it.

---

## 21. Development starting checklist

Before beginning Foundation implementation work, use this order:

1. keep InterMix 10.0.3 as the current DI baseline;
2. implement/release the required Webrick WB-1 through WB-4 corrections;
3. capture lower-layer benchmark baselines;
4. update Foundation dependency floors to those exact released versions;
5. begin Foundation Phase 2 composition-root work;
6. do not port old ContainerFactory/ContainerCacheManager/WebrickRouterFactory architecture as temporary production scaffolding;
7. keep all four runtime paths in every composition/lifetime/release decision from the first implementation commit.

This is the implementation baseline to use when development begins.
