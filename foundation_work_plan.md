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
3. keep specialist engines in their owning packages;
4. keep public framework modules purpose-oriented rather than package-oriented;
5. keep full tests/release gates deferred until implementation/config/docs are stable.

# Current checkpoint

- Date: 2026-08-23
- Foundation source checkpoint: `11801b097f93b593df98e5a3c3d3fdca702166f1`
- Infbyte source checkpoint: `8e5e9443be1a40a770fc80b4ffaf22322dc08de9`
- Current phase: **pre-documentation cleanup pass**.
- Latest completed cleanup: **purpose-first module architecture**.
- Full PHPUnit/static-analysis/PHPForge/release matrix: not run yet.

# Fixed architecture

## Runtime/framework boundary

- exactly four runtimes: Web, CLI, Worker, Scheduler;
- no retired Console hierarchy or compatibility layer;
- InterMix owns DI/container mechanics; Foundation owns runtime composition/lifecycle policy;
- UID is the canonical generated-ID provider;
- config/command/schedule/route/container optimized artifacts are deployment-owned;
- specialist libraries retain their own engines and public implementation APIs;
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

## Public rule

A Foundation module represents an **application purpose/capability**, not a Composer package.

Current canonical modules:

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

### Naming/alias decisions

- `database` is canonical; `db` and `dblayer` remain aliases.
- `security` is canonical; `crypto` and `epicrypt` remain aliases.
- standalone `otp` and `passkeys` modules are removed.
- `otp`, `mfa`, `passkey`, `passkeys`, `webauthn`, and the corresponding package names resolve to `auth`.

## Multi-package module behavior

`ModuleCatalog` now stores `packages` as `package => constraint` instead of singular `package`/`constraint` fields.

`ModuleManager` now:

- installs all dependencies in a module bundle in one Composer operation;
- removes only module packages that are direct application requirements;
- reports per-package installed/direct/version state;
- reports module status as `built-in`, `installed`, `partial`, or `available`;
- keeps built-in module config publication working without Composer installation.

`module:list` now presents:

- module;
- status;
- backing packages;
- purpose/description.

Alias input is normalized back to the canonical module name in install/remove output.

## Runtime readiness stays exact

Purpose-level installation does **not** make runtime readiness coarse.

Examples:

- `AUTH_MFA=otp` requires the OTP implementation package and cache coordination, not unused WebAuthn runtime state;
- `AUTH_PASSKEY=webauthn` checks the WebAuthn package;
- database-backed auth/session checks the `database` module package;
- Epicrypt-backed passwords/tokens check the `security` module package.

This preserves the distinction between:

1. module bundle installation;
2. package availability;
3. application configuration;
4. runtime capability activation.

## Infbyte config alignment

`config/auth.php` now exposes only capability choices and documents their purpose-level module requirements:

- database storage → `module:install database`;
- cache-backed auth state → `module:install cache`;
- Epicrypt password/token drivers → `module:install security`;
- OTP or WebAuthn → `module:install auth`;
- TalkingBytes notifications → `module:install communication`.

OTP and WebAuthn remain independently selectable even though they share the same installation module.

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
- purpose-module install/remove/publication matrix, including partial auth bundle state;
- CLI preflight and Web/CLI/Worker/Scheduler isolation;
- optimize/optimize:clear/deploy behavior;
- persistent execution/fork/locking checks;
- startup/memory/throughput benchmarks;
- final stale-symbol/config/doc scan;
- final Foundation 2.0 + Infbyte compatibility review.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkeys modules;
- no `FoundationConsole`, `Foundation::console()`, or second CLI hierarchy;
- no broad specialist Application manager/facade proxies;
- no static global application state;
- no generic IdentifierManager/IDs driver;
- no `app.container.request_scope`;
- no duplicated specialist engines;
- no bulk-copied optional-module config in Infbyte;
- no generated optimized artifacts committed.
