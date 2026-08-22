# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only.

## Working branch

`feature/foundation-2.0`

## Maintenance rule

After every completed implementation/review batch:

1. update this file before starting the next batch;
2. record the latest Foundation code checkpoint;
3. move finished work into **Completed**;
4. keep **Immediate next work** limited to the next concrete phase;
5. keep later source work ordered under **Remaining source queue**;
6. do not reopen completed cleanup without new source evidence;
7. defer the full test/release matrix until source/runtime cleanup is complete.

## Current checkpoint

- Date: 2026-08-23
- Latest Foundation code commit: `f3230553720dcfc44e6c3ad185a789ce200a8bc2`
- Latest completed phases: InterMix 9.1.1 integration, UID canonicalization, Foundation env helpers, declarative config paths, CacheLayer lock topology, DB/cache activation-order cleanup.
- Foundation branch: `feature/foundation-2.0`.
- Full tests/release matrix: still deferred until the final source/runtime sweep is complete.

## Framework boundary

Foundation and Infbyte now have an explicit relationship:

- **Foundation** is the reusable framework/runtime layer, analogous to Illuminate.
- **Infbyte** (`infocyph/Infbyte`) is the opinionated application/project skeleton that assembles Foundation, analogous to Laravel's application layer.
- Foundation owns reusable runtime, application composition primitives, configuration engine, CLI/runtime support, common framework integration policy, and specialist-library bridges.
- Infbyte owns project defaults, published application config, routes, application code, bootstrap entry files, deployment conventions, and the final opinionated framework experience.
- Do not move reusable runtime machinery into Infbyte merely to keep Foundation smaller.
- Do not make Foundation depend on Infbyte.

## Current dependency baseline

Core:

- `php` `^8.4`
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

No attached-library update is currently required beyond the completed InterMix 9.1.1 prerequisite.

# Completed

## Foundation 2.0 structural cleanup

- Console ownership merged into Foundation; no compatibility hierarchy should be restored.
- Broad static/pass-through facade direction removed.
- Broad `Application::*()` specialist-manager accessors removed and must stay removed.
- `DatabaseManager`, `RouterManager`, `RouteCacheRouter`, generic `FilesystemManager`, generic Data proxy layer and obsolete manager support abstractions removed/reduced.
- Specialist libraries own their engines; Foundation keeps application/runtime/integration policy.
- Pathwise/filesystem cleanup completed.
- Communication/notification and messaging surfaces reduced to focused Foundation integration.
- Config/default ownership consolidated around `FoundationDefaults` and `ConfigRepository`.
- Runtime provider groups use `common`, `web`, `cli`, `worker`, `scheduler`.
- Runtime validation remains declarative/resolution-free.
- `bin/infbyte` is package-owned and executable.
- Module install/remove use `--update-no-dev`.
- Module config publication is staged/rollback-safe.
- Logger contracts are PSR-3 typed.

## InterMix 9.1.1 integration — complete

Foundation now uses the public InterMix distinctions correctly:

- `Container::definitions()->has($id)` for explicit registration;
- `Container::isResolved($id)` for successful resolution history;
- `Container::has($id)` only for broad PSR-style resolvability.

Completed:

- minimum InterMix constraint bumped to `^9.1.1`;
- auth override/default-registration uses `definitions()->has()`;
- pooled-worker parent cleanliness uses `isResolved()`;
- internal `getRepository()` coupling covered by these APIs removed.

## UID v5 canonical ID integration — complete

- `infocyph/uid` remains core/mandatory.
- `ExecutionId::generate()` now uses UID UUIDv7.
- Auth ID generation remains UID-backed.
- deterministic schedule/cache keys remain deterministic hashes where identity generation would be wrong;
- cryptographic secrets/nonces/temp suffixes remain cryptographic randomness where that is the correct semantic.
- no generic `IdentifierManager`/UID facade was reintroduced.

## Foundation environment helper contract — complete

Foundation now deliberately owns only the global environment helper surface required by application config:

- `env()`;
- `env_bool()`;
- `env_int()`;
- `env_string()`.

Implementation rules now fixed:

- helpers are Composer-loaded from one small Foundation helper file;
- environment lookup uses ArrayKit's hydrated environment state;
- Foundation does not duplicate ArrayKit's `.env` parser;
- no service locator or application singleton is exposed through helpers;
- path helpers were **not** restored globally because lazy config may be evaluated for multiple applications in one process and a static current application path would be unsafe.

Published Foundation config paths are therefore declarative/application-relative and resolved later by `PathManager`/the owning integration factory. Cache, filesystem and notification spool defaults have been migrated away from `storage_path()`/`public_path()` dependencies.

## CacheLayer lock topology — complete

Foundation no longer silently converts an unspecified coordination lock into an unrelated file lock.

Current behavior:

- an explicit `cache.lock.driver` wins;
- otherwise `CacheLayerFactory::lock()` resolves the selected/default cache store and inherits its native `AuthenticationStateCacheInterface::authenticationStateLock()` capability;
- `cache.lock.store` is optional and defaults to the application's default cache store instead of hard-coded `local`;
- stores without a native coordination lock fail clearly when a coordination feature requests one;
- explicit local file locking remains available when deliberately configured;
- store-local CacheLayer lock configuration remains respected by normal cache construction.

This applies to scheduler overlap/single-server, singleton workers, webhook replay, auth/session coordination and other Foundation mutex consumers through the shared factory path.

## CacheManager / DBLayer activation-order coupling — complete

- resolving CacheManager no longer autoloads DBLayer merely because DBLayer is installed;
- CacheManager wires DBLayer only when DBLayer is already active/loaded;
- DatabaseServiceProvider detects an already-resolved CacheManager and completes the cache bridge when DB activates later;
- behavior is therefore independent of whether cache or database capability resolves first;
- optional package presence remains distinct from capability activation.

# Architecture decisions fixed

## Specialist package ownership

Do not duplicate engines already owned by CacheLayer, DBLayer, Epicrypt, Omnibus, OTP, Pathwise, ReqShield, TalkingBytes, UID, Webrick or InterMix.

Foundation integration code is justified only when it adds reusable framework configuration, runtime policy, security policy, lifecycle composition, named profiles, or cross-package orchestration.

## Config paths stay declarative

- Global path helpers are not part of Foundation 2.0's config contract.
- Published reusable config should prefer application-relative paths.
- `PathManager` and the integration that owns a path resolve it against the current application.
- This preserves multiple-application/process safety and keeps lazy config free of mutable global application state.

# Immediate next work — optimized artifact trust/freshness

The final known performance issue before the closing source sweep is repeated source filesystem validation on optimized startup/preflight paths.

## Scope

Audit and align:

- config cache;
- command manifest/cache;
- schedule manifest/cache;
- route cache;
- compiled container metadata where applicable.

## Target production policy

When a deployment has deliberately generated optimized artifacts, runtime startup should trust compatible artifacts without repeatedly scanning/hash/stat-ing all source files.

Required design:

1. source mode remains authoritative when the artifact is absent;
2. optimize/build/deployment commands own artifact generation and invalidation;
3. artifacts carry a cheap format/schema/framework/build compatibility identity;
4. optimized production reads validate only the artifact itself and cheap compatibility metadata;
5. do not hash/stat every config/route/command/schedule source file on every startup/preflight;
6. development/source-freshness checking may remain available as an explicit mode if useful, but it must not be the production optimized default;
7. corrupt/incompatible artifacts fall back safely to source where fallback is semantically valid;
8. `optimize:clear`, module/config publication and other mutating commands invalidate the affected artifacts explicitly.

## Specific current hotspots

- `ConfigLoader` computes the complete config/provider source fingerprint before attempting to load the config manifest.
- `CommandCacheManager`/CLI preflight recompute hashes/stat application command sources before trusting the command cache.
- schedule cache checks schedule source freshness at runtime.
- route cache behavior must be reviewed against the same contract so all artifact types follow one model.

# Remaining source queue

## Final source/runtime integration sweep

After artifact policy is corrected, re-scan the complete branch for:

- Web/CLI/Worker/Scheduler boot isolation;
- optional package autoload leaks;
- package-present vs module-configured/activated semantics;
- stale `class_exists()` checks that accidentally activate optional packages;
- dead wrappers and one-use abstractions;
- stale service IDs, aliases, imports and config keys;
- obsolete Console-era compatibility remnants;
- specialist APIs unnecessarily mirrored by Foundation;
- process/fork/runtime lifecycle defects;
- remaining path-helper calls or global application state;
- remaining ad-hoc Foundation identity generation;
- any stale references to InterMix internals.

Keep `RuntimeContextTracker`'s touched-state cleanup model unless a concrete defect appears; do not replace it with a broad global reset registry.

# Documentation and release gate

Once the source sweep is complete, source work is frozen and the project enters finalization:

1. update README/docs against the actual Foundation 2.0 public surface;
2. remove stale references to deleted APIs/configs/Console ownership/path helpers;
3. document Foundation vs Infbyte ownership clearly;
4. run Composer validation and dependency checks;
5. run static analysis and PHPForge gates;
6. run the complete PHPUnit/integration suite;
7. verify core-only installation/runtime without optional packages;
8. verify optional-module combinations;
9. verify Web/CLI/Worker/Scheduler load isolation;
10. verify persistent worker/scheduler scope reset and fork safety;
11. verify scheduler/cache coordination topology;
12. benchmark startup, optimized artifact paths, memory and representative throughput;
13. run dead-symbol/config/doc scans;
14. verify a clean archive/consumer install;
15. perform final Foundation 2.0 release-readiness review.

# Deferred Infbyte follow-up — do after Foundation 2.0 is frozen

Do **not** modify `infocyph/Infbyte` during the current Foundation finalization. Track the required application-skeleton migration here and execute it after Foundation 2.0's public surface is frozen.

Current Infbyte follow-up list:

1. bump `infocyph/foundation` from the current `^1.3` constraint to the final Foundation `^2.0` release;
2. update Infbyte config files to the final Foundation 2.0 config schema/defaults and remove stale 1.x keys such as old container/request-scope settings;
3. remove the old `config/ids.php`/IdentifierManager-era surface if it is still present, because UID is now a core Foundation provider and generic Foundation ID facades/managers are gone;
4. align Infbyte's root `infbyte` launcher with the final package-owned `vendor/bin/infbyte`/Foundation CLI delegation model, keeping the project root script tiny;
5. verify `bootstrap/app.php` and runtime entrypoints select the correct `Foundation::web()`, `cli()`, `worker()`, and `scheduler()` modes without rebuilding runtime ownership in the skeleton;
6. republish/refresh module config from Foundation 2.0 as appropriate rather than preserving stale application copies;
7. update `.env.example` for the final Foundation 2.0 environment names/defaults, including the new lock-store inheritance behavior;
8. update deployment flow so optimized Foundation artifacts are built during deployment and not source-revalidated on every request/process start;
9. update Infbyte README/docs to present Infbyte as the opinionated application framework built on Foundation;
10. run Infbyte's own clean-install/application smoke tests against the released Foundation 2.0 package.

The Infbyte repo remains untouched until this Foundation plan reaches the release-frozen state.

# Do not regress

- Do not restore broad `Application::cache()`, `db()`, `files()`, `communication()`, `messaging()`, `validator()`, etc.
- Do not restore the static global facade/application-state architecture.
- Do not restore generic Foundation Data/ArrayKit proxy APIs.
- Do not restore the deleted generic `FilesystemManager`.
- Do not restore global application path helpers for lazy config.
- Do not use `Container::has()` where explicit registration or resolution-history semantics are required.
- Do not access InterMix `getRepository()` when a public API exists.
- Do not duplicate specialist engines in Foundation.
- Do not move reusable Foundation runtime ownership into Infbyte.
