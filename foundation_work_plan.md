# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only and is not the current TODO source.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation and Infbyte are finalized together. Foundation is the reusable runtime/framework layer; Infbyte is the opinionated application skeleton.

## Maintenance rule

After every completed joint batch:

1. update this file and Infbyte's `infbyte_work_plan.md`;
2. record source checkpoints separately from tracker-only commits;
3. keep immediate next work limited to the next concrete phase;
4. do not reopen completed Foundation cleanup without new source/integration evidence;
5. fix framework defects in Foundation rather than working around them in Infbyte;
6. keep specialist-library engines in their owning packages;
7. keep full test/release gates deferred until implementation/config/docs are stable.

# Current checkpoint

- Date: 2026-08-23
- Foundation source checkpoint: `2e1415fd871d1564a70d08b18da45ebf243fe4e5`
- Infbyte source checkpoint: `297d171f793f083795fba6ee70876a5170ff5f66`
- Foundation branch: `feature/foundation-2.0`
- Infbyte branch: `feature/foundation-2.0`, created from `main` at `47fb985f266c977504c3dca6bd13e85c9a1b73dc`.
- Status: **Foundation source/runtime + joint Infbyte implementation/config baseline complete; joint documentation reconciliation is next.**
- Full PHPUnit/static-analysis/PHPForge/release matrix: not run yet.

# Framework boundary

## Foundation owns

- `Application`, runtime modes and lifecycle;
- Web, CLI, Worker and Scheduler composition;
- InterMix DI/container/scopes;
- CLI parsing/preflight/dispatch/execution;
- configuration loading/cache and optimized artifacts;
- reusable module integration/publication machinery;
- reusable defaults and specialist-package bridges.

## Infbyte owns

- project Web bootstrap/public entrypoint;
- application-facing config overrides;
- provider lists/routes/application code;
- root `infbyte` convenience launcher;
- deployment conventions;
- intentionally configured application branding/defaults;
- skeleton documentation/developer experience.

Foundation must not depend on Infbyte. Infbyte must not rebuild Foundation runtime machinery.

# Dependency baseline

Core/runtime:

- `php` `^8.4`
- `composer-runtime-api` `^2.0`
- `infocyph/arraykit` `^5.1.1`
- `infocyph/intermix` `^9.1.1`
- `infocyph/uid` `^5.0`
- `infocyph/webrick` `^4.0.1`
- `psr/log` `^3.0.2`

Optional/integration:

- `infocyph/cachelayer` `^3.1.3`
- `infocyph/dblayer` `^4.1`
- `infocyph/epicrypt` `^2.1`
- `infocyph/omnibus` `^2.2`
- `infocyph/otp` `^6.0`
- `infocyph/pathwise` `^3.1`
- `infocyph/phpforge` `dev-main@dev`
- `infocyph/reqshield` `^3.0`
- `infocyph/talkingbytes` `^2.0`
- `web-auth/webauthn-lib` `^5.3.5`

# Completed Foundation 2.0 source architecture

## Structural/runtime ownership

- retired Console runtime merged into Foundation with no compatibility hierarchy;
- broad static/pass-through facades and specialist Application manager accessors removed;
- obsolete Database/Router/Filesystem/Data proxy managers removed/reduced;
- runtime provider groups are exactly `common|web|cli|worker|scheduler`;
- specialist libraries own their engines; Foundation keeps reusable composition/policy only;
- logger contracts are PSR-3 typed;
- runtime/config validation is declarative and resolution-free.

## InterMix / UID

- public InterMix APIs distinguish explicit definition, broad resolvability and resolution history;
- known repository/current-resolver internal coupling removed;
- runtime `ExecutionId` and default container identity use UID UUIDv7;
- deterministic hashes and cryptographic randomness remain where semantically correct.

## Defaults/config/runtime lifecycle

- canonical `FoundationDefaults`;
- no `app.container.request_scope`;
- lazy loading normal/default behavior;
- env helpers limited to `env`, `env_bool`, `env_int`, `env_string`;
- paths remain declarative/application-relative;
- neutral Foundation runtime identity (`foundation:*`, `foundation_session`, nullable HTTP User-Agent);
- CacheLayer lock inheritance follows the selected store's native coordination capability unless explicitly overridden;
- CacheManager/DBLayer activation order no longer turns package presence into capability activation;
- `ExecutionScope` and targeted `RuntimeContextTracker` lifetime cleanup retained;
- ProcessRunner argv/no-shell and bounded/portable process behavior retained.

## Deployment-owned optimized artifacts

- config/command/schedule/route/container artifacts are build/deployment owned;
- optimized runtime trusts compatible artifacts without rescanning full source trees;
- normal Web boot does not generate/bless route cache state;
- compiled config folds `bootstrap/providers.php` into provider groups;
- aggregate `optimize`/`optimize:clear` own deployment artifact generation/removal.

# Completed joint Foundation/Infbyte integration

## CLI contract

Infbyte exposed one legitimate framework integration need:

- `CliPreflight` accepts a lightweight display name;
- `CommandDispatcher::project()` carries it through preflight;
- Foundation package binary remains `Foundation` by default;
- Infbyte root launcher reports `Infbyte <foundation-version>` without loading environment/Application or rebuilding a Console object.

No separate CLI Application/bootstrap hierarchy was restored.

## Module mutation invalidation

Infbyte's module lifecycle review exposed another concrete Foundation defect:

- successful non-dry `module:install` and `module:remove` now clear runtime-specific compiled containers plus the optimize manifest;
- config publication still owns config-cache invalidation;
- unrelated route/command/schedule artifacts are not needlessly cleared;
- a changed Composer/provider graph can no longer leave a stale prevalidated compiled container active.

## Infbyte architecture verified against Foundation

- Web bootstrap is one direct `Foundation::web(['base_path' => ...])` call;
- CLI/Worker/Scheduler runtime selection is owned by `CommandDispatcher`, so no unused `bootstrap/cli.php` is needed;
- route source and route-cache build expose the same local `$router` registrar contract;
- `routes/console.php` remains the command-registration filename, not a Console subsystem;
- application maintenance workers use Foundation `WorkerProvider`; message workers belong to Omnibus;
- schedule routes use Foundation `Scheduling\Schedule`;
- UID ID-driver config is gone;
- core Infbyte config remains intentionally lean (`app`, `auth`, `router`).

## Module/config publication decision

Foundation's current model is fixed:

- Infbyte does not copy all Foundation config templates into the base skeleton;
- built-in logging/resources/session work from Foundation defaults until explicitly published/customized;
- external module config appears when the corresponding package/capability is intentionally installed;
- `module:install` is the authoritative publication path;
- existing host config is not overwritten;
- module removal does not delete host-owned config;
- package presence remains distinct from configuration and runtime activation.

## Infbyte branding decision

Foundation stays neutral and Infbyte does not publish module config solely to rename defaults.

Application-specific cache/User-Agent/session/response identity is applied when the application actually owns/publishes that config. This keeps the clean skeleton dependency-light and avoids copied framework templates drifting.

# Immediate next work — joint documentation reconciliation

Implementation/config architecture is stable enough to document.

Update Foundation and Infbyte together:

1. Foundation root README and docs index;
2. Infbyte README and install/bootstrap overview;
3. architecture/runtime docs with exactly Web, CLI, Worker, Scheduler;
4. CLI docs: package dispatcher, Infbyte delegator, preflight, `routes/console.php` command registration;
5. worker/scheduler docs: Foundation maintenance workers vs Omnibus message workers;
6. configuration docs: lean Infbyte core config, Foundation defaults, module publication;
7. module docs: package installed vs config published vs capability activated;
8. deployment docs: `optimize`, optimized artifact ownership and invalidation;
9. Foundation-vs-Infbyte ownership throughout examples;
10. remove stale `FoundationConsole`, `Foundation::console()`, `App\Console`, request-scope, ID-driver/IdentifierManager, old cache path and old manager/facade references;
11. align `.env`/`app:install` secret generation docs;
12. freeze public names/config examples after docs match current source.

Documentation may reveal concrete implementation defects; if so, fix them in the owning repository and update both trackers.

# After docs — deferred test/release matrix

When explicitly started, run both repositories through:

- Composer validation/dependency checks;
- PHPForge/static analysis;
- PHPUnit/integration suites;
- clean Infbyte create-project/install;
- CLI version/list/help/completion preflight;
- Web/CLI/Worker/Scheduler isolation;
- core-only install/runtime without optional packages;
- optional module install/remove/config publication combinations;
- optimize/optimize:clear/deploy behavior;
- persistent execution-scope/fork isolation;
- locking topology checks;
- startup/memory/throughput benchmarks;
- final stale-symbol/config/doc scan;
- clean consumer/archive install;
- final Foundation 2.0 + Infbyte compatibility/release-readiness review.

# Do not regress

- no `FoundationConsole`, `Foundation::console()`, `src/Console`, or second CLI hierarchy;
- no broad specialist Application manager/facade proxy surface;
- no static global application state;
- no generic Data/Filesystem manager proxies;
- no global current-application path helpers;
- no `auth.drivers.ids`/IdentifierManager surface;
- no `app.container.request_scope`;
- no InterMix internals when public APIs exist;
- no duplicated specialist engines;
- no bulk-copied optional-module config in Infbyte;
- no generated optimized artifacts committed;
- no Infbyte workaround for a Foundation defect.
