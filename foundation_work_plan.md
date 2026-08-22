# Foundation 2.0 — Live Work Plan

> Maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is historical architecture/reference material only.

## Working branch

`feature/foundation-2.0`

## Plan maintenance rule

After every completed implementation/review batch:

1. update this file before starting the next batch;
2. record the new code checkpoint and completed work;
3. rewrite **Immediate next work** to the exact next actions;
4. keep later work ordered under **Remaining queue**;
5. remove stale/speculative TODOs once the current source proves them complete;
6. do not reopen completed cleanup without new source evidence.

## Current checkpoint

- Date: 2026-08-22
- Latest Foundation code commit: `da161cd4037d793a0e28079cc8872c86b458098a`
- Subject: `fix: preserve explicit auth override semantics`
- Later commits on this branch are plan-only unless explicitly recorded here as code changes.
- Main manager/facade/provider/config/default cleanup is complete.
- Full tests/release matrix remain intentionally deferred until the remaining source/runtime work is finished.

## Dependency baseline verified against current GitHub release heads

Foundation is already aligned with the current released code for the attached Infocyph libraries:

- `infocyph/arraykit` `^5.1.1`
- `infocyph/intermix` `^9.1`
- `infocyph/uid` `^5.0`
- `infocyph/webrick` `^4.0.1`
- `infocyph/cachelayer` `^3.1.3`
- `infocyph/dblayer` `^4.1`
- `infocyph/epicrypt` `^2.1`
- `infocyph/omnibus` `^2.2`
- `infocyph/otp` `^6.0`
- `infocyph/pathwise` `^3.1`
- `infocyph/reqshield` `^3.0`
- `infocyph/talkingbytes` `^2.0`

Do not bump or modify attached libraries merely for churn. Only InterMix currently requires an upstream API addition before Foundation can remove its remaining internal-container coupling cleanly.

# Phase 0 — upstream prerequisite: InterMix only

Complete and release the required InterMix public introspection API before continuing the affected Foundation cleanup.

## InterMix requirement A — explicit definition existence

Foundation needs to distinguish **explicitly registered definitions** from merely resolvable services.

`Container::has()` must remain the broad PSR-style/resolution check. It currently correctly answers whether a service can resolve through explicit definitions, resolved entries, autowireable concrete classes, or environment-bound interfaces. It must not be repurposed for override detection.

Add a public API with explicit-definition semantics, preferably one of:

- `definitions()->has(string $id)`; or
- `hasDefinition(string $id)`.

It must check only intentionally registered definitions/resources relevant to overriding a default binding. It must not become true merely because:

- a concrete class is autoloadable/autowireable;
- an interface has an environment concrete;
- the service happened to be resolved previously.

Foundation currently needs this in auth registration instead of reading `Container::getRepository()` internals.

## InterMix requirement B — resolved-state introspection

Foundation worker-pool fork safety needs a separate public question: **has this service already been resolved in this process/container?**

Expose an explicit public resolved-state API, for example:

- `resolutions()->has(string $id)`; or
- `isResolved(string $id)`.

This API must not be conflated with explicit-definition existence or broad `Container::has()` resolvability.

Foundation currently uses internal repository resolved-state checks before forking pooled workers. Once this API exists, remove that repository access.

## Phase 0 completion gate

Before Foundation continues with the affected integration work:

1. implement the two distinct InterMix semantics;
2. keep `Container::has()` behavior unchanged;
3. release/tag the InterMix update;
4. update Foundation's InterMix constraint only if the new release requires it;
5. then remove every remaining Foundation `getRepository()` dependency that is covered by these public APIs.

No ArrayKit, CacheLayer, DBLayer, UID, TalkingBytes, Omnibus, Pathwise, ReqShield, OTP, Epicrypt, or Webrick upstream change is currently required.

# Foundation architecture decisions now fixed

## UID is the canonical Foundation ID provider

`infocyph/uid` is a required/core Foundation provider and is the default source for Foundation-generated IDs.

- Auth IDs already correctly use UID v5 (`uuid7` by default, `ulid` for correlation IDs).
- Replace ad-hoc runtime ID generation such as `ExecutionId::generate()` using native random hex with an appropriate UID v5 primitive.
- Scan all Foundation-owned generated identifiers and use UID where the identifier is part of Foundation application/runtime identity.
- Do not introduce a generic `IdentifierManager` or a pass-through UID facade merely to wrap UID.
- Native randomness remains valid where the value is cryptographic entropy/secret material rather than an application identifier.

## Environment helper ownership stays in Foundation

Foundation owns the application-facing `env*()` configuration helper surface.

- Do **not** request ArrayKit changes for global `env*()` helpers.
- Keep ArrayKit as the parser/config engine underneath Foundation where useful.
- Restore/provide the Foundation helper contract required by published Foundation config templates.
- Ensure at minimum the currently used helper surface is valid and deliberately loaded: `env()`, `env_bool()`, `env_int()`, `env_string()` plus Foundation path helpers used by published config files.
- Keep helper behavior thin, deterministic and compatible with Foundation's environment hydration.
- Do not duplicate ArrayKit's `.env` parsing engine in Foundation.

# Immediate next work after Phase 0

Work in this order.

## 1. Remove InterMix internal repository coupling

After the InterMix release:

- replace auth `getRepository()` override checks with the explicit-definition API;
- replace pooled-worker `hasResolved()` repository reads with the public resolved-state API;
- search the whole Foundation source for any other `getRepository()` use and remove it unless there is a truly unsupported semantic requirement;
- preserve lazy loading, scopes and fork-before-resolution guarantees.

## 2. Canonicalize Foundation-generated IDs through UID v5

- change runtime `ExecutionId` generation to UID;
- audit command, schedule, worker, auth, correlation, operation/history and generated resource identifiers for ad-hoc ID creation;
- use UUIDv7/ULID/another UID primitive according to semantics rather than one format blindly;
- keep existing external/public format expectations coherent;
- do not add another manager/facade layer.

## 3. Repair Foundation config helper contract

Published config templates currently rely on Foundation-style global helpers while the old helper autoload was removed.

- define the canonical Foundation helper file/ownership;
- restore deliberate Composer/bootstrap loading for the required helpers without restoring obsolete global facade state;
- route environment reads through Foundation/ArrayKit's current environment source;
- preserve typed defaults for bool/int/string helpers;
- verify path helpers resolve from the actual application base/storage/public/config paths rather than process-global stale state;
- scan every `resources/config/*.php` template and eliminate undefined helper calls.

## 4. Correct CacheLayer lock topology

The current `CacheLayerFactory::lock()` behavior can silently fall back to a node-local file lock when no global lock driver is configured. That is unsafe for multi-node coordination such as scheduler single-server/overlap protection, singleton workers and webhook replay protection.

Use existing CacheLayer 3.1.3 capabilities; no CacheLayer upstream change is currently needed.

Target behavior:

- explicit configured lock provider wins;
- otherwise inherit the selected CacheLayer store's native coordination lock where the store exposes one;
- never silently downgrade a shared/distributed semantic requirement to an unrelated local file lock;
- fail clearly when a coordination feature is enabled but the selected topology cannot provide an appropriate lock;
- keep intentionally local/single-node lock behavior available when explicitly configured.

Audit all callers including:

- scheduler overlap/single-server execution;
- singleton workers;
- webhook replay store;
- session/auth state coordination;
- migrations and any other cache-backed mutex path.

## 5. Remove CacheManager → DBLayer autoload/order coupling

Current default-cache resolution can autoload DBLayer through `class_exists(DB::class)` and wire static DB cache state depending on which capability resolves first.

Refactor so:

- resolving cache never loads DBLayer merely because DBLayer happens to be installed;
- DBLayer integration is owned by the DB integration/bootstrap side or by an explicit bridge activated only when both capabilities are active;
- resolution order does not change behavior;
- DBLayer query-cache integration still receives Foundation's intended default CacheLayer store when both capabilities are actually active.

## 6. Optimize compiled artifact trust/freshness policy

Current optimized paths still perform source filesystem work before trusting caches:

- config cache scans/stats config/provider source files;
- command manifest hashes Foundation metadata, command routes and application handler files;
- schedule manifest hashes schedule source;
- route cache/freshness behavior must be reviewed alongside these.

For production/performance-first operation, design one consistent policy so compiled artifacts can be trusted without repeatedly rescanning source files on every startup/preflight.

Requirements:

- source mode remains safe and authoritative when artifacts are absent;
- optimize/build/deployment commands own artifact generation and invalidation;
- cache manifests carry format/schema/build identity sufficient for compatibility checks;
- avoid repeated per-source hashing/stat calls in hot startup/preflight paths;
- development ergonomics may use a stricter freshness mode if desired, but production optimized mode should not pay source-validation cost continuously;
- invalid/corrupt artifacts must fail safely back to source where that fallback is semantically valid.

## 7. Continue whole-source runtime/integration sweep

After the concrete items above:

- re-audit Web / CLI / Worker / Scheduler boot isolation;
- review Bootstrapper optional-package checks for accidental autoloading of unrelated capabilities;
- review module-installed vs module-configured/activated semantics;
- verify current package public APIs are used directly where Foundation adds no policy;
- remove dead wrappers, one-use abstractions, stale aliases/config keys and old Console-era compatibility remnants;
- preserve `RuntimeContextTracker`'s touched-state cleanup approach unless a concrete defect is found; do not replace it with a broad global reset registry.

# Confirmed complete / remove from old TODO lists

The current source already proves these older plan items complete or no longer actionable:

- module install/remove already use `--update-no-dev`;
- module config publication already stages changes and rolls back partial publication;
- `bin/infbyte` is already executable and package-owned;
- logger contracts are already PSR-3 typed;
- broad manager/facade pruning is complete;
- generic `FilesystemManager` is already removed;
- `DatabaseManager`, `RouterManager`, `RouteCacheRouter` and obsolete static application facade direction are already removed;
- current scheduler/worker execution uses centralized `ExecutionScope` cleanup;
- current attached Infocyph releases other than the requested InterMix API addition are sufficient for the next Foundation work.

Do not carry these forward as speculative TODOs.

# Remaining queue after source/runtime stabilization

## Documentation freeze

Only after source/main implementation stabilizes:

- update README and `docs/` to the actual Foundation 2.0 public surface;
- remove stale references to deleted managers/facades/Console bridges/config keys;
- document runtime isolation and optional-capability ownership;
- document module/config/optimization behavior from the final implementation;
- freeze public names/config shapes before the release-test pass.

## Deferred tests and release matrix

Then perform the complete final gate:

- core install/runtime checks;
- optional-module matrix;
- Web / CLI / Worker / Scheduler load-isolation checks;
- persistent worker/scheduler scope-reset and soak checks;
- worker-pool/fork-safety checks;
- static analysis;
- PHPUnit/integration tests;
- Composer validation;
- PHPForge quality/security/release checks;
- startup/preflight/config/route/command/scheduler benchmarks;
- steady-state, memory and throughput-sensitive benchmarks;
- stale-symbol/config/doc scan;
- final Foundation 2.0 release-readiness review.

# Do not regress

- Do not restore broad `Application::cache()`, `db()`, `files()`, `messaging()`, `communication()`, `validator()`, etc. merely for convenience or stale callers.
- Do not restore generic Foundation ArrayKit/Data proxy APIs.
- Do not restore static global application/facade state.
- Do not restore the deleted generic `FilesystemManager`.
- Do not duplicate specialist engines owned by CacheLayer, DBLayer, Pathwise, ReqShield, TalkingBytes, Omnibus, OTP, Epicrypt, UID, Webrick, ArrayKit or InterMix.
- Do not use broad InterMix `Container::has()` for explicit-definition or already-resolved semantics.
- Do not request upstream library changes where current released APIs already satisfy Foundation.
- Do not begin the full release-test matrix until the remaining source/runtime work is complete, except for narrow validation needed for a specific implementation batch.
