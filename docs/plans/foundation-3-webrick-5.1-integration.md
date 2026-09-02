# Foundation 3 — Webrick 5.1 Deep Integration Plan

**Status:** Dedicated library pass 2 complete / implementation baseline  
**Foundation base:** `main`  
**Foundation target:** 3.x  
**Webrick baseline:** `^5.1` (`5.1` tag at `7d1527f7b9076087549d64bf543e54c96a37911f`)  
**InterMix baseline:** `^10.0.3`  
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

## 1. Purpose

This pass reviews Foundation's entire direct Webrick integration against the actual Webrick 5.1 production architecture.

The central rule is:

> Foundation must use Webrick as the HTTP runtime, router compiler, execution planner, request materializer, middleware dispatcher, runtime-adapter layer, and response writer. Foundation must contribute application policy and services without recreating those runtime responsibilities above Webrick.

The Webrick pass is not a simple dependency bump from 4.x to 5.1. Foundation 2 is architected around the old live `RouterKernel`/`Registrar`/route-cache model, while Webrick 5.1 has a deliberate development/production split and a coordinated release compiler.

This plan therefore treats the old Foundation HTTP/routing bootstrap as replaceable internal architecture.

## 2. Review scope and method

The Foundation repository was scanned from the current `main` tree with focus on:

- every routing and HTTP bootstrap class;
- direct Webrick `Request`/`Response` integration;
- Webrick middleware use and configuration;
- route aliases, groups, presets and parameterized aliases;
- route-file and attribute-route discovery;
- matcher/route-cache ownership;
- exception/error rendering;
- maintenance mode;
- URL-generation/runtime registries;
- session/auth middleware;
- filesystem upload/download HTTP bridges;
- test-client and integration-test assumptions;
- runtime/persistent-worker behavior;
- release-artifact identity and verification;
- Request/scope creation and middleware pipeline costs.

Foundation's connected GitHub repository does not currently expose a complete code-search index, so this pass uses recursive repository-tree enumeration plus direct source inspection. During implementation, add a local/CI scan for `Infocyph\\Webrick`, `RouterKernel`, `Registrar`, `Collection`, `RouteCache`, `MiddlewareAliases`, `Request`, `Response`, and old route-cache classes so moved files cannot escape the migration.

## 3. Executive findings

### W-1 — Foundation is still wired to the Webrick development kernel as if it were production

Current Foundation resolves `RouterKernel` as a singleton and wraps it in `HttpKernel`.

Webrick 5.1 explicitly defines `RouterKernel` as the **development/registrar kernel**. Production is `CompiledRouterKernel` loaded from a compiled router artifact and backed by an InterMix `ProductionContainer`.

Foundation 3 must have separate development and production HTTP bootstrap paths.

### W-2 — `WebrickRouterFactory` must not be ported

The current factory:

- owns matcher selection;
- manages matcher route-cache boot;
- replays route collections;
- passes a live dynamic InterMix container into Webrick;
- disables Webrick request scoping because Foundation owns an outer scope;
- constructs the live `RouterKernel`.

Those assumptions are obsolete in Webrick 5.1. The class should be deleted or reduced to a development-only adapter; it must not remain the production routing composition root.

### W-3 — Foundation's `RouteCacheManager` is not the Webrick 5.1 production release model

Foundation currently builds Webrick matcher caches through `Support\\RouteCache` and maintains separate Foundation freshness metadata.

Webrick 5.1's `ReleaseCompiler` coordinates:

1. strict InterMix graph validation;
2. InterMix production-container compilation;
3. route registration/build;
4. execution-plan compilation;
5. router-artifact compilation;
6. release-manifest publication.

A matcher cache remains a lower-level matcher optimization, not the complete production application artifact.

Foundation production `optimize` must use Webrick's coordinated release compiler for the web runtime rather than running its old route-cache and container-cache systems independently.

### W-4 — Foundation currently destroys Webrick's requestless/scopeless fast path

Current `HttpKernel` receives a fully-created `Request`, enters a Foundation execution scope for every request, performs maintenance work, then calls Webrick.

Webrick 5.1 production dispatch instead:

- derives a lightweight `RoutingInput` first;
- matches a compiled route;
- reads the compiled `ExecutionPlan`;
- creates `Request` only if the plan/global middleware requires it;
- enters an InterMix scope only if the plan/pipeline requires it;
- directly invokes zero-arg and route-argument handlers when possible.

Foundation 3 must not put another unconditional Request/scope wrapper around this path.

### W-5 — Foundation's default empty global-middleware topology is valuable and must remain empty by default

Current Foundation defaults have empty `pre` and `post` global middleware arrays.

Preserve this. Any global middleware makes every route enter Webrick's middleware path and therefore require a `Request`; runtime-backed global middleware also forces a scope.

Capabilities such as auth, session, CSRF, validation, compression, telemetry and maintenance must not silently become universal middleware merely because Foundation supports them.

### W-6 — Existing Foundation middleware should stay Webrick-native, but its construction model must change

Foundation's auth/session middleware correctly uses Webrick `Request`/`Response` instead of inventing another HTTP abstraction. Keep that.

The problem is how middleware is registered today:

- `RouteMiddlewareRegistrar` resolves aliases through `$app->make()`;
- parameterized aliases materialize middleware objects during route registration;
- those objects can contain live Foundation services and runtime state;
- Webrick's artifact codec can persist callable objects, but serializing a resolved Foundation service graph into the router artifact is the wrong boundary.

The production artifact should contain lightweight descriptors/parameters; InterMix should resolve runtime-backed middleware when the pipeline is built.

### W-7 — Parameterized middleware exposes a genuine Webrick 5.1 integration gap

Webrick 5.1 production middleware descriptors support:

- functions/callables;
- class/method descriptors;
- class-backed invokable middleware resolved through `InterMixRuntime::resolveNow()`.

`CompiledMiddlewarePipeline` already supplies runtime parameters (`request`, `next`) through `resolveNow()`.

However, persisted runtime-backed middleware descriptors do not currently carry alias parameters such as:

```text
role:admin
permission:invoice.approve
policy:document,update
oauth-scope:payments.write
oauth-audience:merchant-api
```

Foundation therefore currently resolves these aliases into constructed middleware objects too early.

**Preferred lower-layer fix:** add a small backwards-compatible Webrick 5.1.x descriptor, conceptually `MiddlewareReference` / `ParameterizedMiddlewareDescriptor`, containing:

```text
spec: class/method/class-string
parameters: exportable scalar/list/map values
```

The artifact codec should persist this declaratively, and `CompiledMiddlewarePipeline` should invoke:

```php
$runtime->resolveNow(
    $descriptor->spec,
    [
        ...$descriptor->parameters,
        'request' => $request,
        'next' => $next,
    ],
);
```

This keeps middleware dependencies inside the InterMix runtime instead of serializing live service objects.

If Webrick is not changed, Foundation must redesign each parameterized middleware family so route parameters are compiled into route attributes and one DI-backed middleware reads those attributes. That is possible for some policies but is more invasive and harder to preserve for arbitrary user-defined aliases.

Do not accept “serialize the already-built middleware object” as the normal production solution.

### W-8 — The current always-custom `ErrorHandler` sacrifices Webrick's direct 404/405 fast path

`CompiledRouterKernel` uses direct control-flow responses for routing errors when no custom `ErrorHandler` is installed.

Foundation currently always installs a custom `ErrorHandler` for logging and `ExceptionRenderer`. With that configuration, routing errors may require full Request materialization and custom rendering.

Foundation 3 must separate these concerns:

- preserve Foundation exception mapping/logging where required;
- do not force custom route-error rendering merely to handle application exceptions;
- benchmark custom-handler overhead separately.

**Preferred Webrick 5.1.x refinement:** allow a custom dispatch/exception handler while retaining direct default 404/405 handling unless the host explicitly opts into custom routing-error rendering. This is a lower-layer optimization that benefits every framework integration, not a Foundation-specific workaround.

If Webrick is left unchanged, Foundation must choose deliberately between the faster null-handler path and Foundation-specific exception/logging behavior; correctness/observability wins, but the cost must be measured and not hidden.

### W-9 — Foundation maintenance mode is currently in the worst possible location for the new runtime

Current `HttpKernel` checks `MaintenanceManager::status()` before every router dispatch. File mode can perform a file read/stat on every request, and the check occurs after Foundation has already created a full `Request` and scope.

Webrick has `MaintenanceModeMiddleware` and `FileMaintenanceState` with worker-local refresh caching. Reuse Webrick's middleware/state contract where the feature shape fits.

However, putting maintenance middleware globally still forces Request/pipeline creation for every normal request. Foundation's maintenance feature is operational and route-independent, so evaluate two options:

1. **portable/simple:** a Webrick maintenance middleware backed by a Foundation `MaintenanceStateInterface` adapter with worker-local TTL caching;
2. **fastest dynamic operational gate:** a tiny pre-routing gate working on `RoutingInput`/runtime context and returning an optional Response without Request/scope creation.

If option 2 proves materially better, prefer adding a generic boot-selected pre-routing gate to Webrick 5.1.x rather than inventing a substantial Foundation HTTP runtime wrapper. Until benchmarked, do not make a new abstraction mandatory.

The old `HttpKernel` per-request maintenance check must disappear either way.

### W-10 — `FilesystemResponseFactory` contains a runtime-adapter incompatibility

Foundation currently creates `Response::stream()` with a producer that opens `php://output` and lets Pathwise write directly to that resource.

Webrick 5.1's runtime writer expects a streaming producer to **yield/return chunks**; persistent runtime adapters own the actual native response write. Writing directly to `php://output` bypasses that ownership and is not portable to RoadRunner/Swoole/Workerman.

Foundation 3 must:

- never write to `php://output` inside a Webrick response producer;
- for local files, prefer Webrick `FileBody`/`Response::download()`, `inline()`, `rangedDownload()`, `rangedFile()`, or `streamDownload()` as appropriate;
- preserve Foundation/Pathwise security/policy checks before creating the Webrick response;
- for non-local/custom Pathwise sources, expose a Webrick-compatible `BodyStream` or chunk-yielding producer rather than a SAPI output side effect;
- keep X-Sendfile/X-Accel-Redirect policy where required;
- test SAPI plus at least one persistent/native adapter path.

This is a real correctness issue for Webrick 5.1 runtime-adapter support, not merely a micro-optimization.

### W-11 — Foundation should consume Webrick runtime capabilities instead of duplicating transport decisions

Webrick runtime adapters expose `RuntimeCapabilities`, including persistent/concurrent behavior, native streaming/file handling, transport compression and request limits.

Built-in Webrick middleware such as compression and request limits already bypasses itself when the selected transport provides that capability.

Foundation should configure these Webrick middleware components as portable fallbacks where desired and let the runtime adapter suppress duplicate work. Do not add Foundation transport-detection branches around them.

### W-12 — Production URL generation must come from the compiled kernel registry, not a live Registrar/Collection

Foundation currently exposes live `Registrar`, `Collection`, and `foundation.router` services.

Webrick 5.1 production boots/finalizes URL services from the compiled artifact and freezes `UrlGeneratorRegistry` before traffic.

Foundation 3 rules:

- no live mutable `Registrar`/`Collection` service in normal production;
- route registration is a build/development concern;
- application URL generation uses Webrick's compiled/frozen URL generator service/registry;
- attempts to register routes after production freeze fail fast.

## 4. Direct Foundation Webrick surfaces and required action

### 4.1 `src/Http/HttpKernel.php`

Current:

```text
Request already exists
-> Foundation ExecutionScope
-> MaintenanceManager::status()
-> RouterKernel::handle(Request)
```

Target:

- remove from the native production hot path;
- do not own the HTTP scope;
- do not own native emission;
- do not pre-create Request;
- do not perform universal maintenance I/O;
- optionally retain a very thin embedded/testing API that delegates an already-created `Request` to the selected Webrick kernel.

### 4.2 `src/Http/HttpServiceProvider.php`

Current binds live `RouterKernel`, custom ErrorHandler and Foundation HttpKernel.

Target:

- development graph may expose a development `RouterKernel`;
- production bootstrap constructs/loads `CompiledRouterKernel` from release metadata rather than resolving `RouterKernel` from a general provider;
- split exception-renderer services from kernel construction;
- custom ErrorHandler becomes an explicit boot choice, not an incidental always-on service;
- no Foundation outer execution-scope dependency.

### 4.3 `src/Routing/WebrickRouterFactory.php`

**Disposition: remove from production; likely delete entirely after migration.**

Development boot can be represented directly by a small route-build function plus:

```text
RouterKernel::bootWithRegistrar(...)
```

Production uses `ReleaseCompiler` + `CompiledRouterKernel` and therefore does not need the old factory.

### 4.4 `src/Routing/RoutingServiceProvider.php`

Current production-like graph exposes:

- `WebrickRouterFactory`;
- `Registrar`;
- `Collection`;
- `RouteFileLoader`;
- `foundation.router`.

Target:

- DI graph contains controller/middleware/application services;
- route-registration tooling belongs to development/build composition;
- live Registrar/Collection services are dev/build-only;
- production URL-generation service is separate from registration;
- do not bind the mutable Router facade as an application runtime dependency.

### 4.5 `src/Routing/RouteCacheManager.php`

**Disposition: remove from production optimize flow.**

Possible outcomes:

- delete if Webrick's release compiler covers all useful behavior;
- retain only as an explicit development/matcher diagnostic command if there is a demonstrated use case.

Do not maintain two independent production route artifact systems.

### 4.6 `src/Routing/RouteCachePath.php`

Current class:

- owns matcher-cache path layout;
- owns a Foundation SHA-256 routing-config freshness manifest;
- reconstructs a matcher to detect warm state.

Production action:

- retire from production boot/status;
- use Webrick release-manifest/router-artifact identity instead;
- a Foundation `config_fingerprint` may remain SHA-256 (Webrick treats it as an opaque non-empty identity); do not confuse this with Webrick's 32-hex xxh128 artifact `digest`/`fingerprint` fields;
- if a dev matcher cache remains, isolate its config/path from production release readiness.

### 4.7 `src/Routing/RouteFileLoader.php`

Keep route-file/attribute discovery behavior, but run it only inside:

- development route registration; or
- Webrick `ReleaseCompiler`'s build-time registration closure.

Production requests must never scan route directories, require route files, reflect controller directories, or load attributes.

### 4.8 `src/Routing/RoutePresetRegistrar.php`

Keep the Foundation policy presets (`web`, `web-auth`, etc.) as a thin build-time layer over Webrick registration.

Do not let preset registration activate middleware services. It should contribute middleware descriptors/route attributes only.

### 4.9 `src/Routing/OAuthRouteRegistrar.php`

The current class-method route descriptors are a good Webrick 5.1 production shape.

Keep declarative route registration, but execute it only during development/build route composition. Do not register OAuth routes into a live production router after boot.

### 4.10 `src/Routing/RouteMiddlewareRegistrar.php`

Rewrite around build-safe descriptors.

Rules:

- register deterministic aliases before route compilation;
- never resolve middleware service graphs during alias parsing just to persist them into the artifact;
- non-parameterized DI middleware should resolve as class/method descriptors;
- parameterized aliases should use the Webrick descriptor enhancement described in W-7, or an explicit route-attribute design;
- alias registries are finalized/frozen before traffic;
- old “warm matcher cache requirements” logic must not control production alias registration.

### 4.11 `src/Routing/WebrickMiddlewareFactory.php`

The factory correctly reuses Webrick's production-grade middleware rather than duplicating it. Preserve that ownership.

Change the construction boundary:

- normalize middleware config at build/graph time;
- register DI-backed middleware where practical;
- avoid serializing middleware objects containing live Foundation services;
- keep disabled middleware entirely out of the active graph/topology;
- cache/database-backed Webrick middleware activates those capabilities only when configured;
- use Webrick `RuntimeCapabilities` so transport-native compression/request-limit handling bypasses portable middleware automatically;
- global pre/post lists stay empty by default.

### 4.12 Foundation auth middleware family

Files include principal resolution and auth/guest/verified/MFA/recent/role/permission/policy/OAuth audience/scope middleware.

Keep Webrick-native signatures:

```php
__invoke(Request $request, callable $next): Response
```

But ensure:

- only routes declaring the middleware pay Request/scope cost;
- DI-backed middleware is compiled/resolved through InterMix;
- per-route parameters are artifact data, not serialized service objects;
- current-principal state is correctly scoped/Fiber-safe;
- middleware order remains deterministic.

### 4.13 Session/CSRF middleware

Keep Foundation session semantics and Webrick Request/Response/Cookie types.

Requirements:

- session middleware remains route/preset-specific, never universal by default;
- session cleanup works inside Webrick-selected request scope;
- no second Foundation outer scope;
- CSRF runs only on routes/groups that actually require the browser-session stack.

### 4.14 Filesystem HTTP bridge

Keep Webrick request parsing, conditional validation, Range semantics and immutable Response types.

Change download response production as described in W-10 so Webrick owns actual response writing under every runtime adapter.

`FilesystemUploadRequestHandler` already consumes Webrick `UploadedFile`/`Request` without owning transport emission; that boundary is appropriate.

### 4.15 Testing HTTP client

`HttpTestClient` intentionally constructs `Request::fake()` and calls the embedded application handler. Keep this convenience.

Production/native runtime tests must additionally exercise the compiled kernel/runtime-adapter path so the test client does not accidentally become the architecture being benchmarked.

## 5. Exact Webrick 5.1 contract Foundation must use

### 5.1 Host-owned InterMix graph

Foundation owns the InterMix `ContainerBuilder` and environment.

Webrick contributes to that exact graph:

```php
Webrick::contributeTo($builder, $providers);
```

Do not create a separate Webrick container.

### 5.2 Development kernel

Development path:

```text
same Foundation graph
-> ContainerBuilder::development()
-> Invoker::with(dynamic container)
-> RouterKernel::bootWithRegistrar(...)
```

`RouterKernel` is development/registrar infrastructure. Development may use route discovery, mutable aliases, tracing and convenient diagnostics.

### 5.3 Production release compilation

For web production, use Webrick `ReleaseCompiler::compile(...)` as the coordinated web release builder.

It already performs:

```text
builder->validate(strict: true)
builder->compile(intermixPath)
RouteCompiler->compile(...)
RouterArtifactCompiler->compile(...)
release manifest publication
runtime PHP manifest publication
```

Foundation should pass:

- the already-composed Foundation/Webrick builder;
- one deterministic route-registration closure;
- environment;
- one Foundation config fingerprint;
- artifact paths;
- registrar options;
- configured global middleware descriptors/tags.

Do not separately compile the same InterMix web builder in another Foundation container-cache step.

### 5.4 Release manifest format

Authoritative Webrick 5.1 source uses release format **2**.

Fields include:

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

Webrick writes:

- JSON release manifest; and
- OPcache-friendly PHP runtime manifest.

Runtime loader prefers the PHP manifest and falls back to JSON.

### 5.5 Digest/fingerprint semantics

Do not collapse these identities:

- `intermix.digest` — xxh128 generated InterMix artifact identity;
- `webrick.digest` — xxh128 Webrick router artifact file digest used in verified loading;
- `webrick.fingerprint` — xxh128 semantic artifact fingerprint used by trusted prevalidated router loading;
- `config_fingerprint` — opaque host configuration identity; Foundation may choose SHA-256 or another deterministic algorithm, but must use one consistent value across the coordinated release.

There is no Webrick 5.1 SHA-256 compatibility field for the InterMix/Webrick artifact identities.

Some Webrick documentation examples still use older SHA-256 naming; Foundation must follow the tagged 5.1 source contract.

### 5.6 Production kernel

Normal verified boot:

```text
ProductionContainer
-> CompiledRouterKernel::fromCompiledArtifact(...)
```

Trusted immutable deployment boot:

```text
ProductionContainer loaded with trusted InterMix digest
-> CompiledRouterKernel::fromPrevalidatedArtifact(
       trusted Webrick artifact fingerprint,
       ...
   )
```

Use prevalidated loading only when the trusted values come from immutable deployment metadata outside the runtime-writable artifact trust boundary.

### 5.7 Matcher boot

`CompiledRouterKernel` asks the matcher whether it can boot from cache. If so, it does not replay canonical routes into the matcher. Otherwise it hydrates the already-compiled route metadata from the router artifact.

Foundation must not rebuild route indexes or replay its own live route collection around this.

### 5.8 Compiled execution plan

Every route has an `ExecutionPlan` carrying capabilities such as:

```text
REQUEST
SCOPE
MIDDLEWARE
DOMAIN
CORS
PRODUCES
ROUTE_ARGS
```

Foundation features must preserve this information instead of flattening every route into the same heavyweight path.

### 5.9 Request materialization

Production `RuntimeRequestContext` contains:

- lightweight `RoutingInput`;
- lazy Request factory;
- runtime capabilities;
- native request/response handles.

Full `Request` is cached/materialized only when required and receives `RuntimeCapabilities` as an attribute.

Foundation must never put native request handles into global state or force Request construction before route matching.

### 5.10 Scope ownership

Webrick production enters an InterMix request scope only when the execution plan or runtime-backed middleware requires one.

The scope is seeded with the Request only when Request/scoped state exists.

Foundation's HTTP cleanup hooks should attach to that InterMix scope lifecycle. CLI/job/scheduler scopes remain Foundation-owned.

### 5.11 Runtime adapters/server

Use Webrick's boot-selected runtime adapter and `RuntimeServer` for native serving:

```text
SapiRuntimeAdapter
RoadRunnerRuntimeAdapter
SwooleRuntimeAdapter
WorkermanRuntimeAdapter
```

`RuntimeServer` performs no per-request engine discovery.

Foundation must not add a second SAPI/RoadRunner/Swoole/Workerman abstraction unless a concrete missing capability is proven.

### 5.12 Response ownership

Exactly one layer writes the response.

Production native path:

```text
handler/middleware returns Webrick Response
-> Webrick RuntimeAdapter writes native response
```

Embedded/testing path:

```text
host passes Request
-> kernel returns Webrick Response
-> host/test owns what happens next
```

No Foundation service may both return a Webrick Response and independently emit its body/headers.

### 5.13 Frozen registries

Before production traffic, `CompiledRouterKernel` freezes Webrick process-level registries/configuration including URL generation, middleware aliases, constraints, header policy, trusted-proxy and method-override configuration.

Foundation must finish all related build/bootstrap configuration first. Production mutation after freeze is a deployment/design error.

## 6. Target Foundation 3 HTTP architecture

### 6.1 Development

```text
Config/build context
-> Foundation InterMix ContainerBuilder
-> Webrick::contributeTo(builder)
-> Foundation middleware/controller definitions
-> development()
-> development route registration
-> RouterKernel::bootWithRegistrar(...)
-> embedded Request handling
```

### 6.2 Web production build

```text
sealed normalized config
-> Foundation graph composition
-> Webrick::contributeTo(same builder)
-> finalized capability/provider topology
-> finalized middleware aliases/presets
-> deterministic route-registration closure
-> Webrick ReleaseCompiler
     -> strict InterMix validation
     -> InterMix production artifact
     -> compiled routes
     -> ExecutionPlans
     -> router artifact/meta
     -> release JSON + runtime PHP manifest
-> inspect InterMix skipped islands
-> publish release atomically
```

### 6.3 Web production boot

```text
trusted/verified Foundation release metadata
-> recreate same Foundation graph
-> InterMix ProductionContainer
-> Webrick release manifest loader
-> CompiledRouterKernel
-> boot-selected RuntimeAdapter
-> RuntimeServer
-> traffic
```

### 6.4 Native request hot path

```text
native request
-> already-selected Webrick RuntimeAdapter
-> RuntimeRequestContext/RoutingInput
-> compiled matcher
-> ExecutionPlan
-> [optional] Request materialization
-> [optional] InterMix scope
-> [optional] lazy compiled middleware pipeline
-> handler
-> Webrick Response
-> RuntimeAdapter writer
```

Foundation should not insert a mandatory service layer between these stages.

### 6.5 Embedded/test API

Foundation may preserve:

```php
$app->handle(Request $request): Response
```

for tests, embedded use and host-owned request adaptation.

This API must delegate to the selected Webrick kernel and must not define the native production architecture. Benchmark native production through RuntimeServer/adapter, not only through `$app->handle(Request)`.

## 7. Middleware policy

### 7.1 Global middleware

Default: none.

Any proposed global middleware must answer:

1. Must literally every route run it?
2. Does it force Request creation?
3. Is it runtime-backed and therefore scope-forcing?
4. Can it instead be attached to a route group/preset?
5. Can the selected transport already provide the capability?
6. Is its Foundation tax measured?

### 7.2 Route-specific middleware

Prefer route/group middleware for:

- session;
- CSRF;
- principal resolution;
- auth/guest;
- verification;
- MFA/recent auth;
- role/permission/policy;
- OAuth scope/audience;
- application validation/policies.

### 7.3 Webrick built-ins

Continue using Webrick implementations for:

- CORS/policies;
- signed URL verification;
- throttling;
- cache validators;
- response cache;
- compression;
- request limits;
- negotiation;
- response linting;
- telemetry;
- gateway hardening;
- input sanitization;
- method normalization;
- vary accumulation;
- maintenance where the state model fits.

Foundation owns configuration/integration, not alternate implementations.

### 7.4 Runtime capability-aware middleware

Do not disable portable middleware using Foundation-specific engine detection.

Let Webrick's `RuntimeCapabilities` short-circuit middleware when the runtime transport already handles compression/request limits/native features.

### 7.5 Tagged global middleware

Webrick supports pre/post global middleware tags resolved from InterMix.

Use only for truly universal host middleware. A tagged global service still makes every route enter the global middleware path and may require scope resolution.

## 8. Route/handler policy

### 8.1 Preserve direct handler shapes

Webrick can optimize:

- zero-argument direct handlers;
- Request-only direct handlers;
- route-argument direct handlers;
- compiled InterMix invocation for service/controller handlers.

Foundation route wrappers must not convert simple handlers into generic closures or `Application::make()` trampolines.

### 8.2 Prefer declarative handlers in production

Prefer:

```text
Controller::class
[Controller::class, 'method']
'Controller::method'
function descriptors
```

when suitable.

Route/middleware closures remain supported, but persistent compilation may require `opis/closure`; treat that as an intentional project/build dependency rather than silently requiring it for Foundation core routes.

### 8.3 Route registration is build/dev work

All route files, attribute controller scans, route presets and OAuth route registration complete before production artifact publication.

No production request performs route discovery.

## 9. Error/exception strategy

Foundation must classify HTTP failures:

### Routing control errors

404/405 should use Webrick's direct path whenever Foundation does not need custom routing-error representation.

### Expected application HTTP errors

Where possible, express HTTP-aware Foundation exceptions through Webrick-compatible HTTP exception contracts/status metadata rather than routing all expected failures through a heavyweight generic renderer.

### Unexpected failures

Preserve secure production rendering and Foundation logging/observability.

Do not expose exception messages/traces merely to avoid a custom renderer.

### Gate

Benchmark:

- null/default ErrorHandler;
- Foundation custom ErrorHandler;
- custom dispatch handler with direct route errors if the recommended Webrick refinement is implemented.

## 10. Maintenance strategy

Required behavior must be preserved:

- file/cache driver where supported by Foundation;
- operational enable/disable without application code change;
- message/retry metadata;
- persistent-worker freshness bounded by a configured refresh interval;
- no per-request disk read when state is unchanged.

Implementation must not restore the Foundation 2 pattern of:

```text
full Request + outer scope + file/cache status read + router
```

Benchmark middleware versus pre-routing-gate designs before fixing the final implementation.

## 11. Filesystem response integration

### Local files

After Pathwise/Foundation authorization, root/extension/policy and download metadata checks, prefer Webrick native file-body response forms so adapters can use their optimized write paths.

### Range/conditional handling

Foundation already uses Webrick conditional validation. Where Webrick `rangedFile()` / `rangedDownload()` fully covers the required local-file semantics, prefer it instead of reproducing the response body layer.

### Non-local/custom Pathwise sources

Provide a Webrick `BodyStream` or string-chunk iterable.

Never call `echo`, `header()`, `fastcgi_finish_request()`, `php://output`, Swoole response methods, RoadRunner response methods, etc. from Foundation response factories.

### Native-file capability

Allow Webrick runtime adapters to exploit native file/stream features. Foundation should not guess runtime type.

## 12. Production artifact/release ownership

For the web runtime, Webrick's release bundle is authoritative for InterMix+router identity.

Foundation's higher-level release manifest may reference:

```text
foundation format/version
environment
Foundation config artifact/fingerprint
capability topology artifact
command/scheduler/worker artifacts
Webrick release manifest path/reference
```

Do not duplicate:

```text
intermix.digest
webrick.digest
webrick.fingerprint
```

into conflicting Foundation fields.

Publishing must be transaction-like: do not make a new Foundation release visible until config/capability/InterMix/Webrick artifacts and manifests all succeeded and correspond to the same build.

## 13. Production readiness/status commands

Replace old route-cache readiness with release readiness.

`optimize:report` / readiness should report at least:

- Webrick release format;
- environment;
- config fingerprint;
- runtime PHP manifest presence;
- InterMix artifact/digest/compiled/skipped counts;
- Webrick router artifact path/meta;
- Webrick digest;
- Webrick fingerprint;
- route count;
- trusted-prevalidated mode status;
- unexpected dynamic islands;
- stale/missing/mismatched artifact failures.

Do not rebuild a matcher merely to answer readiness in normal production.

## 14. Testing migration

### 14.1 Rewrite `WebrickInterMixIntegrationTest`

The current test file encodes Foundation 2 assumptions:

- live `RouterKernel` service;
- live `Registrar`/`Collection` services;
- Foundation outer HTTP scope;
- matcher route-cache classes;
- dynamic container inspection.

Split it into explicit development and compiled-production suites.

### 14.2 Development suite

Verify:

- route files/facade registration;
- mutable dev aliases;
- useful diagnostics;
- embedded fake Request handling;
- dev/prod graph parity at observable boundaries.

### 14.3 Compiled production suite

Verify:

- `ReleaseCompiler` generates InterMix/router/release artifacts;
- runtime PHP manifest is preferred;
- normal verified load succeeds;
- wrong environment/config fingerprint fails;
- wrong InterMix digest fails;
- wrong Webrick fingerprint/digest fails;
- trusted prevalidated load accepts exact trusted identities;
- matcher cached boot avoids route replay;
- registries are frozen after boot;
- production route registration mutation fails.

### 14.4 Execution-plan tests

Provide representative routes:

1. zero-arg direct JSON/string route;
2. route-arg direct route;
3. Request handler;
4. controller DI handler;
5. one DI middleware;
6. parameterized middleware;
7. session/CSRF route;
8. auth route;
9. global middleware variant.

Assert compiled Request/SCOPE/MIDDLEWARE capabilities match expectations.

### 14.5 Request avoidance test

A minimal route with no global middleware must complete without full Request materialization.

Use Webrick stage profiling/test instrumentation rather than inferring from unrelated service-resolution state.

### 14.6 Scope tests

Verify:

- direct route can avoid scope;
- controller/DI middleware route gets scope when required;
- Request is seeded only in scoped request paths;
- Foundation principal/session/database cleanup is attached to the Webrick-owned scope and survives exceptions;
- sequential/Fiber/persistent requests do not leak state.

### 14.7 Error-path tests

Verify direct 404/405 behavior, custom application exceptions, debug/prod rendering, request IDs and logging without weakening security.

### 14.8 Runtime-adapter tests

At minimum:

- SAPI context construction/write support;
- one persistent adapter in integration CI when its extension/runtime is available;
- lazy Request materialization;
- RuntimeCapabilities propagation;
- streamed/file responses do not use SAPI side effects in portable code.

### 14.9 Maintenance tests

Verify refresh caching, enable/disable transition, message/retry behavior and no state leakage in long-lived workers.

### 14.10 Filesystem response tests

Verify local file, range, HEAD, 304/412 conditional behavior, inline/attachment, X-Sendfile/X-Accel, streaming and persistent-adapter compatibility.

## 15. Benchmark matrix

The central HTTP metric remains:

```text
Foundation tax = Foundation 3 compiled minimal endpoint - standalone Webrick 5.1 compiled minimal endpoint
```

Required comparisons:

### Build/boot

- Webrick standalone release compile;
- Foundation web release compile;
- verified release manifest load;
- trusted prevalidated load;
- cold process boot;
- warm persistent-worker boot.

### Routing/dispatch

- 404;
- 405;
- zero-arg direct route;
- route-argument route;
- Request handler;
- controller DI handler;
- one middleware;
- several middleware;
- parameterized auth middleware;
- session route;
- bearer/OAuth route;
- global middleware variant.

### Runtime stage metrics

Use Webrick's opt-in profiler to attribute:

- native context creation;
- Request materialization;
- matching;
- execution-plan lookup;
- scope entry/leave;
- middleware-pipeline first-build and warm reuse;
- handler dispatch;
- response write.

Profiler must remain absent/zero-cost in normal production when disabled.

### Response types

- small string/JSON;
- large string;
- local file;
- ranged file;
- streaming response.

### Real deployment

Repeat the representative benchmark through the actual Apache/Nginx + PHP-FPM + OPcache environment used by the InfByte benchmark. Local in-process microbenchmarks are not sufficient acceptance evidence.

## 16. Webrick-related configuration cleanup

Foundation 3 should review/remove obsolete configuration such as:

```text
router.cache              # old matcher-cache production semantics
app.container.compiled_*  # handled by InterMix pass
```

Keep useful router settings that map directly to Webrick 5.1, including matcher choice, route files, attribute routes, slash policy, URL/signed-URL settings, middleware definitions/groups and runtime-specific options.

Add explicit production release paths only where Foundation needs to choose artifact locations; avoid duplicating Webrick manifest fields as configuration.

## 17. Potential Webrick 5.1.x follow-up fixes exposed by Foundation

These are lower-layer improvements discovered by the integration scan. Implement them in Webrick rather than compensating in Foundation if accepted.

### WB-L1 — Parameterized runtime-backed middleware descriptor — recommended

Add a declarative artifact-safe descriptor carrying runtime spec + parameters, supported by:

- `MiddlewareAliases` resolver return contract;
- `HandlerCompiler` normalization/capability logic;
- `ArtifactValueCodec` encode/decode;
- `CompiledMiddlewarePipeline` runtime invocation.

This removes the need to serialize live DI-built middleware objects for parameterized aliases.

This should be backwards compatible with existing string/array/callable middleware descriptors.

### WB-L2 — Preserve direct routing errors with custom dispatch ErrorHandler — recommended

Decouple custom application-exception rendering/logging from 404/405 routing-control handling so hosts can retain direct route-error responses without giving up custom dispatch exception behavior.

Make custom routing-error rendering explicit opt-in.

### WB-L3 — Lightweight pre-routing gate — benchmark-driven/optional

If dynamic maintenance or another universal operational gate needs to run before routing, consider a tiny boot-selected gate API over `RoutingInput`/`RuntimeRequestContext` that can return an optional `Response` before Request/scope creation.

Do not add it solely for Foundation unless measurement shows a meaningful advantage over portable middleware.

### WB-L4 — Keep source/docs artifact terminology aligned

The tagged source is authoritative and uses xxh128 format-2 digest/fingerprint fields. Update any stale framework-integration examples that still use SHA-256 naming so downstream integrations cannot implement the wrong trust contract.

## 18. Foundation implementation order

### Batch WB-1 — Dependency and boot split

- set Foundation Webrick requirement to `^5.1`;
- remove old Webrick 4 method assumptions;
- add explicit development versus compiled-production HTTP boot;
- keep same host-owned InterMix builder.

### Batch WB-2 — Build/release compiler

- replace production `RouteCacheManager` + old container compilation with Webrick `ReleaseCompiler` for web;
- one route-registration closure;
- one config fingerprint;
- atomic release publication;
- exact format-2 manifest handling.

### Batch WB-3 — Production kernel/runtime adapter

- load `CompiledRouterKernel`;
- select SAPI/RoadRunner/Swoole/Workerman adapter once;
- use `RuntimeServer`;
- retain embedded `$app->handle(Request)` only as a delegated API;
- remove native emission from Foundation.

### Batch WB-4 — Remove old router runtime classes

- delete/reduce `WebrickRouterFactory`;
- remove live production `Registrar`/`Collection` bindings;
- retire production `RouteCachePath`/matcher freshness logic;
- move RouteFileLoader to dev/build use only;
- switch URL generation to compiled registry/service.

### Batch WB-5 — Middleware compilation

- normalize built-in middleware configuration;
- keep global lists empty unless explicitly configured;
- migrate aliases to lightweight descriptors;
- implement/consume WB-L1 for parameterized middleware if accepted;
- ensure optional cache/database middleware capabilities stay cold when disabled.

### Batch WB-6 — Error and maintenance paths

- benchmark and finalize ErrorHandler strategy;
- consume WB-L2 if accepted;
- remove old HttpKernel maintenance I/O;
- implement cached state adapter or benchmarked pre-routing gate;
- preserve operational/security semantics.

### Batch WB-7 — Scope and cleanup

- remove Foundation outer HTTP scope;
- attach Foundation request cleanup to Webrick-selected InterMix scope lifecycle;
- audit principal/session/DB/log context cleanup;
- verify Fiber/concurrent/persistent isolation.

### Batch WB-8 — HTTP specialist bridges

- fix filesystem stream response ownership;
- use Webrick native file/range/conditional APIs where appropriate;
- verify upload handling;
- audit any other Foundation class that writes/assumes SAPI output.

### Batch WB-9 — Tests

- rewrite old live-router integration tests;
- add release/kernel/artifact tests;
- execution-plan capability tests;
- runtime-adapter tests;
- error/maintenance/filesystem response tests;
- dev/prod parity tests.

### Batch WB-10 — Benchmarks and final rescan

- benchmark standalone Webrick 5.1 vs Foundation 3 compiled;
- attribute Foundation-only overhead;
- rescan every `Infocyph\\Webrick` direct use;
- remove stale Webrick 4/cache docs/config/tests;
- freeze the Webrick pass before moving to ArrayKit/UID.

## 19. Webrick pass completion gate

The Webrick 5.1 integration pass is complete only when:

- Foundation requires `infocyph/webrick:^5.1`;
- Webrick contributes to Foundation's existing InterMix `ContainerBuilder` rather than creating another container;
- development uses `RouterKernel::bootWithRegistrar()` only as the development/registrar path;
- production uses Webrick `ReleaseCompiler`;
- production uses InterMix `ProductionContainer` + `CompiledRouterKernel`;
- native serving uses one boot-selected Webrick runtime adapter + `RuntimeServer`;
- no Foundation HTTP layer performs per-request runtime-engine discovery;
- no production request scans routes/controllers/filesystem metadata for routing freshness;
- no production route collection is replayed by Foundation around the compiled kernel;
- old Foundation production `RouteCacheManager`/`RouteCachePath` semantics are gone;
- no full Webrick `Request` is created before matching merely because Foundation exists;
- no Foundation outer HTTP scope is entered for every request;
- a minimal Foundation route can remain Request-free and scope-free when its execution plan allows it;
- middleware pipelines are Webrick-owned and built lazily per matched plan;
- default global middleware remains empty;
- parameterized aliases do not serialize captured Foundation/Application/container service graphs;
- URL generation comes from the compiled/frozen Webrick URL runtime;
- registry mutation is complete before production freeze;
- error handling preserves security/observability while direct routing-control overhead is measured/minimized;
- maintenance mode no longer performs unconditional Foundation per-request file/cache work after Request/scope creation;
- Foundation portable response code never writes directly to `php://output`/native runtime handles;
- local file/range/stream responses work under Webrick response writers;
- RuntimeCapabilities are consumed instead of duplicated transport detection;
- normal and trusted-prevalidated artifact paths use the exact format-2 xxh128 contracts;
- `config_fingerprint`, `intermix.digest`, `webrick.digest`, and `webrick.fingerprint` are not conflated;
- old Webrick integration tests are rewritten for dev + compiled production;
- real-HTTP Foundation tax versus standalone Webrick is measured and attributable;
- the full Foundation codebase is rescanned for direct Webrick use after implementation.

## 20. Working rules for the implementation branch

For every HTTP/routing change ask:

1. Does Webrick 5.1 already own this behavior?
2. Can it happen during `ReleaseCompiler` instead of process/request runtime?
3. Does this change force Request creation on a route that could otherwise avoid it?
4. Does it force an InterMix scope?
5. Does it force a global middleware pipeline?
6. Does it serialize a live service graph into a router artifact?
7. Can the runtime adapter already provide the transport capability?
8. Is Foundation attempting to write a response that Webrick should write?
9. Is a mutable Registrar/registry being exposed after production freeze?
10. Is the cost measured against standalone compiled Webrick?

If Webrick already provides the correct lower-layer mechanism, Foundation must configure/use it directly. If the lower-layer API is genuinely missing a general capability, fix Webrick once rather than building a Foundation-only runtime workaround.
