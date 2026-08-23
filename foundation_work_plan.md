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
- Foundation source checkpoint: `b81f508c2d14f1b7bb6a4dc63982ba19cde7fb81`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Current phase: **pre-documentation cleanup pass**.
- Latest completed cleanup: **purpose-first modules + module-owned schema lifecycle**.
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

- Infbyte branch created from `main` and targets the Foundation 2.0 feature branch during development;
- root Infbyte CLI is a thin Foundation `CommandDispatcher` delegator;
- Foundation preflight accepts application display-name metadata without booting Application;
- Infbyte Web bootstrap is one `Foundation::web()` call;
- CLI/Worker/Scheduler runtime selection is owned by the Foundation dispatcher;
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
| `resources` | built into Foundation |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built into Foundation |
| `validation` | `infocyph/reqshield ^3.0` |

Naming rules:

- `database` is canonical; `db` and `dblayer` are aliases;
- `security` is canonical; `crypto` and `epicrypt` are aliases;
- standalone `otp` and `passkeys` modules are gone;
- `otp|mfa|passkey|passkeys|webauthn` resolve to `auth`;
- provider dependency errors use canonical `auth|database|security` names.

`ModuleManager` supports multi-package bundles, per-package state, module status `built-in|installed|partial|available`, grouped Composer install, and removal of direct application requirements only.

Runtime readiness remains implementation-exact: enabling OTP does not require WebAuthn at runtime and vice versa.

# Completed cleanup — module-owned schema lifecycle

## Public rule

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

## Module schema metadata

`ModuleCatalog` now includes `schemas` alongside packages/config. `ModuleManager::all()` exposes that metadata and `module:list` shows:

- Module;
- Status;
- Packages;
- Schemas;
- Purpose.

## Public schema commands

Canonical schema lifecycle:

- `module:schema:status <module> [--connection=...]`;
- `module:schema:install <module> [--connection=...]`;
- `module:schema:sync [--connection=...]`.

The duplicate specialized public commands `auth:schema:*` and `session:schema:*` were removed. Their schema classes remain native implementation details behind the module lifecycle.

## Install behavior

`module:install <module>` now performs one application-level operation:

1. install the module's Composer package bundle when required;
2. publish that module's config without overwriting host config;
3. invalidate compiled container/optimize state after package/config mutation;
4. invoke `module:schema:sync` in a **fresh PHP process** so newly installed Composer namespaces are visible;
5. provision only database schemas required by the active configuration;
6. return schema state in machine-readable output and fail if a configured required schema cannot be made ready.

Fresh-process sync is intentional; Foundation does not mutate Composer's active autoloader after `composer require`.

Install ordering is safe: installing `database` later synchronizes already-configured database-backed auth/session/cache capabilities.

Explicit `module:schema:install` can provision a module-owned schema ahead of activation where the native backend is available.

## Data safety

`module:remove` **never drops schemas or application data**. Package removal and destructive schema teardown are deliberately separate concerns.

## Readiness integration

`app:ready` now checks both:

- exact implementation packages required by configured capabilities;
- applicable module-owned database schemas.

A package being installed is no longer sufficient to declare a configured persistence capability ready when its required schema is missing.

## Config alignment

Foundation cache/session templates and Infbyte auth config now document the module schema lifecycle:

- database auth storage → auth schema;
- database sessions → session schema;
- active PDO/SQLite CacheLayer resources → CacheLayer native schema provisioners;
- active PDO invalidation transports → CacheLayer native invalidation schema.

# Immediate next work — continue cleanup pass

Do not start the documentation rewrite yet.

Continue reviewing Foundation + Infbyte public surfaces for cleanup before docs are frozen. Any concrete framework defect found in this pass is fixed in Foundation; application-only policy stays in Infbyte.

After the cleanup pass is complete:

1. joint Foundation/Infbyte documentation reconciliation;
2. public name/config freeze;
3. deferred test/release matrix.

# Deferred test/release matrix

When explicitly started:

- Composer validation/dependency checks;
- PHPForge/static analysis;
- PHPUnit/integration suites;
- clean Infbyte create-project/install;
- core-only runtime without optional packages;
- purpose-module install/remove/config/schema matrix, including partial auth bundle state;
- module schema status/install/sync behavior and install-order combinations;
- app:ready missing-schema diagnostics;
- CLI preflight and Web/CLI/Worker/Scheduler isolation;
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
- no `FoundationConsole`, `Foundation::console()`, or second CLI hierarchy;
- no broad specialist Application manager/facade proxies;
- no static global application state;
- no generic IdentifierManager/IDs driver;
- no `app.container.request_scope`;
- no duplicated specialist engines;
- no bulk-copied optional-module config in Infbyte;
- no generated optimized artifacts committed.
