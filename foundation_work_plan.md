# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only and is not the current TODO source.

## Working branch

`feature/foundation-2.0`

## Maintenance rule

After every completed implementation/review batch:

1. update this file before starting the next batch;
2. record the latest Foundation **code** checkpoint separately from tracker-only commits;
3. move finished work into **Completed**;
4. keep **Immediate next work** limited to the next concrete phase;
5. keep later work ordered under the remaining queue;
6. do not reopen completed cleanup without new source evidence;
7. do not modify Infbyte while Foundation 2.0 is still being finalized;
8. keep the full test/release matrix deferred until the user explicitly moves to that phase.

# Current checkpoint

- Date: 2026-08-23
- Latest Foundation source-code commit: `9d37e2b5e05629a7fd735a379aed70546f458127`
- Latest source phase: **final Foundation 2.0 source/runtime integration sweep — complete**.
- Source status: **freeze source architecture unless documentation or later test evidence exposes a concrete defect**.
- Foundation branch: `feature/foundation-2.0`.
- Full PHPUnit/static-analysis/PHPForge/release matrix: **not run yet; intentionally deferred**.

## Framework boundary

Foundation and Infbyte have a fixed dependency direction:

- **Foundation** is the reusable framework/runtime layer, analogous to Illuminate.
- **Infbyte** (`infocyph/Infbyte`) is the opinionated application/project skeleton built on Foundation, analogous to Laravel's application layer.
- Foundation owns reusable runtime, composition primitives, configuration/bootstrap machinery, CLI/runtime support, common framework integration policy, and specialist-library bridges.
- Infbyte owns project/application defaults, project entry files, routes, application code, deployment conventions, and the final opinionated application experience.
- Foundation must not depend on Infbyte.
- Reusable runtime machinery must not be pushed into Infbyte merely to make Foundation smaller.

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

No attached-library change is currently required. InterMix 9.1.1 was the upstream prerequisite identified by the Foundation review and is already integrated.

# Completed

## 1. Foundation 2.0 structural ownership cleanup

- Console ownership merged into Foundation; no compatibility hierarchy should be restored.
- No `Infocyph\\Console`, `Infocyph\\Foundation\\Console`, `src/Console`, Console bridge, compatibility proxy, Composer replacement, or dual CLI hierarchy remains part of the target architecture.
- Broad static/pass-through facade direction removed.
- Broad specialist `Application::*()` manager accessors such as cache/db/files/messaging/communication/validator were removed and must stay removed.
- `DatabaseManager`, `RouterManager`, `RouteCacheRouter`, generic `FilesystemManager`, generic Data proxy layer, and obsolete manager support abstractions removed/reduced.
- Specialist libraries own their engines; Foundation keeps reusable application/runtime/integration policy only.
- Pathwise/filesystem ownership cleanup complete.
- Communication/notification and messaging surfaces reduced to focused integration rather than specialist-library mirrors.
- Config/default ownership consolidated around `FoundationDefaults` and `ConfigRepository`.
- Runtime provider groups are `common`, `web`, `cli`, `worker`, `scheduler` only.
- `bin/infbyte` is the canonical executable package binary.
- Module install/remove use `--update-no-dev`.
- Module config publication is staged/rollback-safe and does not overwrite existing host config.
- Logger contracts are PSR-3 typed.

## 2. InterMix 9.1.1 public API integration

Foundation now uses the three InterMix semantics deliberately:

- `Container::definitions()->has($id)` — explicit registration;
- `Container::isResolved($id)` — successful-resolution history;
- `Container::has($id)` — broad PSR-style resolvability only.

Completed:

- minimum InterMix version is `^9.1.1`;
- auth override/default registration uses public explicit-definition inspection;
- pooled-worker parent cleanliness uses public resolution history;
- `ServiceProvider::hasExplicitBinding()` uses `definitions()->has()`;
- command handlers resolve through public `Container::make()`;
- known `getRepository()` / current-resolver coupling has been removed from the Foundation source paths reviewed in the final sweep.

Do not reintroduce repository/resolver internals when a public InterMix API exists.

## 3. UID v5 canonical Foundation identity

- `infocyph/uid` remains core/mandatory.
- runtime `ExecutionId` uses UUIDv7.
- the default InterMix container alias now uses UID UUIDv7 rather than random hex.
- auth/application identity remains UID-backed with purpose-specific UUIDv7/ULID policy.
- deterministic cache/lock/schedule keys remain deterministic hashes where identity generation would be semantically wrong.
- secrets/nonces/temp staging suffixes remain cryptographic randomness where entropy is the real requirement.
- no generic `IdentifierManager` or UID pass-through facade exists.

## 4. Foundation environment/config path contract

Foundation deliberately owns only the small global environment helper surface required by reusable config templates:

- `env()`;
- `env_bool()`;
- `env_int()`;
- `env_string()`.

Rules now fixed:

- helpers are Composer-loaded from one Foundation helper file;
- reads use ArrayKit's hydrated environment state;
- Foundation does not duplicate ArrayKit's `.env` parser;
- no global service locator/current-application singleton is exposed;
- global `storage_path()`/`public_path()`-style application path state was not restored;
- published paths are application-relative/declarative and resolved later by `PathManager` or the owning integration.

Cache/filesystem/notification spool and related templates have been migrated to this model.

## 5. CacheLayer lock topology

Foundation no longer silently replaces unspecified shared coordination with an unrelated file lock.

Current model:

- explicit `cache.lock.driver` wins;
- otherwise the selected/default CacheLayer store provides its native authentication/coordination lock when available;
- `cache.lock.store` is optional and inherits the default cache store;
- stores without an appropriate native lock fail clearly when a coordination feature requests one;
- deliberate local file locking remains available explicitly;
- production/session topology validators evaluate the effective inherited lock topology rather than requiring redundant explicit lock configuration.

This shared path covers scheduler overlap/single-server, singleton workers, webhook replay, auth/session coordination, migrations and other Foundation mutex consumers.

## 6. CacheManager / DBLayer activation-order cleanup

- resolving cache no longer autoloads DBLayer merely because DBLayer is installed;
- CacheManager wires DBLayer only when DBLayer is already active/loaded;
- DatabaseServiceProvider completes the cache bridge when DB activates later and CacheManager is already resolved;
- behavior is independent of cache-vs-database resolution order;
- optional package presence remains distinct from capability activation.

## 7. Optimized artifact trust model

Config, command, schedule, route, and compiled-container paths now follow one deployment-owned optimization policy.

### Config

- source remains authoritative when no compatible artifact exists;
- cache build records provenance/format/schema data;
- optimized runtime does not scan/stat the whole config/provider source tree before using a compatible compiled artifact;
- config cache build folds `bootstrap/providers.php` into compiled provider groups, so compiled boot may safely skip that source file.

### Command

- command manifests use explicit manifest format/version metadata;
- CLI preflight no longer hashes Foundation command metadata, command route source, and every application handler before trusting the artifact;
- invalid/unsupported manifests fall back to source command registration.

### Schedule

- schedule manifests use explicit format/version metadata;
- scheduler runtime no longer hashes the schedule source on each load before using a built artifact;
- invalid artifacts fall back to source.

### Routes

- `route:cache`/build owns Webrick route artifact generation and the Foundation compatibility marker;
- runtime checks cheap routing-configuration compatibility and Webrick cache bootability;
- normal source web boot no longer writes, generates, or "blesses" route cache state;
- recursive route/controller source hashing was removed from optimized startup.

### Container

- runtime-specific InterMix compiled artifacts remain deployment-generated and activated through the Foundation optimize manifest.

General rule: runtime consumes compatible optimized artifacts; build/deployment commands generate/invalidate them.

## 8. Final source/runtime integration sweep — complete

The final branch-native sweep covered Application/bootstrap, container, config, command/CLI, ProcessRunner, routes, Web/HTTP, worker, scheduler, session, validation, module/readiness, logging/security, optional-package bridges, and runtime reset behavior.

Concrete fixes completed:

- fixed ServiceRegistry activation so an added-but-unregistered provider is registered before use;
- duplicate provider adds no longer replace a live registered provider while retaining stale state;
- deferred-provider activation restores deferred state if activation fails;
- removed remaining command resolver-internal coupling in favor of public `Container::make()`;
- removed the stale `ServiceProvider::getRepository()` explicit-binding check;
- dependency-free `messaging.workers` is now empty, so a core-only application does not falsely activate/require Omnibus;
- default InterMix container identity now uses UID UUIDv7;
- route runtime no longer generates optimization artifacts during normal requests;
- Foundation reusable runtime defaults no longer advertise Infbyte application identity:
  - HTTP User-Agent defaults to null;
  - cache prefix defaults to `foundation:cache:`;
  - lock prefix defaults to `foundation:cache:lock:`;
  - browser-session cookie defaults to `foundation_session`.

Verified without further source changes:

- ProcessRunner defaults to argv/no-shell, keeps shell execution explicit, bounds output, propagates env/cwd, maps termination deterministically, and handles Unix/Windows process-tree termination;
- `ExecutionScope` owns one InterMix scope per execution unit;
- `RuntimeContextTracker` resets only touched external/static/process-local state and does not autoload optional packages merely for cleanup;
- Web requests, commands, scheduler entries, and worker jobs use execution scopes;
- file/array sessions stay independent of CacheLayer/DBLayer until a selected backend requires them;
- ReqShield database access remains lazy behind actual DB validation use;
- worker pools reject process-local transports and check parent process cleanliness;
- Auth HTTP-specific services remain Web-only;
- no `src/Console` runtime hierarchy remains;
- no global application path helper state was restored;
- no new broad reset registry or generic specialist proxy was introduced.

### Source freeze rule

Do not continue speculative refactoring now. Reopen Foundation source architecture only if documentation review, static analysis, tests, benchmarks, or release checks expose a concrete defect.

# Architecture decisions fixed

## Specialist package ownership

Do not duplicate engines already owned by CacheLayer, DBLayer, Epicrypt, Omnibus, OTP, Pathwise, ReqShield, TalkingBytes, UID, Webrick or InterMix.

Foundation integration is justified only for reusable framework configuration, runtime/security/lifecycle policy, named application profiles, or cross-package composition.

## Config/runtime identity stays Foundation-neutral

Foundation defaults must describe Foundation, not the Infbyte application skeleton.

- Framework-owned defaults may use `foundation:*`/`foundation_*` identity where a neutral default is required.
- Host applications may override these values.
- Infbyte-specific branding belongs in the Infbyte application skeleton, not Foundation source defaults.

## Config paths stay declarative

- no mutable global current-application path state;
- reusable config templates use application-relative paths;
- `PathManager`/the owning integration resolves paths for the current Application.

## Runtime reset stays targeted

Keep `RuntimeContextTracker`'s touched-state cleanup model. Do not replace it with a global reset registry unless a concrete external/static lifetime defect requires one.

# Immediate next work — documentation freeze

Source/runtime architecture is now frozen. The next phase is to make documentation describe the actual Foundation 2.0 implementation rather than historical/1.x/Console-era behavior.

## Documentation sweep

Review and update:

- root `README.md`;
- `docs/README.md`;
- architecture/runtime documentation;
- configuration docs;
- CLI/console documentation;
- cache/database/filesystem/security/auth/session/communication/messaging/validation docs;
- module/install/config publication docs;
- optimization/deployment docs;
- examples and code snippets.

Required outcomes:

1. remove stale deleted-manager/facade/Console compatibility references;
2. document exactly four runtime modes: Web, CLI, Worker, Scheduler;
3. distinguish runtime mode from optional capability/module;
4. document Foundation vs Infbyte ownership clearly;
5. document InterMix lazy/scoped composition and execution lifetime accurately;
6. document optional package presence vs actual configured/activated capability;
7. document native CacheLayer lock inheritance and distributed topology requirements;
8. document deployment-owned optimized artifacts and explicit invalidation/build commands;
9. update neutral Foundation runtime defaults (`foundation:*`, `foundation_session`, nullable HTTP User-Agent);
10. ensure docs match current module constraints/config names/public classes;
11. remove stale `storage_path()`/global application-helper assumptions;
12. freeze public names/config shapes after documentation matches implementation.

Documentation work may correct documentation only. If it reveals a real implementation defect, record the evidence here before reopening source.

# After documentation — deferred test/release matrix

The full test/release phase remains deferred until explicitly started.

When started, run:

1. Composer validation and dependency checks;
2. static analysis;
3. PHPForge quality/security/release gates;
4. complete PHPUnit/integration suite;
5. core-only install/runtime matrix with optional packages absent;
6. optional-module install/config/activation combinations;
7. Web/CLI/Worker/Scheduler load-isolation checks;
8. persistent request/worker/scheduler execution-scope reset/soak checks;
9. fork/process-pool parent and child isolation checks;
10. scheduler/session/auth/webhook locking topology checks;
11. config/command/schedule/route/container optimized-artifact tests;
12. ProcessRunner Unix/Windows behavior where CI platforms permit;
13. startup/steady-state/memory/representative throughput benchmarks;
14. final stale-symbol/config/doc scan;
15. clean archive/consumer installation test;
16. final Foundation 2.0 release-readiness review.

No test success should be inferred from the source review alone.

# Deferred Infbyte follow-up — execute only after Foundation 2.0 freeze/release surface

Do **not** modify `infocyph/Infbyte` during Foundation finalization. Track application-skeleton work here for later.

Current Infbyte follow-up list:

1. bump `infocyph/foundation` from the current 1.x constraint to the final `^2.0` release;
2. update Infbyte config to the final Foundation 2.0 schema and remove stale 1.x/container/request-scope keys;
3. remove old `config/ids.php` / IdentifierManager-era configuration if still present; generic Foundation ID manager/facade APIs are gone;
4. keep the root `infbyte` launcher a tiny delegator to the package-owned Foundation CLI binary/runtime;
5. align project entrypoints with `Foundation::web()`, `Foundation::cli()`, `Foundation::worker()`, and `Foundation::scheduler()` without rebuilding runtime ownership in the skeleton;
6. refresh/publish module config from the finalized Foundation 2.0 templates rather than carrying stale application copies;
7. update `.env.example` for final 2.0 environment names and native/default lock inheritance;
8. decide Infbyte application branding explicitly now that Foundation defaults are neutral:
   - set `COMMUNICATION_HTTP_USER_AGENT` to an Infbyte/application-specific value if desired;
   - set `CACHE_PREFIX=infbyte:cache:` if that application namespace is desired;
   - set `CACHE_LOCK_PREFIX=infbyte:cache:lock:` if desired;
   - set `SESSION_COOKIE=infbyte_session` if desired;
9. update deployment flow so Foundation optimized artifacts are generated during deployment and not source-revalidated on each request/process start;
10. update Infbyte README/docs to present Infbyte as the opinionated application framework/skeleton built on Foundation;
11. run Infbyte clean-install/application smoke tests against the released Foundation 2.0 package.

The Infbyte repository remains untouched until Foundation reaches its frozen/release-ready state.

# Do not regress

- Do not restore broad `Application::cache()`, `db()`, `files()`, `communication()`, `messaging()`, `validator()`, etc.
- Do not restore static global facade/application-state architecture.
- Do not restore generic Foundation Data/ArrayKit proxy APIs.
- Do not restore the deleted generic `FilesystemManager`.
- Do not restore global application path helpers for lazy config.
- Do not use `Container::has()` where explicit registration or resolution-history semantics are required.
- Do not access InterMix repository/current-resolver internals when a public API exists.
- Do not duplicate specialist engines in Foundation.
- Do not make Foundation depend on Infbyte.
- Do not reintroduce Infbyte-branded runtime defaults into Foundation.
- Do not make optional package installation itself equal capability activation.
- Do not create runtime optimization artifacts during normal Web/CLI/Worker/Scheduler execution.
- Do not reopen frozen source architecture without concrete evidence from docs/tests/benchmarks/release checks.
