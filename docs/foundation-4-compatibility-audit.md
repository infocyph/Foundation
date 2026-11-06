# Foundation 4 — compatibility and documentation ownership audit

Reviewed against Foundation 4 source `9fb02a5f` (2026-11-06) and
published specialist versions in
[the exact resolved dependency record](foundation-4-dependency-qualification.md).
This audit concerns **library-major runtime/API compatibility**; it does not
require altering application data formats, key-rotation policies or deliberate
public configuration.

## Removed major-version compatibility

- The [API replacement map](foundation-4-api-map.md) and
  [migration guide](foundation-4-migration.md) describe the unsupported
  Foundation 3 / Runwire 2 / InterMix 10 / DBLayer 5 / Omnibus 2 public
  entrypoints and their current native replacements. The old APIs do not
  return as alias methods, version-dependent runtime probes or hidden
  generated-container fallback paths.
- `LegacyRuntimePathBoundaryTest` rejects retired Foundation container
  cache classes, `compileTo()`, `useCompiled()`, `usePrevalidated()`,
  `compilationReport()`, retired duplicate DB/cache/route/factory
  wrappers and standalone route-cache commands across **all production PHP
  sources**. `CurrentInfocyphApiCompatibilityTest` exercises the **current**
  DBLayer 6 and Omnibus 3 connection/worker/failure contracts, not a 2.x shim.
- The 3-module/8-feature v2 public CLI catalog does not accept old 11-module
  install aliases or implicit group-wide activation. Composer package
  installation is distinct from actual `modules.features` enabling.
  A missing `modules.features` map legitimately allows ordinary
  `app.capabilities` selection and installed-package discovery where
  applicable; an explicit map constrains selected specialists and fails
  on missing packages. This is a current application policy, **not** a
  runtime probe for an excluded library major.
- Benchmark-only `Omnibus26BenchmarkCodec/Message/StopLifecycle` support
  classes, the old `omnibus26Cycle` helper and `omnibus-2.6` benchmark
  suite/output naming were renamed to current neutral `Omnibus*`
  names in Batch 71. No production serializer compatibility code or
  historical benchmark verdict is being rewritten.

## Deliberately retained current contracts

| Contract | Keep? | Reason |
| --- | --- | --- |
| `foundation.omnibus26.durable-message.v1` stored message codec alias | Yes | Persisted durable queue rows written under that alias must remain readable and retryable; this is stored-data identity, not an older Omnibus API. `OmnibusDurableIntegrationTest` verifies it |
| Signed URL active/fallback key set | Yes | Webrick verifies valid previously issued URLs until expiry; current active key alone signs new links, with terminal rejection of tampered/expired requests |
| OAuth/JWT old-key verification and issuer/jti/key-purpose policy | Yes | Bounded security key-rotation and token revocation are current public protocols |
| Explicit MobileOTP legacy import command | Yes | Deliberate, bounded application data-import workflow, not a v6 runtime adapter |
| `OAuth21BaselineCompatibilityTest` / `OAuth21PrincipalCompatibilityTest` | Yes | Preserve released application token-principal and security-middleware contracts while adopting current OAuth 2.1 services |
| `app.capabilities` and optional `modules.features` policy | Yes | Intentional Foundation 4 application configuration; explicit feature flags constrain, never grant, capabilities |
| Webrick direct host adapters distinct from Runwire | Yes | Actual selected host ownership must remain explicit; neither is a fallback for an excluded major |
| Ordinary PHP/CLI/FPM without live Runwire context | Yes | Required synchronous operation of the current Core runtime, not a compatibility path |

## Documentation cross-check

Reviewed `README.md` and all 26 non-plan Foundation documentation
pages, including `foundation-4-migration.md`,
`foundation-4.0-release-notes.md`, `foundation-4-api-map.md`,
`modules.md`, `runtime-hosting.md`, the auth/OTP/communication/DB/cache/
filesystem/messaging/security guides and host performance certification.
Published dependency versions match the current Composer manifest:
ArrayKit 5.3.2, CacheLayer 4.0.2, InterMix 11.2.1,
DBLayer 6.1.4, Epicrypt 3.3, Omnibus 3.0.3, OTP 7.0.1,
Pathwise 4.2.1, ReqShield 3.3.1, Runwire 3.0,
TalkingBytes 2.3.1, UID 6.0.1, Webrick 6.0.4,
WebAuthn 5.3.12, and PSR Log 3.0.2.

References to older library names in the **migration instructions** identify
what applications must *replace*; they are not recommendations to keep those
versions. Current authenticated/immutable releases require new artifacts and
strict dependency fingerprints; no prior-major generated release may be loaded
through a fallback. All eight selected modules' ordinary PHP and borrowed
Runwire examples are in [Modules](modules.md); real host behavior is
[Runtime hosting](runtime-hosting.md). Independent app/Infbyte migration
occurs only **after** Foundation 4 publishes.

## Evidence and remaining external work

- [Batch 69 QA](https://github.com/infocyph/Foundation/actions/runs/42174116800)
  and [native hosts](https://github.com/infocyph/Foundation/actions/runs/42174116254)
  passed current API, runtime isolation, source-free releases and persisted
  message-alias tests.
- [Batch 71 QA](https://github.com/infocyph/Foundation/actions/runs/42176222216)
  and [native hosts](https://github.com/infocyph/Foundation/actions/runs/42176221613)
  passed with the benchmark-only compatibility-name cleanup. The separate
  Composer audit reports **0 advisories** and **1 explicitly reported,
  non-blocking abandoned transitive package** (`doctrine/annotations`);
  full and Core-only platform checks passed. The exact lock is uploaded as
  a workflow artifact, not version-controlled by this library.
- The original pre-upgrade 3.x lock is not committed. Retained-state multi-
  service backup/restore and sustained paired native FPM/Runwire baseline
  comparisons remain independent release gates. Source-only/lock-upgrade
  comparisons cannot be inferred from CI smoke and are **not** certified here.

No blind deletion of classes, fallbacks or data formats solely because a
name contains `legacy`, `fallback`, `compatibility` or `26`.
