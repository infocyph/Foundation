# Foundation Roadmap

## Purpose

This document records the remaining work required to make Foundation and the
Infbyte skeleton a production-ready, performance-oriented alternative to
Laravel for APIs, services, workers, and secure backend applications.

The goal is not to reproduce every Laravel feature. The intended position is:

> A modular, repository-first, performance-oriented PHP framework for APIs,
> services, workers, and secure backend applications.

Foundation owns the framework-native application and authentication domains,
configuration, provider registration, container composition, HTTP/console
integration, facades, and deployment-time optimization. Standalone libraries
continue to own reusable infrastructure domains such as routing, databases,
caching, validation, communication, filesystems, cryptography, OTP, and
identifiers.

## Engineering Rules

All work in this roadmap must follow
`vendor/infocyph/phpforge/resources/engineering-principles.md`.

In particular:

- Preserve correctness, security, data integrity, and operational stability.
- Optimize for measured, sustained successful RPM on complete representative
  request paths.
- Do not infer application-level gains from library microbenchmarks.
- Keep the normal request path short and predictable.
- Do not initialize optional packages for routes or commands that do not use
  them.
- Prefer deployment/cache-time computation over request-time computation.
- Compile stable routes, configuration, container wiring, command metadata,
  event maps, and resource metadata where useful.
- Do not perform filesystem scanning, reflection discovery, configuration
  parsing, or container compilation during requests.
- Keep web, console, scheduler, queue-consumer, and worker lifecycles separate.
- Keep diagnostics, tracing, and expensive logging opt-in.
- Avoid Foundation-prefixed copies of behavior already owned by standalone
  packages.
- Do not introduce compatibility shims for the next major release unless a
  concrete requirement appears.
- Every substantial hot-path change requires representative before/after
  benchmarks.

## Current Position

### Strong or substantially covered

- Explicit web and console runtime separation.
- Lazy provider activation and optional module installation.
- Compiled configuration, route caches, command manifests, and schedule
  manifests.
- Webrick routing, middleware, signed URLs, content negotiation, response
  helpers, streaming, ranged downloads, and persistent-runtime emitters.
- InterMix dependency injection, request scopes, direct factories, and compiled
  resolvers.
- CacheLayer adapters, tiers, locks, invalidation, metrics, memoization, and
  stampede protection.
- ReqShield validation, sanitization, typed output, schema composition,
  database batching, and localized validation messages.
- DBLayer query building, repositories, read replicas, pooling, transactions,
  keyset pagination, cursors, streaming, execution plans, telemetry, schema,
  migrations, seeding, and explicit relation batching.
- Console typed commands, preflight paths, prompts, testing, execution controls,
  scheduling, dynamic process supervision, and lazy Omnibus command adapters.
- Omnibus synchronous events, queue transports, workflows, retries, failed
  messages, execution policies, broadcasting boundaries, testing fakes, and
  telemetry.
- JsonDispatch 3.0.0 specification, schemas, conformance fixtures, and
  documentation sources.
- Authentication features including tokens, auth sessions, password reset,
  passwordless flows, email verification, MFA, passkeys, roles, permissions,
  policies, delegation, device trust, lockout, and auditing.
- Lazy browser sessions, file/cache/database stores, per-session leases, flash
  data, old input, and origin-aware CSRF middleware.
- Filesystem adapters, secure uploads, file operations, and HTTP file
  responses.
- TalkingBytes HTTP, email, webhook, gRPC, signature, inbound-message, and DKIM
  capabilities.

### Completed in the Foundation integration pass

- Configurable PSR-3 JSON logging, separate exception reporting/rendering,
  secret redaction, exception exclusions, sampling, and bounded throttling.
- DBLayer migration, rollback, reset, refresh, fresh, status, seeding, locking,
  readiness, and database-test composition without Foundation SQL duplication.
- Lazy Omnibus events, queues, consumers, failed-message commands, schedules,
  InterMix execution scopes, auth-event forwarding, and package-owned fakes.
- JsonDispatch 3 resource/envelope composition, ReqShield failure mapping, and
  DBLayer cursor pagination.
- Compiled third-party module metadata with collision-safe aliases.
- Native Webrick HTTP testing, database helpers, package fake composition,
  frozen time, and persistent-runtime cleanup.
- A Foundation documentation tree covering lifecycle, configuration,
  authentication, sessions, databases, messaging, responses, logging, testing,
  modules, and operations.

### Deliberate non-goals or later extensions

- A bundled view/template engine; applications may bind Webrick's view
  boundary to their selected renderer.
- A general translation package; validation localization remains owned by
  ReqShield until a concrete cross-domain localization requirement exists.
- Provider-specific broadcast delivery adapters; Omnibus already owns the
  provider-neutral contracts and Foundation must not force a provider into
  non-broadcast applications.
- A second telemetry store; Foundation composes request/message cleanup and
  correlation while each owning package retains its observability surface.

## Priority 0: Correct Package Ownership

### 0.1 Keep authentication canonical in Foundation

#### Decision

There is no separate authentication layer in the framework architecture.
Foundation directly owns its authentication and authorization domain.
Foundation must not require, bridge to, or wait for a separate authentication
package.

Foundation owns:

- accounts and principals;
- authentication orchestration;
- auth sessions and remember-me domain behavior;
- password reset/change/passwordless behavior;
- email verification;
- access and refresh token lifecycle;
- MFA and passkey orchestration contracts;
- authorization decisions, gates, permissions, roles, policies, and grants;
- device trust and lockout;
- audit events and notification intents;
- auth domain contracts and in-memory support stores;
- auth providers, application configuration, and readiness;
- route middleware and principal resolution;
- HTTP error/response mapping;
- adapters for CacheLayer, DBLayer, Epicrypt, OTP, WebAuthn, TalkingBytes, and
  UID.

#### Implementation requirements

- Treat `Infocyph\Foundation\Auth` as the only framework auth namespace and
  public ownership boundary.
- Do not add a separate authentication-domain Composer dependency.
- Audit the existing auth tree for redundant classes, interfaces, managers,
  wrappers, duplicated normalization, and avoidable call hops.
- Consolidate internal types only when responsibilities and lifecycles are
  genuinely the same.
- Preserve interfaces that define real storage, security, transport, or
  consumer extension boundaries.
- Keep auth provider activation lazy.
- Ensure routes without auth middleware do not resolve auth services or auth
  adapters.
- Keep optional auth infrastructure packages optional.
- Keep authentication middleware route-specific rather than globally loading
  auth for every web request.
- Test Foundation's canonical auth domain directly and through its framework
  integrations.

#### Acceptance criteria

- Foundation contains the complete canonical authentication implementation.
- Foundation has no runtime dependency on a separate auth-domain package.
- Foundation auth tests cover domain behavior and every configured adapter.
- An unauthenticated public route does not load or construct auth services.
- Authenticated-route behavior remains functionally equivalent.
- Persistent-worker requests do not leak principal, session, ability, or tenant
  state.
- Representative public and authenticated route benchmarks show no material
  sustained RPM regression.

### 0.2 Preserve external package boundaries

- DB lifecycle behavior belongs in DBLayer.
- Queue delivery and general application-event behavior belong together in the
  standalone `infocyph/omnibus` message-lifecycle package.
- General browser-session behavior belongs directly in Foundation as an
  optional route-selected capability. It must not be moved into InterMix,
  TalkingBytes, or Webrick.
- API resource transformation belongs directly in Foundation against the
  pinned JsonDispatch specification.
- Foundation must integrate these packages without reimplementing them.
- Do not create Foundation-prefixed bridge packages.
- Authentication is the explicit exception: its framework domain belongs
  directly in Foundation.

## Priority 1: Production Runtime Essentials

### 1.1 Logging and exception reporting

#### Finding

Webrick has a central error boundary with content negotiation, safe rendering,
request IDs, and PSR-3 support. Foundation currently constructs that boundary
with `NullLogger`, so production exception information is discarded unless the
application replaces the integration.

#### Required work

- Add a configured PSR-3 logger binding.
- Allow application providers to replace the logger without replacing the HTTP
  kernel.
- Separate exception reporting from response rendering.
- Add structured context for request ID, correlation ID, trace ID, route,
  method, path, principal ID when safe, and environment.
- Redact secrets, credentials, tokens, cookies, authorization headers, and
  configured sensitive keys.
- Add configurable report exclusions and sampling/throttling for repeated
  exceptions.
- Keep log-context construction lazy and avoid building expensive context when
  the selected logger/level will not consume it.
- Reuse the same logger boundary for Webrick errors, selected DBLayer events,
  queue failures, and scheduler/worker failures without imposing synchronous
  logging on every successful request.

#### Acceptance criteria

- Unhandled production exceptions are reported through the configured logger.
- Responses never expose production traces or secrets.
- Request and correlation identifiers are consistent across response, logs, and
  enabled telemetry.
- Logging can be disabled with near-zero request-path overhead.

### 1.2 Database schema lifecycle

This behavior should be implemented in DBLayer and exposed through Foundation
commands.

#### Initial scope

- Driver-aware schema builder for MySQL/MariaDB, PostgreSQL, and SQLite.
- Migration repository/table.
- Ordered, versioned migration files.
- `migration:create`.
- `migration:run`.
- `migration:status`.
- `migration:rollback`.
- `migration:reset`.
- `migration:fresh`.
- Dry-run/pretend SQL output.
- Connection selection.
- Production confirmation/force policy.
- Distributed migration lock through CacheLayer.
- Atomic behavior where the selected driver supports transactional DDL.
- Explicit reporting where a driver cannot provide atomic DDL.

#### Acceptance criteria

- Migrations run and roll back correctly on every supported driver.
- Concurrent deployment nodes cannot apply the same migration simultaneously.
- Destructive production commands require explicit authorization.
- Application tests can create a clean schema deterministically.
- Migration discovery is not performed during web requests.

### 1.3 Omnibus message, queue, and event subsystem

Console's worker supervisor manages processes. It must remain queue-neutral;
Omnibus owns message routing, synchronous application events, queue storage,
retries, and delivery semantics.

#### Initial drivers

- Synchronous.
- Database through DBLayer.
- Redis/Valkey through a dedicated adapter.

#### Required semantics

- Stable, versioned job envelope.
- Explicit serializer contract and payload-size limits.
- Queue name and connection routing.
- Immediate and delayed dispatch.
- Attempt limits.
- Backoff policy.
- Execution timeout.
- Visibility/reservation timeout.
- Retry and release.
- Failed/dead-letter job storage.
- Failed-job list, retry, forget, flush, and prune commands.
- Unique jobs and overlap prevention through CacheLayer locks.
- Dispatch after database commit.
- Optional dispatch after HTTP response where the runtime supports it safely.
- Graceful worker restart and deployment draining.
- Idempotency guidance and hooks.
- Structured queue telemetry.
- Queue fake and dispatch assertions.
- Direct event-to-listener map.
- Synchronous dispatch.
- Listener ordering only where explicitly configured.
- Configurable listener failure behavior.
- Queued listeners through the same Omnibus routing and envelope contracts.
- Event fake and dispatch/listener assertions.
- Deployment-time event manifest compilation.
- Explicit registration by application code or package metadata.

#### Ownership constraints

- No request-time directory scanning.
- No request-time reflection discovery.
- Do not instantiate unrelated listeners.
- Do not add an event for operations where a direct call is clearer.
- Do not duplicate Console process supervision, signals, scaling, scheduling,
  command rendering, or process limits. Omnibus supplies a queue-neutral
  consumer boundary that Console can supervise.
- Do not duplicate DBLayer connections, transactions, schema, migrations, or
  repository entity lifecycle. Database transports and dispatch-after-commit
  are optional DBLayer adapters.
- Do not duplicate CacheLayer storage, leases, locks, rate-limit state, or
  circuit-breaker state. Omnibus policies adapt those existing contracts.
- Do not introduce Active Record/model hooks. Repository code emits explicit
  domain events after successful state changes.
- Keep outbound HTTP/email/webhook protocol transport in TalkingBytes.
  Omnibus may queue those application messages without reimplementing their
  protocol clients.
- Broadcasting contracts and channel authorization remain optional. Provider
  delivery adapters must not load for applications that do not broadcast.
- Keep Foundation authorization, dependency composition, module installation,
  and after-response lifecycle integration outside Omnibus.
- Foundation auth domain events remain owned by Foundation and are forwarded to
  the general application dispatcher only when configured.

#### Acceptance criteria

- Queue consumers run only in the console/worker lifecycle.
- Web requests do not initialize consumers or scan messages/listeners.
- Synchronous event-only applications do not load queue transports,
  serializers, failure stores, or workers.
- A dispatch-only web route pays only the cost required to validate, serialize,
  and enqueue its selected message.
- Duplicate, retry, timeout, crash, listener failure, and worker-termination
  behavior is tested.
- Queue depth and worker memory remain bounded during soak tests.

## Priority 2: API Application Experience

### 2.1 API resources and JsonDispatch conformance

JsonDispatch is a language-agnostic response specification, not a PHP library.
Foundation needs an executable resource layer that conforms to a pinned
JsonDispatch specification version. No PHP implementation, Composer package, or
framework adapter belongs in the JsonDispatch repository.

Foundation pins **JsonDispatch 3.0.0**. Its implementation must validate
representative responses against that release's envelope and HTTP-response
schemas and preserve its prose-only HTTP semantics.

#### Required work

- Resource transformer contract.
- Resource collection contract.
- Support arrays, DTOs, repository projections, and iterables.
- Conditional fields.
- Explicit relation inclusion.
- Sparse fieldsets.
- Cursor/offset pagination metadata and links.
- JsonDispatch success, fail, and error envelopes.
- Native HTTP status handling by default and explicitly configured
  restricted-transport tunneling through matching ``status_code`` and
  ``X-JD-Status-Code`` values plus ``Cache-Control: no-store``.
- Consistent ReqShield validation failure mapping.
- Request ID, correlation ID, and API version headers.
- Bounded streaming for large collections.
- JSON encoding policy and failure handling.
- OpenAPI/JSON Schema metadata integration through ReqShield.
- Resource testing assertions.

#### Constraints

- Do not require ORM models.
- Do not trigger relation queries during serialization.
- Do not build fields or relations the caller did not request.
- Compile stable resource metadata when attributes or reflection are used.

### 2.2 Repository relation loading

Do not build an Active Record or Eloquent clone.

Add explicit repository-oriented tools for:

- batched `WHERE IN` loading;
- joined projections;
- explicit one-to-one, one-to-many, and many-to-many mapping helpers;
- bounded prefetching;
- relation result indexing;
- development-time N+1 detection;
- query-count and examined-row telemetry;
- deterministic repository fixtures for tests.

Lazy property access must not hide database calls.

### 2.3 Application HTTP and database testing kit

#### HTTP helpers

- `get`, `getJson`, `post`, `postJson`, `put`, `patch`, and `delete`.
- Fluent response status, header, cookie, text, JSON, JSON-path, streamed-body,
  and download assertions.
- Uploaded-file helpers.
- Middleware enable/disable controls.
- Route-name assertions.
- Exception reporting assertions.

#### Authentication helpers

- `actingAs`.
- Token/session/passkey/MFA test contexts.
- Ability, role, permission, and policy assertions.
- Auth-store fakes.

#### Infrastructure fakes

- Omnibus events and queued messages.
- Notifications/email/webhooks.
- Cache.
- Clock/time.
- HTTP client.
- Filesystem.

#### Database helpers

- Transaction rollback between tests.
- Migrate/refresh support.
- Seeder/factory integration.
- Query-count assertions.

#### Persistent runtime coverage

- Multiple requests through one application/kernel instance.
- Request-scope cleanup.
- Principal and tenant isolation.
- Static registry and facade safety.
- Bounded telemetry, logs, caches, and middleware state.

## Priority 3: Traditional Web Support

This priority is required only if Foundation officially supports browser-based,
stateful web applications. It should remain optional for API-only applications.

### 3.1 Foundation browser-session capability

- Optional Pathwise file, CacheLayer cache, and DBLayer database drivers.
- Secure session cookie configuration.
- Session ID regeneration and fixation protection.
- Invalidation and logout integration.
- Flash data.
- Old input.
- Per-session locking where required.
- Size and lifetime limits.
- Garbage collection/pruning.
- Request-scoped session state.
- Persistent-worker cleanup.

Auth sessions and browser application sessions remain distinct concepts even
when they share storage infrastructure.

Foundation owns the session contracts, manager, lifecycle, secure defaults,
store adapters, and middleware policy. Webrick supplies only its existing HTTP
request, response, cookie, and middleware boundaries. InterMix supplies only
request/execution scoping. TalkingBytes has no browser-session responsibility.

The complete capability is activated only by session middleware on selected
routes. Stateless API routes and unrelated console, scheduler, queue-consumer,
and worker paths MUST NOT resolve a session manager, serializer, lock, or store.

### 3.2 Request-forgery protection

Provide Foundation middleware integrated with the browser-session contract.
Do not make Webrick authoritative for application session or CSRF policy.

Required behavior:

- Apply only to configured state-changing browser routes.
- Ignore safe methods by policy.
- Validate body/header tokens.
- Do not accept CSRF tokens from the query string.
- Support token rotation.
- Use constant-time comparison.
- Add origin-aware verification.
- Handle trusted proxies and configured origins explicitly.
- Provide clear failure responses without leaking token material.
- Keep stateless API routes outside this middleware.

### 3.3 Optional rendering and localization

If traditional web support is selected:

- Keep Webrick's view interface.
- Provide an optional adapter for a maintained template engine rather than
  creating a new Blade-like language.
- Add a general translation contract, locale resolution, fallback locale, and
  message catalogs.
- Keep rendering, session, and localization providers out of API-only paths.

## Priority 4: Operations and Ecosystem

### 4.1 Unified operational context

Compose existing Webrick, DBLayer, CacheLayer, auth, scheduler, worker, and
future queue signals through a small shared context.

Potential fields:

- request ID;
- correlation ID;
- trace/span identifiers;
- route and operation name;
- principal/tenant identifiers where policy permits;
- command execution ID;
- job ID and attempt;
- scheduler run ID;
- deployment/version identifier.

Requirements:

- No expensive context creation when observability is disabled.
- No high-cardinality metrics labels by default.
- Bounded buffers in persistent workers.
- Explicit flush/reset lifecycle.
- OpenTelemetry remains optional.
- Readiness, liveness, and dependency health remain distinct.

### 4.2 Extensible module manifests

The current module catalog is a fixed Foundation constant. Keep the curated
first-party aliases, but allow installed third-party packages to describe:

- provider classes by runtime;
- configuration templates;
- commands;
- routes;
- migrations;
- event listeners;
- optional dependency requirements;
- optimize/clear artifacts.

Discovery requirements:

- Use installed Composer package metadata.
- Validate manifests during install/optimize.
- Compile one directly includable application manifest.
- Do not scan vendor directories during requests.
- Do not auto-enable a package merely because it is installed.
- Preserve explicit application control over enabled modules and runtime paths.

### 4.3 Notifications

Build on TalkingBytes instead of duplicating transports.

Add:

- notifiable routing contract;
- channel selection;
- locale selection;
- queued delivery through the queue subsystem;
- per-channel retry/failure policy;
- database/inbox notification adapter only if applications require it;
- notification fake and delivery assertions;
- template/version ownership rules.

### 4.4 Optional future modules

These are not release blockers:

- broadcasting and realtime/WebSocket integration;
- search abstraction;
- vector search adapters;
- feature flags;
- social authentication providers;
- OAuth2 authorization server;
- image processing;
- frontend asset integration;
- browser automation;
- billing;
- AI provider integrations.

Every optional feature must be independently installable and must not affect
unrelated request or command paths.

## Library-by-Library Task Index

This index contains only work that remains after auditing the current source,
tests, documentation, and Composer metadata of every library in the original
roadmap. Implemented capabilities and speculative package changes have been
removed. Re-open an audited package only when a concrete consumer test exposes
a missing contract, correctness defect, or measurable performance regression.

### Active implementation order

Preserving the agreed dependency order while skipping libraries with no pending
package work:

1. DBLayer 3.0, the required PHPForge fix, and JsonDispatch 3.0.0 are
   published.
2. Omnibus and Console implementation, integration, tests, benchmarks, and
   documentation are complete on ``dev-main@dev``.
3. Complete Foundation integration, including its lazy browser-session
   capability.
4. Finalize Infbyte against the coordinated development branches.
5. Publish stable tags in dependency order only after the complete framework
   integration is verified.

ArrayKit, InterMix, CacheLayer, Pathwise, UID, ReqShield, TalkingBytes, OTP,
Epicrypt, and Webrick remain stable inputs, not active implementation steps.

During coordinated major-version development, immediate consumers MAY use an
explicit ``dev-main@dev`` constraint. Development branches MUST remain verified
together and MUST NOT be described as stable releases. Before the final public
release, publish owning libraries in dependency order, replace every runtime
development constraint with a compatible stable tag, refresh locks, and rerun
consumer integration tests.

A package that needs no change must not receive a release solely to advance
this roadmap.

### Audited packages with no pending package work

The following entries were removed from the active checklist:

| Library | Audit conclusion |
| --- | --- |
| ArrayKit | Lazy namespace loading, cached configuration indexes, environment resolution, and Foundation integration tests already cover the required configuration primitives. |
| InterMix | Scoped lifetimes, enter/leave cleanup, compiled resolvers, disabled-by-default tracing, and Foundation/Webrick/Console integration already provide the required container boundary. |
| CacheLayer | The shared acquire/refresh/release lease contract and file, PDO/advisory-lock, Redis/Valkey, and Memcached providers already cover command, scheduler, migration, queue, and session coordination. Consumers must test their own lease-loss behavior. |
| Pathwise | Existing storage, upload, permission, and security boundaries are sufficient; browser-session semantics remain in Foundation. Revisit only if Foundation's file-store adapter proves a filesystem primitive is missing. |
| UID | Console already requires UID and Foundation already exposes identifier integration; no additional identifier-domain work is identified. |
| ReqShield | JSON Schema export, typed validation failures, and batched database-rule contracts already exist. JsonDispatch response mapping and HTTP testing belong in Foundation. |
| TalkingBytes | Transport-neutral communication results, protocol transports, retry policies, redaction, and test fakes already exist. Application notification routing and queued delivery belong in Foundation and Omnibus integration. |
| OTP | OTP behavior is already owned by OTP and integrated lazily by Foundation; remaining work is Foundation auth coverage. |
| Epicrypt | Secret, password, token, and crypto behavior is already owned by Epicrypt and integrated by Foundation; remaining work is Foundation auth coverage. |
| Webrick | Native HTTP messages, PSR-3 error handling, trace/request context, per-request InterMix scopes, middleware isolation tests, emitters, and documentation already exist. Resources and browser-session policy correctly remain Foundation responsibilities. |

WebAuthn is an external provider rather than an Infocyph library. Any remaining
passkey work is a Foundation adapter and integration-test concern.

### DBLayer

DBLayer now owns connection, query, repository, streaming, transaction,
offset/keyset pagination, schema, migration, seeding, and explicit batched
relation behavior. This is a clean major-version contract: no deprecated
aliases, compatibility shims, or legacy migration path is required.

#### Completed implementation

- [x] Add a driver-aware schema builder and grammar for MySQL/MariaDB,
  PostgreSQL, and SQLite.
- [x] Support table create, alter, rename, and drop; supported column types and
  modifiers; indexes; and foreign keys.
- [x] Define each driver's supported DDL surface and fail explicitly for
  unsupported or unsafe operations.
- [x] Add a migration ledger and deterministic ordered runner with named
  connection support.
- [x] Accept explicit application and package migration sources supplied by a
  compiled Foundation module manifest; do not scan packages at runtime.
- [x] Implement apply, status, rollback, reset, fresh, and pretend operations
  as DBLayer APIs; keep CLI rendering in Foundation.
- [x] Define transactional-DDL and partial-failure behavior per driver.
- [x] Coordinate migration ownership through CacheLayer's existing lease
  contract without adding another lock abstraction.
- [x] Add explicit production safety controls for destructive schema actions.
- [x] Add a small seed execution contract; keep application seed definitions
  outside DBLayer.
- [x] Add explicit batched relation loaders for one-to-one, one-to-many, and
  many-to-many repository projections, including joined projections and
  bounded prefetching.
- [x] Keep relation loading opt-in: no lazy property queries, identity map,
  dirty tracking, or Active Record behavior.
- [x] Build relation query-count/N+1 diagnostics on DBLayer's existing opt-in,
  bounded query telemetry instead of adding an always-on observer.
- [x] Test schema, migration, seed, rollback, partial failure, and concurrent
  ownership behavior on every supported driver; test relation batching and
  deterministic result indexing separately.
- [x] Verify the new subsystem is lazy and does not change ordinary connection,
  query-builder, repository, or pagination hot paths.
- [x] Document the supported schema matrix, migration lifecycle, locking,
  failure recovery, and deployment procedure.
- [x] Run local CI and migration/relation-specific benchmarks.

#### Release gate

- [x] Run the live Composer advisory audit and publish DBLayer 3.0 before
  Foundation removes its database-domain duplication.

#### Completion gate

Applications can create, version, deploy, roll back, seed, and rebuild supported
databases without Foundation owning SQL grammar or migration execution, and can
load repository relations explicitly without hidden per-row queries.

### Console

Console already provides queue-neutral subprocess supervision through
WorkloadProbe, WorkerSupervisor, WorkerOptions, signals, heartbeat cancellation,
bounded scaling, graceful termination, execution IDs, command scopes, process
limits, scheduling, and CacheLayer mutex integration.

#### Completion and integration handoffs

- [x] Add deterministic subprocess fixtures covering supervisor interruption,
  heartbeat/lease loss, scale-down, grace-period escalation, child failure, and
  final accounting.
- [x] Add bounded soak coverage for repeated scale-up/scale-down and shutdown;
  reuse PHPForge's generic worker-soak tooling where applicable.
- [x] Create the missing Console docs tree and document capability loading,
  command scopes, process controls, scheduler leases, worker supervision,
  shutdown semantics, and framework/queue adapter boundaries.
- [x] Run CI, the release guard, comparable command benchmarks, and the bounded
  worker soak after the test/documentation changes.

#### Release gate

- [x] Add lazy Omnibus consumer and scheduled-message commands, a receiver
  workload probe, compiled factory-key schedules, isolation tests, and docs.
- [x] Continue coordinated framework integration through the explicit
  ``dev-main@dev`` Omnibus constraint.
- [ ] After Omnibus is tagged, replace Console's temporary `dev-main@dev`
  runtime constraint, rerun the release guard, and publish one consolidated
  Console release during the final stable-publication pass.

#### Completion gate

Console's existing queue-neutral supervisor is documented and proven under
long-running shutdown and scaling conditions without learning a queue backend.

### Omnibus

`infocyph/omnibus` is the single framework-agnostic package for general
application events, message routing, asynchronous queue delivery, and workflows.
Combining these domains avoids a queue-to-event adapter package and lets queued
listeners use the same envelope, serializer, retry, failure, and telemetry
contracts as ordinary jobs.

Omnibus is not an infrastructure grab bag. It owns message lifecycle semantics;
Console owns processes and schedules, DBLayer owns databases and transactions,
CacheLayer owns distributed coordination/storage primitives, TalkingBytes owns
communication protocols, and Foundation owns application composition and
authorization.

#### Implemented foundation

- [x] Scaffold `infocyph/omnibus` with UID, PSR-20, and PSR-14 as the minimal
  runtime dependencies; keep DBLayer and CacheLayer optional.
- [x] Define one immutable, versioned envelope for commands, events, queries,
  queued listeners, scheduled messages, and broadcasts with bounded extensible
  stamps/metadata.
- [x] Define explicit class/interface routing to named transports/queues and
  direct handler/listener maps with resolved lookup caching.
- [x] Implement a short synchronous bus path and PSR-14-compatible synchronous
  event dispatcher from an explicit listener map.
- [x] Define queued-listener semantics without requiring queue transports to be
  constructed by synchronous-only event applications.
- [x] Define sender, receiver, transport, reservation, serializer, retry,
  failure-store, clock, and execution-scope contracts.
- [x] Define payload versions, allowed message aliases/types, size/depth limits,
  and safe deserialization rules; never instantiate an arbitrary class named by
  untrusted payload data.
- [x] Implement synchronous, reservable in-memory, and recording/fake paths.
- [x] Implement bounded receive, delay, visibility expiry, acknowledgement,
  release, rejection, exponential retry/jitter, and in-memory failure lifecycle.
- [x] Implement dispatch-after-commit using DBLayer's transaction hook and prove
  outer-commit/rollback behavior with real SQLite integration tests.
- [x] Define explicit scheduled-message factory keys for later Console
  integration without serializing closures into schedule manifests.
- [x] Define optional provider-neutral broadcast messages, broadcasters,
  channels, and channel-authorization contracts.
- [x] Document the implemented package and ownership boundaries; add component
  benchmarks, a 10,000-message bounded soak, and a passing full PHPForge CI
  gate.

#### Remaining tasks

- [x] Add optional DBLayer and native Redis/Valkey transports without loading
  an unselected backend.
- [x] Add optional RabbitMQ/AMQP and Amazon SQS transports behind their native
  provider boundaries; document capability and delivery differences instead
  of pretending every broker is identical.
- [x] Add durable backend batch receive/prefetch where reservation and
  acknowledgement semantics can be preserved.
- [x] Add optional MessagePack/custom binary envelope codecs through the
  existing explicit type-alias boundary.
- [x] Add execution-timeout/cooperative cancellation semantics without
  duplicating Console's process timeout enforcement.
- [x] Add durable failed/dead-letter stores with retry, forget, flush, and
  prune services.
- [x] Implement unique jobs and overlap protection through CacheLayer leases,
  including explicit lease-loss behavior.
- [x] Implement bounded rate-limit and circuit-breaker execution policies by
  adapting CacheLayer-owned state; do not add another cache or lock layer.
- [x] Define optional after-response dispatch without assuming one HTTP runtime.
- [x] Define documented delivery and idempotency semantics for duplicates,
  crashes, expired reservations, poison jobs, and backend failure.
- [x] Provide a queue-consumer command boundary that Console can supervise
  without Omnibus managing subprocesses, signals, scaling, or PID files and
  without Omnibus requiring Console. Console now consumes Omnibus as its
  mandatory message contract, while command registration and transport
  composition remain explicit and lazy.
- [x] Add job chains with strict failure-stop semantics and persisted batch
  progress/cancellation. Represent batch completion, failure, and finalization
  as named messages/events; never serialize arbitrary closures.
- [x] Add Console's compiled-schedule and command adapter around the
  existing scheduled-message factory-key contract.
- [x] Add optional provider broadcaster adapters and authorization integration
  without embedding Foundation auth or loading providers for non-broadcast
  applications.
- [x] Require repositories/application services to emit state-change events
  explicitly. Do not add Active Record, model observers, hidden DB queries, or
  duplicate DBLayer repository lifecycle behavior.
- [x] Expose depth, age, wait, processing, attempt, retry, and failure telemetry.
- [x] Expand assertions/fakes plus concurrency, crash, retry, serialization,
  listener-order/failure, termination, clock-change, persistent-worker,
  batching, workflow, broadcast-authorization, and resource-bound tests.
- [x] Expand benchmarks to durable enqueue, serialization formats, backend
  batch receive, retry/failure, and sustained multi-consumer throughput.
- [x] Document every implemented backend guarantee and recovery procedure; run
  the release guard after all remaining adapters and workflows are complete.

The Foundation-owned InterMix ``ExecutionScope`` adapter is an integration
handoff tracked under Foundation runtime capabilities. It is not an Omnibus
release task.

#### Completion gate

Synchronous events remain a direct predictable path, while asynchronous
messages survive retries, crashes, concurrent consumers, deployments, and
backend failures within documented delivery semantics and bounded resources.
Unused brokers, broadcasting, workflows, and telemetry add no bootstrap or
dispatch-path work.

### JsonDispatch specification

JsonDispatch is a language-neutral specification repository, not a Composer
package, PHP library, runtime dependency, or framework adapter.

#### Completed implementation

- [x] Separate normative requirements from recommendations, tutorials, and
  examples using consistent standards language.
- [x] Reconcile envelope, fail/error payload, status, header, media-type,
  identification, link, reference, and pagination rules across all chapters.
- [x] Remove PHP-like implementation APIs and framework/authentication behavior
  from normative specification text.
- [x] Add versioned JSON Schema and positive/negative conformance fixtures that
  express only normative rules.
- [x] Pin JsonDispatch 3.0.0 for Foundation and run documentation/schema
  checks whenever normative artifacts change.
- [x] Configure GitHub Actions and the Read the Docs pre-build to reject schema,
  fixture, or warning-as-error documentation failures.

#### Release gate

- [x] Tag JsonDispatch 3.0.0.
- [x] Publish the Read the Docs build and verify the schema, fixture, and
  specification artifacts through the released documentation.

#### Completion gate

A pinned, internally consistent specification provides enough normative
fixtures for an independent implementation to prove conformance.

### Foundation browser sessions

Browser-session support is part of Foundation. Do not create a Session package
or move this domain into TalkingBytes, InterMix, Webrick, Pathwise, CacheLayer,
or DBLayer. Those libraries provide reusable boundaries that Foundation may
adapt without making them session-aware.

#### Implemented

- [x] Document the stateful-browser use cases and security model.
- [x] Define the request-scoped session, store, ID, cookie, payload, and lock
  boundaries without adding abstractions for the native clock or JSON encoding.
- [x] Add a dependency-free file store plus optional CacheLayer cache and
  DBLayer database adapters without loading unselected stores.
- [x] Implement ID regeneration/fixation protection, invalidation, flash data,
  old input, idle lifetime, payload bounds, and explicit out-of-request pruning.
- [x] Add optional per-session CacheLayer leases with explicit lock-loss
  behavior.
- [x] Add Foundation session and origin-aware CSRF middleware over Webrick's
  existing HTTP/middleware contracts; accept body/header tokens, reject
  query-string tokens, rotate tokens, and compare them in constant time.
- [x] Define trusted-origin/proxy policy and secure cookie defaults.
- [x] Add the array fake plus integration coverage for persistence, flash,
  fixation, expiry, CSRF replay surfaces, origin rejection, lock release,
  SQLite schema/storage, file storage, and cache storage.
- [x] Keep Webrick framework-neutral; its existing CSRF helper remains
  unchanged because Foundation composes the required HTTP primitives.
- [x] Prove unused/stateless routes have no session cost and document the
  distinction from Foundation auth sessions.
- [x] Prove request-scoped session state is cleared after successful and failed
  dispatch through InterMix scope cleanup and SessionManager finally blocks.

#### Follow-up test depth

- [ ] Add live Redis/Valkey/Memcached/PDO lock contention tests to the
  environment-gated integration matrix; the common lease behavior itself
  remains CacheLayer's responsibility.
- [ ] Add fault-injection coverage for disk-full, database disconnect, and
  cache write failure paths in the owning adapter suites.

#### Completion gate

Browser state and CSRF protection are secure and request-scoped while API routes
and non-web runtimes remain stateless and unaffected.

### Foundation

Foundation already has separate web/CLI boot graphs, lazy optional providers,
route-selected auth/middleware activation, module-driven config publication,
one InterMix graph, request scopes, config/route/command/schedule optimization,
Console scheduling/worker integration, and adapters for the audited libraries.
The remaining work is below.

#### Auth consolidation and proof

- [x] Audit the complete Foundation auth domain and remove pass-through
  managers, duplicate normalization/validation, redundant DTOs, and abstractions
  that do not protect a real boundary.
- [x] Preserve the canonical Foundation auth contracts while verifying account,
  principal, login/logout, auth-session, remember-me, password
  reset/change/passwordless, email-verification, token rotation/revocation,
  MFA/recovery, passkey, authorization, role/permission/grant, device trust,
  impersonation, lockout, audit, and notification flows.
- [x] Add missing unit and integration coverage for those flows, including
  persistent-process and Fiber cleanup of current-principal state, failure
  cleanup, mutable in-memory stores, and scoped dependencies. The unused local
  event-dispatch abstraction was removed; explicit forwarding remains part of
  the Omnibus integration task.
- [x] Extend lazy-activation regression coverage across Epicrypt, OTP, UID,
  WebAuthn, DBLayer, CacheLayer, and TalkingBytes-backed auth drivers.
- [x] Benchmark representative login, authorization, token, MFA/passkey, and
  repeated-request paths.

#### Runtime capabilities

- [x] Add configurable PSR-3 logger resolution instead of constructing
  NullLogger directly in Foundation HTTP factories.
- [x] Separate exception reporting from response rendering and add redacted
  structured operational context.
- [x] Update Foundation's DBLayer development/integration constraint from
  ``^2.3`` to the published ``^3.0`` line.
- [x] Integrate DBLayer schema/migrations and delete Foundation-owned SQL
  grammar/execution that DBLayer supersedes.
- [x] Integrate Omnibus configuration, providers, facades, commands, readiness,
  dispatch boundaries, notification dispatch, job scopes, synchronous events,
  compiled listener manifests, fakes, and explicit auth-event forwarding.
- [x] Add Foundation's InterMix ``ExecutionScope`` adapter for Omnibus and prove
  cleanup after successful and failed message handling.
- [x] Implement Foundation-owned resource transformation and
  JsonDispatch-conforming envelopes against a pinned specification version.
- [x] Map ReqShield failures and DBLayer cursor pagination into that response
  boundary without reparsing or renormalizing their data.
- [x] Implement Foundation-owned browser sessions and CSRF as route-selected
  optional capabilities without affecting stateless routes or non-web
  lifecycles.
- [x] Add a compiled third-party module manifest while retaining curated
  first-party aliases and explicit module installation.

#### Testing and operations

- [x] Add a Foundation HTTP application test client and response assertions
  around native Webrick requests/responses.
- [x] Compose auth, Omnibus event/queue, notification, HTTP-client, cache,
  browser-session, and time fakes through owning-library contracts, with
  Pathwise filesystem isolation through a temporary application base.
- [x] Add database transaction and migration-refresh test helpers.
- [x] Expand multi-request persistent-worker isolation tests beyond container
  scopes to every mutable framework context.
- [x] Extend readiness, optimize, and optimize:clear for migrations, Omnibus,
  resources, and browser sessions only as those capabilities are installed.
- [ ] Verify repeated optimize/clear, create-project, and deployment workflows
  as a normal non-root user.
- [x] Add representative end-to-end performance workloads for auth, HTTP,
  JsonDispatch resources, synchronous events, and queue dispatch/consumption.
- [ ] Establish explicit regression budgets from repeated production-equivalent
  Foundation/Infbyte benchmark runs.

#### Documentation and release

- [x] Create the Foundation docs tree.
- [x] Document the public API, lifecycle, package ownership, optional activation,
  testing, operations, and every final configuration key with its type, default,
  valid values, and example.
- [ ] Replace the runtime infocyph/console development constraint with a stable
  tag after Console's required release.
- [ ] Run clean-install CI without local path repositories, the full release
  guard, and comparable benchmark profiles.
- [ ] Publish the stable Foundation major release.

#### Completion gate

Foundation composes stable domain libraries without copying them, proves its
large auth surface, and keeps every unselected capability out of unrelated web
and CLI hot paths.

### Infbyte

Infbyte already has the minimal application skeleton, /api/health and /json,
explicit console/schedule/worker maps, module-owned config publication, and
root-only test export exclusion.

#### Remaining tasks

- [ ] Update to the stable Foundation major only after Foundation is published,
  then remove minimum-stability: dev.
- [ ] Add migration, Omnibus, resource, and optional browser-session examples
  only through their installation workflows; do not preinstall optional
  modules.
- [ ] Add an HTTP testing base and representative API test after Foundation's
  testing kit is released.
- [ ] Verify composer create-project, optimize/clear, deployment permissions,
  and every system command as a normal non-root user.
- [ ] Run end-to-end benchmark profiles against equivalent validated outputs.
- [ ] Keep application documentation focused on project setup and operations,
  linking domain behavior to owning-library documentation.
- [ ] Run clean create-project CI and publish the stable skeleton release.

#### Completion gate

A new project can be created, configured, optimized, tested, deployed, and
extended entirely through stable Foundation and package contracts.

## Deferred Stable Publication Sequence

Omnibus and Console are development-complete and remain on ``dev-main@dev``
while Foundation and Infbyte are integrated. When the coordinated framework is
ready, stable publication preserves this dependency order:

| Step | Library | Dependency/release gate |
| ---: | --- | --- |
| 1 | Omnibus | Rerun its release gate and publish the completed stable package |
| 2 | Console | Replace the Omnibus development constraint, verify, and publish Console |
| 3 | Foundation | Replace Console and other runtime development constraints, verify, and publish Foundation |
| 4 | Infbyte | Replace Foundation's development constraint, run create-project/benchmark gates, and publish Infbyte |

The linear order does not justify unnecessary hard dependencies:

- Omnibus must not require Console.
- Console requires Omnibus as its unified message contract, but registration
  remains explicit and unrelated commands must not initialize its runtime
  graph or message transports.
- Synchronous Omnibus events must not construct or load queue transports.
- JsonDispatch is not a runtime package or dependency; Foundation implements
  its pinned specification without moving framework behavior into the spec.
- Foundation browser sessions must keep unused storage drivers optional and
  remain absent from stateless routes and non-web lifecycles.
- Foundation integrates optional libraries without forcing them into unrelated
  applications or request paths.

### Per-library working procedure

For every package that actually changes in the sequence:

1. Re-read that library's code, tests, Composer metadata, and documentation.
2. Re-read the relevant sections of the engineering principles.
3. Confirm ownership and non-goals before changing code.
4. Establish correctness and performance baselines.
5. Implement the smallest complete package-owned capability.
6. Add unit, integration, failure-path, and persistent-runtime tests where
   applicable.
7. Measure representative performance before and after.
8. Update the owning package documentation.
9. Run the full CI and release guard.
10. Publish a stable release.
11. Update the immediate consumer library.
12. Re-run consumer integration tests and end-to-end benchmarks.

## Documentation Work

Foundation needs a full `docs/` tree rather than relying only on its README.
Console now has its package-owned documentation tree.

### Foundation documentation

- Installation and create-project workflow.
- Directory structure.
- Web and console lifecycles.
- Configuration loading and cache modes.
- Providers and lazy service activation.
- Modules and package ownership.
- Routing and route cache modes.
- Middleware ordering and route presets.
- Authentication and authorization.
- Cache.
- Database and repositories.
- Migrations and seeding when available.
- Validation.
- Filesystem and uploads.
- HTTP client, email, webhook, and notifications.
- Console commands.
- Scheduling and workers.
- Omnibus messages, queues, events, workflows, and operations.
- Browser sessions and CSRF, including their distinction from auth sessions.
- API resources and JsonDispatch conformance.
- Testing.
- Error handling, logging, and observability.
- Security.
- Persistent workers.
- Optimization and deployment.
- Performance measurement.
- Troubleshooting.

### Documentation requirements

- Every final configuration key documents its type, default, valid predefined
  values, and a safe example for free-form values.
- Clearly label required versus optional packages.
- Clearly identify which runtime activates each provider.
- Include complete minimal examples and production notes.
- Avoid implying that optional packages are loaded globally.
- Keep package-specific behavior in the owning package documentation and link
  to it from Foundation.
- Do not add a separate upgrading guide for the currently planned major
  release.

## Release Readiness

Before declaring the next Foundation/Infbyte major stable:

- Replace runtime `dev-main@dev` constraints with tagged compatible releases.
- Remove application `minimum-stability: dev` when no runtime dependency
  requires it.
- Publish supported PHP and dependency version matrices.
- Test the lowest supported PHP version, primary production version, and next
  intended version.
- Run all package CI pipelines.
- Run Foundation and Infbyte integration CI from clean Composer installations.
- Validate `composer create-project` output without local path repositories or
  global Composer configuration.
- Verify module installation and config publication from Packagist packages.
- Verify optimize/clear repeatedly and under realistic permissions.
- Verify web and console boot isolation.
- Soak-test persistent HTTP workers and queue workers.
- Perform a security review of auth, sessions, CSRF, serialization, queues,
  uploads, signed URLs, secrets, and log redaction.
- Tag immutable releases and use stable constraints in Infbyte.

## Performance Acceptance

Do not judge framework progress only by the `/json` route.

Maintain separate reproducible benchmarks for:

- raw runtime;
- Webrick-only minimal response;
- Foundation/Infbyte minimal JSON;
- route cache modes;
- middleware-free route;
- representative middleware stacks;
- validation;
- cache hit;
- cache miss/fill;
- database read;
- database multi-row/keyset pagination;
- database transaction/write;
- authenticated request;
- authorization-heavy request;
- file upload/download;
- external HTTP call;
- API resource transformation;
- event dispatch;
- queue dispatch;
- queue consumption;
- persistent-worker repeated requests.

For each workload record:

- successful RPS and RPM;
- failures and timeouts;
- response validation failures;
- p50, p95, and p99;
- duration and concurrency;
- CPU and memory;
- worker utilization;
- query count;
- database connections;
- cache hit rate;
- queue depth/growth where applicable;
- external calls;
- exact PHP/extensions/OPcache/runtime configuration.

Use repeated production-equivalent runs and compare median sustained successful
RPM. Treat regressions above the workload's established variance and budget as
blocking unless explicitly approved.

## Explicit Non-Goals

The next major release will not:

- clone Eloquent or introduce hidden Active Record database access;
- create a Blade-compatible template language;
- install all optional infrastructure by default;
- load auth globally for public routes;
- merge web and CLI boot paths;
- discover providers, commands, routes, listeners, or migrations during
  requests;
- create Foundation-prefixed duplicates of standalone packages;
- add abstractions solely for Laravel API familiarity;
- sacrifice correctness or security for benchmark numbers;
- claim Laravel ecosystem parity.

## Recommended Implementation Order

1. Complete Foundation integration against the verified Omnibus and Console
   development branches, including lazy browser-session and CSRF support.
2. Finalize Infbyte and run the complete application integration and benchmark
   matrix.
3. Publish Omnibus, Console, Foundation, and Infbyte in dependency order,
   replacing ``dev-main@dev`` constraints as each stable tag becomes available.

ArrayKit, InterMix, CacheLayer, Pathwise, UID, ReqShield, TalkingBytes, OTP,
Epicrypt, and Webrick were audited and have no pending package work for this
roadmap.

## Definition of Framework Completion

Foundation is ready for a stable major release when:

- package ownership is unambiguous and Foundation is the canonical
  authentication implementation;
- public API and console applications can be built without missing database,
  event, queue, testing, logging, or serialization lifecycle primitives;
- optional modules stay absent from unrelated runtime paths;
- web, console, scheduler, queue, and persistent-worker lifecycles are isolated
  and tested;
- configuration and metadata are compiled outside request handling;
- documentation covers installation through production operations;
- all dependencies use stable tagged constraints;
- security reviews and failure-path tests are complete;
- representative end-to-end benchmarks satisfy defined sustained RPM,
  correctness, latency, memory, and stability budgets.
