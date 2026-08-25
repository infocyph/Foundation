# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file is the evidence-driven source of truth for current Foundation 2.0 implementation and release closure.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable framework/runtime layer. Infbyte is the opinionated application skeleton built on it.

## Active closure run

- Started: 2026-08-24 (Asia/Dhaka)
- Original closure checkpoint: `16d60f114314544a5c6db91c0e986423fa6fbb70`
- Dependency-rebaseline checkpoint: `6663dad26e75453fcebb7975dda2ad0b49661951`
- Latest verified implementation checkpoint: `4dbccd51a9b033f80947c1f4fe1dd047e14200ba`
- Authoritative Security & Standards run: `32814167565`
- Authoritative dedicated PHPStan run: `32814167162`
- Current phase: **pool/fork safety closure, then Scheduler runtime matrix**.
- Architecture/public ownership boundaries are frozen. Correct integration defects, tests, diagnostics and docs only; do not restore retired convenience APIs or duplicate specialist engines.
- Final finish condition: updated dependencies resolve, PHPUnit/PHPForge/static-analysis matrices are green, specialist/runtime/security/process/deployment behavior is evidenced, Infbyte is aligned, benchmarks remain acceptable, and every checklist item below has explicit evidence.

## Dependency baseline

### Core

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

### Optional/dev capabilities

- `infocyph/cachelayer ^3.2.0`
- `infocyph/dblayer ^5.0`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.5`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.1`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

`ModuleCatalog` must stay aligned with Composer. Stable repository refs were verified directly for DBLayer `5.0`, Omnibus `2.5`, and ReqShield `3.1`.

## Verified closure evidence — 2026-08-25

### Dependency rebaseline and specialist compatibility

- `composer.json` and `ModuleCatalog` are aligned to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
- DBLayer 5 integration continues to delegate database/query/schema/migration/transaction mechanics to DBLayer.
- `ReqShieldDatabaseProvider` uses DBLayer 5 `Connection::safeBatchSize()` so physical validation queries respect configured/driver bind ceilings without changing ReqShield's public DB-agnostic contract.
- Omnibus 2.5 integration continues to delegate transport/reservation/retry/failure/worker/pool/workflow mechanics to Omnibus.
- current docs/messages target the rebaselined specialist versions.

### DBLayer 5 compatibility — executed

Green across the authoritative PHP 8.4/8.5 lowest/stable matrix:

- migration pretend without mutation;
- step-mode batches and status batch reporting;
- exact `rollbackBatch()` and latest-batch rollback;
- migration up/down lifecycle;
- `fresh(true)`, `refresh(true)`, `reset(true)` and reverse reset order;
- monitor status/snapshot shape;
- explicit destructive wipe path;
- migration fixtures register DB facade connections as required by DBLayer 5 schema cache-tag invalidation.

### Destructive database safeguards — executed

`DatabaseDestructiveCommandTest` exercises the real `CommandDispatcher` boundary and verifies:

- non-interactive wipe/reset/fresh/refresh refuse without `--force` and preserve existing state;
- authorized operations execute correctly;
- production destructive commands require `--force` even when interactive confirmation would succeed;
- production detection uses the real `app.env` key;
- DBLayer facade state is purged between command applications.

### Module lifecycle/schema matrix — executed

`ModuleConfigPublicationTest` and `ModuleLifecycleIntegrationTest` verify:

- canonical module list/show and aliases;
- install/remove dry-run Composer commands;
- remove only direct project requirements;
- non-direct removal is a successful no-op;
- built-in removal refusal;
- config publication/duplicate preservation;
- forced multi-file publication rollback and staging/backup cleanup;
- package removal preserves application config and database data;
- session schema status/install/status;
- cache schema status is observational and does not create a missing SQLite database;
- schema sync creates required cache storage only while provisioning;
- package removal has no schema/data deletion path.

### Optional-capability isolation — executed

`OptionalCapabilityIsolationTest` runs an isolated subprocess that preloads Foundation/core classes, verifies optional marker classes were not eagerly loaded, unregisters Composer, then boots a fresh CLI application with optional packages genuinely unavailable.

Green behavior in Security & Standards run `32812503732`:

- base CLI creation/boot remains healthy without optional specialist packages;
- CacheLayer, DBLayer, TalkingBytes, Pathwise, Omnibus, Epicrypt and ReqShield marker classes are not eagerly loaded by dependency-free boot/auth paths;
- OTP and WebAuthn markers are also not eagerly loaded by default auth;
- `Application::has()` returns false for representative unavailable managed capabilities;
- `Application::make()` returns stable `ServiceResolutionException` install-module guidance for cache, communication, database, filesystem, messaging, security and validation;
- default dependency-free auth still resolves after optional autoloading is removed;
- selecting the OTP MFA driver fails explicitly with the OTP package/install guidance;
- selecting the WebAuthn passkey driver fails explicitly with the WebAuthn package/install guidance;
- core notification services intentionally remain available without TalkingBytes; email bindings themselves own the TalkingBytes requirement, so no provider-level hard dependency was added.

### Web runtime matrix — executed

Existing Webrick/InterMix/route-cache coverage plus `WebMaintenanceRuntimeTest` are green in Security & Standards run `32812796414` across PHP 8.4/8.5 lowest/stable.

Verified behavior includes:

- route-file loading and canonical router/kernel services;
- warm generated/fused/sharded route-cache boot plus disabled/cold cache behavior;
- per-request InterMix execution scopes;
- middleware laziness/aliases and global middleware application without recursive boot;
- unrelated optional subsystems remain deferred on plain routes;
- principal/session/DB execution state resets between requests, including rollback of open DB transactions;
- optional auth adapters remain lazy until selected;
- concurrent fiber principal isolation and failure restoration;
- 404 logging and auth exception rendering return expected statuses without eager renderer construction;
- file-backed maintenance short-circuits router dispatch, returns 503 with configured message and `Retry-After`, and prevents route side effects;
- disabling maintenance restores normal route dispatch immediately on the same persistent Web application.

### CLI runtime matrix — executed

`CliRuntimeIntegrationTest` is green in Security & Standards run `32813737909` across PHP 8.4/8.5 lowest/stable. The dedicated PHPStan run `32813737450` is also green.

Verified behavior includes:

- source command discovery and aliases when no valid cache exists;
- valid command-manifest precedence over source routes;
- incompatible command manifests fall back to `routes/console.php`;
- list/version/help paths execute through `CliPreflight` and expose only non-hidden commands;
- completion command listing and Bash/Zsh/Fish generation work, while unsupported shells return invalid usage;
- hidden commands cannot be dispatched through the normal CLI surface;
- unknown commands return command-not-found and emit suggestions;
- descriptor-aware required arguments, long/short valued options, repeatable options and negatable flags parse correctly;
- global `--env`, `--json`, `--no-interaction` and verbosity flags propagate into the command/application context;
- machine-readable command data is preserved through `CommandIO`;
- missing arguments, unknown options and excess arguments return invalid usage;
- handler non-zero exit codes propagate unchanged;
- thrown handler exceptions become framework failure exits with a stable error message;
- execution history records pending/running/succeeded for success and pending/running/failed for non-zero/exception exits;
- overlap `Skip` mode uses the configured CacheLayer lock, prevents handler execution when ownership is unavailable, and records pending/cancelled while returning a successful skip exit;
- destructive confirmation remains covered independently by `DatabaseDestructiveCommandTest`.

The first CLI matrix run failed one fallback assertion because the test incorrectly expected a source command to disappear after an invalid cache was rejected. Production behavior was correct; the assertion was corrected, and the exact-head rerun is fully green.

### Worker lifecycle matrix — executed

`WorkerLifecycleClosureTest`, the existing WorkerManager tests, and Omnibus worker integration coverage are green in Security & Standards run `32814167565` across PHP 8.4/8.5 lowest/stable. Dedicated PHPStan run `32814167162` is also green.

Verified Foundation-owned Worker behavior includes:

- provider workers are Worker-runtime-only;
- each bounded provider unit passed through `WorkerRuntime::execute()` receives a fresh InterMix execution scope and a distinct execution ID while repeated resolutions inside the same unit share the same scoped service;
- existing bounded Omnibus message-worker coverage also proves distinct scoped services per message;
- runtime, worker-wide and named-worker control tokens are captured when `WorkerManager::run()` starts;
- worker processes register in `RuntimeProcessRegistry`, heartbeat their record and unregister in `finally`;
- a named restart signal is observed through `WorkerRuntime::stopRequested()` and `heartbeat()`, converted to graceful manager exit `0`, prevents execution after the heartbeat, and leaves no registry record;
- non-singleton providers do not acquire a global lock;
- singleton providers acquire CacheLayer ownership and refresh it from heartbeat;
- externally held singleton ownership prevents provider entry and still leaves no stale process-registry record;
- process-local memory and sync transports are rejected for pools;
- pooled workers require an unbooted/clean parent and scalar/array declarative configuration;
- pool child construction remains inside the post-fork factory as a fresh `Foundation::worker($config)` followed by child boot;
- an already resolved parent CacheManager is rejected before pool start;
- the stale WorkerManager Omnibus `^2.4` diagnostic was aligned to the current `^2.5` dependency baseline.

The first Worker closure candidate failed before runtime execution because two state-recording test providers were declared `readonly` while carrying mutable static assertion state. The fixture classes were corrected without changing Worker behavior; `4dbccd51` is fully green.

Checklist item 21 remains separate: source guards and current pool tests support the architecture, but opened DB/network parent-state and real non-skipped child creation/reaping/termination still require explicit closure evidence.

### Omnibus 2.5 current compatibility evidence

Green compatibility coverage includes:

- consumer/execution scope;
- `Worker` / `WorkerOptions`;
- `WorkerLifecycle::heartbeat()` / `stopRequested()`;
- `WorkerPool` availability/integration;
- `HandlerInvoker` middleware;
- failure retry-claim/send/removal, prune, forget and flush;
- Foundation-to-Omnibus worker lifecycle callback wiring;
- existing memory dispatch/consume, routing, retry/release/failure storage, job middleware and metadata tests.

Checklist item 25 remains open until monitor/execution-scope/shutdown/restart behavior is explicitly closed as a release matrix.

## Authoritative QA baseline — 2026-08-25

Verified implementation checkpoint: `4dbccd51a9b033f80947c1f4fe1dd047e14200ba`.

Security & Standards run `32814167565`:

- matrix preparation: PASS;
- clean production install: PASS;
- PHP 8.4 analysis: PASS;
- PHP 8.5 analysis: PASS;
- PHP 8.4 prefer-lowest QA: PASS;
- PHP 8.4 prefer-stable QA: PASS;
- PHP 8.5 prefer-lowest QA: PASS;
- PHP 8.5 prefer-stable QA: PASS;
- PHP 8.4 representative benchmark validation: PASS;
- PHP 8.5 representative benchmark validation: PASS;
- benchmark comparison: skipped because no baseline artifact is configured; result validation itself passed;
- Security SVG report: skipped and not a release gate.

The QA jobs use `fail_on_skipped_tests: true` and enforce Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize. Analyzer jobs enforce PHPStan and Psalm. Dedicated Foundation PHPStan diagnostic run `32814167162` also passed.

# Frozen architecture

Exactly four runtimes exist: Web, CLI, Worker, Scheduler.

Specialist ownership remains:

- InterMix — DI/lifetimes/scopes;
- Webrick — HTTP/router/request/response/emission;
- UID — identifiers;
- DBLayer — database/query/schema/migration/transaction infrastructure;
- CacheLayer — cache/locks/counters/shared coordination;
- Omnibus — message transport/handlers/retry/failure/workers/pools/workflows;
- TalkingBytes — HTTP/email/webhook/gRPC;
- ReqShield — validation;
- Epicrypt — cryptographic primitives;
- Pathwise/Flysystem — filesystem/storage mechanics;
- OTP — OTP/replay;
- WebAuthn library — WebAuthn protocol.

`Application` stays narrow: boot/runtime state, config/container/providers, generic DI, execution coordination, canonical Web handling and application paths. Do not add broad auth/cache/database/filesystem/messaging/security/testing/response facades.

Purpose-first public modules remain `auth`, `cache`, `communication`, `database`, `filesystem`, `logging`, `messaging`, `operations`, `resources`, `security`, `session`, `validation`. No standalone OTP/passkey modules.

Module removal never deletes schema/data. Schema ownership remains auth → Foundation auth schema, cache → CacheLayer provisioners, session → Foundation session schema.

# 32-point verification / release closure checklist

1. [x] Freeze Foundation 2.0 architecture/public ownership boundaries.
2. [x] Freeze narrow `Application` API and remove retired convenience proxies/managers.
3. [x] Align Composer capability baseline to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
4. [x] Align `ModuleCatalog` constraints with Composer baseline.
5. [x] Normalize PHPForge reusable-workflow configuration to repository-specific overrides only.
6. [x] Production clean-install gate passes on the rebaselined dependency set (`32814167565`).
7. [x] PHP 8.4 representative benchmark validation passes (`32814167565`).
8. [x] PHP 8.5 representative benchmark validation passes (`32814167565`).
9. [x] Psalm passes on PHP 8.4 and PHP 8.5 analyzer jobs (`32814167565`).
10. [x] Deptrac passes across the current QA matrix (`32814167565`).
11. [x] Current syntax/PHPProbe/Pest blocking defects are cleared (`32814167565`).
12. [x] DBLayer 5 migration/rollback/status/reset/refresh/wipe/monitor compatibility matrix is green.
13. [x] Destructive database safeguard/confirmation matrix is green through the real CLI dispatcher.
14. [x] Module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync plus dry-run/duplicate/failure rollback behavior is green.
15. [x] Optional capability isolation, dependency-free base boot and graceful unavailable-capability errors are green (`32812503732`, `32812503419`).
16. [x] Web routes/cache/middleware/session/auth-principal/maintenance/exception behavior is green (`32812796414`, `32812795816`).
17. [x] CLI discovery/cache/status/exit/overlap/global options/help/completion/machine-readable/destructive-confirmation behavior is green (`32813737909`, `32813737450`).
18. [x] Worker scope reset/restart/heartbeat/singleton/pools/fork-before-resource-open behavior is green (`32814167565`, `32814167162`).
19. [ ] Verify Scheduler once/work/interrupt/runtime-reload/overlap/scheduled-message behavior without forbidden blocking APIs.
20. [ ] Verify no persistent execution-state leaks across InterMix/principal/session/DB/cache/messaging.
21. [ ] Verify pool/fork safety: no pre-fork DB/network resources; child init/cleanup/reaping/termination.
22. [ ] Verify full auth/session/token/password/email/passwordless/lockout flows.
23. [ ] Verify MFA recovery/passkey/step-up/recent-auth/authorization/impersonation flows.
24. [ ] Verify production security posture: secrets, secure defaults, OTP/WebAuthn/shared-state topology, unsafe-memory rejection.
25. [ ] Execute Omnibus 2.5 dispatch/consume/retry/failure/prune/monitor/execution-scope/shutdown/restart matrix.
26. [ ] Verify config/route/command/schedule/container optimize/clear idempotency.
27. [ ] Verify maintenance/runtime reload/worker restart/scheduler interrupt/process-registry/status/stale cleanup.
28. [ ] Verify env encrypt/decrypt/temp-file/permissions/overwrite and storage link/unlink safety.
29. [x] Pint/Rector/Composer Normalize/PHPCS/PHPStan/Psalm/Deptrac are green on the current PHP 8.4/8.5 lowest/stable matrix with `fail_on_skipped_tests: true` (`32814167565`, `32814167162`).
30. [ ] Review soak-sensitive persistent-runtime paths and establish/compare a representative benchmark baseline where appropriate.
31. [ ] Final dependency/package/stale-version/retired-API audit plus Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results with zero ambiguous closure items.

## Pool/fork safety audit — active

Current source and existing verified tests establish the Foundation-side preconditions:

- pool factory invocation is delegated to Omnibus `WorkerPool`, whose factory executes inside the child after `pcntl_fork()`;
- Foundation creates and boots a fresh child Worker application inside that post-fork factory;
- memory/sync transports are rejected for process pools;
- booted parents and non-declarative runtime objects/closures are rejected before pool startup;
- the parent guard checks resolved CacheLayer/cache, DBLayer connection, Omnibus consumer/failure/transport-registry and TalkingBytes HTTP client services;
- it also rejects any globally registered DBLayer connections even when the connection object was not resolved through the Foundation container;
- the existing cache-resource test proves a resolved parent CacheManager blocks pool startup.

Checklist 21 remains open until the following execute without skips:

- opened DBLayer parent connection rejection;
- resolved TalkingBytes HTTP-client parent rejection;
- CI `pcntl`/`posix` support exercised by a real Omnibus `WorkerPool` child;
- child factory runs under a different PID, stop/termination completes, the child is reaped, and parent signal handlers are restored.

## Immediate next work

1. add the focused pool/fork-safety matrix above and close checklist item 21 only with non-skipped exact-head evidence;
2. proceed to checklist 19 Scheduler once/work/interrupt/runtime-reload/overlap/scheduled-message behavior;
3. consolidate persistent-state cleanup under checklist 20 using the verified Web/Worker/messaging scope evidence plus any remaining cache/session/DB leak probes;
4. continue into auth/security/Omnibus/optimize/operations/environment closure items in checklist order where dependencies allow.