# Foundation 3.0 release notes

Foundation 3.0 completes the builder-first runtime architecture and makes the
production contract explicit. The release keeps Foundation focused on
application composition and policy while InterMix, Webrick, CacheLayer and the
specialist packages retain their native domain mechanics.

## Highlights

- One explicit application runtime is selected with
  `Foundation::web/cli/worker/scheduler()`; Foundation never derives policy from
  `PHP_SAPI`.
- Production uses one immutable Foundation generation containing the normalized
  config snapshot, Webrick web release and generated InterMix CLI/worker/scheduler
  runtimes.
- Release publication is staged and serialized. Trusted loading fails closed on
  config, dependency or artifact identity mismatches, and runtime generation
  leases prevent pruning a generation while an old process is still draining.
- CacheLayer is a direct Foundation runtime dependency and core infrastructure,
  outside the specialist module installation/removal lifecycle.
- The module contract is seven specialist namespaces plus four built-in catalog
  entries. Optional packages remain cold until selected by application
  capability/configuration.
- Browser-session cleanup preserves the primary application failure, session lock
  durations must be finite, stale lock owners cannot rotate/invalidate state, and
  shared-lock contention is exercised across Redis, Valkey, Memcached, MySQL and
  PostgreSQL.
- `app:ready --json` is versioned and agrees with module/configuration/production
  compilation semantics while keeping inactive optional capabilities cold.
- Project-specific architecture tests enforce the Foundation/Infbyte/tooling,
  build-plane/runtime and optional-package ownership boundaries.

## Dependency baseline

Foundation 3.0 requires PHP `^8.4`, ArrayKit `^5.2`, CacheLayer `^3.4`,
InterMix `^10.1.1`, UID `^5.0`, Webrick `^5.4`, PSR Log `^3.0.2` and
Composer Runtime API `^2.0`.

Optional integration floors are DBLayer `^5.1`, Epicrypt `^3.1`, Omnibus
`^2.6`, OTP `^6.1`, Pathwise `^4.1`, ReqShield `^3.2`, TalkingBytes
`^2.2`, and WebAuthn `^5.3.9` when passkeys are selected.

`infocyph/phpforge` remains the development QA dependency at
`dev-main@dev`. Release CI pins the reusable PHPForge workflow to the immutable
revision recorded in the release evidence index.

## Breaking/operational changes

Applications upgrading from Foundation 2.x must review provider composition,
generated containers, the unified immutable release generation, explicit
production capability topology, core-cache/module terminology, optional auth
feature selection, specialist dependency floors and persisted-state migration.
Do not reuse 2.x generated cache/runtime artifacts.

Deployment must supply the trusted Foundation manifest SHA-256 from
deployment-controlled metadata. Keep at least one previous generation for
rollback; draining processes retain their generation automatically through a
runtime lease. Code rollback is separate from database/data rollback, and mixed
generations require additive/expand-contract persistence changes.

Resolved secret/key material is not embedded in generated artifacts. Rolling key
changes use the owning subsystem's active/fallback verification contract.

## Foundation 3.0 support boundary

Foundation 3.0 certifies only the rows executed by its release workflow:
Linux/PHP 8.4 and 8.5, ordinary Webrick/SAPI response semantics, generated web
releases, persistent-adapter scope/emission semantics, generated
CLI/worker/scheduler runtimes, supported shared session-lock backends and
persistent-state isolation.

The package does **not** claim repository-level native-host certification for
separately managed PHP-FPM, Runwire portable/listener mode, FrankenPHP,
RoadRunner, Swoole/OpenSwoole or Workerman. Webrick/Runwire adapter availability
is documented, but it is not converted into a Foundation support promise without
native-host release jobs.

Representative performance evidence covers minimal HTTP, route-selected session,
application bearer auth and OAuth bearer resolution; DBLayer and Omnibus retain
separate integration-attribution benchmarks. Shared-CI benchmark numbers are
diagnostic unless baseline and candidate environment fingerprints match.

## Migration and verification

Use the [Foundation 2.x to 3.0 migration guide](foundation-3-migration.md) for
the cumulative upgrade path, [runtime support](foundation-3-runtime-support.md)
for the tested support statement, [operations](operations.md) for
build/activation/rollback, and the
[Foundation 3.0 release evidence index](evidence/foundation-3.0/README.md) for
release-gate identities and artifacts.

Infbyte migration/publication is intentionally deferred until the Foundation
package is published. Foundation does not depend on the skeleton and this release
does not claim Infbyte consumer certification.
