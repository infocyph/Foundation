# Foundation 3 — Runtime Architecture, Boot, DI & Performance Plan

**Status:** Draft / implementation baseline  
**Base:** `main`  
**Target:** Foundation 3  
**Primary runtime baselines:** InterMix `^10.0.3`, Webrick `^5.1`  
**Priority order:** correctness → performance → scalability → ergonomics

## 1. Purpose

Foundation 3 is a deep internal runtime revision, not a feature-expansion release. Its purpose is to make Foundation consume the new InterMix 10.0.3 and Webrick 5.1 architecture correctly and to ensure Foundation does not reintroduce overhead that those libraries have removed.

The central goal is simple:

> Foundation must become a thin, deterministic application composition and integration layer around the optimized InterMix/Webrick runtime, rather than a second runtime layered on top of them.

Public application-facing behavior should remain stable where it is useful and inexpensive to preserve, but internal compatibility with the Foundation 2 boot/container architecture is not a design constraint for this major release.

## 2. Non-goals

Foundation 3 must not:

- create a second DI framework or substantial builder abstraction above InterMix;
- create a second HTTP transport/runtime abstraction above Webrick;
- duplicate InterMix or Webrick artifact hashing, validation, manifest, scope, matcher, dispatch, or runtime-adapter behavior;
- perform compilation, route discovery, provider discovery, module discovery, or graph mutation inside a production request/job hot path;
- retain old internals merely for internal backward compatibility;
- add unrelated features while the runtime and performance work is in progress;
- optimize synthetic microbenchmarks at the cost of real HTTP behavior.

## 3. Current Foundation 2 gaps to resolve

The current `main` architecture predates the new runtime model. In particular:

- `Application` directly owns an InterMix dynamic `Container`.
- `ContainerFactory` creates the runtime container before the application is fully composed.
- `Application` registers an `onMissing()` callback that activates managed services dynamically.
- `Application::make()` performs Foundation-managed activation before container resolution.
- core application objects are bound into the dynamic container after construction.
- `ExecutionScope` is currently bound as a singleton Foundation service.
- `HttpKernel` wraps every HTTP request in Foundation `ExecutionScope` before Webrick dispatch.
- `HttpKernel` performs maintenance-state work before Webrick dispatch.
- production container activation is an optional runtime behavior rather than an explicit composition-root runtime choice.
- current dependencies still target InterMix 9.x and Webrick 4.x.

These patterns must be reevaluated against InterMix 10.0.3 and Webrick 5.1 rather than mechanically ported.

## 4. Core architectural decisions

### AD-1 — InterMix `ContainerBuilder` is the sole DI graph builder

Foundation must not introduce an `ApplicationBuilder` that mirrors InterMix. The application graph is built through one InterMix `ContainerBuilder` owned by the host/Foundation composition root.

Foundation may introduce a small graph-composition object/function (working name: `FoundationGraph`) whose job is only to contribute Foundation definitions, providers, capabilities, and integration metadata to an existing `ContainerBuilder`.

Conceptually:

```text
FoundationGraph
    |
    +-- Foundation core definitions
    +-- selected Foundation integrations
    +-- optional package contributions
    +-- Webrick::contributeTo(...)
    |
    v
InterMix ContainerBuilder
```

### AD-2 — One graph definition serves development, build, and production

There must be one deterministic application-owned graph composition path. Development, release compilation, production bootstrap, CLI, scheduler, and worker runtimes must not maintain independent binding definitions that can drift.

Environment selection and runtime selection remain separate concepts:

- `setEnvironment(...)` selects environment-specific graph metadata/bindings;
- `development()` selects the dynamic development runtime;
- `compile()` produces the production graph artifact;
- `production()` / `productionPrevalidated()` selects the generated production runtime.

### AD-3 — Webrick contributes to the host graph

Foundation owns the host `ContainerBuilder`. Webrick is integrated through its 5.1 contribution model (`Webrick::contributeTo(...)` and related release APIs), not through a separately owned Webrick container.

### AD-4 — Production is compiled and finalized

Production compilation is a build/deployment operation, never a live-request operation.

Late mutation of a finalized production graph is an exceptional correctness fallback that may deoptimize the runtime; it must not become Foundation's normal configuration strategy.

### AD-5 — Webrick owns HTTP runtime and transport

Foundation must not rediscover or normalize SAPI/RoadRunner/Swoole/Workerman requests itself when Webrick 5.1 already owns that boundary.

Production HTTP should terminate in Webrick's compiled runtime model:

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

Foundation supplies integration/configuration at boot; it must not insert an unnecessary generic dispatch layer into every request.

### AD-6 — HTTP scope creation is demand-driven

Foundation must remove the assumption that every HTTP request needs an outer Foundation execution scope.

Webrick's compiled execution plan and InterMix scopes should determine whether a matched route/pipeline actually requires scoped state. Foundation cleanup requirements should be contributed through InterMix scope lifecycle hooks where appropriate.

Foundation continues to own explicit execution boundaries for CLI commands, jobs, scheduler runs, and worker jobs where those lifecycles belong to Foundation.

### AD-7 — Specialist packages keep domain ownership

Foundation owns integration, configuration, composition, lifecycle bridging, and application-facing convenience. CacheLayer, DBLayer, Omnibus, OTP, ReqShield, TalkingBytes, Webrick, InterMix, and other specialist libraries retain their domain behavior.

Foundation must delete or collapse duplicate logic when a lower-level library now owns the correct implementation.

### AD-8 — Foundation release metadata extends, not duplicates, Webrick release metadata

Webrick 5.1 release manifest format 2 already owns InterMix/Webrick artifact metadata and xxh128 identities. Foundation must reference/compose that release information instead of inventing parallel SHA-256 or duplicate digest fields.

## 5. Target boot and runtime lifecycle

### 5.1 Development bootstrap

Development remains flexible and diagnostic:

```text
load environment/config
    -> create ContainerBuilder
    -> compose FoundationGraph
    -> Webrick contribution
    -> optional development-only diagnostics
    -> development()
    -> development RouterKernel::bootWithRegistrar(...)
    -> handle request/command
```

Development may retain reflection, route registration, mutable configuration, diagnostic tracing, and convenient dynamic behavior.

Development must still use the same graph-composition function used by the production compiler.

### 5.2 Build / release step

A release/cache-warm command must perform deterministic work once:

1. load and normalize environment/configuration;
2. determine capability/module topology;
3. compose the exact application `ContainerBuilder` graph;
4. validate the graph strictly;
5. compile the InterMix production artifact;
6. review the InterMix compilation report and every skipped/dynamic definition;
7. compile Webrick route/execution artifacts through the Webrick 5.1 release path;
8. generate other Foundation-owned deterministic artifacts only where they remove meaningful runtime work;
9. emit a Foundation-level release manifest that references the Webrick release bundle;
10. publish code, dependencies, artifacts, manifests, and trusted deployment metadata as one immutable release unit.

Potential Foundation-owned compiled artifacts include:

- normalized configuration;
- capability/module topology;
- console command map;
- scheduler definition map;
- worker definition map;
- provider/boot topology when deterministic.

Do not compile data merely because it can be compiled. Each artifact must remove measurable runtime work or improve correctness/determinism.

### 5.3 Production bootstrap

Production bootstrap should be explicit and fail-fast:

```text
load Foundation release metadata
    -> reconstruct same FoundationGraph
    -> load InterMix ProductionContainer
    -> load verified/prevalidated Webrick release artifacts
    -> construct CompiledRouterKernel
    -> select RuntimeAdapter once
    -> construct RuntimeServer
    -> freeze finalized registries
    -> serve
```

`productionPrevalidated()` and Webrick prevalidated artifact loading may only be used when the digest/fingerprint comes from trusted immutable deployment metadata. Reading the expected digest from the same runtime-writable artifact directory does not establish trust.

Missing, corrupt, stale, ABI-incompatible, environment-mismatched, or fingerprint-mismatched production artifacts must produce a clear boot/deployment failure. Production must not silently fall back to expensive development discovery.

### 5.4 Desired production HTTP hot path

The desired path is:

```text
native request
    -> boot-selected RuntimeAdapter context
    -> canonical routing input
    -> compiled matcher
    -> execution plan
    -> create full Request only if required
    -> enter InterMix scope only if required
    -> dispatch handler/middleware
    -> write through RuntimeAdapter
```

Foundation should add no mandatory layer between these stages unless the behavior cannot live at build/boot time or in a route/middleware integration and its cost is measured and accepted.

## 6. DI and graph rules

Foundation 3 DI work must follow these rules:

- prefer compile-friendly class-string definitions and explicit lifetimes;
- use `singleton`, `scoped`, and `transient` intentionally and audit every Foundation registration;
- avoid closure definitions/factories where a statically compilable definition can represent the same behavior;
- treat each InterMix dynamic/skipped compilation entry as an intentional compatibility island, not harmless noise;
- use `onMissing()` only for narrow, justified optional-capability behavior; it must no longer be the general Foundation provider-discovery mechanism;
- use `onScopeLeave()` for cleanup that belongs to a DI scope;
- use resolving/resolved hooks only when their semantics justify the compilation/runtime cost;
- ensure singleton services never retain request/job-specific mutable state in persistent processes;
- ensure scoped state is isolated across sequential requests/jobs and concurrent Fiber/coroutine contexts supported by InterMix;
- prohibit provider/runtime code from mutating the graph after production finalization during normal operation;
- avoid typing public Foundation integration APIs to the dynamic `Container` where a runtime-neutral contract or application resolver can preserve production compatibility.

## 7. Application and service-provider redesign

The existing `Application`, `Bootstrapper`, `ServiceRegistry`, `ServiceProvider`, `ProviderFileLoader`, `ContainerFactory`, and `ContainerCacheManager` model requires a coordinated review.

The redesign should separate three concerns that are currently easy to mix:

### Graph contribution

Runs before development-container creation or production compilation/loading. It registers definitions, lifetimes, aliases, tags, contextual bindings, and deterministic integration metadata.

### Process boot

Runs once after the selected runtime/container is available. It performs true process-level side effects that cannot be represented as graph definitions.

### Execution/request/job behavior

Runs inside an explicit request/job/command lifecycle, normally via Webrick middleware/handlers or InterMix/Foundation scopes.

Provider APIs should be reshaped or adapted so these phases are explicit. A provider must not silently mutate a finalized production graph from a `boot()` method.

`Application` may remain the user-facing application object, but it should become a façade/coordinator over the selected runtime rather than the owner of a permanently dynamic InterMix `Container`.

## 8. HTTP integration redesign

The current Foundation `HttpKernel` unconditionally:

- receives/builds a full Webrick `Request` before routing;
- enters Foundation `ExecutionScope` for every request;
- performs maintenance-state handling before Webrick routing;
- calls the development-style `RouterKernel` abstraction.

Foundation 3 must remove those assumptions from production.

Specific tasks:

- add a clean development HTTP path around `RouterKernel::bootWithRegistrar()`;
- add a separate compiled production path around `CompiledRouterKernel`/`RuntimeServer`;
- stop creating a Foundation outer HTTP scope when Webrick/InterMix execution planning can avoid one;
- preserve Webrick 5.1 requestless direct-route execution when Foundation features do not require a request;
- preserve direct 404/405 control flow and lazy middleware construction;
- do not perform runtime-engine discovery per request;
- do not perform route/provider/capability discovery per request;
- audit every Foundation global middleware/integration for whether it forces `Request` or scope creation;
- audit maintenance mode specifically: integrate it without duplicating Webrick behavior and without forcing an expensive request/scope path when a cheaper boot/runtime-aware mechanism is possible;
- activate session/auth/CSRF and other stateful capabilities only where the route/middleware topology requires them rather than making the minimal request path stateful by default.

## 9. Configuration and capability topology

Production configuration must become boot/build friendly:

- normalize environment values once;
- prefer OPcache-friendly PHP artifacts for deterministic configuration/topology;
- eliminate repeated directory scans, globbing, reflection, `class_exists()` capability probing, file metadata walks, and deep config normalization from request hot paths;
- compile a capability map when it removes repeated optional-package discovery;
- retain direct optional project dependencies; Foundation must not absorb package ownership;
- fingerprint configuration consistently so InterMix, Webrick, and Foundation release artifacts can be rejected when they do not belong to the same release/configuration;
- keep development invalidation convenient without carrying development freshness checks into production requests.

## 10. Release/artifact model

A Foundation-level manifest may contain Foundation-owned data such as:

```text
format
foundation_version
environment
config_artifact + config_fingerprint
capability_artifact
command_artifact
scheduler_artifact
worker_artifact
webrick_release_manifest reference
```

It must not duplicate Webrick 5.1 fields that already represent:

```text
intermix.path
intermix.digest
webrick.path
webrick.digest
webrick.fingerprint
```

InterMix/Webrick runtime identities use the current xxh128 contract. Foundation must not introduce a legacy SHA-256 compatibility path around those artifacts.

## 11. Runtime-specific behavior

### SAPI / PHP-FPM

- use compiled artifacts in production;
- minimize per-request boot work even though PHP process/request lifecycles differ from persistent servers;
- use prevalidated artifact paths only when the deployment trust model actually makes them safe and beneficial;
- keep OPcache-friendly manifests/config artifacts.

### RoadRunner / Swoole / Workerman

- select the Webrick runtime adapter once at process boot;
- reuse finalized immutable singleton graph state;
- use scoped state for request/job data;
- guarantee cleanup on success, exception, cancellation, and worker replacement;
- verify Foundation integrations do not leak state between requests;
- support graceful worker replacement when a new release/artifact set is deployed;
- audit static registries, facades, log context, auth/session state, database transactions, cache locks, temporary files, and external context trackers for persistent-process safety.

### CLI / scheduler / worker jobs

Foundation continues to own these lifecycle boundaries:

- one selected runtime/container per process when safe;
- one execution scope per command/job/scheduled invocation where scoped services are needed;
- deterministic scope exit/cleanup;
- no rebuilding the application graph for each item in a long-running worker;
- no accidental carry-over of request/job state;
- clear lost-ownership/lease behavior for scheduler/worker coordination;
- preserve existing runtime-control correctness work while removing unnecessary container/bootstrap work.

## 12. Performance strategy and acceptance gates

Foundation performance must be measured by layer, not guessed.

### Required benchmark layers

1. raw PHP baseline;
2. standalone Webrick 5.1 compiled minimal endpoint;
3. Foundation 3 + Webrick 5.1 compiled minimal endpoint;
4. minimal InfByte application.

The key attribution metric is:

```text
Foundation tax = Foundation minimal request - standalone Webrick 5.1 request
```

Measure separately:

- cold process boot;
- warm process request;
- real Apache/Nginx + PHP-FPM/OPcache HTTP behavior used by the benchmark environment;
- persistent-runtime behavior where supported;
- throughput;
- latency distribution (at least p50/p95/p99 when the harness provides it);
- memory/peak memory;
- artifact load/verification stages;
- request construction;
- matching;
- DI scope entry/exit;
- middleware/handler dispatch;
- response writing.

Do not invent an arbitrary percentage budget before the new baseline exists. Establish the standalone Webrick 5.1 baseline first, then define an evidence-based maximum Foundation overhead. A minimal Foundation route should trend as close to Webrick as its genuinely required integration behavior permits.

Profiling instrumentation must be opt-in and have effectively zero normal-path timing work when disabled.

## 13. Full source audit checklist

The Foundation 3 pass is not complete after the new boot path works. Every class/function in the full `src/` tree must be reviewed against the new ownership and hot-path rules, including at minimum:

- Application and service-provider lifecycle;
- Bootstrap;
- Config;
- Container integration/cache compatibility code;
- HTTP/routing/middleware;
- Runtime and execution scopes;
- Auth/session/security;
- Cache integration;
- Database integration;
- Commands and console integration;
- Scheduler and workers;
- Modules/capabilities;
- Messaging/communication/notifications;
- Logging/diagnostics;
- Filesystem/storage/uploads;
- Operations/runtime control;
- validation;
- OTP/WebAuthn integration;
- generators/publishing;
- facades/helpers and other convenience APIs.

For each code path, explicitly search for:

- reflection on a production hot path;
- `class_exists()`/`interface_exists()`/feature probing repeated during requests/jobs;
- `glob()`, directory scans, recursive discovery, file reads, `filemtime()` loops, and repeated artifact validation;
- config traversal/normalization that can happen once;
- closure-heavy DI definitions that unnecessarily become dynamic islands;
- service locator/facade/container lookups where compiled constructor injection can be direct;
- global/static mutable state unsafe for persistent workers;
- request/job state stored in singleton services;
- unnecessary full `Request` construction;
- nested/duplicated scopes;
- exception throw/catch used for expected control flow;
- repeated serialization, hashing, fingerprinting, or manifest parsing;
- duplicated Webrick functionality;
- duplicated InterMix lifecycle/runtime behavior;
- hidden DB/cache coupling;
- cleanup failures that mask the original exception;
- persistent-worker resource leaks;
- fork/coroutine/Fiber safety issues;
- schema/migration checks in boot or request paths;
- module/provider/command discovery that can be compiled;
- eager logging context creation when logging is disabled or the level is filtered.

## 14. Implementation phases

### Phase 0 — Baseline and dependency alignment

- raise InterMix requirement to `^10.0.3`;
- raise Webrick requirement to `^5.1`;
- retain PHP `^8.4` and current compatible ecosystem floors after verification;
- keep `infocyph/phpforge` as `dev-main@dev`;
- capture pre-change Foundation representative benchmark;
- capture standalone Webrick 5.1 compiled benchmark in the same environment;
- inventory current boot/container/provider/request lifecycle.

**Exit:** reproducible baseline and dependency/runtime API map.

### Phase 1 — Composition root / `FoundationGraph`

- introduce one application graph composition path around `ContainerBuilder`;
- move core Foundation registrations into graph contribution;
- integrate Webrick contributions into the same builder;
- classify all current bindings by lifetime and compilation eligibility;
- establish a controlled compatibility strategy for current service providers.

**Exit:** development graph can be built through the new composition root without parallel binding definitions.

### Phase 2 — Development/build/production boot split

- make runtime choice explicit;
- implement development dynamic-container path;
- implement strict graph validation and InterMix compilation path;
- implement production and trusted-prevalidated loading paths;
- prohibit normal production graph mutation after finalization;
- add clear artifact/boot failure semantics.

**Exit:** same graph definition boots correctly in development and generated production modes.

### Phase 3 — Webrick 5.1 release/runtime integration

- integrate coordinated release compilation;
- consume Webrick format-2 release metadata;
- construct `CompiledRouterKernel` for production;
- select/use `RuntimeAdapter`/`RuntimeServer` correctly;
- remove duplicated Foundation HTTP transport behavior;
- preserve Webrick artifact trust/fingerprint semantics.

**Exit:** production HTTP runs entirely on Webrick's compiled runtime path.

### Phase 4 — HTTP hot-path reduction

- remove unconditional outer Foundation request scope;
- remove unnecessary `Request` creation;
- remove repeated service/capability discovery;
- audit maintenance integration;
- audit global middleware for forced request/scope work;
- remove Foundation wrappers that add no domain behavior;
- preserve requestless/direct compiled execution.

**Exit:** minimal Foundation endpoint has measured and attributable overhead over standalone Webrick.

### Phase 5 — Provider, config, and capability compilation

- split graph contribution from process boot side effects;
- replace broad lazy provider activation with deterministic topology where possible;
- compile normalized production config/capability metadata when beneficial;
- eliminate request-time discovery/freshness scans;
- create explicit allowlist/reporting for intentional InterMix dynamic islands.

**Exit:** production request path performs no provider/module/config discovery.

### Phase 6 — Scope and persistent-runtime correctness

- map cleanup behavior to InterMix scope hooks;
- retain Foundation-owned CLI/job/scheduler scopes where appropriate;
- audit singleton/scoped state;
- verify sequential and concurrent request/job isolation;
- audit all persistent-worker resources and registries.

**Exit:** no state leakage and deterministic cleanup across supported runtimes.

### Phase 7 — Optional ecosystem integration audit

Audit Foundation adapters against current package releases and remove duplicated behavior. Keep optional package dependencies optional/direct to consuming projects unless Foundation genuinely requires them.

**Exit:** package ownership is clear and optional integrations do not tax unrelated requests.

### Phase 8 — CLI, scheduler, worker, operations audit

- reuse selected production runtime correctly;
- compile command/schedule/worker topology only when beneficial;
- preserve scheduler lease/lost-ownership correctness;
- preserve atomic runtime-control behavior;
- remove graph/bootstrap rebuilding in long-lived loops;
- ensure exit-code and exception semantics remain correct.

**Exit:** non-HTTP runtimes use the same composition architecture without unnecessary rebuilds.

### Phase 9 — Performance attribution and regression pass

- run representative real-HTTP benchmark;
- compare Webrick 5.1 vs Foundation 3 vs minimal InfByte;
- use stage profiling only to attribute remaining overhead;
- optimize only measured Foundation costs;
- repeat full correctness/security/static-analysis suite.

**Exit:** Foundation overhead is known, justified, and within the evidence-based target established from the baseline.

### Phase 10 — Full rescan and release readiness

- rescan every class/function after architecture work;
- reconcile documentation with actual runtime behavior;
- document dynamic-island allowlist;
- document artifact build/deployment requirements;
- provide Foundation 2 → 3 migration notes;
- verify InfByte consumes Foundation 3 without recreating boot/DI logic;
- perform final benchmark and release audit.

**Exit:** Foundation 3 release candidate.

## 15. Compatibility and migration policy

Foundation 3 is a major release and may change internals aggressively.

Prefer to preserve high-value application-facing methods/facades when they can delegate cleanly to the new runtime without forcing a dynamic container or extra hot-path work. Do not preserve APIs whose semantics require the old runtime architecture.

Areas likely to need migration or compatibility adapters include:

- APIs exposing the concrete dynamic InterMix `Container`;
- service providers whose `boot()` methods currently register/mutate bindings;
- runtime container-cache toggles based on the old compiled resolver mechanism;
- custom bootstrappers that assume lazy managed-service activation;
- code relying on an always-present Foundation `ExecutionScope` around HTTP;
- code that assumes `Application::handle(Request)` is the production transport entry point.

Where a bridge is retained, it must be measured and must not force production onto the dynamic runtime.

## 16. Expected deliverables

- Foundation 3 composition root / `FoundationGraph` equivalent;
- unified graph contribution contract;
- updated `Application` runtime coordination;
- development boot path;
- production build/cache-warm command/path;
- production artifact loader;
- Foundation release manifest (only if Foundation-owned artifact coordination warrants it);
- Webrick 5.1 compiled runtime integration;
- runtime-adapter integration for supported servers;
- scoped cleanup integration;
- deterministic config/capability artifacts where justified;
- intentional dynamic-island report/allowlist;
- updated representative benchmark and layer-attribution tooling;
- Foundation 2 → 3 migration guide;
- updated architecture/deployment documentation;
- exhaustive final source audit report/checklist closure.

## 17. Definition of done

Foundation 3 is not complete until all of the following are true:

- one graph definition is the source of truth for development/build/production;
- InterMix `ContainerBuilder` is the sole DI graph builder;
- production uses generated `ProductionContainer` rather than the dynamic container as its normal runtime;
- Webrick contributes to the host graph and production uses its compiled release/runtime path;
- no route/provider/module graph is rebuilt in a production request;
- no full `Request` is created unless the execution path actually requires it;
- no Foundation HTTP scope is entered unless scoped state is actually required;
- no per-request HTTP runtime-engine discovery exists;
- no silent dynamic fallback hides missing/corrupt/stale production artifacts;
- every InterMix dynamic compilation island is intentional and reviewed;
- persistent runtimes do not leak request/job state;
- cleanup behavior is deterministic under success and failure;
- optional integrations add no meaningful overhead when disabled/uninstalled;
- Foundation's real HTTP overhead over standalone Webrick 5.1 is measured and attributable;
- the full Foundation source tree has been rescanned after implementation;
- InfByte can use Foundation 3's composition/build/runtime lifecycle directly instead of rebuilding another framework-level boot layer.

## 18. Working principle for the implementation branch

For every proposed Foundation abstraction, ask:

1. Does InterMix 10.0.3 already own this responsibility?
2. Does Webrick 5.1 already own this responsibility?
3. Can this work happen at build time instead of process boot?
4. Can it happen once at process boot instead of per request/job?
5. Can it be lazy without turning into runtime discovery?
6. Does the feature force a `Request`, DI scope, container lookup, reflection, filesystem access, or allocation onto routes that do not need it?
7. Is its real-HTTP cost measured?

If a lower layer already provides the correct mechanism, Foundation should integrate it rather than wrap or reproduce it.
