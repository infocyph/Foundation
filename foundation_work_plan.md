# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable runtime/framework layer. Infbyte is the opinionated application skeleton built on it.

## Maintenance rule

After each joint batch:

1. record Foundation and Infbyte source checkpoints separately from tracker-only commits;
2. fix framework defects in Foundation rather than working around them in Infbyte;
3. keep specialist engines and schema grammar in their owning packages;
4. keep public framework modules purpose-oriented rather than package-oriented;
5. keep full tests/release gates deferred until implementation/config/docs are stable.

# Current checkpoint

- Date: 2026-08-24
- Foundation source checkpoint: `65a60e9874786c8b260e774e074a309268692ad2`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Current phase: **documentation reconciliation + public-name/config freeze**.
- Latest completed cleanup: **runtime/scheduler correctness + application-contract/API freeze review**.
- Full PHPUnit/static-analysis/PHPForge/release matrix: not run yet.

# Current dependency baseline

## Core

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

## Optional/dev capability packages

- `infocyph/cachelayer ^3.2.0`
- `infocyph/dblayer ^4.1`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.4`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.0.1`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

# Fixed architecture

## Runtime/framework boundary

- exactly four runtimes: Web, CLI, Worker, Scheduler;
- no retired Console hierarchy or compatibility layer;
- InterMix owns DI/container mechanics; Foundation owns runtime composition/lifecycle policy;
- UID is the canonical generated-ID provider;
- config/command/schedule/route/container optimized artifacts are deployment-owned;
- specialist libraries retain their own engines, schema implementations, and public implementation APIs;
- Infbyte does not rebuild Foundation runtime machinery;
- no broad specialist forwarding managers/facades on `Application`.

## Configuration/runtime lifecycle

- no `app.container.request_scope`;
- lazy loading is the normal/default path;
- global config helpers are limited to `env()`, `env_bool()`, `env_int()`, `env_string()`;
- application paths remain declarative rather than global state;
- CacheLayer coordination inherits the selected store's native lock unless explicitly overridden;
- optional package presence remains distinct from configured/activated capability;
- execution cleanup stays targeted through execution scopes/runtime context tracking.

# Completed joint migration baseline

- Infbyte branch targets Foundation 2.0 during development;
- root Infbyte CLI is a thin Foundation `CommandDispatcher` delegator;
- Foundation preflight accepts application display-name metadata without booting Application;
- Infbyte Web bootstrap is one `Foundation::web()` call;
- CLI/Worker/Scheduler runtime selection is owned by Foundation `CommandDispatcher`;
- provider groups are `common|web|cli|worker|scheduler`;
- old Console/IdentifierManager/request-scope surfaces are gone;
- Infbyte checked-in config remains lean: `app`, `auth`, `router`;
- module/package mutations invalidate compiled container/optimize state;
- route files use the loader-provided scoped Webrick registrar;
- generated optimized artifacts are not committed.

# Completed cleanup — purpose-first modules

A Foundation module represents an **application purpose/capability**, not a Composer package.

| Module | Backing packages |
|---|---|
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built into Foundation |
| `messaging` | `infocyph/omnibus ^2.4` |
| `operations` | built into Foundation |
| `resources` | built into Foundation |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built into Foundation |
| `validation` | `infocyph/reqshield ^3.0` |

Naming rules:

- `database` is canonical; `db` and `dblayer` are aliases;
- `security` is canonical; `crypto` and `epicrypt` are aliases;
- standalone `otp` and `passkeys` modules are gone;
- `otp|mfa|passkey|passkeys|webauthn` resolve to `auth`;
- `ops|runtime` resolve to built-in `operations`;
- provider dependency errors use canonical purpose-module names.

`ModuleManager` supports multi-package bundles, per-package state, module status `built-in|installed|partial|available`, grouped Composer install, and removal of direct application requirements only.

Runtime readiness remains implementation-exact: enabling OTP does not require WebAuthn at runtime and vice versa.

# Completed cleanup — module config + schema lifecycle

## Module config lifecycle

Public config operations are purpose-oriented:

- `module:config:publish <module>` publishes missing module-owned config;
- `module:config:publish <module> --force` stages the existing file, atomically replaces it, and restores the prior file if publication fails;
- post-commit backup cleanup cannot turn a successful publication into a destructive rollback;
- host config is never silently overwritten by normal `module:install`;
- config publication clears config cache and invalidates compiled runtime state where required.

`module:show <module>` reports package state, publication state, schemas, and schema readiness.

## Public schema rule

A module may declare database schemas that belong to its capability. Foundation orchestrates those schemas but **does not copy specialist-package SQL or privately reimplement it**.

Current schema ownership:

| Module | Schema | Native owner |
|---|---|---|
| `auth` | authentication/accounts/tokens/MFA/passkeys/authorization | Foundation `AuthSchemaInstaller` |
| `cache` | PDO/SQLite cache entries | CacheLayer `PdoCacheSchema` |
| `cache` | PDO cluster invalidation events | CacheLayer `PdoInvalidationSchema` |
| `session` | database session store | Foundation `SessionDatabaseSchema` |

Other modules currently declare no application database schema. The `database` module owns DB/migration infrastructure rather than an application schema of its own.

CacheLayer node/tiered SQLite internals remain CacheLayer-owned self-initializing implementation details because CacheLayer exposes no public schema provisioner for them. Foundation does not duplicate their private SQL.

Schema status/readiness is read-only: checking a missing SQLite cache database reports `pending` and does not create a directory/database file. Explicit schema installation owns creation.

## Public schema commands

Canonical schema lifecycle:

- `module:schema:status <module> [--connection=...]`;
- `module:schema:install <module> [--connection=...]`;
- `module:schema:sync [--connection=...]`.

The duplicate specialized public commands `auth:schema:*` and `session:schema:*` remain removed.

## Install behavior

`module:install <module>` performs one application-level operation:

1. install the module's Composer package bundle when required;
2. publish that module's config without overwriting host config;
3. invalidate compiled container/optimize state after package/config mutation;
4. invoke `module:schema:sync` in a **fresh PHP process** so newly installed Composer namespaces are visible;
5. provision only database schemas required by the active configuration;
6. return schema state in machine-readable output and fail if a configured required schema cannot be made ready.

Install ordering remains safe: installing `database` later synchronizes already-configured database-backed auth/session/cache capabilities.

`module:remove` never drops schemas or application data.

`app:ready` checks exact configured implementation packages plus applicable module-owned database schemas.

# Completed cleanup — application contracts + generators

Application-level contracts now exist only where Foundation adds application composition value over specialist packages.

## Validation/request

- `FormRequest` is Foundation's request-to-ReqShield composition point;
- ReqShield's native `Contracts\Rule` remains the rule contract—Foundation does not wrap or duplicate it;
- `create:request` generates a `FormRequest` subclass;
- `create:rule` generates a class implementing ReqShield `Rule` directly.

## Notifications/mail

- `Notification` returns channel payloads keyed by channel name;
- `NotificationRecipient` supplies application routing per channel;
- `NotificationChannel` is the application channel contract;
- `NotificationDispatcher` resolves channels through `NotificationChannelRegistry`;
- `MailMessage` adds only application sender-profile selection over TalkingBytes `EmailMessage`;
- `Mailer` and `MailNotificationChannel` delegate actual email construction/transport to TalkingBytes;
- `create:mail`, `create:notification`, and `create:notification-channel` generate those real contracts.

## Messaging/jobs

- `Job` is a semantic application data-message marker;
- `JobContext` carries queue/attempt/async execution metadata;
- `JobMiddleware` is the application-facing middleware contract;
- `JobMiddlewarePipeline` adapts it to Omnibus handler middleware;
- `create:job`, `create:handler`, and `create:job-middleware` are backed by these real contracts.

## Resources

- `JsonResource` owns the minimal application resource contract;
- `create:resource` correctly generates `resolve(): mixed` against the current API.

No generator exists solely to imitate Laravel terminology without a real Foundation contract.

# Completed cleanup — Omnibus 2.4 + worker execution

Omnibus 2.4 is the messaging baseline.

Foundation composition rules:

- one shared Omnibus `HandlerInvoker` is constructed by `MessagingServiceProvider`;
- the same invoker is used by `SyncTransport` and queued `Consumer` execution;
- `messaging.handler_middleware` is the ordered low-level Omnibus middleware surface;
- `messaging.job_middleware` is the ordered Foundation application middleware surface and runs only for Foundation `Job` messages;
- raw Omnibus middleware wraps the single Foundation job-middleware adapter;
- ordinary synchronous PSR event listeners do not enter handler/job middleware; queued listeners naturally do when consumed.

Worker lifecycle alignment:

- Bootstrapper probes Omnibus 2.4 `WorkerLifecycle`, not a 2.3-era capability;
- single messaging workers use native Omnibus lifecycle callbacks for Foundation heartbeat/reload handling, including platforms without `pcntl`;
- `WorkerPool` retains the Unix watchdog because the upstream pool itself is Unix/pcntl based;
- provider-only workers do not activate Omnibus merely because `worker:run` can also run messaging workers;
- pooled workers still require scalar/array declarative configuration before fork.

# Completed cleanup — operations/runtime correctness

`operations` owns:

```text
operations.history.*
operations.maintenance.*
operations.runtime_control.*
operations.runtime_registry.*
```

`FoundationDefaults`, publishable `operations.php`, and `RuntimeConfigValidator` are aligned.

## Runtime control

- file-backed runtime-control mutations use a stable lock file plus atomic replacement;
- cache-backed mutations use CacheLayer coordination around one read/modify/write transaction;
- concurrent `runtime:reload`, `worker:restart`, and `schedule:interrupt` operations cannot silently overwrite each other;
- cache-backed runtime control validates state visibility and atomic coordination for the configured deployment topology.

## Runtime registry

- process records remain heartbeat-based observability, not process-supervision authority;
- `operations.runtime_registry.visibility` is explicitly `host|shared`, default `host`;
- host mode ignores records written by other hosts;
- shared mode intentionally aggregates a shared registry directory;
- `worker:status` reports registry visibility.

## Execution scope

- original application exceptions are never replaced by cleanup failures;
- targeted runtime-context reset and InterMix scope exit both still run;
- cleanup failure is thrown only when no application failure already exists.

# Completed cleanup — scheduling correctness

- schedule ownership locks refresh while a child command is running;
- refresh failure becomes `heartbeat_lost`, terminates the child, and fails the run rather than continuing without ownership;
- `schedule:test` returns failure when the scheduled command fails or ownership cannot be acquired;
- schedule execution history records `schedule_identity` on every lifecycle transition;
- `schedule:list` resolves last status by schedule identity, so duplicate command strings cannot cross-contaminate status;
- scheduler/runtime interrupt tokens remain cooperative generation controls.

# Completed cleanup — CLI/process behavior

Global CLI parsing/help supports:

- `-q|--quiet`;
- `--silent`;
- `-v|-vv|-vvv`;
- `--profile`;
- `--json`;
- `--env`;
- `--no-interaction`;
- help/version/completion.

Profiling rules:

- stdout/JSON payloads are not contaminated by profiling;
- `--silent` disables profile output;
- supervised child commands suppress duplicate `--profile` output so only the parent reports command-level profiling.

Process/safety details:

- command/scheduler subprocesses use `ProcessRunner`;
- overlap/scheduler leases use heartbeat callbacks;
- storage unlink retains symlink/target safety;
- `log:tail --follow` detects truncation and file replacement/rotation and follows the active log file.

# Completed cleanup — config/readiness verification

- `config:validate --production` passes production intent into OTP replay-topology validation;
- production security validation remains shared with `app:ready`;
- `ReadinessReport` resolves package requirements by active capability, including individual packages in the multi-package auth module;
- runtime-control/maintenance cache state validates configured store existence and deployment visibility;
- runtime-registry visibility validates `host|shared`;
- DBLayer 4.1 `MigrationRunner::pretend()` API was verified against Foundation's rendering shape: migration id => ordered `{sql,bindings}` statement list;
- `AuthPruner` SQL was verified against the current Foundation auth schema table/column definitions;
- database provider activation no longer touches an already-resolved CacheManager just because DBLayer activates.

# Application public-surface freeze review

`Application` is already substantially reduced from the old Foundation surface.

Retained categories are intentional:

- runtime/bootstrap state: `boot`, runtime-mode checks, `handle`, `http`, `execution`;
- DI/composition: `make`, `has`, `register`, `providers`, `container`, `config`;
- Foundation-owned application workflows: auth/session/router/response/testing entry points;
- Foundation-owned application paths.

Do **not** add specialist forwarding methods such as generic cache/database/filesystem/messaging/security managers. Native specialist services remain resolved directly through DI or used directly from their owning packages.

# Infbyte alignment

No Infbyte source/config mutation was required in the runtime/application-contract correctness batches.

That is intentional:

- root `infbyte` already delegates to `CommandDispatcher`, so new/updated built-in generators and runtime commands arrive automatically;
- optional module config stays module-published rather than checked into the skeleton;
- Foundation owns module constraints/config lifecycle;
- Infbyte does not duplicate runtime, messaging, notification, validation, or schema machinery.

# Source-audit status

Source-level verification completed for the current cleanup included:

- scheduler lease refresh/loss behavior;
- execution cleanup failure precedence;
- Omnibus 2.4 lifecycle integration;
- runtime-control atomicity;
- runtime-registry visibility;
- schedule status/identity behavior;
- supervised profiling behavior;
- production OTP validation intent;
- cache SQLite schema-status side effects;
- ModuleManager publication rollback/finalization behavior;
- JsonResource generator contract;
- FormRequest/ReqShield rule generator contracts;
- notification/mail generator contracts;
- AuthPruner schema compatibility;
- DBLayer 4.1 migration pretend return shape;
- Application public-surface review.

This remains a **source/config audit**. The deferred PHPUnit/static/PHPForge/runtime matrix has **not** been run.

# Immediate next work

Application-contract cleanup is complete. Proceed with the documentation/public-name freeze:

1. reconcile README and `docs/*` with the actual Foundation 2.0 module names, commands, dependency versions, runtime semantics, and generators;
2. remove stale references to retired Console/facade/manager/schema-command surfaces;
3. reconcile Infbyte documentation/examples with Foundation 2.0 without adding optional config to the skeleton;
4. freeze public command/module/config/class names after docs expose the intended surface;
5. only then start the deferred full verification/release matrix.

# Deferred test/release matrix

When explicitly started:

- Composer validation/dependency checks;
- PHPForge/static analysis;
- PHPUnit/integration suites;
- clean Infbyte create-project/install;
- core-only runtime without optional packages;
- purpose-module install/remove/config/schema matrix, including partial auth bundle state;
- module schema status/install/sync behavior and install-order combinations;
- app:ready/config:validate missing-package/missing-schema/production diagnostics;
- CLI list/help/completion/global-option behavior;
- Web/CLI/Worker/Scheduler isolation;
- maintenance file/cache modes;
- worker/scheduler reload and heartbeat/process visibility;
- queue failed-message/monitoring commands across supported transports;
- Omnibus handler middleware parity across sync/consumer execution;
- Foundation JobMiddleware ordering, short-circuit/result propagation and sync/async JobContext;
- pooled-worker middleware configuration/fork-safety behavior;
- DB monitor/pretend/wipe/rollback-batch behavior;
- env encrypt/decrypt success, force, failure and rollback cases;
- storage link/status/unlink safety cases;
- optimize/optimize:clear/deploy behavior;
- persistent execution/fork/locking checks;
- startup/memory/throughput benchmarks;
- final stale-symbol/config/doc scan;
- final Foundation 2.0 + Infbyte compatibility review.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkeys modules;
- no duplicated public auth/session schema command families;
- no automatic schema drop during module removal;
- no copied CacheLayer/internal specialist SQL in Foundation;
- no fake generators without real framework contracts;
- no Foundation-owned duplicate DB/queue/cache/crypto engines;
- no second messaging/retry/failure/worker engine above Omnibus;
- no Foundation job middleware leaking Omnibus Envelope/HandlerContext APIs;
- no `FoundationConsole`, `Foundation::console()`, or second CLI hierarchy;
- no broad specialist Application manager/facade proxies;
- no static global application state;
- no generic IdentifierManager/IDs driver;
- no `app.container.request_scope`;
- no duplicated specialist engines;
- no bulk-copied optional-module config in Infbyte;
- no environment-protection key inside the environment file it protects;
- no generated optimized artifacts committed.
