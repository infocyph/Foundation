# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file tracks the current implementation state.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable framework/runtime layer. Infbyte is the opinionated application skeleton built on it.

## Current checkpoint

- Date: 2026-08-24
- Foundation source checkpoint: `493c39a7a06bac0455397556254f0f8e7e25f973`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Current phase: **documentation reconciliation + public-name/config freeze**.
- Application-contract/API cleanup: **complete**.
- Full PHPUnit/static-analysis/PHPForge/runtime/release matrix: **not run yet**.

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
- `infocyph/dblayer ^4.1`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.4`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.0.1`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

`ModuleCatalog` constraints must remain aligned with these package baselines where the package backs a public module.

# Fixed architecture

## Runtime boundary

- exactly four runtimes: Web, CLI, Worker, Scheduler;
- no retired Console hierarchy or compatibility layer;
- root executable remains `infbyte`;
- InterMix owns DI/container mechanics;
- Foundation owns runtime composition, provider activation, configuration and lifecycle policy;
- UID is the canonical generated-ID provider;
- optimized config/command/schedule/route/container artifacts are deployment-owned;
- specialist packages retain their engines, public implementation APIs and schema grammar;
- Infbyte never rebuilds Foundation runtime machinery.

## Application surface

`Application` is intentionally narrow. It owns:

- boot/runtime state;
- config/container/provider access;
- service resolution through `make()` / `has()`;
- execution scope;
- Foundation application paths;
- canonical Web entry through `handle()` / `http()`.

Removed service-shortcut proxies include auth/auth-actions/auth-manager, browser-session/session, router, response and testing conveniences. Consumers resolve real services through DI instead of turning `Application` into another facade.

Do not add generic cache/database/filesystem/messaging/security forwarding managers to `Application`.

## Configuration lifecycle

- no `app.container.request_scope`;
- lazy loading is normal/default;
- global helpers are only `env()`, `env_bool()`, `env_int()`, `env_string()`;
- paths are declarative, not global state;
- package presence is distinct from configured/activated capability;
- one InterMix execution scope exists per execution unit;
- external runtime cleanup stays targeted through `RuntimeContextTracker`.

# Purpose-first modules

A public module represents an application purpose, not a Composer package.

| Module | Backing package(s) |
|---|---|
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2.0` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built in |
| `messaging` | `infocyph/omnibus ^2.4` |
| `operations` | built in |
| `resources` | built in |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built in |
| `validation` | `infocyph/reqshield ^3.0.1` |

Naming rules:

- `database` canonical; aliases `db`, `dblayer`;
- `security` canonical; aliases `crypto`, `epicrypt`;
- no standalone OTP/passkey module;
- `otp|mfa|passkey|passkeys|webauthn` resolve to `auth`;
- `ops|runtime` resolve to `operations`;
- `notifications` remains an alias of communication for TalkingBytes-backed communication configuration, while Foundation's core notification routing contracts themselves are framework-owned.

`ModuleManager` reports `built-in|installed|partial|available`, supports multi-package bundles, installs a bundle in one Composer operation, and removes only direct application requirements.

# Module config + schema lifecycle

## Config publication

- `module:config:publish <module>` never overwrites existing host config;
- `--force` stages replacements and previous files transactionally;
- symbolic-link targets are never replaced;
- staging/backup cleanup failures are checked;
- rollback failures are surfaced explicitly rather than silently leaving partial state;
- successful publication clears config cache/compiled runtime state where needed.

## Schema ownership

| Module | Schema | Native owner |
|---|---|---|
| `auth` | accounts/tokens/MFA/passkeys/authorization | Foundation `AuthSchemaInstaller` |
| `cache` | PDO/SQLite cache entries | CacheLayer `PdoCacheSchema` |
| `cache` | PDO invalidation events | CacheLayer `PdoInvalidationSchema` |
| `session` | database sessions | Foundation `SessionDatabaseSchema` |

Other modules currently own no application database schema. `database` owns DB/migration infrastructure, not application tables.

CacheLayer node/tiered SQLite internals remain CacheLayer-owned self-initializing details; Foundation does not copy their private SQL.

Schema status/readiness is read-only. A missing SQLite cache database is reported as `pending`; only explicit schema installation may create its directory/database/schema.

Canonical commands:

- `module:schema:status <module> [--connection=...]`
- `module:schema:install <module> [--connection=...]`
- `module:schema:sync [--connection=...]`

No duplicate `auth:schema:*` or `session:schema:*` public families.

`module:install` performs package installation, safe config publication, compiled-state invalidation and fresh-process schema synchronization. `module:remove` never drops schema/data.

# Application contracts

## Validation / requests

- `FormRequest` is the Foundation request-to-ReqShield composition point;
- validation mechanics remain ReqShield-owned;
- custom rules implement ReqShield `Contracts\Rule` directly—Foundation does not wrap it;
- `create:request` generates `FormRequest` subclasses;
- `create:rule` generates native ReqShield rules.

## Notifications / mail

Foundation owns application notification routing:

- `Notification`;
- `NotificationRecipient`;
- `NotificationChannel`;
- `NotificationChannelRegistry`;
- `NotificationDispatcher`.

Core notification routing works without TalkingBytes. Custom channels therefore do not require the communication module.

TalkingBytes remains optional implementation infrastructure for email:

- `MailMessage` adds only application sender-profile selection over TalkingBytes `EmailMessage`;
- `Mailer` delegates to TalkingBytes email sender profiles;
- `MailNotificationChannel` adapts mail to Foundation notification routing;
- missing mail capability reports the canonical `module:install communication` diagnostic.

Generator behavior:

- `create:mail` requires communication;
- `create:notification` currently generates a mail notification and therefore requires communication;
- `create:notification-channel` is package-neutral and uses Foundation's built-in channel contract.

## Messaging / jobs

Omnibus owns message transport, handlers, retries, failures, workers and process pools.

Foundation adds only application composition:

- `Job` semantic data-message marker;
- immutable `JobContext`;
- `JobMiddleware`;
- `JobMiddlewarePipeline` adapter to Omnibus handler middleware.

`create:job`, `create:handler`, `create:job-middleware` use these real contracts.

## Resources

- `JsonResource` is the minimal Foundation application-resource contract;
- `create:resource` generates the current `resolve(): mixed` API correctly.

No generator should exist merely to imitate Laravel terminology without a real Foundation contract.

# Omnibus 2.4 integration

- one shared Omnibus `HandlerInvoker` is used for synchronous and queued handler execution;
- `messaging.handler_middleware` is low-level Omnibus middleware;
- `messaging.job_middleware` is Foundation job-only middleware;
- ordinary synchronous PSR event listeners stay outside handler middleware;
- queued listeners naturally enter the handler pipeline.

Worker lifecycle:

- Bootstrapper probes Omnibus 2.4 `WorkerLifecycle`;
- single messaging workers use native lifecycle heartbeat/stop callbacks, including runtimes without `pcntl`;
- `WorkerPool` retains Foundation's Unix watchdog because the upstream pool itself is Unix/pcntl-based;
- provider-only workers do not activate Omnibus;
- pooled workers require scalar/array declarative config before fork.

# Operations/runtime correctness

`operations` owns:

```text
operations.history.*
operations.maintenance.*
operations.runtime_control.*
operations.runtime_registry.*
```

`FoundationDefaults`, publishable `operations.php` and `RuntimeConfigValidator` are aligned.

## Runtime control

- file driver serializes read/modify/write with a stable lock and atomic replacement;
- cache driver coordinates the state mutation through CacheLayer locks;
- concurrent runtime/worker/scheduler generation signals cannot silently overwrite each other;
- cache-backed runtime control validates deployment visibility and atomic coordination.

## Runtime registry

- heartbeat records are observability metadata, never supervision authority;
- `operations.runtime_registry.visibility` is `host|shared`, default `host`;
- host mode ignores records from other hosts;
- shared mode intentionally aggregates a shared directory;
- `worker:status` reports the selected visibility.

## Execution scope

- application exceptions remain primary;
- `RuntimeContextTracker::reset()` and InterMix `leaveScope()` both run;
- cleanup failure is thrown only when no application failure already exists.

# Scheduling correctness

- overlap/single-server leases refresh while child processes run;
- lease refresh failure becomes `heartbeat_lost`, terminates the child and fails the run;
- `schedule:test` returns failure for command failure or unavailable ownership;
- every scheduler history transition carries `schedule_identity`;
- `schedule:list` resolves last state by schedule identity rather than command text;
- duplicate scheduled command strings therefore do not share status accidentally.

# CLI / process behavior

Global controls include:

- `-q|--quiet`
- `--silent`
- `-v|-vv|-vvv`
- `--profile`
- `--json`
- `--env`
- `-n|--no-interaction`
- help/version/completion

Supervised child commands suppress duplicate profile output; the parent owns command-level `--profile` diagnostics.

Other completed correctness work:

- `schedule:test` exit status fixed;
- `log:tail --follow` handles truncation and file replacement/rotation;
- storage unlink remains symlink/target safe;
- environment replacement remains staged/rollback-safe;
- no environment-encryption key is stored in `.env`/`.env.example`.

# Config/readiness alignment

- `config:validate --production` passes production intent into OTP replay topology validation;
- runtime-registry visibility validates `host|shared`;
- cache-backed maintenance/runtime-control verifies configured store existence and required visibility;
- runtime control additionally requires atomic coordination;
- `app:ready` requires CacheLayer for session locks, migration locks and cache-backed operations state;
- explicit validation DB connections require DBLayer;
- multi-package auth readiness checks only the package actually activated by configuration;
- applicable auth/cache/session schemas remain part of readiness.

Verified source contracts:

- DBLayer 4.1 `MigrationRunner::pretend()` returns migration => ordered `{sql,bindings}` statements and Foundation renders that exact shape;
- `AuthPruner` matches the current auth schema and prunes disposable expired/consumed/revoked state without deleting durable account/authorization/audit data;
- Database provider activation does not wake CacheLayer merely because CacheManager was previously resolved.

# Public CLI surface

Major capability-oriented commands include:

- config/application: `config:validate`, cache/config/optimize/application commands;
- database: `db:monitor`, `db:wipe`, migration preview/rollback/status/fresh/refresh/reset, seed/show/table;
- messaging: `messaging:list`, queue consume/failure/retry/forget/flush/prune/monitor;
- scheduling: `schedule:list`, `schedule:run`, `schedule:test`, `schedule:work`, cache/clear/interrupt;
- operations: execution history, maintenance, runtime reload, worker restart/status, env protect, log tail;
- storage/session/auth operational commands;
- purpose-module list/show/install/remove/config/schema lifecycle;
- generators for class/command/controller/enum/event/exception/handler/interface/job/job-middleware/listener/mail/middleware/migration/notification/notification-channel/policy/provider/repository/request/resource/rule/service/seeder/test/trait/worker/config.

Do not add commands that duplicate specialist engines or unsupported Laravel features.

# Infbyte alignment

No Infbyte application source/config mutation was required by these cleanup batches.

Intentional skeleton rules remain:

- root CLI delegates directly to `CommandDispatcher`;
- web bootstrap delegates to one `Foundation::web()` application;
- checked-in config remains only `app.php`, `auth.php`, `router.php`;
- optional module configs remain publish-on-demand;
- `.env.example` stays lean;
- route files use the scoped Webrick registrar;
- no runtime/messaging/notification/validation/schema engine is duplicated in Infbyte.

# Verification status

Completed work is a **source/config/API audit**, not the release verification pass.

The following full gates remain deliberately deferred until documentation/public names are frozen:

- PHPUnit matrix;
- static analysis;
- PHPForge;
- core-only and optional-module combinations;
- partial auth bundle states;
- module install ordering;
- CLI global control matrix;
- maintenance/runtime reload/worker/scheduler behavior;
- fork/process-pool safety;
- queue failure/retry lifecycle;
- destructive operation safeguards;
- optimization artifacts;
- performance/soak checks.

# Immediate next work

Proceed with **documentation reconciliation + public-name/config freeze**:

1. reconcile root README and `docs/*` against the source checkpoint above;
2. remove stale Console/facade/manager/module/version references;
3. document current request/rule/notification/mail/job/resource contracts and generators;
4. document CacheLayer 3.2 / Omnibus 2.4 runtime semantics;
5. reconcile Infbyte docs/examples without copying optional config into the skeleton;
6. freeze public command/module/config/class names;
7. then run the deferred full verification matrix;
8. fix verification defects, perform performance/soak review, and prepare Foundation 2.0 release.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkey modules;
- no duplicated schema command families;
- no schema/data deletion during module removal;
- no copied specialist SQL/transport/retry/cache/database engine;
- no unsafe lock fallback;
- no broad `Application` service facade;
- no second messaging worker/retry engine above Omnibus;
- no Omnibus `Envelope`/`HandlerContext` leakage into Foundation `JobMiddleware`;
- no retired Console runtime hierarchy;
- no static global application state;
- no generic ID-driver/request-scope compatibility;
- no bulk optional-config copy into Infbyte;
- no environment encryption key in `.env` or `.env.example`;
- no generated optimized artifacts committed.
