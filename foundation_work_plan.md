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
- Latest verified implementation checkpoint: `31db003e6319bc35ccba5909b3c619847ae28c49`
- Authoritative Security & Standards run: `32812503732`
- Authoritative dedicated PHPStan run: `32812503419`
- Current phase: **Web runtime closure, then CLI/Worker/Scheduler runtime matrices**.
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

No production optional-capability redesign was required. The only first-run failure was PHPForge forbidding `echo()` in the subprocess fixture; replacing it with `file_put_contents('php://stdout', ...)` produced a fully green exact-head matrix.

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

Verified implementation checkpoint: `31db003e6319bc35ccba5909b3c619847ae28c49`.

Security & Standards run `32812503732`:

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

The QA jobs use `fail_on_skipped_tests: true` and enforce Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize. Analyzer jobs enforce PHPStan and Psalm. Dedicated Foundation PHPStan diagnostic run `32812503419` also passed.

This supersedes all earlier closure-run QA checkpoints and the historical 2026-08-24 PHPStan/RuntimeLoggingValidator snapshot.

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
6. [x] Production clean-install gate passes on the rebaselined dependency set (`32812503732`).
7. [x] PHP 8.4 representative benchmark validation passes (`32812503732`).
8. [x] PHP 8.5 representative benchmark validation passes (`32812503732`).
9. [x] Psalm passes on PHP 8.4 and PHP 8.5 analyzer jobs (`32812503732`).
10. [x] Deptrac passes across the current QA matrix (`32812503732`).
11. [x] Current syntax/PHPProbe/Pest blocking defects are cleared (`32812503732`).
12. [x] DBLayer 5 migration/rollback/status/reset/refresh/wipe/monitor compatibility matrix is green.
13. [x] Destructive database safeguard/confirmation matrix is green through the real CLI dispatcher.
14. [x] Module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync plus dry-run/duplicate/failure rollback behavior is green.
15. [x] Optional capability isolation, dependency-free base boot and graceful unavailable-capability errors are green (`32812503732`, `32812503419`).
16. [ ] Verify Web routes/cache/middleware/session/auth-principal/maintenance/exception behavior.
17. [ ] Verify CLI discovery/cache/status/exit/overlap/global options/help/completion/machine-readable/destructive-confirmation behavior.
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
29. [x] Pint/Rector/Composer Normalize/PHPCS/PHPStan/Psalm/Deptrac are green on the current PHP 8.4/8.5 lowest/stable matrix with `fail_on_skipped_tests: true` (`32812503732`, `32812503419`).
30. [ ] Review soak-sensitive persistent-runtime paths and establish/compare a representative benchmark baseline where appropriate.
31. [ ] Final dependency/package/stale-version/retired-API audit plus Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results with zero ambiguous closure items.

## Web runtime audit — active

Existing green `WebrickInterMixIntegrationTest` / route-cache coverage already verifies:

- Webrick route-file loading and canonical router/kernel services;
- per-request InterMix execution scopes;
- unrelated optional subsystems stay deferred on plain routes;
- 404 exception logging and auth-exception rendering are lazy and return the expected 404/401 statuses;
- principal/session/DB state is cleaned between HTTP execution scopes, including rollback of an open DB transaction;
- optional auth adapters are lazy until their capability is selected;
- auth actions/capabilities resolve through DI;
- current principals are isolated across concurrent fibers and restored on failure;
- configured middleware aliases are not instantiated until a route uses them;
- global middleware applies without recursive application boot;
- warm fused/generated/sharded Webrick route caches boot correctly and preserve signed routes/aliases;
- disabled/cold route-cache path behavior is covered.

`HttpKernel` checks `MaintenanceManager::status()` before router dispatch and returns a negotiated 503 response with `maintenance: true` plus optional `Retry-After`. No existing test explicitly proves the router is short-circuited while maintenance is active and resumes on the same persistent Web application after maintenance is disabled. That is the remaining focused #16 evidence gap.

## Immediate next work

1. add a focused persistent-Web maintenance test: route side-effect must not execute while maintenance is enabled; response must be 503 with the configured message/`Retry-After`; disabling maintenance on the same app must immediately restore normal route dispatch;
2. rerun exact-head PHPForge/PHPStan and close checklist item 16 only if the Web runtime matrix stays green;
3. then audit checklist item 17 CLI discovery/cache/status/exit/overlap/global-option/help/completion/machine-readable behavior and add only missing command-boundary probes.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkey modules;
- no duplicate specialist schema command families;
- no schema/data deletion during module removal;
- no copied specialist SQL/transport/retry/cache/database engines;
- no unsafe lock fallback;
- no broad `Application` service facade;
- no second messaging/retry/failure/worker/workflow engine above Omnibus;
- no Omnibus `Envelope`/`HandlerContext` leakage into Foundation `JobMiddleware`;
- no retired Console runtime hierarchy;
- no static global application state;
- no generic IdentifierManager/request scope;
- no bulk optional-config copy into Infbyte;
- no environment-protection key inside `.env`/`.env.example`;
- no generated optimized artifacts committed.
