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
- Latest verified implementation checkpoint: `36fc3600ebd1774a673a79ec08e01cdfd3b85f8d`
- Current Worker lifecycle candidate under validation: `9a87be2156b6819d65e434146a460d0532441222`
- Authoritative Security & Standards run: `32813737909`
- Authoritative dedicated PHPStan run: `32813737450`
- Current phase: **Worker lifecycle/fork-safety closure, then Scheduler runtime matrix**.
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
- destructive confirmation remains covered independently by `DatabaseDestructiveCommandTest`, so the generic CLI matrix does not duplicate it.

The first CLI matrix run failed one fallback assertion because the test incorrectly expected a source command to disappear after an invalid cache was rejected. Production behavior was correct; the assertion was corrected, and the exact-head rerun is fully green.

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

Verified implementation checkpoint: `36fc3600ebd1774a673a79ec08e01cdfd3b85f8d`.

Security & Standards run `32813737909`:

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

The QA jobs use `fail_on_skipped_tests: true` and enforce Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize. Analyzer jobs enforce PHPStan and Psalm. Dedicated Foundation PHPStan diagnostic run `32813737450` also passed.

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
6. [x] Production clean-install gate passes on the rebaselined dependency set (`32813737909`).
7. [x] PHP 8.4 representative benchmark validation passes (`32813737909`).
8. [x] PHP 8.5 representative benchmark validation passes (`32813737909`).
9. [x] Psalm passes on PHP 8.4 and PHP 8.5 analyzer jobs (`32813737909`).
10. [x] Deptrac passes across the current QA matrix (`32813737909`).
11. [x] Current syntax/PHPProbe/Pest blocking defects are cleared (`32813737909`).
12. [x] DBLayer 5 migration/rollback/status/reset/refresh/wipe/monitor compatibility matrix is green.
13. [x] Destructive database safeguard/confirmation matrix is green through the real CLI dispatcher.
14. [x] Module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync plus dry-run/duplicate/failure rollback behavior is green.
15. [x] Optional capability isolation, dependency-free base boot and graceful unavailable-capability errors are green (`32812503732`, `32812503419`).
16. [x] Web routes/cache/middleware/session/auth-principal/maintenance/exception behavior is green (`32812796414`, `32812795816`).
17. [x] CLI discovery/cache/status/exit/overlap/global options/help/completion/machine-readable/destructive-confirmation behavior is green (`32813737909`, `32813737450`).
18. [ ] Verify Worker scope reset/restart/heartbeat/singleton/pools/fork-before-resource-open behavior.
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
29. [x] Pint/Rector/Composer Normalize/PHPCS/PHPStan/Psalm/Deptrac are green on the current PHP 8.4/8.5 lowest/stable matrix with `fail_on_skipped_tests: true` (`32813737909`, `32813737450`).
30. [ ] Review soak-sensitive persistent-runtime paths and establish/compare a representative benchmark baseline where appropriate.
31. [ ] Final dependency/package/stale-version/retired-API audit plus Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results with zero ambiguous closure items.

## Worker lifecycle/fork-safety audit — active

Current source and existing test coverage already prove:

- provider workers run only in the Worker runtime;
- provider work is expected to pass bounded units through `WorkerRuntime::execute()`;
- `WorkerRuntime::execute()` enters a fresh Foundation execution scope for each unit;
- bounded Omnibus message workers already prove distinct scoped services per message;
- runtime/worker/named-worker control tokens are captured when `WorkerManager::run()` starts;
- worker processes register in `RuntimeProcessRegistry`, heartbeat their record, and unregister in `finally`;
- explicit singleton providers use CacheLayer ownership and refresh the lease from heartbeat;
- non-singleton providers do not acquire a global lock;
- process-local memory and sync transports are rejected for pools;
- pooled workers require an unbooted/clean parent and declarative scalar/array configuration;
- pool children construct and boot a fresh `Foundation::worker($config)` inside the child factory;
- existing parent-resource guard coverage proves a resolved CacheManager prevents pool startup;
- `WorkerManager` now reports the current Omnibus `^2.5` requirement rather than the stale `^2.4` message.

Candidate `9a87be2156b6819d65e434146a460d0532441222` adds focused proofs for:

- two provider execution units receiving distinct InterMix scoped services and execution IDs while reusing the same service within each scope;
- a named worker restart signal being observed by `WorkerRuntime::heartbeat()`, converted into graceful manager exit `0`, and followed by process-registry cleanup;
- an externally held singleton lock preventing provider entry and still leaving no stale process-registry record.

Checklist item 18 remains open until this candidate is fully green across the exact-head QA matrix. Checklist item 21 remains separate: source guards and current tests are supporting evidence, but actual pre-fork DB/network and child/reaping/termination behavior still need explicit non-skipped closure evidence.

## Immediate next work

1. finish exact-head PHPForge/PHPStan validation for Worker lifecycle candidate `9a87be21` and close checklist item 18 only if fully green;
2. extend checklist 21 with explicit parent DB/network-resource rejection and determine whether reliable non-skipped pcntl/posix child-init/reaping/termination tests can run in the enforced CI environment;
3. proceed to checklist 19 Scheduler once/work/interrupt/runtime-reload/overlap/scheduled-message behavior;
4. then consolidate persistent-state cleanup under checklist 20 using the already-verified Web/Worker/messaging scope evidence plus any remaining cache/session/DB leak probes.