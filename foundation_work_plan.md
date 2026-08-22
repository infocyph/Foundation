# Foundation 2.0 — Live Work Plan

> This is the maintained execution tracker for Foundation 2.0.
> `foundation_plan.md` is the older architecture/reference plan and is not the live source of current TODO state.

## Working branch

`feature/foundation-2.0`

## Update rule

After every completed implementation/review batch, update this file in the same branch before moving to the next batch:

1. move finished items into **Completed since checkpoint**;
2. update **Current checkpoint** to the new branch head;
3. rewrite **Next batch** to describe the immediate work to perform next;
4. keep later unfinished work ordered under **Remaining queue**;
5. do not silently re-open already completed cleanup unless new source evidence requires it.

## Current checkpoint

- Date: 2026-08-22
- Commit: `2871cddcaf8e6cb003e73fcb933e4fec82b26baa`
- Commit subject: `refactor: keep messaging validation resolution-free`
- Source cleanup status: manager/facade pruning and config/default cleanup complete.
- Test status: tests are intentionally deferred until the remaining source/main implementation work is finished. Do not add or run the full test/release matrix yet.

## Completed since the Foundation 2.0 architecture draft

### Manager / facade / ownership cleanup

- Removed broad static/pass-through facade direction instead of restoring deleted `Application::*()` aggregate accessors.
- Removed `DatabaseManager`.
- Removed `RouterManager` and `RouteCacheRouter`.
- Reduced `CacheManager` to legitimate Foundation-owned integration policy.
- Removed obsolete manager/support abstractions discovered during the pruning pass.
- Completed Pathwise/filesystem ownership cleanup; do not restore the deleted generic `FilesystemManager` merely to satisfy stale analysis findings.

### Config / defaults cleanup

- Established canonical `FoundationDefaults` ownership.
- Removed `app.container.request_scope`.
- Set lazy loading as the normal default.
- Enabled strict upload validation by default.
- Disabled cache object/closure payloads by default.
- Bumped configuration format to version 5.
- Removed dead `database.pool`, notification, and security configuration keys.
- Consolidated environment hydration to one owner.
- Fixed SQLite/session path defaults.
- Aligned cached and uncached routing behavior.
- Kept runtime configuration validation declarative/resolution-free.
- Messaging callable/listener validation no longer resolves runtime callables while validating configuration.

## Next batch — runtime/tooling/package polish

Work through these in order. Keep changes narrow and remove code rather than adding wrappers where ownership already belongs to a specialist package.

### 1. Remove remaining InterMix internal-repository coupling

- Find Foundation code that reaches into InterMix repository/container internals rather than its supported public composition/resolution surface.
- Replace those usages with the public InterMix 9.1 API or simplify Foundation ownership away entirely.
- Preserve lazy runtime scopes and avoid introducing reflection/runtime scanning.

### 2. Audit CacheLayer fallbacks

- Inspect every CacheLayer-backed optional path for hidden local/fallback implementations.
- Keep Foundation fallback behavior only where Foundation genuinely owns the semantic policy.
- Do not emulate CacheLayer locks/cache/counters/state engines in Foundation.
- Ensure optional CacheLayer absence does not break unrelated runtime paths.

### 3. Tighten logger typing and ownership

- Review logger bindings, defaults, and nullable/fallback paths for precise PSR-3 types.
- Remove ambiguous `mixed`/duck-typed logger handling where a concrete PSR contract is available.
- Keep Foundation's minimal logging infrastructure independent of optional specialist packages.

### 4. Make module/config publication atomic

- Audit module installation/config publication writes for partial-update risk.
- Use temp-file + rename/replace semantics where Foundation writes generated/published artifacts.
- Do not overwrite host-owned config by default.
- Invalidate only the affected compiled artifact after publication.

### 5. Finalize executable `bin/infbyte`

- Verify the package-owned CLI entrypoint is executable and canonical.
- Keep any host-root `infbyte` file as a tiny delegator only.
- Remove duplicate bootstrap behavior and old Console ownership assumptions.
- Confirm simple preflight (`version`, `help`, `list`, completion metadata) remains minimal.

### 6. ProcessRunner / CLI polish

- Review process execution defaults, argv handling, shell opt-in, environment propagation, output bounds, exit/termination mapping, redaction, and platform behavior.
- Remove leftover Console-era compatibility assumptions.
- Keep CLI preflight free of unnecessary application/provider resolution.

### 7. Metadata and fork/reset audit

- Review process/runtime metadata for stale mutable state across long-lived workers, scheduler loops, commands, and forked children.
- Ensure request/command/job scope cleanup is explicit.
- Reset only external/static/process-local state that InterMix scopes cannot own.
- Avoid broad reset registries when lifetime ownership can be expressed directly.

### 8. Dependency and module-catalog consistency

- Reconcile `composer.json`, module catalog, config publication metadata, readiness/module reporting, and docs-facing package constraints.
- Ensure optional package presence is not confused with module configuration/activation.
- Verify no runtime path accidentally makes an optional package mandatory.
- Keep one authoritative module/version registry rather than duplicated version strings.

### 9. Obsolete-symbol / dead-code sweep

- Search for classes, methods, interfaces, traits, config keys, aliases, service IDs, imports, docs references, and compatibility code made obsolete by the completed Foundation 2.0 pruning.
- Delete dead symbols rather than preserving them for BC; Foundation 2.0 intentionally has no BC requirement.
- Re-check one-implementation interfaces and one-method wrappers against the PHPForge engineering rules.

## Remaining queue after runtime/tooling/package polish

### Documentation freeze

Only after source/main implementation stabilizes:

- update README and `docs/` to the actual 2.0 public surface;
- remove stale references to deleted managers/facades/Console bridges/config keys;
- document runtime isolation and optional-capability ownership accurately;
- document module install/config behavior from the final implementation, not from the old architecture draft;
- freeze public names/config shapes before the release-test pass.

### Deferred tests and release matrix

Tests are deliberately deferred until the source cleanup/polish above is complete.

Then perform the full final gate:

- core-only install/runtime checks;
- optional-module matrix;
- Web / CLI / Worker / Scheduler load-isolation checks;
- persistent worker/scheduler scope-reset and soak checks;
- static analysis;
- PHPUnit/integration tests;
- Composer validation;
- PHPForge quality/security/release checks;
- benchmark startup, steady-state, memory, and throughput-sensitive paths;
- stale-symbol/config/doc scan;
- release-readiness review for Foundation 2.0.

## Current exclusions / do not regress

- Do not restore removed broad `Application::cache()`, `db()`, `files()`, `messaging()`, `communication()`, `validator()`, etc. only to satisfy stale callers; remove/update stale callers instead.
- Do not restore generic Foundation Data/ArrayKit proxy APIs.
- Do not restore the static global facade/application-state architecture.
- Do not restore the deleted generic `FilesystemManager`.
- Do not duplicate specialist engines owned by CacheLayer, DBLayer, Pathwise, ReqShield, TalkingBytes, Omnibus, OTP, Epicrypt, UID, Webrick, or InterMix.
- Do not start the full test matrix until the remaining source/main tasks above are complete, unless a narrow command/check is required to safely validate a specific implementation change.
