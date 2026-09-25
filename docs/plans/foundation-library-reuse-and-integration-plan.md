# Foundation library reuse and integration plan

Date: 2026-09-25

Status: complete — implementation, staged QA, and release gates closed

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
| 1 | F1 native Webrick CacheLayer throttle bridge | Complete | Native bridge selected; compatibility wrapper deprecated/outside default graph; final matrix green |
| 2 | F2 auth atomic counter hardening | Complete | Shared auth state cannot silently use read/modify/write counters; process-local state remains supported |
| 3A | F3 OAuth authorization single evaluation | Complete | HTTP path reuses one native protocol result; runtime operation-count coverage green |
| 3B | F4 Pathwise download preparation reuse | Complete | No-range/stale-If-Range full response reuses initial preparation; trust-boundary checks preserved |
| 4 | F5 legacy refresh-token retirement | Complete for Foundation 3.x compatibility boundary | Epicrypt path canonical; legacy runtime tests moved; credential cutover policy explicit; contention QA green |
| 5 | F6 CSRF overlap decision | Complete — QA validated, no code change | Foundation 3.x keeps raw-token semantics; Webrick masked-token expansion is not adopted implicitly |
| 6 | Documentation and architecture guards | Complete | Ownership boundaries and native-owner guards recorded and validated |
| 7 | F8 default/named cache identity | Complete | Default and explicit configured names share canonical store identity in development and generated runtime |
| 8 | F9 named cache registry ownership | Complete | Confirmed application-owned consumers reuse CacheManager resources without crossing lifecycle boundaries |
| 9 | F10 TalkingBytes 2.2 typed HTTP composition | Complete | Foundation parses base HTTP config once; TalkingBytes owns composition; focused integration and benchmark gates green |
| 10 | Final release and consumer gates | Complete | Final candidate CI, production database/Redis evidence, clean install, and real Infbyte candidate certification are green |
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
- [x] Run focused cache/throttle tests.

### Batch 2 — F2 atomic auth counters

- [x] Remove implicit shared non-atomic fallback.
- [x] Preserve explicit in-memory development/test state.
- [x] Document fixed-window expiry contract.
- [x] Add registrar/configuration/concurrency tests.
- [x] Run focused auth/cache tests.

### Batch 3A — F3 OAuth authorization evaluation

- [x] Remove successful-path duplicate native validation.
- [x] Reuse one protocol result for redirectable failures where it remains simple and safe.
- [x] Add validation-operation-count coverage. Runtime probe executed successfully.
- [x] Re-run OAuth HTTP/OIDC/redirect/audit tests.

### Batch 3B — F4 Pathwise download preparation

- [x] Reuse initial preparation when effective range is null.
- [x] Preserve stream/body freshness checks.
- [x] Add preparation/storage-operation-count coverage. Runtime probe executed successfully.
- [x] Re-run filesystem trust-boundary/range/offload tests.

### Batch 4 — F5 refresh-token consolidation

- [x] Freeze persistence and existing-credential upgrade policy.
- [x] Move legacy concurrency/reuse/audit assertions to active Epicrypt path.
- [x] Retire unused legacy coordinator/store/contracts/types once coverage is equivalent.
- [x] Update OAuth ownership/migration documentation.
- [x] Re-run refresh rotation/concurrency and full OAuth integration tests.

### Batch 5 — F6 CSRF decision

- [x] Prove exact semantic parity or explicitly approve masked-token expansion.
- [x] Reuse native Webrick mechanics only when the resulting contract/lifecycle is simpler and intentional.
- [x] Otherwise document no-change decision and retain Foundation raw-token comparison.
- [x] Run browser-session/CSRF/origin/persistent-worker tests.

### Batch 6 — Documentation and architecture guards

- [x] Update `docs/architecture/ownership-boundaries.md`.
- [x] Add architecture guards for selected native owners and forbidden retired owners.
- [x] Validate ownership/architecture guards in the full PHPForge QA suite.

### Batch 7 — F8 canonical cache identity

- [x] Correct canonical cache-store identity.
- [x] Add development behavioral regression coverage.
- [x] Add generated-runtime identity coverage.
- [x] Execute F8 focused and generated-runtime coverage.

### Batch 8 — F9 named cache registry ownership

- [x] Unify DB query-cache resolution with named-cache ownership.
- [x] Reuse generation-owned session/application lock providers.
- [x] Route MFA/passkey/webhook named cache state through CacheManager.
- [x] Route migration/scheduler/worker coordination through CacheManager.
- [x] Preserve transactional invalidation and infrastructure-PDO boundaries.
- [x] Add identity/invalidation/replacement/PDO/resource-reuse coverage.
- [x] Execute focused F9 lifecycle and persistent-runtime coverage.

### Batch 9 — F10 TalkingBytes 2.2 typed HTTP composition

- [x] Evaluate the released TalkingBytes 2.2 native APIs.
- [x] Raise the Foundation communication dependency floor to `^2.2`.
- [x] Reuse the typed HTTP base configuration through native resolved composition.
- [x] Preserve Foundation production TLS policy and TalkingBytes optional middleware composition.
- [x] Cross-check email, webhook and gRPC integration contracts against 2.2.
- [x] Execute the TalkingBytes 2.2 focused integration tests and representative benchmark.

### Batch 10 — Final release and consumer gates

- [x] Run focused suites for each changed subsystem on the final candidate.
- [x] Run required PHPForge QA/analysis/security/duplicate/architecture flow on the final revision.
- [x] Run relevant benchmarks/operation-count checks on the final revision.
- [x] Validate PHP 8.4/8.5 stable/lowest dependency rows through CI.
- [x] Validate production database and Redis/Valkey-backed contention paths where CI services support them.
- [x] Validate clean production installation.
- [x] Validate Infbyte consumer against the final Foundation branch/revision.
- [x] Update this tracker to final state with evidence.


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

## Batch 1 QA closure — 2026-09-26

Batch 1 is closed against implementation head `297d34f0ba07eaf55a5e184334ae8e8863293f86`.

- Webrick's native `AtomicCounterAdapter` remains the selected throttle bridge.
- The compatibility Foundation wrapper remains outside the default graph.
- GitHub Actions run `36168426947` completed successfully across PHP 8.4/8.5 stable and lowest QA rows, both analysis rows, clean install, both benchmark rows, all production-consumer rows, and release evidence.
- The full QA suite included the native-owner registration and cache/auth composition regression coverage added for F1.
- No F1-specific failure remained on the validated implementation head.

## Batch 2 QA closure — 2026-09-26

Batch 2 is closed against the corrected atomic-counter implementation validated
at `297d34f0ba07eaf55a5e184334ae8e8863293f86`.

- Host/cluster-visible auth cache state requires the configured native atomic counter resource.
- Process-local and non-state cache backends no longer get misclassified as shared state.
- Missing shared atomic counter configuration fails explicitly.
- The earlier QA regressions in `RuntimeCapabilityConfigTest` and passkey composition were resolved before the final validation run.
- GitHub Actions run `36168426947` completed all PHP 8.4/8.5 stable/lowest QA rows successfully with the F2 regression coverage enabled.

## Batch 3A QA closure — 2026-09-26

Batch 3A is closed against the single-evaluation OAuth implementation validated
at `297d34f0ba07eaf55a5e184334ae8e8863293f86`.

- The HTTP authorization path carries one `AuthorizationProtocolResult` through native validation, redirect/error handling, and Foundation mapping.
- The runtime client-store operation-count regression probe executes in the full Pest suite and passed.
- OAuth HTTP, redirect safety, OIDC, PKCE/scope mapping, and audit regression coverage completed successfully in GitHub Actions run `36168426947`.
- No request-global or singleton protocol-result cache was introduced.

## Batch 3B QA closure — 2026-09-26

Batch 3B is closed against the Pathwise preparation-reuse implementation
validated at `297d34f0ba07eaf55a5e184334ae8e8863293f86`.

- Full/no-effective-range responses reuse the first Pathwise preparation.
- Valid range responses still perform the range-specific preparation.
- Pathwise stream/body freshness and trust-boundary revalidation remains intact.
- The runtime Flysystem metadata-count regression probe executed successfully in the full Pest suite.
- Filesystem range, conditional, offload, traversal/root, and file-change regression coverage passed in GitHub Actions run `36168426947`.

## Batch 4 QA closure — 2026-09-26

Batch 4 is closed for the Foundation 3.x compatibility boundary.

- Active refresh rotation, replay/family revocation, and audit coverage targets Epicrypt's native manager/store path.
- Legacy Foundation refresh execution remains deprecated compatibility-only rather than active graph ownership.
- The migrated concurrent refresh test exposed transient SQLite `BUSY/LOCKED` contention on the active store.
- `DBLayerEpicryptRefreshTokenStore::rotate()` now uses a bounded three-attempt DBLayer transaction retry; DBLayer retries only classified transaction contention/deadlock/serialization failures.
- The exactly-once rotation/reuse assertion remains unchanged and passed across PHP 8.4/8.5 stable and lowest rows in GitHub Actions run `36168426947`.
- Existing credential/table compatibility policy remains explicit; no silent wire or persistence break was introduced.

## Batch 5 QA closure — 2026-09-26

Batch 5 is closed with the deliberate no-change decision.

- Foundation continues to accept its existing raw CSRF proof contract only.
- Webrick masked-token acceptance is not adopted implicitly because it would broaden the public security contract.
- Foundation retains safe-method bypass, configurable proof extraction, Origin policy, session rotation, and 419/no-store response policy.
- Browser-session, CSRF/origin, and persistent-runtime regression coverage completed successfully in GitHub Actions run `36168426947`.

## Batch 6 QA closure — 2026-09-26

Batch 6 is closed.

- Ownership boundaries document the specialist-library/native-owner rules.
- Architecture/registration guards cover native service selection and retired ownership.
- The architecture guards passed in GitHub Actions run `36168426947` together with syntax, references, duplicate analysis, Pest, Pint, PHPCS, Deptrac, Rector, and PHP 8.4/8.5 analysis.
- Release/consumer certification is intentionally moved to Batch 10 so later F8-F10 work is validated before final closure.

## Batch 7 QA closure — 2026-09-26

Batch 7 / F8 is closed.

- `tests/Feature/CacheManagerIdentityTest.php` covers default/explicit-name identity, replacement, distinct named stores, application isolation, default-name changes, and lock-provider invalidation behavior.
- `tests/Feature/GeneratedNonWebRuntimeTest.php` covers generated-runtime identity parity.
- Both regressions are part of the full Pest suite that passed across PHP 8.4/8.5 stable and lowest rows in GitHub Actions run `36168426947`.
- The canonical `__default__` sentinel split is no longer present in the active implementation.

## Batch 8 QA closure — 2026-09-26

Batch 8 / F9 is closed.

- `tests/Feature/DBLayer51QueryCacheIntegrationTest.php` covers named query-cache identity, invalidation visibility, replacement, disabled-cache cold behavior, and infrastructure-PDO separation.
- `tests/Feature/CacheManagerIdentityTest.php` covers lock-provider reuse and replacement invalidation while keeping lock handles per acquisition.
- Existing browser-session locking, transactional invalidation, scheduler/worker coordination, MFA/passkey state, webhook replay, and persistent-runtime suites remain active regression gates.
- The full QA matrix passed these paths on implementation head `297d34f0ba07eaf55a5e184334ae8e8863293f86` in GitHub Actions run `36168426947`.
- Counter/cluster/infrastructure and execution-bound transactional cache lifecycles remain intentionally outside the named application-store registry where their ownership differs.

## Batch 9 QA closure — 2026-09-26

Batch 9 / F10 is closed.

- `tests/Feature/CommunicationIntegrationTest.php` certifies the TalkingBytes 2.2 typed HTTP path while preserving Foundation's production TLS policy.
- `tests/Feature/TalkingBytes22RuntimeIsolationTest.php` covers runtime isolation and the 2.2 replay-window contract.
- `benchmarks/talkingbytes-22-utilization.php` is the representative construction/utilization benchmark for the released integration line.
- Both PHP 8.4/8.5 benchmark rows and all four QA rows passed in GitHub Actions run `36168426947`.
- Email, webhook, and gRPC integration contracts remain native TalkingBytes-owned; Foundation retains only its application policy/persistence adapters.

## Batch 10 QA closure — 2026-09-26

Batch 10 is closed against final candidate implementation head
`44fa8d0fa7fa3f5891c75bc159452cbc4cb25e96`.

GitHub Actions run `36202421388` completed successfully with:

- PHP 8.4 and PHP 8.5, each on prefer-stable and prefer-lowest QA rows;
- PHP 8.4/8.5 analysis;
- PHP 8.4/8.5 representative benchmark rows;
- clean installation;
- four isolated production-consumer rows;
- two real Infbyte `main` candidate-consumer rows, installing the current Foundation workspace rather than the last published tag;
- MySQL, PostgreSQL, SQLite, Redis, Valkey, and Memcached integration services with skipped-test failure enforcement;
- Redis atomic counter/process contention and Redis/Valkey/session lock contention coverage;
- release evidence.

The shared PHPForge `Security Report` presentation job is conditionally skipped on
this pull-request run; executable audit/analyzer and QA security gates completed
successfully. No project quality/security standard was bypassed or suppressed.

The permanent Foundation workflow now retains the real Infbyte candidate-consumer
matrix and makes release evidence depend on it.

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


## Follow-up findings F8-F10

Review baseline: `e7c3e369e761f06afec414752e9c707abb00b1a0`.

| Finding | Priority | Status | Next step |
| --- | --- | --- | --- |
| F8 — Default/named cache identity | P2 | Complete | Identity/replacement/development/generated parity coverage green |
| F9 — Consumers bypass cache registry | P2 | Complete for confirmed application-owned named resources | Invalidation, lock/resource, PDO recursion, and lifecycle suites green |
| F10 — HTTP configuration parsed twice | P3 | Complete against released TalkingBytes 2.2 | HTTP profile/TLS/middleware and benchmark gates green |

### F8 — Default cache selection and its explicit name create different stores

**Priority:** P2  
**Status:** complete; implementation and execution gates closed.

The original `CacheManager` stored `store(null)` under a private
`__default__` key and `store('name')` under the configured name. That made
the default accessor and its explicit configured name separate CacheLayer
instances, which is observably incorrect for instance-owned memory state.

Implemented:

- `CacheLayerFactory::storeName()` resolves the canonical configured name.
- `CacheManager::store()` and `useStore()` use that canonical name; the
  `__default__` sentinel is gone.
- A later explicit default-store configuration change resolves the new named
  store rather than mutating an old alias entry. Previously resolved named
  stores remain accessible by their actual names.
- No process-static cache state was introduced.
- Behavioral coverage checks both access orders, shared values, delete/clear,
  replacement, distinct named-store isolation, independent-application
  isolation, default-name changes, and generated-runtime parity.

Acceptance is closed: the dedicated identity/replacement/generated-runtime tests passed on the final candidate.

- [x] Correct canonical cache-store identity.
- [x] Add development behavioral regression coverage.
- [x] Add generated-runtime identity coverage.
- [x] Execute F8 focused and generated-runtime coverage.

### F9 — Consumers bypass the named cache registry and recreate resources

**Priority:** P2  
**Status:** complete for confirmed application-owned resources; execution and lifecycle gates closed.

Implemented application/generation-owned resolution:

- DBLayer query caching now resolves
  `CacheManager::store(database.query_cache.store)` instead of calling
  `CacheLayerFactory::make()` independently.
- The DB query-cache resolution guard remains in place. PDO-backed CacheLayer
  stores continue to obtain generation-owned infrastructure PDO via
  `DBLayerFactory::infrastructureConnection()`, which does not bind the query
  cache and therefore avoids recursive execution-cache composition.
- `CacheManager` memoizes lock providers by canonical store identity. For
  native store locks it supplies the already-resolved registry store to
  `CacheLayerFactory`; it does not construct another cache merely to extract
  the lock provider.
- `useStore()` invalidates the corresponding memoized lock provider so a later
  native lock selection follows the replacement store.
- Browser-session locking, OTP challenge state, passkey ceremony state, webhook
  replay state, migration locks, scheduler overlap locks, and singleton-worker
  locks now resolve through `CacheManager`.
- Lock handles remain per-acquisition and are never memoized.
- CacheLayer counters, clusters, schema/infrastructure construction, and the
  execution-bound transactional invalidation factory remain outside the named
  application store registry where their lifecycle requires it.

Regression coverage added:

- DBLayer connection query cache is the same object as the configured named
  registry store.
- Writes/clear operations are visible through both DBLayer and the registry.
- A supported registry replacement is rebound when the DBLayer connection is
  next resolved.
- PDO-backed named query-cache construction verifies infrastructure PDO remains
  separate from the execution connection.
- Disabled query caching verifies neither the CacheLayer factory nor
  CacheManager is touched.
- Repeated named/default lock resolution returns one provider while acquisitions
  return separate handles; replacing the store invalidates that provider.
- Existing transaction commit/rollback invalidation and browser-session lock
  lifecycle suites remain required final gates.

- [x] Unify DB query-cache resolution with named-cache ownership.
- [x] Reuse generation-owned session/application lock providers.
- [x] Route MFA/passkey/webhook named cache state through CacheManager.
- [x] Route migration/scheduler/worker coordination through CacheManager.
- [x] Preserve transactional invalidation and infrastructure-PDO boundaries.
- [x] Add identity/invalidation/replacement/PDO/resource-reuse coverage.
- [x] Execute focused F9 lifecycle and persistent-runtime coverage.

### F10 — HTTP profile construction parses native configuration twice

**Priority:** P3  
**Status:** complete against TalkingBytes 2.2; execution and benchmark gates closed.

TalkingBytes 2.2 released the library-owned typed composition prerequisite:

- `HttpClientFactory::fromConfig(HttpClientConfig, array, ...)` composes
  authentication, cookies, retry, rate limiting, circuit breaking, and
  idempotency around an already-parsed base HTTP configuration.
- `HttpClient::fromResolvedConfig(..., baseConfig: $config)` exposes the same
  path through the public facade while preserving existing array-only callers.

Foundation now parses each selected HTTP profile once with
`HttpClientConfig::fromArray()`, applies its production TLS policy to that
typed object, and passes the same object back to TalkingBytes as
`baseConfig:`. TalkingBytes owns all optional middleware/auth composition and
does not reparse the base HTTP options on this path.

TalkingBytes 2.2 was also reviewed across Foundation's other integration
surfaces. Its release is API-compatible for the email sender/receiver/mailbox,
webhook replay-store, and gRPC factory contracts Foundation consumes. The new
webhook replay TTL is explicitly a lower bound; Foundation's atomic CacheLayer
store already honors the TTL requested by the native receiver.

No throughput claim is made until the representative benchmark is rerun.

- [x] Evaluate the released TalkingBytes 2.2 native APIs.
- [x] Raise the Foundation communication dependency floor to `^2.2`.
- [x] Reuse the typed HTTP base configuration through native resolved composition.
- [x] Preserve Foundation production TLS policy and TalkingBytes optional middleware composition.
- [x] Cross-check email, webhook and gRPC integration contracts against 2.2.
- [x] Execute the TalkingBytes 2.2 focused integration tests and representative benchmark.

## Follow-up verification gates

- [x] Run new F8/F9 identity, invalidation, replacement, and resource-reuse regression tests.
- [x] Verify development/generated graph parity, PDO-backed cache composition, transactional invalidation, and persistent request/job isolation.
- [x] Complete Redis/Valkey-backed counter and lock contention/expiry verification; prior host Redis connection refusal left this open.
- [x] Verify F10 production TLS enforcement, protocol middleware, scoped client-state isolation, and the TalkingBytes 2.2 construction benchmark.
- [x] Run the complete required PHPForge QA, analysis, security, duplicate, and architecture checks on the final candidate.
- [x] Run relevant representative benchmarks before making throughput claims.
- [x] Validate the PHP 8.4/8.5 stable/lowest dependency matrix and relevant production database engines on the final revision.
- [x] Verify clean production installation and the Infbyte consumer against the final candidate.
- [x] Record final-revision CI and remaining gate results before release.


### TalkingBytes 2.2 integration refresh

TalkingBytes tag `2.2` is now the Foundation communication baseline. The
release is a compatible minor but contains broad protocol hardening: HTTP
credential/cookie/redirect/download correctness, spool claim behavior, DKIM
verification corrections, generated gRPC stream final-status handling, and
webhook replay-window retention.

Foundation's cross-check found no required adapter rewrite outside F10:

- HTTP now uses the released typed-base resolved-composition API.
- Email profile construction remains on native `EmailSenderFactory`,
  `EmailReceiverFactory`, `EmailMailboxFactory` and typed config objects.
- Webhook replay storage still implements the native `WebhookReplayStore`;
  its atomic CacheLayer claim honors the larger TTL TalkingBytes 2.2 may request.
- gRPC callable/native/generated client and inbound-dispatch contracts remain
  compatible; the corrected native/generated stream status behavior stays
  TalkingBytes-owned.

The old TalkingBytes 2.1 benchmark/test labels were refreshed to 2.2 so release
evidence identifies the dependency actually under test.
