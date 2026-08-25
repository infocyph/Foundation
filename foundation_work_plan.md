# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file is the evidence-driven source of truth for the current Foundation 2.0 implementation and release closure.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable framework/runtime layer. Infbyte is the opinionated application skeleton built on it.

## Active closure run

- Started: 2026-08-24 (Asia/Dhaka)
- Original closure-run checkpoint: `16d60f114314544a5c6db91c0e986423fa6fbb70`
- Dependency-rebaseline starting checkpoint: `6663dad26e75453fcebb7975dda2ad0b49661951`
- Latest implementation/documentation checkpoint before this plan update: `a3b1087c6443ffdc5b4db005f8b858f9acf6a588`
- Current phase: **DBLayer 5 / Omnibus 2.5 / ReqShield 3.1 rebaseline + verification closure**.
- Architecture/public ownership boundaries remain frozen. This run may correct implementation, integration, tests, diagnostics, and documentation; it must not restore retired convenience APIs or duplicate specialist engines.
- Finish condition: updated dependency set resolves, PHPForge/QA/static analysis and PHPUnit matrices pass, specialist/runtime/security/process/deployment matrices are evidenced, Infbyte is aligned, representative benchmarks remain acceptable, and every checklist item below has explicit evidence.

### Closure order

1. complete DBLayer 5 / Omnibus 2.5 / ReqShield 3.1 integration rebaseline;
2. verify database/messaging/worker destructive and lifecycle behavior;
3. clear semantic/runtime blockers and regenerate the authoritative PHPUnit/PHPStan state;
4. clear PHPForge formatting/refactor/sniff/Composer/static-analysis gates;
5. run PHP 8.4/8.5 × lowest/stable matrices plus configured service-backed tests;
6. verify Web/CLI/Worker/Scheduler/auth/security/module/operations matrices;
7. rerun representative benchmarks and soak-sensitive paths;
8. perform final dependency/retired-API/Infbyte/release audit and record exact final checkpoints.

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

`ModuleCatalog` must remain aligned with this baseline. Stable repository refs were verified directly for DBLayer `5.0`, Omnibus `2.5`, and ReqShield `3.1`.

## 2026-08-25 dependency/integration rebaseline evidence

### Metadata and capability boundaries

- `composer.json` moved to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
- `ModuleCatalog` uses the same constraints.
- Bootstrapper's stale Omnibus `^2.4` capability error was updated to `^2.5`.
- Public database/messaging documentation is now written against DBLayer 5 and Omnibus 2.5.
- `docs/console.md` now identifies native single-worker lifecycle integration as Omnibus 2.5.

### DBLayer 5 source compatibility

Source comparison confirms Foundation still uses supported DBLayer 5 APIs:

- `ConnectionConfig::fromArray()`;
- `DB::setDefaultConnection()`, `DB::hasConnection()`, `DB::addConnection()`, `DB::connection()`;
- `Connection::query()`, `transactionLevel()`, `rollbackTransaction()`, `resetRuntimeStateForReuse()`, `disconnect()`;
- `DB::getConnections()` and `DB::resetRuntimeState(false)`;
- `MigrationRunner` construction, `run()`, `pretend()`, `status()`, `rollback()`, `rollbackBatch()`, `fresh()`, `refresh()`, `reset()`;
- `DatabaseMonitor` status/sessions/queries/locks/tables/indexes/replication/snapshot surfaces;
- `SchemaManager::dropAllTables(true)` for explicitly authorized wipe behavior.

No Foundation repository/ORM rewrite is required. DBLayer remains the owner of database infrastructure.

### ReqShield 3.1 + DBLayer 5 validation adapter

ReqShield 3.1 preserves its minimal database boundary:

- `DatabaseProvider::batchExists()`;
- `DatabaseProvider::batchUnique()`.

A concrete Foundation defect was corrected: `ReqShieldDatabaseProvider` previously sent an entire logical value group into one DBLayer `whereIn()`. DBLayer 5 enforces configured/driver bind ceilings, so a large wildcard/batch could fail.

The adapter now:

- uses `Connection::safeBatchSize()` for physical DB chunks;
- counts the unique-ignore predicate as a fixed binding;
- preserves ReqShield's logical validation batch semantics;
- keeps null lookup handling separate;
- does not introduce repositories, collections, cache, or another validation abstraction.

`ReqShieldDatabaseValidationTest` now configures a deliberately small DBLayer `security.max_params` ceiling and forces multi-chunk exists/unique-ignore behavior.

### DBLayer 5 compatibility coverage prepared

`DBLayer5MigrationCompatibilityTest` now explicitly covers:

- `pretend()` returning pending migration SQL without creating tables;
- step-mode migration batches;
- status batch reporting;
- exact `rollbackBatch()` selection;
- normal latest-batch rollback;
- `fresh(true)`;
- `refresh(true)`;
- `reset(true)` and reverse rollback order;
- `DatabaseMonitor::status()` / `snapshot()` shape;
- `SchemaManager::dropAllTables(true)` wipe behavior.

`CurrentInfocyphApiCompatibilityTest` now targets DBLayer 5 and includes bind-sizing/monitoring probes.

These tests are **prepared evidence, not executed evidence**. Checklist items 11–13 remain open until an authoritative run proves them and command-level destructive safeguards are exercised.

### Omnibus 2.5 source compatibility

Source comparison confirms Foundation's current integration remains compatible with:

- `Consumer` constructor and execution scope;
- `Worker` constructor;
- `WorkerOptions` fields;
- `WorkerLifecycle::heartbeat()` / `stopRequested()`;
- `WorkerPool` constructor and Unix process-pool ownership;
- `HandlerInvoker` middleware pipeline;
- `FailureManager::retry()`, `forget()`, `prune()`, `flush()`;
- `FailureStore` retry-claim lifecycle.

Foundation continues to delegate delivery, retry, reservation, failure-store, worker-loop, and process-pool mechanics to Omnibus. There is no second Foundation workflow or queue engine.

### Omnibus 2.5 compatibility coverage prepared

- Commit `fa6dc9a77d9c8a249696d870def7f5ef60c4ab4a` adds failure lifecycle coverage for retry-claim/send/removal, prune, forget, and flush.
- Commit `ff5d95bd798f3b2bd01bb800ea5ed5c045240235` adds a Foundation worker integration probe proving `WorkerLifecycle` callbacks are passed into the Omnibus worker.
- Existing messaging tests already cover memory dispatch/consume, routing, retry/release/failure storage, job middleware, and metadata.
- Existing worker integration already covers bounded workers and optional Unix `WorkerPool` behavior.

These tests are also **prepared evidence, not executed evidence**. Checklist item 25 remains open pending runtime proof.

## Historical closure evidence — 2026-08-24 (`test_details_5`)

This report predates the current dependency rebaseline and is retained only as historical context:

- PHP `8.4.24`; Composer `2.10.2`.
- Syntax/PHPProbe: `564/564` PHP files passed.
- PHPUnit/Pest: `133 passed`, `1 failed`, `5 skipped`, `734 assertions`.
- The historical remaining test failure was `RuntimeCapabilityConfigTest` for invalid `logging.exceptions.ignore`; current source already contains the intended `Throwable` validation, but a new run is required.
- Five service-backed browser-session lock-contention datasets were skipped because Redis, Valkey, Memcached, MySQL PDO, and PostgreSQL PDO lock backends were not configured in that local run.
- Pint and PHPCS passed that snapshot; duplicate-code probe passed at `3.64%`; Psalm and Deptrac passed.
- PHPStan reported 40 errors in that historical snapshot. Current source is materially newer, so the count must be regenerated and must not be used as the current defect list.

No current checklist gate is closed from this historical report alone.

# Frozen architecture

## Runtimes

Exactly four Foundation runtimes exist:

- Web
- CLI
- Worker
- Scheduler

No `FoundationConsole`, `Foundation::console()`, `src/Console` hierarchy, runtime inference from `PHP_SAPI`, static global application state, generic IdentifierManager, or compatibility request-scope manager is permitted.

## Application surface

`Application` remains narrow:

- boot/runtime state;
- `config()` / `container()` / `providers()`;
- generic DI through `make()` / `has()`;
- `execution()`;
- canonical Web handling;
- Foundation application paths.

Do not add broad auth/cache/database/filesystem/messaging/security/testing/response facades.

## Specialist ownership

- InterMix: DI/lifetimes/scopes.
- Webrick: HTTP/router/request/response/emission.
- UID: identifier algorithms.
- DBLayer: connection/query/schema/migration/database transaction infrastructure.
- CacheLayer: cache/lock/counter/node/cluster coordination.
- Omnibus: message transport/handler/retry/failure/worker/pool/workflow engines.
- TalkingBytes: HTTP/email/webhook/gRPC protocols.
- ReqShield: validation mechanics.
- Epicrypt: cryptographic primitives.
- Pathwise/Flysystem: filesystem/storage mechanics.
- OTP: OTP algorithms and replay primitives.
- WebAuthn library: WebAuthn protocol validation.

Foundation adds only application policy/composition where the framework owns a real responsibility.

# Frozen purpose-first modules

| Module | Backing package(s) |
|---|---|
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^5.0` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built in |
| `messaging` | `infocyph/omnibus ^2.5` |
| `operations` | built in |
| `resources` | built in |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built in |
| `validation` | `infocyph/reqshield ^3.1` |

Canonical aliases remain purpose-first (`db|dblayer -> database`, `events|omnibus|queue|queues -> messaging`, `reqshield|validator -> validation`, etc.). No standalone OTP/passkey public modules.

# Frozen config/schema lifecycle

Public module operations remain:

- `module:list`
- `module:show`
- `module:install`
- `module:remove`
- `module:config:publish`
- `module:schema:status`
- `module:schema:install`
- `module:schema:sync`

Schema owners:

- `auth` → Foundation auth schema;
- `cache` → CacheLayer public provisioners;
- `session` → Foundation database-session schema.

The `database` module owns database infrastructure, not arbitrary application tables. `module:remove` never deletes schema/data.

# Frozen application contracts

## Validation

- `FormRequest` composes Webrick input into ReqShield.
- Custom validation rules implement ReqShield `Contracts\Rule` directly.

## Notifications/mail

Foundation application routing contracts remain `Notification`, `NotificationRecipient`, `NotificationChannel`, `NotificationChannelRegistry`, `NotificationDispatcher`; TalkingBytes backs mail/protocol integration.

## Messaging/jobs

Foundation application-facing contracts remain `Job`, `JobContext`, `JobMiddleware`, `JobMiddlewarePipeline`. Omnibus `HandlerInvoker` remains the single sync/async handler execution point. Omnibus `Envelope` / `HandlerContext` must not leak into Foundation `JobMiddleware`.

## Resources/testing

`JsonResource::resolve(): mixed` remains the application resource contract. Testing/auth/response services are resolved through DI; retired `Application` convenience proxies stay removed.

# Runtime invariants already frozen

- one InterMix execution scope per execution unit;
- targeted `RuntimeContextTracker` cleanup with the application exception remaining primary;
- scheduler lease refresh during child execution and stable schedule identity;
- single messaging workers use native Omnibus lifecycle callbacks;
- process registry is heartbeat observability, not supervisor truth;
- provider-only workers remain messaging-lazy;
- DB provider activation does not wake CacheLayer accidentally;
- persistent DB runtime state is reset between execution units;
- storage unlink remains symlink/target safe;
- environment encryption uses external key material and rollback-safe publication.

# 32-point verification / release closure checklist

Items remain evidence-driven and unchecked until an authoritative execution proves them.

1. [x] Freeze Foundation 2.0 architecture/public ownership boundaries.
2. [x] Freeze narrow `Application` API and remove retired convenience proxies/managers.
3. [x] Align Composer capability baseline to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
4. [x] Align `ModuleCatalog` package constraints with Composer baseline.
5. [x] Normalize Foundation PHPForge reusable-workflow configuration to repository-specific overrides only.
6. [x] Verify production clean install with `--no-dev --classmap-authoritative` and platform checks on the pre-rebaseline set; **must be rerun for final release alignment**.
7. [x] Verify PHP 8.4 representative benchmark gate on the pre-rebaseline set; **rerun required at final closure**.
8. [x] Verify PHP 8.5 representative benchmark gate on the pre-rebaseline set; **rerun required at final closure**.
9. [x] Verify Psalm static-analysis gate on the pre-rebaseline set; **rerun required at final closure**.
10. [x] Verify Deptrac architecture gate on the pre-rebaseline set; **rerun required at final closure**.
11. [ ] Clear all syntax/PHPProbe/PHPUnit blocking defects after dependency rebaseline.
12. [ ] Execute DBLayer 5 migration up/down/pretend/rollback-batch/status/reset/refresh/wipe/monitor matrix, including exact batch rollback and no mutation during pretend.
13. [ ] Preserve and execute destructive database-operation safeguards/confirmation behavior.
14. [ ] Verify module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync and dry-run/duplicate/failure rollback behavior.
15. [ ] Verify optional capability isolation and graceful unavailable-capability errors.
16. [ ] Verify Web runtime routes/cache/middleware/session/auth-principal/maintenance/exception behavior.
17. [ ] Verify CLI discovery/cache/status/exit/overlap/global options/help/completion/machine-readable/destructive confirmation behavior.
18. [ ] Verify Worker execution-scope reset/restart/heartbeat/singleton/pools/fork-before-resource-open behavior.
19. [ ] Verify Scheduler once/work loop/interrupt/runtime-reload/overlap/scheduled-message-dispatch behavior without forbidden blocking APIs.
20. [ ] Verify no persistent execution-state leaks across InterMix scopes/principal/session/DB/cache/messaging.
21. [ ] Verify pool/fork safety: no pre-fork DB/network resources; child initialization/cleanup/reaping/termination.
22. [ ] Verify full auth/session/token/password/email/passwordless/lockout flows.
23. [ ] Verify MFA recovery/passkey/step-up/recent-auth/authorization/impersonation flows.
24. [ ] Verify production security posture: secrets, secure defaults, OTP/WebAuthn/shared-state topology, unsafe local-memory rejection.
25. [ ] Execute Omnibus 2.5 dispatch/consume/retry/failure-management/prune/monitor/execution-scope/shutdown/restart matrix.
26. [ ] Verify config/route/command/schedule/container cache optimize/clear idempotency.
27. [ ] Verify maintenance/runtime reload/worker restart/scheduler interrupt/process-registry/status/stale-cleanup operations.
28. [ ] Verify environment encrypt/decrypt/temp-file/permissions/overwrite and storage link/unlink safety.
29. [ ] Clear Pint/Rector/Composer Normalize/PHPCS/PHPStan and retain Psalm/Deptrac green across PHP 8.4/8.5 lowest/stable QA matrices with zero unjustified skips.
30. [ ] Re-run representative benchmarks, compare regression threshold, and review soak-sensitive persistent-runtime paths.
31. [ ] Perform final dependency/package audit, stale-version/retired-API/manager grep, and Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results in this plan; leave zero ambiguous/open closure items.

## Immediate next work

1. clean remaining stale source/test references to DBLayer 4.1 / Omnibus 2.4 (notably the messaging-worker runtime error path and any historical test labels);
2. add/verify command-level destructive database safeguards for `db:wipe`, `migrate:fresh`, `migrate:refresh`, and `migrate:reset`, including non-interactive refusal without `--force`;
3. complete Omnibus 2.5 command-facing failure/monitor/restart verification without adding a second workflow abstraction;
4. obtain an authoritative executable run for the newly added compatibility tests;
5. regenerate PHPUnit/PHPStan/PHPForge results from current HEAD and use those results—not the historical 2026-08-24 counts—as the next defect list.

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
