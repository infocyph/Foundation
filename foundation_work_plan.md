# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file tracks the current implementation/release state.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable framework/runtime layer. Infbyte is the opinionated application skeleton built on it.

## Active closure run — STARTED

- Started: 2026-08-24 (Asia/Dhaka)
- Starting Foundation head: `16d60f114314544a5c6db91c0e986423fa6fbb70`
- Scope: resolve every remaining verification defect and close the full 32-point Foundation 2.0 release checklist.
- Architecture status: frozen; this run is verification/correction only. Removed convenience APIs/managers are not to be restored.
- Required finish condition: PHPForge/QA/static analysis and PHPUnit matrix green, specialist integration matrices verified, release/deployment checks verified, Infbyte alignment checked, and this plan updated again with final evidence.
- Current execution state: **IN PROGRESS**.

### Closure order

1. clear semantic/runtime blockers (OTP recovery metadata, scheduler control/waiting);
2. clear PHPForge formatting/refactor/sniff/Composer/static-analysis gates;
3. rerun PHP 8.4/8.5 × lowest/stable tests with zero unjustified skips;
4. verify DBLayer/module/runtime/auth/messaging/security/process/deployment matrices;
5. verify benchmarks/soak-sensitive paths and dependency/release alignment;
6. perform final retired-API grep, update Foundation + Infbyte release state, and record exact completion evidence here.

## Current checkpoint

- Date: 2026-08-24
- Foundation source checkpoint before closure run: `16d60f114314544a5c6db91c0e986423fa6fbb70`
- Foundation documentation checkpoint: `944220490e1c28e9945fd398265dc9d072eb4c93`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Infbyte documentation checkpoint: `26a35da0926285119c31ed880bf1f5aa06f3cf19`
- Current phase: **verification/closure in progress**.
- Application-contract/API cleanup: complete.
- Documentation reconciliation: complete for the public architecture/module/runtime/operations/auth/database/messaging/application-contract surfaces.
- CI facts already established before this closure run: clean production install passes; PHP 8.4/8.5 representative benchmarks pass; Psalm passes; Deptrac passes; service startup for MySQL/PostgreSQL/SQLite/Redis/Valkey/Memcached passes.
- Known blocker already corrected before this marker: duplicate OTP recovery `metadata()` declaration fatal.
- Remaining gates are tracked by the 32-point checklist below and may only be checked off with verification evidence.

## Latest closure evidence — 2026-08-24 (`test_details_5`)

- Runtime: PHP `8.4.24`; Composer `2.10.2`.
- Syntax/PHPProbe: `564/564` PHP files pass syntax checking.
- PHPUnit/Pest: `133 passed`, `1 failed`, `5 skipped`, `734 assertions`. The earlier migration-class validation failure has been cleared; the remaining failure is `RuntimeCapabilityConfigTest` expecting `logging.exceptions.ignore` to reject `Missing\Exception`. Checklist item 11 therefore remains open.
- The five skipped tests are live browser-session lock-contention datasets for Redis, Valkey, Memcached, MySQL PDO, and PostgreSQL PDO because those lock backends are not configured in this local environment. They still require configured matrix evidence before closure.
- Pint: `523` files pass. PHPCS: `554` files complete with no reported violations.
- Duplicate-code probe: PASS at `3.64%` (`42` clone groups, `1729` duplicated lines).
- Comment policy: PASS under the configured gate with `1256` INFO findings across `239` files; these are predominantly missing PHPDoc parameter annotations and are not current blocking defects.
- Deptrac: `0` violations / `0` warnings / `0` errors. Psalm: no errors. Rector: completed. Composer Normalize: already normalized.
- PHPStan remains a blocking gate with `40` errors: Bootstrapper `1`; CacheLayerFactory `6`; DatabaseSystemCommand `5`; MessagingSystemCommand `2`; ModuleSystemCommand `1`; OperationsSystemCommand `3`; RuntimeSystemCommand `13`; ConfigValidator `5`; ModuleManager `2`; MiddlewareConfigValidator `2`. These are primarily mixed/list typing plus cognitive-complexity violations. Checklist item 29 remains open.
- No additional checklist item is marked complete from this report alone; specialist/runtime matrices remain evidence-driven.
- Immediate closure priority: fix `RuntimeLoggingValidator` so configured ignored exception entries must resolve to valid `Throwable` types, rerun the focused test, then clear the PHPStan set before the next authoritative full details run.

# Dependency baseline

## Core

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

## Optional/dev capabilities

- `infocyph/cachelayer ^3.2.0`
- `infocyph/dblayer ^4.1`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.4`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.0.2`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

`ModuleCatalog` constraints stay aligned with this baseline.

# Frozen architecture

## Runtimes

Exactly four Foundation runtimes exist:

- Web
- CLI
- Worker
- Scheduler

No `FoundationConsole`, `Foundation::console()`, `src/Console` hierarchy, runtime inference from `PHP_SAPI`, static global application state, generic IdentifierManager, or `app.container.request_scope` compatibility layer is permitted.

## Application surface

`Application` is intentionally a narrow runtime/composition object. Its stable categories are:

- boot/runtime state;
- `config()` / `container()` / `providers()`;
- generic DI resolution through `make()` / `has()`;
- `execution()`;
- canonical Web entry through `handle()` / `http()`;
- Foundation application path methods.

Auth, session, router, response and testing convenience proxies are removed. Consumers resolve concrete services through DI. Do not add broad cache/database/filesystem/messaging/security/application facades.

## Specialist ownership

- InterMix owns DI/lifetimes/scopes.
- Webrick owns HTTP/router/request/response/emission engines.
- UID owns identifier algorithms.
- DBLayer owns DB/query/schema/migration engines.
- CacheLayer owns cache/lock/counter/node/cluster engines.
- Omnibus owns message transport/handlers/retry/failure/workers/pools.
- TalkingBytes owns HTTP/email/webhook/gRPC protocol engines.
- ReqShield owns validation mechanics.
- Epicrypt owns cryptographic primitives.
- Pathwise/Flysystem own generic filesystem/storage behavior.
- OTP owns OTP algorithms/replay primitives.
- WebAuthn library owns WebAuthn protocol validation.

Foundation adds application policy/composition only where it owns a real framework responsibility.

# Frozen purpose-first modules

| Module | Backing package(s) |
|---|---|
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built in |
| `messaging` | `infocyph/omnibus ^2.4` |
| `operations` | built in |
| `resources` | built in |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built in |
| `validation` | `infocyph/reqshield ^3.0.2` |

Canonical aliases:

- `db|dblayer -> database`
- `crypto|epicrypt -> security`
- `otp|mfa|passkey|passkeys|webauthn -> auth`
- `notifications|talkingbytes -> communication`
- `events|omnibus|queue|queues -> messaging`
- `files|pathwise|storage -> filesystem`
- `reqshield|validator -> validation`
- `ops|runtime -> operations`

No standalone OTP/passkey public modules.

# Frozen config/schema lifecycle

Public module operations:

- `module:list`
- `module:show <module>`
- `module:install <module>`
- `module:remove <module>`
- `module:config:publish <module> [--force]`
- `module:schema:status <module> [--connection=...]`
- `module:schema:install <module> [--connection=...]`
- `module:schema:sync [--connection=...]`

Schema owners:

- `auth` -> Foundation auth schema;
- `cache` -> CacheLayer public PDO/SQLite/invalidation schema provisioners;
- `session` -> Foundation database-session schema.

`database` owns DB/migration infrastructure, not arbitrary application tables. `module:remove` never drops schema/data. Schema status is observational and must not create a missing SQLite cache DB.

Force config publication stages replacement/backup transactionally, refuses symlink targets, and treats post-commit backup cleanup as best-effort finalization rather than a reason to destructively roll back a successful publication.

# Frozen application contracts

## Validation

- Foundation `FormRequest` composes Webrick request input into ReqShield.
- Custom validation rules implement ReqShield `Contracts\Rule` directly.
- Generators: `create:request`, `create:rule`.

## Notifications/mail

Foundation application routing contracts:

- `Notification`
- `NotificationRecipient`
- `NotificationChannel`
- `NotificationChannelRegistry`
- `NotificationDispatcher`

TalkingBytes-backed mail integration:

- `MailMessage`
- `Mailer`
- `MailNotificationChannel`

Generators: `create:mail`, `create:notification`, `create:notification-channel`.

## Messaging/jobs

- `Job`
- `JobContext`
- `JobMiddleware`
- `JobMiddlewarePipeline`

Omnibus `HandlerInvoker` remains the single sync/async handler execution point. Generators: `create:job`, `create:handler`, `create:job-middleware`.

## Resources/testing

- `JsonResource::resolve(): mixed` is the application resource contract.
- `create:resource` targets that contract.
- `TestKit` is resolved through DI; there is no `Application::testing()` facade.
- JsonDispatch response factory is resolved through DI; there is no `Application::responses()` facade.
- `AuthServices` is resolved through DI; there is no `Application::auth()` facade.

# Runtime correctness completed before freeze

## Execution cleanup

- one InterMix execution scope per execution unit;
- targeted `RuntimeContextTracker` cleanup;
- original application exception remains primary if cleanup also fails;
- reset and scope-leave are both attempted.

## Scheduling

- overlap/single-server lease refresh continues during child execution;
- lost lease -> heartbeat loss -> terminate child -> failed run;
- `schedule:test` returns real failure status;
- every schedule history transition carries stable `schedule_identity`;
- `schedule:list` resolves last state by identity, not only command text.

## Workers / Omnibus 2.4

- Bootstrapper probes `WorkerLifecycle` as the Omnibus 2.4 capability boundary;
- single messaging workers use native Omnibus lifecycle heartbeat/stop callbacks and do not require `pcntl` solely for generation polling;
- Omnibus `WorkerPool` remains Unix/pcntl/posix based and retains the Unix watchdog;
- provider-only workers remain messaging-lazy;
- provider singleton workers refresh CacheLayer ownership through `WorkerRuntime::heartbeat()`.

## Runtime control/process registry

- file runtime-control state uses stable lock + atomic replacement;
- cache runtime-control state uses CacheLayer coordination for one read/modify/write transaction;
- concurrent generation signals cannot silently overwrite one another;
- runtime registry visibility is `host|shared`, default `host`;
- registry records are heartbeat observability, not supervisor truth;
- `worker:status` reports registry visibility.

## Other correctness work

- DB provider activation no longer wakes CacheLayer accidentally;
- supervised child `--profile` output is suppressed so only parent profiles;
- `config:validate --production` passes production intent into OTP topology checks;
- Cache schema readiness is read-only for missing SQLite files;
- `log:tail --follow` handles truncation and replacement/rotation;
- `AuthPruner` matches the current auth schema;
- DBLayer 4.1 `MigrationRunner::pretend()` return shape is used directly;
- storage unlink remains symlink/target safe;
- environment encryption uses external key material and rollback-safe publication.

# Frozen CLI families

Current public command families are source-of-truth in `CommandCatalog` and documentation. Major surfaces:

- application: `about`, `app:install`, `app:ready`, `env:show`, `serve`
- config/cache: `config:*`, `cache:*`, `command:*`
- database: `db:*`, `migrate*`
- modules: `module:*`
- operations: `execution:*`, `maintenance:*`, `runtime:reload`, `log:tail`, env protect
- messaging: `messaging:list`, `queue:*`, `schedule:dispatch-message`
- scheduling: `schedule:*`
- workers: `worker:list|run|restart|status`
- storage/session/auth operational commands
- optimization: `optimize`, `optimize:clear`, `optimize:report`
- `create:*` generators backed by real framework/package contracts.

Do not add command-for-command Laravel parity or commands that duplicate specialist engines.

# Documentation reconciliation completed

Updated/reconciled public docs include:

- root `README.md`;
- architecture/runtime ownership;
- retired Console parity closure record;
- modules/configuration;
- CLI/scheduler/workers;
- operations/runtime control;
- database/migrations/schema lifecycle;
- messaging/Omnibus 2.4;
- authentication/OTP/browser sessions;
- security;
- HTTP/capability ownership;
- testing/resource DI examples;
- Infbyte skeleton README.

Important removed stale references:

- `Foundation::console()` / Console-owned runtime architecture;
- arbitrary `foundation-module.php` package discovery;
- standalone OTP/passkey public modules;
- `auth:schema:*` / `session:schema:*` command families;
- `$app->auth()` / `$app->responses()` / `$app->testing()` convenience proxies;
- `$app->messaging()` forwarding manager;
- nonexistent generic Composer release/test scripts;
- old Omnibus 2.3 / CacheLayer 3.1 dependency wording;
- old `router.route_files` key.

# Infbyte boundary

Infbyte remains intentionally lean:

- root `infbyte` delegates directly to Foundation `CommandDispatcher`;
- Web bootstrap is one `Foundation::web()` call;
- checked-in config remains only `app.php`, `auth.php`, `router.php`;
- optional capability config is publish-on-demand;
- no Foundation runtime/messaging/notification/validation/schema engine is copied into Infbyte;
- `.env.example` contains no environment-encryption key;
- generated optimized artifacts are not committed.

# 32-point verification / release closure checklist

Status is deliberately evidence-driven. Items stay unchecked until this run proves them.

1. [x] Freeze Foundation 2.0 architecture/public ownership boundaries.
2. [x] Freeze narrow `Application` API and remove retired convenience proxies/managers.
3. [x] Align Composer capability baseline, including ReqShield `^3.0.2`.
4. [x] Align `ModuleCatalog` package constraints with Composer baseline.
5. [x] Normalize Foundation PHPForge reusable-workflow configuration to repository-specific overrides only.
6. [x] Verify production clean install with `--no-dev --classmap-authoritative` and platform checks.
7. [x] Verify PHP 8.4 representative benchmark gate.
8. [x] Verify PHP 8.5 representative benchmark gate.
9. [x] Verify Psalm static analysis gate.
10. [x] Verify Deptrac architecture gate.
11. [ ] Clear all syntax/PHPProbe/PHPUnit blocking defects after OTP recovery-store correction.
12. [ ] Verify DBLayer 4.1 migration up/down/pretend/rollback-batch/status/reset/refresh/wipe/monitor behavior, including exact batch rollback and no mutation during pretend.
13. [ ] Preserve/verify destructive database-operation safeguards.
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
25. [ ] Verify Omnibus dispatch/consume/retry/failure-management/prune/monitor/execution-scope/shutdown/restart behavior.
26. [ ] Verify config/route/command/schedule/container cache optimize/clear idempotency.
27. [ ] Verify maintenance/runtime reload/worker restart/scheduler interrupt/process-registry/status/stale-cleanup operations.
28. [ ] Verify environment encrypt/decrypt/temp-file/permissions/overwrite and storage link/unlink safety.
29. [ ] Clear Pint/Rector/Composer Normalize/PHPCS/PHPStan and retain Psalm/Deptrac green across PHP 8.4/8.5 lowest/stable QA matrices with zero unjustified skips.
30. [ ] Re-run representative benchmarks, compare regression threshold, and review soak-sensitive persistent runtime paths.
31. [ ] Perform final dependency/package audit, retired-API/manager grep, and Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results in this plan; leave zero ambiguous/open closure items.

# Verification status before this closure run

Completed evidence already available:

- Composer dependency resolution succeeds for PHP 8.4/8.5 matrices.
- Clean production install passes with no dev packages and classmap-authoritative autoloading.
- Stable runtime constraint guard passes.
- MySQL/PostgreSQL/SQLite/Redis/Valkey/Memcached CI services start successfully.
- Representative benchmark gate passes on PHP 8.4 and PHP 8.5.
- Psalm reports zero findings.
- Deptrac passes.
- OTP duplicate `metadata()` fatal was identified and corrected while preserving the public recovery-store contract.

Known work entering this run:

- scheduler work-loop must preserve both runtime reload and schedule interrupt semantics while eliminating forbidden `sleep()` usage;
- Pint/PHPCS/Rector/Composer Normalize findings need to be resolved semantically, not blindly suppressed;
- PHPStan must be reassessed after syntax blockers are removed;
- full PHPUnit/integration and specialist/runtime/security matrices must be closed;
- final Infbyte/deployment/release alignment remains to be evidenced.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkey modules;
- no duplicate specialist schema command families;
- no schema/data deletion during module removal;
- no copied specialist SQL/transport/retry/cache/database engines;
- no unsafe lock fallback;
- no broad `Application` service facade;
- no second messaging/retry/failure/worker engine above Omnibus;
- no Omnibus Envelope/HandlerContext leakage into Foundation JobMiddleware;
- no retired Console runtime hierarchy;
- no static global application state;
- no generic IdentifierManager/request scope;
- no bulk optional-config copy into Infbyte;
- no environment-protection key inside `.env`/`.env.example`;
- no generated optimized artifacts committed.
