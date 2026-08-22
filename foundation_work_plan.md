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
- Latest Foundation code commit: `b4fe4a41f53d7e0332dba8611c0325a12c08b219`
- Latest completed phase: InterMix 9.1.1 public-introspection integration.
- Foundation branch: `feature/foundation-2.0`.
- Full tests/release matrix: intentionally deferred.

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

Do not modify attached libraries merely for churn. InterMix was the only upstream prerequisite identified by the latest whole-codebase review, and that prerequisite is now complete in 9.1.1.

# Completed

## Foundation 2.0 structural cleanup

- Console ownership merged into Foundation; no compatibility hierarchy should be restored.
- Broad static/pass-through facade direction removed.
- Broad `Application::*()` specialist-manager accessors removed and must stay removed.
- `DatabaseManager`, `RouterManager`, `RouteCacheRouter`, generic `FilesystemManager`, generic Data proxy layer and obsolete manager support abstractions removed/reduced.
- Specialist libraries own their engines; Foundation keeps only application/runtime/integration policy.
- Pathwise/filesystem cleanup completed.
- Communication/notification and messaging surfaces reduced to focused Foundation integration instead of broad specialist-library mirrors.
- Config/default ownership consolidated around `FoundationDefaults` and `ConfigRepository`.
- Runtime provider groups use `common`, `web`, `cli`, `worker`, `scheduler`.
- Runtime validation remains declarative/resolution-free.
- `bin/infbyte` is package-owned and executable.
- Module install/remove already use `--update-no-dev`.
- Module config publication is already staged/rollback-safe.
- Logger contracts are already PSR-3 typed.

## InterMix 9.1.1 integration — complete

InterMix 9.1.1 now provides the two distinct public semantics Foundation required:

- `Container::definitions()->has($id)` — explicit registration only;
- `Container::isResolved($id)` — successful resolution history;
- `Container::has($id)` remains the broad PSR-style resolvability check.

Foundation changes completed:

- bumped minimum InterMix constraint from `^9.1` to `^9.1.1`;
- auth override/default-registration logic now uses `definitions()->has()`;
- pooled-worker parent cleanliness now uses `Container::isResolved()`;
- removed the known Foundation `getRepository()` coupling covered by these semantics;
- do not replace either check with broad `Container::has()`.

# Architecture decisions fixed

## UID is the canonical Foundation ID provider

`infocyph/uid` is core/mandatory and should generate Foundation-owned application/runtime identifiers.

- Auth already uses UID v5, primarily UUIDv7 and ULID.
- Do not add an `IdentifierManager` or pass-through UID facade.
- Use native randomness only for entropy/secret material, not Foundation identity values.

## Environment helper ownership stays in Foundation

Foundation owns its application-facing global config helper contract.

- Keep `env()`, `env_bool()`, `env_int()`, `env_string()` in Foundation.
- Keep Foundation path helpers used by published config templates in Foundation.
- ArrayKit remains the parser/config/environment engine underneath where appropriate.
- Do not duplicate ArrayKit's `.env` parser.
- Do not request an ArrayKit upstream change for these helpers.

## Specialist package ownership

Do not duplicate engines already owned by CacheLayer, DBLayer, Epicrypt, Omnibus, OTP, Pathwise, ReqShield, TalkingBytes, UID, Webrick or InterMix.

Foundation integration code is justified only when it adds application configuration, runtime policy, security policy, lifecycle composition, named profiles, or cross-package orchestration.

# Immediate next work — UID v5 canonical ID integration

This is the next source phase.

## 1. Replace runtime `ExecutionId` ad-hoc generation

- Replace native random-hex generation in `ExecutionId::generate()` with UID v5.
- Prefer UUIDv7 for execution identity unless source semantics show a better UID primitive.
- Preserve `ExecutionId` as a small Foundation value object; do not expose UID internals through it.

## 2. Scan all Foundation-owned identifier generation

Audit the whole source for identifiers created with:

- `random_bytes()` / random hex;
- `uniqid()`;
- manual timestamp/random concatenation;
- direct UUID/ULID generation outside the established UID integration;
- hashes being used as identity where they are not actually content-derived keys.

Classify each finding:

- **identity** → use UID v5;
- **deterministic/content-derived key** → hash/deterministic ID may remain appropriate;
- **secret/nonce/token entropy** → keep cryptographically secure random generation or Epicrypt as appropriate;
- **cache/lock namespace key** → keep deterministic derivation where identity generation would be wrong.

## 3. Keep ID semantics purpose-driven

Default direction:

- runtime execution IDs → UUIDv7;
- auth/application entity IDs → existing UID-backed policy;
- correlation IDs → ULID where ordering/readability is useful;
- deterministic IDs → UID deterministic API only when deterministic identity is the actual semantic requirement.

Do not force all IDs into one algorithm blindly.

## UID phase completion gate

Before moving on:

- no Foundation identity path should bypass UID without an explicit reason;
- no new generic ID facade/manager should be introduced;
- deterministic hashes and cryptographic secrets must not be incorrectly converted into UUIDs;
- update this plan with the new code checkpoint and next phase.

# Remaining source queue

## Phase after UID — repair Foundation helper contract

Published config templates still depend on Foundation-style global helpers after the old helper autoload removal.

- define one canonical Foundation helper file/owner;
- deliberately load the required helper surface;
- provide `env()`, `env_bool()`, `env_int()`, `env_string()`;
- provide the path helpers actually used by Foundation config templates;
- route environment reads through the current Foundation/ArrayKit environment state;
- ensure path helpers use the current application base/path policy and do not retain stale process-global application state;
- scan every `resources/config/*.php` file and remove undefined helper usage;
- keep helpers thin and avoid rebuilding service/facade APIs globally.

## Then — correct CacheLayer lock topology

Current concern: an unspecified lock driver can silently become a node-local file lock even when the feature requires shared coordination.

Target behavior:

- explicit configured lock provider wins;
- otherwise use the selected CacheLayer store's native coordination lock when available;
- never silently downgrade distributed/shared semantics to an unrelated local file lock;
- fail clearly when a coordination feature requires a lock topology the selected store cannot provide;
- retain explicitly local locking for intentional single-node use.

Audit:

- scheduler overlap/single-server;
- singleton workers;
- webhook replay;
- browser/auth state/session locking;
- migrations and other mutex users.

No CacheLayer upstream change is currently required; use 3.1.3 public capabilities.

## Then — remove CacheManager / DBLayer resolution-order coupling

- resolving cache must not autoload DBLayer merely because DBLayer is installed;
- DB integration should own DBLayer-specific cache wiring, or use an explicit bridge activated only when both capabilities are active;
- behavior must not depend on whether cache or database resolved first;
- preserve DBLayer query-cache integration when both capabilities are active.

## Then — optimize compiled artifact trust/freshness

Current optimized paths still do source filesystem validation work:

- config cache scans/stats config/provider source files;
- command preflight hashes framework metadata, command route files and application handler files;
- schedule cache hashes schedule source;
- route cache must be reviewed under the same policy.

Design one consistent performance-first policy:

- source remains authoritative when no artifact exists;
- optimize/build/deployment commands generate and invalidate artifacts;
- artifacts carry format/schema/build identity;
- production optimized startup should not continuously scan/hash source files;
- a development freshness mode may validate source more aggressively;
- corrupt/incompatible artifacts fall back safely where source fallback is semantically valid.

## Then — final source/runtime integration sweep

Re-scan the complete branch for:

- Web/CLI/Worker/Scheduler boot isolation;
- optional package autoload leaks;
- package-present vs module-configured/activated semantics;
- dead wrappers and one-use abstractions;
- stale service IDs, aliases, imports and config keys;
- obsolete Console-era compatibility remnants;
- specialist APIs unnecessarily mirrored by Foundation;
- process/fork/runtime lifecycle defects.

Keep `RuntimeContextTracker`'s touched-state cleanup model unless a concrete defect appears; do not replace it with a broad global reset registry.

# Deferred documentation and release gate

Only after the remaining source phases are complete:

1. freeze README/docs against the actual Foundation 2.0 public surface;
2. remove stale references to deleted APIs/configs/Console ownership;
3. run the full test and integration matrix;
4. run static analysis and PHPForge gates;
5. verify core-only and optional-module installation matrices;
6. verify Web/CLI/Worker/Scheduler isolation;
7. run persistent worker/scheduler scope-reset and soak checks;
8. benchmark startup, optimized artifact paths, memory and representative throughput;
9. run final dead-symbol/config/doc scan;
10. perform Foundation 2.0 release-readiness review.

# Do not regress

- Do not restore broad `Application::cache()`, `db()`, `files()`, `communication()`, `messaging()`, `validator()`, etc.
- Do not restore the static global facade/application-state architecture.
- Do not restore generic Foundation Data/ArrayKit proxy APIs.
- Do not restore the deleted generic `FilesystemManager`.
- Do not use `Container::has()` where explicit registration or resolution-history semantics are required.
- Do not access InterMix `getRepository()` when a public API exists.
- Do not duplicate specialist engines in Foundation.
- Do not begin the full test/release matrix before the remaining source work is complete, except for narrow checks necessary to validate a specific implementation change.
