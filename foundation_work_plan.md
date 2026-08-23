# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only and is not the current TODO source.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation and Infbyte are now being finalized together. Foundation remains the reusable runtime/framework layer; Infbyte is the opinionated application skeleton built on top of it.

## Maintenance rule

After every completed joint implementation/review batch:

1. update this file and Infbyte's `infbyte_work_plan.md`;
2. record source-code checkpoints separately from tracker-only commits;
3. move finished work into **Completed**;
4. keep **Immediate next work** limited to the next concrete cross-repo phase;
5. do not reopen completed Foundation cleanup without new source/integration evidence;
6. fix framework defects in Foundation rather than working around them in Infbyte;
7. keep specialist-library engines in their owning packages;
8. keep the full test/release matrix deferred until implementation/config/docs are stable.

# Current checkpoint

- Date: 2026-08-23
- Latest Foundation source-code commit: `7601aa0803e997ce4e960ce367cd0530b9b10dc3`
- Latest Infbyte source-code commit: `456482094f98d82b5f89c37ebac57a11477d2d5e`
- Foundation branch: `feature/foundation-2.0`
- Infbyte branch: `feature/foundation-2.0`, created from `main` at `47fb985f266c977504c3dca6bd13e85c9a1b73dc`.
- Current phase: **joint Foundation 2.0 / Infbyte application-surface reconciliation**.
- Full PHPUnit/static-analysis/PHPForge/release matrix: not run yet; intentionally deferred.

# Fixed framework boundary

## Foundation owns

- `Application`, runtime modes and lifecycle;
- Web, CLI, Worker and Scheduler composition;
- InterMix DI/container/scopes;
- CLI parsing/preflight/dispatch/execution;
- configuration loading/cache and optimized artifacts;
- reusable module integration and publication machinery;
- reusable framework defaults and specialist-package bridges.

## Infbyte owns

- project/application bootstrap files;
- application-facing config overrides;
- provider lists;
- routes/application code;
- root `infbyte` convenience launcher;
- deployment conventions;
- application branding/default namespace choices;
- final skeleton documentation/developer experience.

Foundation must not depend on Infbyte. Infbyte must not rebuild Foundation runtime machinery.

# Current dependency baseline

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

# Completed Foundation work

## Structural/runtime cleanup

- Console ownership merged into Foundation; no compatibility hierarchy remains in the target architecture.
- broad static/pass-through facades and specialist `Application::*()` manager accessors removed;
- `DatabaseManager`, `RouterManager`, `RouteCacheRouter`, generic `FilesystemManager`, generic Data proxy layer and obsolete support wrappers removed/reduced;
- runtime provider groups are exactly `common`, `web`, `cli`, `worker`, `scheduler`;
- Pathwise/filesystem, CacheLayer, DBLayer, TalkingBytes, Omnibus, ReqShield, OTP, Epicrypt, UID, Webrick and InterMix ownership boundaries aligned;
- logger contracts are PSR-3 typed;
- runtime/config validation is declarative and resolution-free.

## InterMix / UID integration

- Foundation requires InterMix `^9.1.1`;
- explicit definitions use `Container::definitions()->has()`;
- resolution history uses `Container::isResolved()`;
- command resolution uses public `Container::make()`;
- known repository/current-resolver internal coupling removed;
- runtime `ExecutionId` and default container identity use UID UUIDv7;
- deterministic hashes and cryptographic randomness remain where those semantics are correct.

## Runtime/default/config cleanup

- canonical `FoundationDefaults` established;
- `app.container.request_scope` removed;
- lazy loading defaults to true;
- cache object/closure payloads disabled by default;
- dead database/notification/security keys removed;
- env hydration has one owner;
- config paths remain declarative/application-relative;
- Foundation global helpers are limited to `env()`, `env_bool()`, `env_int()`, `env_string()`;
- Foundation defaults are neutral rather than Infbyte-branded:
  - nullable HTTP User-Agent;
  - `foundation:cache:`;
  - `foundation:cache:lock:`;
  - `foundation_session`.

## Cache/database/runtime lifecycle

- unspecified shared coordination inherits the selected CacheLayer store's native lock rather than silently becoming an unrelated file lock;
- CacheManager/DBLayer bridges no longer make optional package presence equal activation;
- ProcessRunner defaults to argv/no-shell with explicit shell opt-in, bounded output and deterministic termination mapping;
- `ExecutionScope` owns one InterMix scope per execution unit;
- `RuntimeContextTracker` resets only touched external/static/process-local state;
- session/validation optional backends stay lazy;
- worker/fork parent-state checks and process-local transport restrictions are retained.

## Optimized artifact policy

- config, command, schedule, route and compiled-container artifacts are deployment-owned;
- compatible optimized runtime paths do not repeatedly hash/stat all source files;
- normal route source boot no longer generates/blesses route cache artifacts;
- compiled config folds `bootstrap/providers.php` into provider groups;
- module/config publication invalidates affected artifacts explicitly;
- `optimize`/`optimize:clear` remain the aggregate deployment operations.

# Completed joint Foundation/Infbyte batch

The first Infbyte migration exposed one legitimate Foundation support gap and otherwise confirmed the 2.0 ownership direction.

## Foundation change

- `CliPreflight` now accepts a lightweight display name;
- `CommandDispatcher::project()` carries that name through preflight;
- package-owned `bin/infbyte` still defaults to `Foundation`;
- Infbyte can report `Infbyte <foundation-version>` without environment loading, Application construction, or a second Console object.

## Infbyte migration baseline

- created `feature/foundation-2.0` from current `main`;
- development Composer constraint targets `dev-feature/foundation-2.0 as 2.0.x-dev`;
- root `infbyte` delegates to Foundation `CommandDispatcher`;
- `bootstrap/console.php` removed and `bootstrap/cli.php` added;
- provider groups aligned to `common|web|cli|worker|scheduler`;
- `app.container.request_scope` removed and lazy loading defaults to true;
- `config/ids.php`, `AUTH_IDS`, and `auth.drivers.ids` removed;
- auth/OTP application overrides aligned to current Foundation schema;
- `.env.example` no longer preserves a weak committed token secret;
- deployment no longer creates `bootstrap/cache/console`;
- generated Foundation artifacts under `bootstrap/cache` are ignored and the directory is retained with its own `.gitignore`.

# Immediate next work — cross-repo surface reconciliation

## 1. Infbyte application entrypoints/routes

Review against actual Foundation 2.0 behavior:

- `public/index.php`;
- `bootstrap/app.php`;
- `bootstrap/cli.php`;
- whether any dedicated worker/scheduler entry files are genuinely needed;
- `routes/web.php`, `routes/api.php`, `routes/console.php`;
- generated command/controller/provider namespace examples.

Do not add duplicate runtime bootstrap files merely for symmetry when Foundation command dispatch already owns the runtime transition.

## 2. Module/config publication model

Reconcile Infbyte's checked-in config with Foundation `ModuleCatalog` and publication behavior:

- decide which config belongs in a fresh core-only skeleton;
- optional module config should normally appear only when intentionally installed/published;
- ensure module installation is the authoritative path for CacheLayer, DBLayer, Epicrypt, OTP, Pathwise, ReqShield, TalkingBytes, Omnibus and WebAuthn integration;
- remove stale application copies rather than maintain divergent Foundation templates;
- verify package presence vs configured vs activated semantics end to end.

Any publication/install defect discovered here belongs in Foundation.

## 3. Infbyte application branding

Now that Foundation is neutral, decide application-owned defaults deliberately, including where relevant:

- HTTP User-Agent;
- cache namespace;
- coordination-lock namespace;
- browser-session cookie;
- remember-me cookie;
- response/application metadata.

Do not force optional modules into core solely to apply branding; keep those overrides with the module/application config that owns them.

## 4. Install/deployment lifecycle

Verify source behavior for:

- `post-create-project-cmd`;
- `.env` creation and secret generation;
- runtime directory creation;
- `module:install` / `module:remove`;
- config publication rollback/invalidation;
- `optimize` and `optimize:clear`;
- final change from the Infbyte development Foundation constraint to `^2.0` before release.

## 5. Documentation in parallel

Foundation documentation freeze remains active, but application-facing docs should now be updated together with Infbyte so examples are not immediately stale.

# After implementation/config/docs — deferred test/release matrix

When explicitly started, run both repositories through:

1. Composer validation/dependency checks;
2. static analysis;
3. PHPForge quality/security/release gates;
4. PHPUnit/integration suites;
5. clean Infbyte create-project/install;
6. core-only Foundation/Infbyte runtime without optional packages;
7. optional module install/remove/config publication matrix;
8. CLI version/list/help/completion preflight;
9. Web/CLI/Worker/Scheduler load isolation;
10. persistent request/worker/scheduler scope reset and soak;
11. fork/process-pool isolation;
12. cache/session/scheduler/webhook locking topology;
13. config/command/schedule/route/container optimized-artifact behavior;
14. Unix/Windows ProcessRunner checks where CI permits;
15. startup/memory/throughput benchmarks;
16. stale-symbol/config/doc scan;
17. clean archive/consumer installation;
18. final Foundation 2.0 + Infbyte compatibility/release-readiness review.

# Do not regress

- no `FoundationConsole`, `Foundation::console()`, or `src/Console` compatibility hierarchy;
- no broad `Application::cache()`, `db()`, `files()`, `communication()`, `messaging()`, `validator()` proxy surface;
- no static global application/facade state;
- no generic Foundation Data/ArrayKit proxy layer;
- no generic `FilesystemManager`;
- no global current-application path helpers;
- no `auth.drivers.ids` or generic IdentifierManager surface;
- no `app.container.request_scope`;
- no InterMix repository/resolver internals when public APIs exist;
- no duplicated specialist-library engines;
- no generated optimized artifacts committed to Infbyte;
- no Infbyte workaround when the underlying defect belongs in Foundation.
