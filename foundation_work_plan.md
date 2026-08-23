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

- Date: 2026-08-23
- Foundation source checkpoint: `3d8b2350094fbf8b031290b17a4085643234b563`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Current phase: **pre-documentation cleanup pass complete for modules, schemas, CLI, and operational runtime surfaces**.
- Latest completed cleanup: **capability-driven CLI expansion + built-in operations lifecycle**.
- Full PHPUnit/static-analysis/PHPForge/release matrix: not run yet.

# Fixed architecture

## Runtime/framework boundary

- exactly four runtimes: Web, CLI, Worker, Scheduler;
- no retired Console hierarchy or compatibility layer;
- InterMix owns DI/container mechanics; Foundation owns runtime composition/lifecycle policy;
- UID is the canonical generated-ID provider;
- config/command/schedule/route/container optimized artifacts are deployment-owned;
- specialist libraries retain their own engines, schema implementations, and public implementation APIs;
- Infbyte does not rebuild Foundation runtime machinery.

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
| `cache` | `infocyph/cachelayer ^3.1.3` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built into Foundation |
| `messaging` | `infocyph/omnibus ^2.2` |
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

`app:ready` checks exact implementation packages plus applicable module-owned database schemas.

# Completed cleanup — capability-driven CLI

The CLI uses useful Laravel/Artisan concepts where they fit Foundation, but it does **not** mirror Artisan command-for-command. A command is added only when Foundation owns the orchestration contract or can delegate to a real specialist capability.

## Application/config/cache

Existing optimization/config/application commands remain, with additions including:

- `config:validate [--production]`;
- `cache:forget <key> [--store=...]`.

`config:validate --production` and `app:ready` share the same runtime/config security expectations, including OTP-specific validation when OTP is active.

## Database/migrations

Foundation delegates DB primitives to DBLayer:

- `db:monitor [--section=...] [--seconds=...] [--maintenance]` uses DBLayer `DatabaseMonitor`;
- `db:wipe` is explicitly destructive and guarded;
- `migrate --pretend` uses DBLayer migration preview;
- `migrate:rollback --batch=N` targets an exact migration batch;
- existing seed/show/table/status/fresh/refresh/reset behavior remains Foundation orchestration over DBLayer.

No DB monitoring/schema engine is duplicated in Foundation.

## Messaging/queues

Foundation delegates queue/failure primitives to Omnibus:

- `messaging:list`;
- `queue:failed`;
- `queue:failed:show <id>`;
- `queue:retry <id>`;
- `queue:forget <id>`;
- `queue:flush`;
- `queue:prune-failed`;
- `queue:monitor`;
- existing bounded `queue:consume`.

Failure storage, retry mechanics, transport sending/receiving, queue sizing, worker lifecycle and process-pool mechanics remain Omnibus-owned.

## Scheduling/storage/auth/logging

Added/expanded surfaces:

- `schedule:test <key-or-command>`;
- `schedule:interrupt`;
- richer `schedule:list` with execution-history state;
- `storage:status`;
- `storage:unlink` with symlink/target safety checks;
- `auth:prune` for expired/revoked database-backed authentication state;
- `log:tail [--lines=N] [--follow]` for the built-in file logger.

## Generators

The public generator set now includes:

- `create:config` for application-owned config files;
- `create:resource` for Foundation `JsonResource` subclasses.

Foundation deliberately does **not** invent `create:request`, `create:rule`, `create:mail`, or `create:notification` until corresponding application-level framework contracts actually exist. Generator names never justify creating artificial abstractions.

# Completed cleanup — operations runtime

`operations` is a built-in purpose module and owns one coherent config surface:

```text
operations.history.*
operations.maintenance.*
operations.runtime_control.*
operations.runtime_registry.*
```

`FoundationDefaults` contains dependency-free file-backed defaults so operations work without publishing config. `resources/config/operations.php` exposes optional environment-backed tuning. `RuntimeConfigValidator` validates all four sub-surfaces.

## Execution history

- `execution:list`;
- `execution:show <id>`;
- `execution:clear`.

History remains opt-in because state transitions write operational metadata.

## Maintenance

- `maintenance:enable [--retry=N] [--message=...]`;
- `maintenance:disable`;
- `maintenance:status`.

The default driver is dependency-free file state. A cache-backed driver is available for shared multi-node state and activates CacheLayer lazily.

HTTP maintenance is real runtime behavior: `HttpKernel` checks maintenance state inside the request execution scope and returns HTTP 503, with `Retry-After` when configured.

## Persistent runtime control

- `runtime:reload` requests a graceful persistent-runtime generation change;
- `worker:restart [name]` requests all/named worker restart;
- `worker:status [name]` reports configured workers plus heartbeat-visible runtime process state;
- `schedule:interrupt` requests scheduler-loop shutdown.

UID UUIDv7 tokens identify control generations. The process registry records worker/scheduler PID, host, start and heartbeat timestamps.

Foundation does not respawn daemons. Supervisor/systemd/Docker/Kubernetes or another process manager remains responsible for starting replacement processes.

Provider workers observe stop state through `WorkerRuntime::heartbeat()`. Omnibus single workers and pools use Omnibus' native `requestStop()` lifecycle; on Unix Foundation translates generation changes through a lightweight `SIGALRM` watchdog. Platforms without `pcntl` retain normal process-manager signals and configured lifecycle limits rather than pretending an interrupt is available.

Messaging activation remains lazy: provider-only `worker:run` does not activate Omnibus merely because the command can also run messaging workers.

# Completed cleanup — environment protection

- `env:encrypt` and `env:decrypt` delegate file protection to Epicrypt `FileProtector`;
- key material is supplied through `--key-file` or an externally supplied environment variable (default `ENV_ENCRYPTION_KEY`);
- no `--key=<secret>` option exists, avoiding shell-history/process-list leakage;
- target writes are staged to a temporary file;
- forced replacement preserves/restores the prior destination if publication/finalization fails;
- symbolic-link and non-regular destination paths are refused.

Infbyte intentionally does **not** put `ENV_ENCRYPTION_KEY` in `.env.example`: the key used to protect an environment file must not be stored in the file it protects.

# Completed cleanup — CLI process controls

Global CLI parsing/help now supports:

- `-q|--quiet` — suppress normal output while preserving errors;
- `--silent` — suppress all output and disable prompts;
- `-v|-vv|-vvv` — parsed diagnostic verbosity level;
- `--profile` — duration/peak-memory diagnostics on STDERR;
- existing `--json`, `--env`, `--no-interaction`, help and version behavior.

Profiling never contaminates normal/JSON command stdout. `--silent` also disables profiling output.

# Source audit result

A source-level consistency pass was completed across the expanded catalog and its major runtime handlers.

Confirmed:

- every newly exposed command is routed to a concrete handler;
- `create:config` and `create:resource` are publicly registered;
- module `--force` publication is transactional;
- worker messaging activation remains lazy through Bootstrapper-managed services;
- storage unlink refuses normal files/directories and mismatched symlink targets;
- maintenance keys, runtime-control keys and runtime-registry keys consistently use `operations.*`;
- Foundation defaults, publishable operations config and runtime validation agree;
- environment replacement is staged/rollback-safe;
- no new InterMix internal-resolver dependency was introduced; the stale messaging resolver usage discovered in this cleanup was replaced with public container `make()`.

This was a source/config audit only. The deferred PHPUnit/static/PHPForge/runtime matrix has **not** been run.

# Infbyte alignment for this batch

No Infbyte source/config mutation was required after Foundation's CLI/operations implementation.

That is intentional:

- root `infbyte` already delegates to `CommandDispatcher`, so the new commands arrive automatically;
- `operations.php` remains an optional publishable built-in-module config rather than another checked-in skeleton config;
- `.env.example` remains lean and does not advertise inactive optional-module variables;
- environment encryption key material stays external to `.env`/`.env.example`.

# Immediate next work

The module/schema/CLI/operations cleanup pass is now ready to move into **joint Foundation/Infbyte documentation reconciliation** unless another public-surface cleanup topic is intentionally opened first.

Documentation reconciliation should then be followed by:

1. public command/module/config-name freeze;
2. deferred test/release matrix;
3. final Foundation 2.0 / Infbyte compatibility and release review.

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
- no `FoundationConsole`, `Foundation::console()`, or second CLI hierarchy;
- no broad specialist Application manager/facade proxies;
- no static global application state;
- no generic IdentifierManager/IDs driver;
- no `app.container.request_scope`;
- no duplicated specialist engines;
- no bulk-copied optional-module config in Infbyte;
- no environment-protection key inside the environment file it protects;
- no generated optimized artifacts committed.
