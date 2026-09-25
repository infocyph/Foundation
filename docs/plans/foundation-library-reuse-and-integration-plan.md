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
| 0 | Baseline, public surface, persistence/compatibility inventory | In progress | Direct callers, generated graph roots, public compatibility, refresh-token persistence requirements recorded |
| 1 | F1 native Webrick CacheLayer throttle bridge | Pending | Native bridge selected in dev/generated graph; compatibility handled |
| 2 | F2 auth atomic counter hardening | Pending | Shared auth state cannot silently use read/modify/write counters |
| 3A | F3 OAuth authorization single evaluation | Pending | Normal HTTP authorization performs one native protocol validation |
| 3B | F4 Pathwise download preparation reuse | Pending | No-range/stale-If-Range full response reuses initial preparation |
| 4 | F5 legacy refresh-token retirement | Pending | Epicrypt path canonical; persistence/credential migration strategy explicit; legacy machinery retired safely |
| 5 | F6 CSRF overlap decision | Pending | Exact proof semantics preserved, or Webrick masked-token support adopted explicitly |
| 6 | Documentation, architecture guards, full release validation | Pending | Required QA/integration/consumer gates green |
| Deferred | F7 HttpKernel public compatibility facade | Deferred for Foundation 3.x | Revisit only in approved public API compatibility window |

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

- [ ] Record current main/base SHA and branch.
- [ ] Inventory F1 public usage and graph roots.
- [ ] Inventory F2 callers/configuration paths.
- [ ] Inventory F3 public/internal call surfaces.
- [ ] Inventory F4 response paths and short-circuit behavior.
- [ ] Inventory F5 legacy classes, tests, table mappings, and credential compatibility.
- [ ] Record F6 exact current accepted proof semantics.
- [ ] Record F7 as deferred Foundation 3.x compatibility surface.

### Batch 1 — F1 Webrick native bridge

- [ ] Select Webrick `Interop\CacheLayer\AtomicCounterAdapter` in Foundation graph.
- [ ] Preserve/deprecate Foundation wrapper only if public compatibility requires it.
- [ ] Add native-owner registration/architecture coverage.
- [ ] Run focused cache/throttle tests.

### Batch 2 — F2 atomic auth counters

- [ ] Remove implicit shared non-atomic fallback.
- [ ] Preserve explicit in-memory development/test state.
- [ ] Document fixed-window expiry contract.
- [ ] Add registrar/configuration/concurrency tests.
- [ ] Run focused auth/cache tests.

### Batch 3A — F3 OAuth authorization evaluation

- [ ] Remove successful-path duplicate native validation.
- [ ] Reuse one protocol result for redirectable failures where it remains simple and safe.
- [ ] Add validation-operation-count coverage.
- [ ] Re-run OAuth HTTP/OIDC/redirect/audit tests.

### Batch 3B — F4 Pathwise download preparation

- [ ] Reuse initial preparation when effective range is null.
- [ ] Preserve stream/body freshness checks.
- [ ] Add preparation/storage-operation-count coverage.
- [ ] Re-run filesystem trust-boundary/range/offload tests.

### Batch 4 — F5 refresh-token consolidation

- [ ] Freeze persistence and existing-credential upgrade policy.
- [ ] Move legacy concurrency/reuse/audit assertions to active Epicrypt path.
- [ ] Retire unused legacy coordinator/store/contracts/types once coverage is equivalent.
- [ ] Update OAuth ownership/migration documentation.
- [ ] Re-run refresh rotation/concurrency and full OAuth integration tests.

### Batch 5 — F6 CSRF decision

- [ ] Prove exact semantic parity or explicitly approve masked-token expansion.
- [ ] Reuse native Webrick mechanics only when the resulting contract/lifecycle is simpler and intentional.
- [ ] Otherwise document no-change decision and retain Foundation raw-token comparison.
- [ ] Run browser-session/CSRF/origin/persistent-worker tests.

### Batch 6 — Closure and release gates

- [ ] Update `docs/architecture/ownership-boundaries.md`.
- [ ] Add architecture guards for selected native owners and forbidden retired owners.
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
