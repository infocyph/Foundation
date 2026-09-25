# Foundation library reuse and integration plan

Date: 2026-09-25

Status: active implementation

Branch: `foundation-3/library-reuse-hardening`

Baseline Foundation revision: `fc9b3258646ac744e61fd02e18d9b98e5afff2e8`

## Objective and decision rule

Foundation should compose specialist libraries and apply application policy. It should not maintain another implementation of mechanics or another bridge already supplied by a specialist library.

Keep configuration selection, capability registration, application persistence mappings, account/permission policy, HTTP presentation, and request/job lifecycle coordination in Foundation. Delegate algorithms, protocol validation, database mechanics, cache atomicity, transport execution, filesystem processing, routing, and native runtime mechanics to their existing owners.

A Foundation adapter is justified when it translates a real contract, policy, persistence, trust, or lifecycle boundary. Similar class names or a small forwarding method alone do not establish harmful duplication.

This plan is source-backed integration remediation. Performance claims require measured evidence; removal of a wrapper or class by itself is not a throughput claim.

## Tracker

| Batch | Scope | Status | Exit condition |
| --- | --- | --- | --- |
| 0 | Baseline, public surface, persistence/compatibility inventory | Complete | Direct callers, generated graph roots, public compatibility, refresh-token persistence requirements recorded |
| 1 | F1 native Webrick CacheLayer throttle bridge | Implemented; final validation pending | Native bridge selected; compatibility wrapper deprecated/outside default graph |
| 2 | F2 auth atomic counter hardening | Implemented; final validation pending | Shared auth state cannot silently use read/modify/write counters |
| 3A | F3 OAuth authorization single evaluation | Implemented; final validation pending | HTTP path reuses one native protocol result |
| 3B | F4 Pathwise download preparation reuse | Implemented; final validation pending | No-range/stale-If-Range full response reuses initial preparation |
| 4 | F5 legacy refresh-token retirement | Implemented as 3.x deprecation/compatibility boundary; final validation pending | Epicrypt path canonical; legacy runtime tests moved; credential cutover policy explicit |
| 5 | F6 CSRF overlap decision | Complete — no code change | Foundation 3.x keeps raw-token semantics; Webrick masked-token expansion is not adopted implicitly |
| 6 | Documentation, architecture guards, full release validation | In progress | Ownership docs updated; execution/CI/consumer gates still pending |
| Deferred | F7 HttpKernel public compatibility facade | Deferred for Foundation 3.x | Revisit only in approved public API compatibility window |


## Batch 0 inventory record

- **Baseline/branch:** work started from `main` at
  `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` on
  `foundation-3/library-reuse-hardening`.
- **F1:** the duplicate Foundation Webrick counter bridge is selected only by
  `CacheServiceProvider`; Webrick already ships the matching native
  `AtomicCounterAdapter`. No separate runtime policy lives in the Foundation
  wrapper.
- **F2:** shared auth counter selection is centralized in `AuthCacheRegistrar`.
  Production validation already requires Redis/Valkey atomic state; development
  composition previously had the weaker implicit fallback.
- **F3:** public manager/validator entry points are retained for compatibility,
  while the normal HTTP authorization path now carries one request-local
  `AuthorizationProtocolResult` through redirect/error and Foundation mapping.
- **F4:** normal streamed/local download responses called Pathwise preparation
  twice; offload responses use only the initial preparation. Pathwise
  `streamChunks()` performs a later intentional freshness/trust check that must
  not be removed.
- **F5:** the active service graph registers Epicrypt `RefreshTokenManager` and
  `DBLayerEpicryptRefreshTokenStore`. Legacy Foundation coordinator/store/types
  were referenced by compatibility tests/fixtures, not the default graph.
  Active and legacy rows share the Foundation-owned refresh table but use
  different artifact/state contracts.
- **F6:** Foundation currently accepts only the stored raw 64-hex CSRF proof.
  Webrick also accepts its masked proof form; substituting Webrick matching would
  broaden the accepted contract.
- **F7:** `Application::http()` retains `HttpKernel` publicly in the
  development/embedded surface, while `WebProductionGraph` removes it from
  generated production.

## Confirmed findings

### F1 — Foundation duplicates Webrick's existing CacheLayer throttle bridge

**Priority:** P1  
**Confidence:** confirmed exact behavioral duplication.

Evidence:

- `src/Cache/WebrickAtomicCounter.php` implements Webrick's `AtomicCounterInterface`; `increment()` delegates to CacheLayer and returns `->value`.
- Webrick `src/Interop/CacheLayer/AtomicCounterAdapter.php` already implements the same interface with the same constructor dependency and behavior.
- `src/Cache/CacheServiceProvider.php` currently registers the Foundation implementation.

Plan:

- Register `Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter` directly using Foundation's selected `AtomicCounterStoreInterface` binding.
- Preserve Foundation's counter selection and namespace/policy decisions.
- Check the public class surface. If compatibility requires retaining `Foundation\Cache\WebrickAtomicCounter`, keep it deprecated and outside the default service graph rather than maintaining two active owners.
- Add a graph/registration guard proving the native Webrick bridge is selected.

Acceptance:

- Development and generated web graphs resolve the native bridge.
- Throttle limits, TTL, reset semantics where applicable, and Redis/Valkey failure behavior remain correct.
- No throughput claim is made solely from deleting a wrapper.

### F2 — Auth registration still has a non-atomic shared counter fallback

**Priority:** P1  
**Confidence:** confirmed semantic divergence; existing production validation already guards the production topology.

Evidence:

- `src/Auth/Internal/AuthCacheRegistrar.php` selects `CacheLayerCounterStore` when shared cache-backed auth is selected but `cache.default_counter` is empty.
- `CacheLayerCounterStore` performs get + PHP increment + set and therefore is not a shared atomic mutation.
- Each fallback write reapplies TTL.
- `AtomicCounterStore` already adapts CacheLayer's native atomic interface while applying Foundation security key policy.
- CacheLayer Redis/Valkey atomic counters use one native atomic operation/Lua path and establish TTL on initial creation.
- `ProductionSecurityValidator::validateAtomicCounter()` already rejects production auth without a Redis/Valkey atomic counter. This finding is hardening/configuration correctness, not evidence of a bypass of that production guard.

Plan:

- For shared/cache-backed auth, require `AtomicCounterStoreInterface`; do not silently substitute read/modify/write.
- Preserve explicit process-local in-memory auth state for development/tests where intentionally selected.
- Missing shared atomic counter configuration must fail clearly at graph/configuration time appropriate to the current architecture.
- Retire/deprecate `CacheLayerCounterStore` according to public compatibility policy.
- Preserve fixed-window expiry semantics; do not accidentally introduce sliding lockout windows.

Acceptance:

- Concurrent failures count correctly.
- Expiration does not slide because of every increment.
- Missing shared atomic configuration fails clearly.
- Disabled auth and explicit in-memory tests remain supported.
- Existing production validation remains enforced.

### F3 — OAuth authorization invokes native protocol validation twice

**Priority:** P1  
**Confidence:** confirmed repeated request work.

Evidence:

- `OAuthHttpHandler::authorization()` first requests `authorizationRedirectContext()`, then calls `validateAuthorizationRequest()` using the same parameters.
- Both paths delegate to `AuthorizationRequestValidator`, and both currently invoke `protocolResult()`.
- `protocolResult()` normalizes parameters, creates Epicrypt authorization validators/adapters, resolves client state, and runs OAuth/OIDC validation.
- Epicrypt's native authorization result already contains the accepted request or a protocol error with safe redirect context where permitted.

Plan:

1. Prefer the least-complex successful-path improvement: validate once on the normal success path rather than precomputing redirect context first.
2. Then, where practical, use one cohesive internal evaluation result so redirectable failures also do not rerun protocol validation.
3. Reuse the existing `AuthorizationProtocolResult`; do not create another result hierarchy.
4. Keep public entry points if compatibility requires them.
5. Keep evaluated protocol/client state request-local. Do not cache mutable client/principal/permission state in a singleton.

Acceptance:

- One native authorization validation per normal successful HTTP authorization attempt.
- Prefer one evaluation for redirectable failures as well if achieved without complicating the public API.
- Safe/unsafe redirect behavior, state echo, PKCE errors, OIDC options, disabled-OIDC behavior, scope/permission mapping, and audit events remain correct.
- Invalid client/redirect input never redirects to an untrusted location.
- Add operation-count instrumentation/test coverage rather than inferring improvement from fewer methods.

### F4 — Normal downloads prepare Pathwise metadata twice

**Priority:** P1  
**Confidence:** confirmed.

Evidence:

- `FilesystemResponseFactory::prepareInitialDownload()` calls Pathwise `prepareDownload()` without a range.
- `respond()` computes the effective range and calls `prepareDownload()` again even when the effective range remains `null`.
- Pathwise preparation performs path/policy validation, storage size, MIME type, modification time, range resolution, ETag/header construction, and related metadata work.
- Pathwise `streamChunks()` later revalidates prepared metadata before streaming. That later trust-boundary validation is intentional and must remain.

Plan:

- Reuse the initial `DownloadPreparation` when no effective range is required, including stale `If-Range` that resolves to a full response.
- Keep Pathwise as owner of range parsing and prepared-download validation.
- If range-path optimization needs a new "apply range to validated metadata" contract, implement it in Pathwise first and consume a released version. Do not duplicate range parsing in Foundation.
- Do not remove stream/body-boundary freshness checks.

Acceptance:

- One preparation for no-range full responses.
- GET/HEAD, empty files, 200/206/304/412/416, suffix/open ranges, stale `If-Range`, local/remote storage, and offload behavior remain correct.
- Traversal, symlink, allowed-root, and file-change protections remain intact.
- Add operation-count assertions and measure affected workloads before claiming throughput improvement.

### F5 — Legacy Foundation refresh-token machinery remains alongside Epicrypt's active path

**Priority:** P2  
**Confidence:** confirmed parallel implementations; active service registration uses Epicrypt.

Evidence:

- Foundation `OAuthRefreshTokenCoordinator` implements issuance/rotation/reuse/revocation and related policy independently.
- Epicrypt `RefreshTokenManager` owns the active native refresh-token lifecycle.
- `AuthOAuthRegistrar` registers Epicrypt's `RefreshTokenManager`, token endpoint flow, and `DBLayerEpicryptRefreshTokenStore`; it does not register the legacy Foundation coordinator.
- `DBLayerOAuthRefreshTokenStore` and `DBLayerEpicryptRefreshTokenStore` coexist with different contracts and record mappings.
- Tests/fixtures still construct the legacy coordinator/store.
- The old and active paths share persistence concepts/tables while representing token identity, idle expiry, DPoP binding, and rotation state differently.

Plan:

- Inventory direct callers, public API commitments, persisted table/record formats, and upgrade requirements before deleting anything.
- Keep Epicrypt canonical for protocol mechanics.
- Preserve Foundation account status, authorization, audit, schema mapping, and DBLayer persistence policy at the existing application boundaries.
- Retain Foundation DBLayer adapters that implement Epicrypt persistence contracts.
- Move legacy concurrency/audit guarantees onto active-path tests before deleting legacy test fixtures.
- Define explicit treatment for existing credentials: retain until expiry, migrate, or revoke. No silent table or wire-format break.
- Update `docs/architecture/oauth-2.1-reuse-gap.md` to describe the current canonical integration and compatibility condition.

Acceptance:

- Active endpoint tests prove one successful concurrent redemption, reuse/family revocation, client/authorization/account binding, scope narrowing, DPoP where enabled, and audit delivery.
- Existing credentials have an explicit upgrade strategy.
- Legacy protocol execution/store types are removed or clearly compatibility-only after all guarantees move to the active path.

### F6 — CSRF mechanics overlap with Webrick, but exact semantics differ

**Priority:** P2  
**Confidence:** overlap confirmed; consolidation is conditional.

Evidence:

- `BrowserSession` generates/stores a 32-byte random token encoded as 64 hex characters.
- Foundation `CsrfMiddleware` controls safe-method bypass, configurable header/field extraction, optional Origin policy, and a 419/no-store response; it compares the raw stored token with `hash_equals()`.
- Webrick `Csrf` provides token generation and matching through `CsrfTokenStoreInterface`, but `matchesValue()` accepts both raw tokens and Webrick's masked-token format.
- Webrick's default request extraction/header behavior also differs from Foundation's configurable extraction policy.

Plan:

- Do not replace Foundation's comparison merely to eliminate one `hash_equals()`.
- Evaluate a small request-scoped `BrowserSession` implementation of Webrick's `CsrfTokenStoreInterface` only if it creates a meaningful ownership simplification.
- Adopt Webrick `Csrf::matchesValue()` only if Foundation explicitly decides to expand the accepted proof contract to raw + masked tokens.
- Otherwise preserve Foundation's exact raw-token semantics and record the retained comparison as application policy, not harmful duplication.
- Keep Origin policy, session regeneration/invalidation, response status/cache policy, and configurable proof extraction in Foundation.

Acceptance:

- Existing custom header/field names, missing/incorrect proofs, cookie/query rejection, Origin behavior, login/session rotation, and persistent-worker isolation remain unchanged unless an explicit documented compatibility change is approved.
- Any masked-token acceptance is deliberate, documented, and tested.
- A valid outcome for this batch is **no code change** if native reuse would make the contract broader or lifecycle more complex.

### F7 — HttpKernel is a public pass-through facade

**Priority:** P3 / deferred  
**Confidence:** confirmed facade.

Evidence:

- `HttpKernel::handle()` only delegates to Webrick `RouterKernel::handle()`.
- It is part of the current public `Application::http()` return contract and development service graph.
- `WebProductionGraph` removes `RouterKernel`, `HttpKernel`, and related development aliases from the generated production graph.

Decision:

- Keep `HttpKernel` as a Foundation 3.x compatibility facade.
- Prefer native Webrick runtime ownership in internal/new production paths, which is already the generated-production model.
- Revisit facade removal only in an approved public API compatibility window such as a future major.
- Do not claim runtime throughput benefit from removing a development/public forwarding facade.

## Package boundaries to retain

| Package | Foundation integration / decision |
| --- | --- |
| ArrayKit | Keep application precedence, presets, schema/source identity, cache publication, and release checks. Use native config parsing/cache mechanics. |
| CacheLayer | Keep store/topology selection, security namespaces, and application factory policy. Address F1/F2; do not create another cache engine. |
| DBLayer | Keep application connection topology, request-owned leases, schema ownership, and configured query-cache binding. Use native repositories/pools/migrations. |
| Epicrypt | Keep native protocol/token/crypto mechanics. Foundation retains account/permission/audit and persistence/schema mapping. Address F3/F5. |
| InterMix | Keep provider composition and execution seeds/cleanup. Generated ProductionContainer remains native DI production representation. |
| Omnibus | Keep Foundation execution identity/lifecycle integration; native durable transport/workflow mechanics remain Omnibus-owned. |
| OTP | Keep Foundation account binding/persistence policy; native OTP/WebAuthn mechanics remain OTP-owned. |
| Pathwise | Keep application disk/root policy and HTTP adaptation. Native upload/download/range/storage processing remains Pathwise-owned. Address F4. |
| ReqShield | Keep Foundation validation profile/schema configuration; use native validators and native DBLayer bridge. |
| TalkingBytes | Keep required Foundation persistence/policy adapters where the library exposes a contract but no equivalent CacheLayer bridge. |
| UID | Keep ID policy adaptation/runtime usage; do not duplicate algorithms. |
| Webrick | Own routing, HTTP messages, kernel, conditional handling, native route caches, and reusable HTTP middleware/mechanics. Address F1 and evaluate F6. |
| Infbyte | Consumer skeleton for migration/create-project/runtime certification; Foundation never depends on Infbyte. |

Do not automatically extract Foundation browser-session stores, auth database stores, cron parsing, command execution, or process handling solely because another package has a related name. Establish equivalent capability, contract, and dependency availability first.

## Implementation order

### Batch 0 — Baseline and compatibility inventory

- [x] Record current main/base SHA and branch.
- [x] Inventory F1 public usage and graph roots.
- [x] Inventory F2 callers/configuration paths.
- [x] Inventory F3 public/internal call surfaces.
- [x] Inventory F4 response paths and short-circuit behavior.
- [x] Inventory F5 legacy classes, tests, table mappings, and credential compatibility.
- [x] Record F6 exact current accepted proof semantics.
- [x] Record F7 as deferred Foundation 3.x compatibility surface.

### Batch 1 — F1 Webrick native bridge

- [x] Select Webrick `Interop\CacheLayer\AtomicCounterAdapter` in Foundation graph.
- [x] Preserve/deprecate Foundation wrapper only if public compatibility requires it.
- [x] Add native-owner registration/architecture coverage.
- [ ] Run focused cache/throttle tests.

### Batch 2 — F2 atomic auth counters

- [x] Remove implicit shared non-atomic fallback.
- [x] Preserve explicit in-memory development/test state.
- [x] Document fixed-window expiry contract.
- [x] Add registrar/configuration/concurrency tests.
- [ ] Run focused auth/cache tests.

### Batch 3A — F3 OAuth authorization evaluation

- [x] Remove successful-path duplicate native validation.
- [x] Reuse one protocol result for redirectable failures where it remains simple and safe.
- [ ] Add validation-operation-count coverage. Runtime probe implemented; fresh execution pending.
- [ ] Re-run OAuth HTTP/OIDC/redirect/audit tests.

### Batch 3B — F4 Pathwise download preparation

- [x] Reuse initial preparation when effective range is null.
- [x] Preserve stream/body freshness checks.
- [ ] Add preparation/storage-operation-count coverage. Runtime probe implemented; fresh execution pending.
- [ ] Re-run filesystem trust-boundary/range/offload tests.

### Batch 4 — F5 refresh-token consolidation

- [x] Freeze persistence and existing-credential upgrade policy.
- [x] Move legacy concurrency/reuse/audit assertions to active Epicrypt path.
- [x] Retire unused legacy coordinator/store/contracts/types once coverage is equivalent.
- [x] Update OAuth ownership/migration documentation.
- [ ] Re-run refresh rotation/concurrency and full OAuth integration tests.

### Batch 5 — F6 CSRF decision

- [x] Prove exact semantic parity or explicitly approve masked-token expansion.
- [x] Reuse native Webrick mechanics only when the resulting contract/lifecycle is simpler and intentional.
- [x] Otherwise document no-change decision and retain Foundation raw-token comparison.
- [ ] Run browser-session/CSRF/origin/persistent-worker tests.

### Batch 6 — Closure and release gates

- [x] Update `docs/architecture/ownership-boundaries.md`.
- [x] Add architecture guards for selected native owners and forbidden retired owners.
- [ ] Run focused suites for each changed subsystem.
- [ ] Run required PHPForge QA/analysis/security/duplicate/architecture flow.
- [ ] Run relevant benchmarks/operation-count checks.
- [ ] Validate PHP 8.4/8.5 and relevant stable/lowest dependency rows through CI.
- [ ] Validate clean production installation.
- [ ] Validate Infbyte consumer against the final Foundation branch/revision.
- [ ] Update this tracker to final state with evidence.

## Prevention and completion gates

1. For every future adapter, record the source interface, target interface, native bridge searched, Foundation policy/lifecycle added, and why direct registration cannot suffice.
2. Add targeted architecture/registration tests for native service selection and retired ownership. Clone detection alone cannot identify duplicated responsibilities across repositories.
3. Preserve optional-package-disabled startup, native exceptions/return contracts, service replacement seams, generated graph parity, and persistent request/job cleanup.
4. Keep PHPForge/PHPProbe standards intact. Do not suppress or bypass project quality gates to land these changes.
5. Use operation-count assertions for F3/F4. Use representative benchmark workloads for performance claims; fewer classes or calls alone are not proof of sustained RPM improvement.
6. Final release claims require final-revision CI, clean consumer installation, and Infbyte certification.

## Baseline verification notes

The review baseline uses Foundation `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` and the released dependency line represented by Foundation 3.0.1: ArrayKit 5.2, CacheLayer 3.4, DBLayer 5.1, Epicrypt 3.1, InterMix 10.1.1, Omnibus 2.6, OTP 6.1, Pathwise 4.1, ReqShield 3.2, TalkingBytes 2.1, UID 5.0, and Webrick 5.4.

The source review confirmed F1-F5 directly. F6 is an overlap with a semantic mismatch, not an automatic replacement. F7 is intentionally deferred because generated production already removes the facade and Foundation 3.x exposes it publicly.

The earlier planning baseline reported a focused test run of 25 passed, 1 failed, 169 assertions; the failure was Redis connection refusal during a contention test, not an established counter implementation failure. Implementation batches must produce their own fresh validation evidence rather than inheriting that planning result.

## Current implementation evidence

Implemented branch changes:

- F1 default graph now selects Webrick's native CacheLayer atomic-counter bridge;
  the Foundation wrapper delegates and is deprecated.
- F2 cache-backed auth now requires `cache.default_counter`; the non-atomic
  shared fallback is deprecated and no longer selected.
- F3 the HTTP authorization path evaluates Epicrypt protocol input once and
  reuses the resulting protocol object for Foundation mapping and safe redirect
  handling.
- F4 full/no-effective-range responses reuse Pathwise's initial preparation;
  later stream/body freshness validation remains intact.
- F5 active refresh rotation, contention, and audit tests now target Epicrypt's
  store/manager path. Legacy Foundation refresh types remain deprecated
  compatibility-only surfaces for the Foundation 3.x line rather than being
  removed in a patch/minor hardening pass.
- F6 intentionally makes no runtime change because native Webrick matching would
  silently expand accepted CSRF proof formats.
- F7 remains deferred.

At the time this tracker was updated, the branch was ahead of `main` with no
upstream divergence. GitHub Actions had not produced a branch run, and no pull
request was opened because merge/review ownership remains external to this task.
Therefore unchecked execution gates below remain genuinely pending; they are not
claimed as passing.

## 2026-09-25 review correction

A fresh local review after the first implementation pass found two concrete
blockers and refined the validation state:

- F1: `CacheServiceProvider` referenced `AtomicCounterAdapter` without importing
  Webrick's native class, causing configured-counter startup to resolve
  `Infocyph\Foundation\Cache\AtomicCounterAdapter`. Fixed by importing
  `Infocyph\Webrick\Interop\CacheLayer\AtomicCounterAdapter`.
- F2: the retained compatibility `CacheLayerCounterStore` contained literal
  `\\n` sequences around its deprecation docblock, producing a PHP syntax
  error. Fixed by replacing them with real newlines.
- Review verification before those corrections: broader OAuth/session/cache/
  filesystem/runtime selection **101 passed**; changed/focused selection
  **19 passed, 1 failed** on the native-adapter architecture check; syntax scan
  reported **1 error across 876 files**, which also blocked reference analysis.
- Full QA, performance, production-database, CI, and Infbyte consumer gates were
  still open at that review point.

Regression coverage added after the review:

- configured cache/auth composition now resolves
  `CounterStoreInterface` to Foundation's `AtomicCounterStore` and Webrick's
  throttle interface to Webrick's native `AtomicCounterAdapter`;
- shared auth cache without `cache.default_counter` is required to fail
  composition explicitly;
- existing Redis multi-process contention coverage continues to exercise
  Foundation's `AtomicCounterStore`;
- the initial OAuth and filesystem operation-count guards were source-structure
  checks only; these were later superseded by runtime probes after follow-up
  review.

These corrections and new tests require a fresh local/CI rerun before any
previous failing validation item can be marked green.


## 2026-09-25 follow-up verification and runtime instrumentation

The next local verification pass confirmed that the earlier F1/F2 blockers were
fixed and reported no additional runtime defect. Evidence from that pass:

- focused/changed selection: **27 tests passed**;
- PHP syntax: **passed across 876 files**;
- reference analysis: **passed**;
- PHPStan: **passed**;
- full release/CI, performance, production-database, and Infbyte consumer gates
  remained pending.

Three follow-up quality issues were addressed on the branch:

1. **Skip-policy:** the Redis-extension `markTestSkipped()` path was removed.
   The registration test now inspects the live InterMix definition graph and
   verifies `CounterStoreInterface -> AtomicCounterStore` and Webrick
   `AtomicCounterInterface -> AtomicCounterAdapter` without resolving or
   connecting to Redis.
2. **F3 runtime operation count:** the source-string guard was replaced with a
   runtime DBLayer OAuth client-store probe. A real `OAuthHttpHandler`
   authorization request now records the client-store reads made by Epicrypt
   validation. The regression expects one redirect-URI read for the protocol
   evaluation; the former double-evaluation path would perform two.
3. **F4 runtime operation count:** the source-string guard was replaced with a
   runtime Flysystem metadata probe used through Pathwise. Full and stale
   `If-Range` responses are expected to perform one size/MIME/mtime read each,
   while a valid range performs two, proving the instrumentation detects the
   range-specific re-preparation.
4. **Pint:** `OAuthManager` authorization imports were restored to the bundled
   PHPForge/Pint alphabetical ordering.

The F3/F4 operation-count tracker items intentionally remain unchecked until
these new runtime probes are executed successfully in a fresh local or CI run.
