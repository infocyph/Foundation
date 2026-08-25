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
- Latest implementation checkpoint before this plan update: `7121392605536b3b0e6f6199d6e1c0adf13d31ce`
- Current phase: **DBLayer 5 / Omnibus 2.5 / ReqShield 3.1 compatibility closure and authoritative QA preparation**.
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

## Rebaseline implementation evidence — 2026-08-25

### Metadata and docs

- `composer.json`: DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
- `ModuleCatalog` uses the same constraints.
- Bootstrapper missing-capability message uses Omnibus `^2.5`.
- `docs/database.md` targets DBLayer 5.
- `docs/messaging.md` and `docs/console.md` target Omnibus 2.5.

### DBLayer 5 source compatibility

Foundation still uses supported DBLayer 5 APIs:

- `ConnectionConfig::fromArray()`;
- `DB::setDefaultConnection()`, `hasConnection()`, `addConnection()`, `connection()`;
- `Connection::query()`, `transactionLevel()`, `rollbackTransaction()`, `resetRuntimeStateForReuse()`, `disconnect()`;
- `Connection::effectiveMaxBindParameters()` / `safeBatchSize()`;
- `DB::getConnections()` / `resetRuntimeState(false)`;
- `MigrationRunner` run/pretend/status/rollback/rollbackBatch/fresh/refresh/reset;
- `DatabaseMonitor` status/sessions/queries/locks/tables/indexes/replication/snapshot;
- `SchemaManager::dropAllTables(true)`.

No Foundation repository/ORM rewrite is required. DBLayer remains the database engine owner.

### ReqShield 3.1 database adapter correction

ReqShield 3.1 keeps the minimal `DatabaseProvider::batchExists()` / `batchUnique()` contract.

Foundation fixed one concrete DBLayer 5 integration issue: `ReqShieldDatabaseProvider` previously sent an entire logical value group into one `whereIn()`. DBLayer 5 enforces driver/configured bind ceilings. The adapter now:

- uses `Connection::safeBatchSize()` for physical chunks;
- counts unique-ignore as a fixed binding;
- preserves logical validation batching;
- handles null separately;
- adds no repository/cache/collection abstraction.

`ReqShieldDatabaseValidationTest` now uses a deliberately tiny `security.max_params` value to force physical splitting.

### DBLayer 5 compatibility coverage prepared

`DBLayer5MigrationCompatibilityTest` covers, once executed:

- pretend without schema mutation;
- step-mode batches;
- status batch reporting;
- exact `rollbackBatch()` selection;
- latest-batch rollback;
- `fresh(true)`, `refresh(true)`, `reset(true)`;
- reverse reset order;
- monitor status/snapshot shape;
- explicit `dropAllTables(true)` wipe path.

`CurrentInfocyphApiCompatibilityTest` now targets DBLayer 5 bind sizing and monitoring surfaces.

### Destructive database command safeguards prepared

`DatabaseDestructiveCommandTest` exercises the real `CommandDispatcher` + `DatabaseSystemCommand` boundary against a persistent temporary SQLite database.

Prepared cases:

- `db:wipe --no-interaction` without `--force` fails before mutation;
- forced non-interactive `db:wipe` succeeds and removes the table;
- `migrate:reset -n` without force fails and leaves schema intact;
- forced reset succeeds;
- `migrate:fresh -n` without force leaves marker data intact;
- forced fresh rebuilds and clears prior marker data;
- `migrate:refresh -n` without force leaves marker data intact;
- forced refresh rebuilds and clears prior marker data;
- production `db:wipe` refuses without `--force` even when interactive IO would confirm;
- production detection is based on the real `app.env` key used by `ConfigRepository::isProduction()`;
- DBLayer facade state is purged between dispatcher executions so test applications do not leak connection state.

This is **prepared coverage, not executed evidence**. Checklist items 11–13 remain open until an authoritative run proves the tests and command behavior.

### Omnibus 2.5 source compatibility

Foundation remains compatible with:

- `Consumer` and execution scope;
- `Worker` / `WorkerOptions`;
- `WorkerLifecycle::heartbeat()` / `stopRequested()`;
- `WorkerPool`;
- `HandlerInvoker` middleware;
- `FailureManager::retry()`, `forget()`, `prune()`, `flush()`;
- `FailureStore` retry-claim lifecycle.

Foundation continues to delegate transport, reservation, retry, failure, worker-loop, pool and workflow mechanics to Omnibus. There is no second Foundation queue/workflow engine.

Prepared Omnibus coverage includes:

- failure retry-claim/send/removal, prune, forget and flush;
- Foundation-to-Omnibus `WorkerLifecycle` callback wiring;
- existing memory dispatch/consume, routing, retry/release/failure storage, job middleware and metadata tests;
- existing bounded worker and optional `WorkerPool` tests.

Checklist item 25 remains open until runtime proof exists.

## Historical QA context — not authoritative

The 2026-08-24 `test_details_5` run predates this dependency rebaseline:

- PHP 8.4.24 / Composer 2.10.2;
- syntax 564/564;
- PHPUnit 133 passed / 1 failed / 5 skipped / 734 assertions;
- Pint/PHPCS/Psalm/Deptrac passed that snapshot;
- historical PHPStan count was 40.

The old logging-ignore failure is already corrected in current source, but all current results must be regenerated. Do not use the historical PHPStan count as the current defect list.

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

Items remain evidence-driven and unchecked until an authoritative execution proves them.

1. [x] Freeze Foundation 2.0 architecture/public ownership boundaries.
2. [x] Freeze narrow `Application` API and remove retired convenience proxies/managers.
3. [x] Align Composer capability baseline to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
4. [x] Align `ModuleCatalog` constraints with Composer baseline.
5. [x] Normalize PHPForge reusable-workflow configuration to repository-specific overrides only.
6. [x] Production clean-install gate passed on the pre-rebaseline set; **rerun required at final closure**.
7. [x] PHP 8.4 representative benchmark passed pre-rebaseline; **rerun required**.
8. [x] PHP 8.5 representative benchmark passed pre-rebaseline; **rerun required**.
9. [x] Psalm passed pre-rebaseline; **rerun required**.
10. [x] Deptrac passed pre-rebaseline; **rerun required**.
11. [ ] Clear current syntax/PHPProbe/PHPUnit blocking defects after dependency rebaseline.
12. [ ] Execute DBLayer 5 migration up/down/pretend/rollback-batch/status/reset/refresh/wipe/monitor matrix.
13. [ ] Execute destructive database safeguard/confirmation matrix.
14. [ ] Verify module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync plus dry-run/duplicate/failure rollback behavior.
15. [ ] Verify optional capability isolation and graceful unavailable-capability errors.
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
29. [ ] Clear Pint/Rector/Composer Normalize/PHPCS/PHPStan and retain Psalm/Deptrac green on PHP 8.4/8.5 lowest/stable with zero unjustified skips.
30. [ ] Rerun representative benchmarks and review soak-sensitive persistent-runtime paths.
31. [ ] Final dependency/package/stale-version/retired-API audit plus Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results with zero ambiguous closure items.

## Immediate next work

1. remove remaining stale textual references to DBLayer 4.1 / Omnibus 2.4 (known examples: one database test description and the messaging-worker missing-package error path);
2. obtain fresh executable evidence for the DBLayer 5, destructive-command and Omnibus 2.5 compatibility tests;
3. regenerate PHPUnit/PHPStan/PHPForge results from current HEAD and use those—not historical counts—as the defect list;
4. fix current defects semantically, then continue module/runtime/auth/security/operations matrices.

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
