# Foundation 4.0 audit and release plan

Audit date: 2026-10-11. Target: **Foundation 4.0**, upgrading directly from
published Foundation 2.1.1 and skipping publication of Foundation 3.

**Live release checkpoint (2026-10-14): Batch 1 6/6, Batch 2 8/8, Batch 3 4/4 and Batch 4 7/7 accepted; overall 25/37. Batch 5 active (0/4 accepted).** Latest fully green Batch 4 implementation [`1ccab59b`](https://github.com/infocyph/Foundation/commit/1ccab59b9d5e4bee2c6cc4ac98db544d6a0e746d), [Security & Standards #38512181935](https://github.com/infocyph/Foundation/actions/runs/38512181935) **SUCCESS**: PHPForge QA PHP 8.4/8.5 lowest/stable **4/4**, PHPStan **2/2**, production consumer installs **4/4**, benchmark jobs **2/2**, clean install and release evidence passed. Batch 4 accepted all seven native-composition gates below using existing real-service integration tests plus this batch's targeted production fixes and regressions. Native libraries retain their implementation ownership; tenant scope still requires an explicit application Gate/policy, and account/device session/token revocation requires the documented explicit owner APIs rather than an implicit hidden cascade. No fallback or BC for retired library series, no shadow owner of Runwire/Webrick/CacheLayer/DBLayer/OTP/Epicrypt/Omnibus/Pathwise/TalkingBytes. **Prior 0/7/pending QA notes below are preserved historical checkpoints, not live acceptance.** PR #20 remains draft; no merge, tag or publication.

### 2026-10-14 — Batch 4 complete: 7/7 accepted, release audit gate closed

**Evidence at exact production source:** [`1ccab59b`](https://github.com/infocyph/Foundation/commit/1ccab59b9d5e4bee2c6cc4ac98db544d6a0e746d), [Security & Standards #38512181935](https://github.com/infocyph/Foundation/actions/runs/38512181935), **full SUCCESS**. PHPForge 4/4 QA (8.4/8.5, stable/lowest); 2/2 PHPStan analyses; 4/4 consumer installs; 2/2 benchmark jobs; clean install and release evidence. Existing native feature suites run under the same full pipeline. Security Report job is nonrequired/skipped; benchmark regression-compare steps are not represented by simply passing benchmark jobs.

| Gate | Accepted verification across source and real integration suites |
| --- | --- |
| **1/7 Authentication/authorization** | Scoped role/permission revoke-on-read, resource-aware delegation, explicit tenant-before-wildcard policy, invalid-account session freshness, CSRF cross-session/rotation, secure cookie/session fixation and expiry (`AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`); real Redis/Valkey/Memcached/MySQL/PostgreSQL session lock contention (`SessionLockContentionTest`), Redis multi-process native CacheLayer counters/CAS (`CacheLayer33AtomicIntegrationTest`), production security posture (`ProductionSecurityClosureTest`) |
| **2/7 Full built-in flows** | Login/lockout/logout/rotation/account-specific session revocation, device revocation irreversibility and rejected trust/touch, verification/password reset/change/passwordless/remember, step-up, recovery, impersonation and native Gate/roles/grants (`AuthDomainIntegrationTest`, `AuthReleaseClosureTest`, `BrowserSessionIntegrationTest`, `OAuth21PersistentPrincipalResetTest`). Session/token revocation remains an explicit domain operation; no undocumented automatic cascade |
| **3/7 OAuth/tokens** | Redirect/PKCE/client/scope/replay failure matrix, independent-process SQLite authorization-code single consume and Epicrypt refresh-rotation reuse family revocation, cross-process durable token revocation, audit/throttle and bounded HTTP input (`OAuth21ProtocolRejectionMatrixTest`, `OAuth21AuthorizationCodeConcurrencyTest`, `OAuth21RefreshConcurrencyTest`, `OAuth21DurableRevocationProcessTest`, `OAuth21AuditLifecycleTest`, `OAuth21HttpBoundaryTest`, `OAuth21SensitiveOutputClosureTest`); Epicrypt owns cryptographic issuance/exchange |
| **4/7 MFA/passkeys** | OTP terminal-state/replay, one-time recovery consumption plus repeat rejection, Epicrypt-protected MFA AAD/encryption with stale-revision CAS, KeyResolver fail-closed production checks, OTP 6.1 factor protocols, native WebAuthn passkey ceremony and origin/RP production-policy validation (`OtpIntegrationTest`, `AuthDomainIntegrationTest`, `Epicrypt3MfaSecretProtectionTest`, `Epicrypt3MfaKeyResolverTest`, `Otp61FactorIntegrationTest`, `OtpPasskeyIntegrationTest`, `ProductionSecurityClosureTest`) |
| **5/7 Persistence/messaging** | Shared MySQL/PostgreSQL DBLayer transaction rollback and native CacheLayer outbox commit propagation across two nodes with exactly one event consumption; multi-process Redis CAS/lockout counters/webhook replay, native Omnibus scoped dispatch and scheduler leadership/restart/cancellation and DB reconnect/rollback (`CacheLayer4PdoOutboxIntegrationTest`, `CacheLayer33AtomicIntegrationTest`, `PersistentDatabaseLifecycleTest`, `SchedulerRuntimeClosureTest`, `GeneratedWorkerCancellationTest`); no Foundation reimplementation of transport ownership |
| **6/7 HTTP/filesystem/communications** | Pathwise untrusted upload and disk/path isolation, Webrick file range/conditional/chunked streaming, body handling/offload/malware-scanner policy, trusted compiled artifacts/HTTP error safety and TLS security defaults with typed TalkingBytes client/Webhook/gRPC ownership (`FilesystemPathwise41TrustBoundaryTest`, `FilesystemPathwise4IsolationTest`, `FilesystemHttpBridgeTest`, `FilesystemOffloadPolicyTest`, `FilesystemMalwareScannerResolverTest`, `WebReleaseRuntimeTest`, `CommunicationIntegrationTest`, `ProductionSecurityClosureTest`, `OAuth21HttpBoundaryTest`); host/native communication policy remains the authority for SSRF/retry/transport mechanics |
| **7/7 Persistent lifecycle** | 1000-cycle GC leak/failure soak, 1000-cycle OAuth principal scope reset, DB rollback and memoization isolation, concurrent web/Runwire Fibers, compiled CLI/worker/scheduler source-free contexts, shared-service cleanup/release, cancellation and dependency generation leases (`PersistentRuntimeSoakTest`, `OAuth21PersistentWorkerSoakTest`, `PersistentExecutionStateIsolationTest`, `PersistentDatabaseLifecycleTest`, `RunwireNonWebScopeTest`, `WebReleaseRuntimeTest`, `GeneratedNonWebRuntimeTest`, `SchedulerRuntimeClosureTest`) |

**Release tracking:** Batch 1 **6/6**, Batch 2 **8/8**, Batch 3 **4/4**, Batch 4 **7/7**; **25/37 overall**. Batch 5 **0/4** next. These are Foundation composition acceptance checks and do not claim general security certification of any upstream native library or application-specific authorization policy. Prior pending entries retained below for provenance. PR remains DRAFT.

### 2026-10-14 — Device revocation result correctness adjustment (source pending QA)

After rejecting invalid trust/touch transitions for revoked devices, ensure the returned `DeviceResult` is itself a **failure**, not a successful `REVOKED` status mistaken for a fresh revoke operation. The new explicit `DeviceStatus::REJECTED` remains outside `DeviceResult::successfulStatuses`; `code='device_revoked'` communicates the reason, with no state mutation. Tests assert rejection via `successful() === false` and permanent revoked/untrusted state. This is a correctness refinement to the consolidated Batch 4 code increment; acceptance remains pending exact-head QA.

### 2026-10-14 — Batch 4 consolidated security and lifecycle acceptance audit (pending exact-HEAD CI)

**Consolidated source increment:** `DeviceManager::trust` and `touch` now refuse to mutate already revoked native device records; the returned result stays `DeviceStatus::REVOKED` without falsely restoring trust or activity. `AuthDomainIntegrationTest` verifies account-scoped session revocation with explicit exception and unrelated-account isolation, revoked-device trust/touch rejection, and single-use MFA recovery code. `CacheLayer4PdoOutboxIntegrationTest` verifies a committed invalidation is consumed once (second consume returns zero) across actual MySQL/PostgreSQL-backed CacheLayer PDO transports, while preserving rollback tests. Previous Batch 4 source work already exercised suspension, permission revocation and tenant gate, browser session fixation/CSRF isolation. **All seven acceptance boxes remain open until full exact-HEAD QA**. Do not count source-only evidence as a release acceptance.

**Seven-gate evidence mapping to real feature suites (audit candidate, final verification pending):**

| Gate | Meaningful regression / native owner evidence | Still require before sign-off |
| --- | --- | --- |
| #1 auth/authorization | `AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`, `SessionLockContentionTest` Redis/Valkey/Memcached/MySQL/PostgreSQL, `CacheLayer33AtomicIntegrationTest` Redis 8-worker claims/CAS/counters, `ProductionSecurityClosureTest`, `OAuth21HttpThrottleTest` | Full exact-head security CI; verify explicit tenant policy is an app requirement |
| #2 complete auth flows | `AuthDomainIntegrationTest` login/verification/reset/passwordless/remember/token/role/device/step-up/impersonation; `AuthReleaseClosureTest`; `BrowserSessionIntegrationTest` cookies/CSRF/rotation; `OAuth21PersistentPrincipalResetTest` | Complete exact-head CI; no automatic cross-device session revocation is promised |
| #3 OAuth | `OAuth21ProtocolRejectionMatrixTest` redirect/PKCE/replay/clients; `OAuth21AuthorizationCodeConcurrencyTest` and `OAuth21RefreshConcurrencyTest` two processes/SQLite; `OAuth21DurableRevocationProcessTest`, `OAuth21AuditLifecycleTest`, `OAuth21HttpBoundaryTest` | Complete exact-head CI with the real persistence/race tests |
| #4 MFA/passkeys | `OtpIntegrationTest` native OTP enrollment/replay; `Epicrypt3MfaSecretProtectionTest` encrypted key/AAD, stale revision CAS and one-time recovery; `OtpPasskeyIntegrationTest`, `Otp61FactorIntegrationTest`, `Epicrypt3MfaKeyResolverTest` | Complete exact-head CI and single-use recovery regression |
| #5 persistence and messaging | `CacheLayer4PdoOutboxIntegrationTest` native MySQL/PostgreSQL rollback/commit/duplicate consume, `CacheLayer33AtomicIntegrationTest` 8-process Redis CAS/replay, `SchedulerRuntimeClosureTest` elected execution/cancellation, `PersistentDatabaseLifecycleTest` and `GeneratedWorkerCancellationTest` | Complete exact-head real-backend CI |
| #6 HTTP/filesystem/communication | `ProductionSecurityClosureTest`, `FilesystemPathwise41TrustBoundaryTest`, `FilesystemPathwise4IsolationTest`, `FilesystemHttpBridgeTest` HTTP range/stream/upload, `FilesystemOffloadPolicyTest`, `FilesystemMalwareScannerResolverTest`, `CommunicationIntegrationTest`, `OAuth21HttpBoundaryTest` | Complete exact-head CI and negative transport tests |
| #7 persistent lifecycle | `PersistentRuntimeSoakTest` 1000 executions and GC after failures; `PersistentExecutionStateIsolationTest` request/principal/DB rollback/memo; `GeneratedNonWebRuntimeTest`, `RunwireNonWebScopeTest`, `WebReleaseRuntimeTest`, `SchedulerRuntimeClosureTest` | Complete exact-head CI across modes |

**Acceptance guardrails:** no invented tenant policy, no implicit runtime, no shadow owner of Epicrypt/OTP/CacheLayer/Webrick/Runwire/DBLayer/Omnibus, and no claim that job-success alone proves a distinct untested contract.

### 2026-10-14 — Batch 4 browser-session CSRF isolation and token rotation (source pending CI)

`BrowserSessionIntegrationTest` now exercises distinct independently issued browser sessions, rejects a CSRF token minted under another browser-session ID (419), verifies the originating session's own token succeeds, rotates its token natively, rejects the superseded token, and confirms the unaffected session can still post successfully. This extends existing origin, header/body, query rejection, SameSite/Secure/HttpOnly and session-ID regeneration evidence without replacing Webrick or Foundation's browser-session owner. **Exact-head CI pending. Gate #1 remains 0/1 and Batch 4 remains 0/7; total 18/37** pending remaining security/persistence matrix.

### 2026-10-14 — Batch 4 revocation freshness and explicit tenant policy tests (source pending CI)

`AuthDomainIntegrationTest` now exercises native permission/role store mutation with the *same* authorizer instance across permission-to-role revocation, role removal, direct account assignment and revocation, verifying denial is immediate instead of relying on a stale permission cache. A separate Gate-before-policy test explicitly rejects mismatched or missing resource tenant IDs despite an otherwise matching broad account permission (`invoice:*`). **This only proves explicit application-defined tenant policy, not automatic tenant isolation for all globally granted abilities.** Exact-head CI pending, Batch 4 0/7, overall 18/37.

### 2026-10-14 — Batch 4 account-status session freshness fix (source pending CI)

**Reproduced inspection:** `SessionPrincipalResolver::resolve` was touching any nonexpired, existing account session *before* applying `AbstractPrincipalResolver::principalForAccount`'s `SUSPENDED`/`LOCKED`/`DISABLED` denial. That kept denied sessions' `lastSeenAt` fresh even though the caller could not authenticate. **Fix:** touch only after obtaining a nonnull eligible principal; no new service or account/session owner. Added `AuthDomainIntegrationTest` regression exercising suspension, unlock/reacceptance, lock, and disable with an old, still-unexpired real session store record. This does not itself revoke sessions or establish tenant-scoped direct permissions. **Exact-head CI pending, Batch 4 remains 0/7; total 18/37.**

### 2026-10-14 — Batch 4 start: security and persistence evidence ledger

**Status: Batch 4 0/7 accepted; total 18/37.** Start with security gate #1 while keeping its many independent subrequirements open until independently verified. Do not infer a full gate pass from one added test. New source: `AuthDomainIntegrationTest` exercises direct permission account isolation, resource-type/resource-ID-scoped delegation, different principals, revocation and expired-grant rejection. CI for the initial Batch 4 delegation/resource test is **SUCCESS** at [`8468ede0`](https://github.com/infocyph/Foundation/commit/8468ede0615b586e856a97112516aa0b779b89f0), [#38508895479](https://github.com/infocyph/Foundation/actions/runs/38508895479). These checks validate principal/resource isolation but do not themselves establish trusted tenant policy enforcement.

| Gate | Existing relevant tests / native owner | Remaining validation before acceptance | Status |
| --- | --- | --- | --- |
| #1 Authentication/authorization | `AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`, `SessionLockContentionTest`, `ProductionSecurityClosureTest`; CacheLayer atomic lock ownership | Tenant-aware policy boundary, stale authorization/permission state, active-session effects of account suspension, cookie/CSRF/rate limits, distributed lock/counter load | **0/1** |
| #2 Full built-in auth flows | `AuthDomainIntegrationTest`, `BrowserSessionIntegrationTest`, `AuthReleaseClosureTest` | Comprehensive cross-request browser/auth session, device revocation, step-up/recovery, token/session invalidation and native owner boundary evidence | **0/1** |
| #3 OAuth / token races | `OAuth21AuthorizationCodeConcurrencyTest`, `OAuth21RefreshConcurrencyTest`, `OAuth21AuditSecurityTest`, Epicrypt OAuth/PKCE suites | Broader real-DB races, approved redirect/PKCE negative combinations, repeated revocation/reuse and auditable atomicity | **0/1** |
| #4 MFA / passkeys | `OtpPasskeyIntegrationTest`, `Epicrypt3MfaSecretProtectionTest` | Real-backend OTP/recovery replay contention, counter CAS, WebAuthn origin/RP and credential lease races | **0/1** |
| #5 Persistence / messaging | `PersistentDatabaseLifecycleTest`, `PersistentExecutionStateIsolationTest`, `SessionLockContentionTest`, Omnibus integrations | Outbox/rollback atomicity, duplicates, webhook replay, elected scheduler, reconnects and generation resets under actual contention | **0/1** |
| #6 HTTP / filesystem / communication | `ProductionSecurityClosureTest`, `WebReleaseRuntimeTest`, HTTP security suites | Proxy trust, uploads/paths, redirects, native response streaming, TLS/SSRF and redacted failure/reporting negative matrix | **0/1** |
| #7 Persistent lifecycle | `PersistentRuntimeSoakTest`, `PersistentDatabaseLifecycleTest`, Batch 3 Runwire host isolation | Cross-mode concurrent request/task/failure/resource-bounds and dependency-replacement soak acceptance | **0/1** |

No fallback or BC for retired dependency families, no duplicate native module ownership, and no reduction of authentication validation to make tests pass.

### 2026-10-14 — Batch 3 final acceptance (exact CI green)

All four Batch 3 checklist gates were accepted against source `0a808972` with full [Security & Standards #38508326050](https://github.com/infocyph/Foundation/actions/runs/38508326050) SUCCESS. Prior QA-red attempts retained in this audit log are historical failures, not current project blockers. Live count is **Batch 3 4/4, Batch 4 0/7, total 18/37**.

### 2026-10-14 — Batch 3 QA fixture warning and exact-head retry

[Security & Standards #38507863227](https://github.com/infocyph/Foundation/actions/runs/38507863227) at `a40333f8` executed all new Runwire native tests successfully and passed PHPStan plus consumer installs, but the PHPForge matrix **failed PHPCS** because its intentionally throwing anonymous PSR logger's three required method parameters were not marked as unused. The logger fixture now explicitly consumes `$level`, `$message` and `$context` with `unset` before throwing. The correction changes no production code. **QA for the new HEAD is pending; do not accept any criterion on prior red QA.**

### 2026-10-14 — Batch 3 generation overlap and throwing reporter (source pending CI)

**Native host regression:** `WebReleaseRuntimeTest` now overlaps separate Runwire host applications, each bound to a distinct process-local native generation, on independent caller-owned Fibers over the same compiled Foundation release. Both request contexts must remain active simultaneously, then complete with exact respective runtime/request DI identities and one response emission. Runwire/Webrick/InterMix retain their native lifecycle ownership; no alternative runtime factory or scheduler.

**Reporter-failure regression:** `RunwireSelectedNativeBoundaryTest` uses Foundation's published `ExceptionReporter`/`HttpExceptionLogger` with a throwing PSR logger inside real InterMix Runwire request scope. The reporter's original failure must emerge, scoped CacheLayer state must detach, and a subsequent request must receive the new exact request identity with no stale state. **Pending QA; do not accept Batch 3 until exact-head gates are green.**

### 2026-10-14 — Batch 3 QA blocker: compiled borrowed scope entry

[Security & Standards #38506895896](https://github.com/infocyph/Foundation/actions/runs/38506895896) at `3cbcd38d` **FAILED** all 4 PHPForge QA variants with the same `RunwireNonWebScopeTest` compiled borrowed-scope error (`ProductionScopeStore::attach`: receiving carrier already has an active scope); production consumers **4/4**, PHPStan **2/2**, benchmark jobs **2/2** and clean install succeeded. No Batch 3 acceptance. The next source change resolves the `ExecutionScope` service on the owner carrier before starting the borrowed Fiber, avoiding lazy fallback/service initialization on the receiving carrier before InterMix scope attachment. **Fix pending exact-HEAD CI, not accepted.**

### Batch 3 same-PID wrong-generation request rejection (source pending CI)

`WebReleaseRuntimeTest` now drives the *compiled* Webrick/Runwire host with two distinct Runwire `RuntimeContext` objects in the **same PID** and different native generations. A request from the other generation must fail before entering route or emitting response; the host's original-generation request must subsequently succeed and finalize exactly once. This complements the proven wrong-PID and two-host release-generation lease cases without inventing a Foundation generation resolver or host scheduler. **Exact-head QA pending; strict #3 and overall Batch 3 acceptance unchanged (0/4, total 14/37).**

### Batch 3 compiled non-web selected Runwire topology (source pending CI)

`RunwireNonWebScopeTest` now builds trusted InterMix production artifacts for explicitly selected **worker, CLI and scheduler** modes, source-free loads them through `GeneratedRuntime::loadPrevalidated`, and checks the exact Runwire `RuntimeContext`/`RequestContext` identity through the real `ExecutionScope::run` and native borrowed `ScopeContext` in a child Fiber. This closes the development-only-versus-compiled non-web parity evidence gap, without a duplicated host or dynamic fallback. **QA pending; strict Batch 3 0/4, overall 14/37** until the full host matrix is accepted.

### Batch 3 native throwing child and sequential host reuse (verified QA #38505924046)

**New acceptance regression:** `RunwireCoroutineScopeIntegrationTest` executes a genuine Runwire `CoroutineRuntime::runRequest`, InterMix `withinRequest` and `CoroutineScope::spawn(wrapChild(...))` with a child throwing `DomainException`. The original failure must propagate, native task/DI/CacheLayer boundaries must detach, and the *same* integration plus coroutine runtime must accept a new host request with the new exact request identity and no retained previous context. Runwire retains request completion ownership; no Foundation-managed event loop, fallback or duplicated scheduler. **QA pending, Batch 3 remains 0/4; overall 14/37.**

### Batch 3 native deferred transport drain (verified QA #38505924046)

**Source increment:** A real compiled Webrick/Runwire response runs against a native `ResponseWriterInterface` implementation returning `WriteState::PRESSURED` on headers. The writer's host-owned drain callback resumes Webrick's published Runwire continuation, allowing the request to complete with one terminal response and preserved Runwire request identity. Before drain, no response end or request completion is allowed. Repeated drain notifications must not duplicate emission. This tests deferred native output without creating a Foundation scheduler, an extra emitter or ownership of the writer. **CI pending, strict counts unchanged** (Batch 3 0/4; overall 14/37).

### Batch 3 compiled host concurrent isolation / cancellation (verified QA #38505924046)

**Source change (new HEAD, pending CI):** `WebReleaseRuntimeTest` runs two real compiled Webrick route executions through separate Fibers under the same native Runwire host and runtime. Both route callbacks suspend with their distinct injected `RequestContext`; the host cancels only the first; the second resumes to complete normally with exactly one response emission and no cross-request identity. The first must finalize without stealing the other's state. This regression exercises actual in-flight host cancellation and overlapping InterMix scopes; it does not simulate admission via independently constructed libraries. **Strict acceptance remains 0/4, overall 14/37** pending exact-head CI and the remaining lifecycle matrix.

### Live Batch 3 criterion ledger — accepted 4/4 on 2026-10-14

| Gate | Exact published/native integration and regression evidence | Acceptance |
| --- | --- | --- |
| #1 Native scope propagation | `WebReleaseRuntimeTest`, `RunwireNonWebScopeTest`, `RunwireCoroutineScopeIntegrationTest`: compiled web and trusted generated worker/CLI/scheduler, owned and borrowed InterMix scope, native task propagation | **Accepted** |
| #2 Library forwarding | `RunwireSelectedNativeBoundaryTest`, `WebReleaseRuntimeTest`: all selected owner API contracts, direct route/module forwarding, native intermediary/borrowed Fiber, actual Runwire structured children | **Accepted** |
| #3 Host capability and identity | Plain-PHP fallback, no implicit Runwire host, incompatible PID/completed/cancelled/wrong runtime requests, distinct same-PID generations, overlapping host instances, independent generation leases | **Accepted** |
| #4 Cleanup and isolation | Native structured child failure/reuse, throwing reporter with fresh request, owned/borrowed DI cleanup, cancellation during two concurrent requests, deferred pressured writer single finalization, generation overlap | **Accepted** |

**Exact-head QA sign-off:** [`0a808972`](https://github.com/infocyph/Foundation/commit/0a8089728e0f3a3bc6c61883e0776199ab59be3b), [CI #38508326050](https://github.com/infocyph/Foundation/actions/runs/38508326050) **SUCCESS**, 4/4 PHPForge QA, 2/2 PHPStan, 4/4 production consumers, 2/2 benchmark jobs, clean install and release evidence passed. No skipped quality acceptance. Earlier `3cbcd38d` (scope attachment) and `a40333f8` (three PHPCS fixture warnings) were red; fixed in `7f48b90c` and `0a808972` respectively. **Batch 3 4/4, overall 18/37.** This acceptance is for Batch 3 Runwire boundaries, not security certification of Batch 4+.

**Verified implementation increment (CI #38502975203 SUCCESS):** strengthen compiled Webrick route to invoke selected native package APIs through the exact injected runtime/request; reject stale-process web hosts before acquiring generation leases; prove two independently constructed hosts prevent early prune when one shuts down; explicitly reject completed non-web requests. These are genuine coverage increments, **not 4/4 sign-off**. Strict counts remain Batch 3 **0/4**, overall **14/37** until accepted exact-head QA plus remaining cases. No change to native owner boundaries or optional Runwire production dependency.

This document contains a preserved baseline audit and a subsequent implementation log. The **live implementation status above** reflects later committed source and dependency changes; the original audit did not itself alter production code. This record does not certify the library as vulnerability-free.

### 2026-10-14 — Batch 3 selected non-web Runwire graph and host seed validation: code-head QA green

**Source implementation:** `FoundationGraph` now registers InterMix's published native Runwire runtime/request/coroutine input declarations for **explicitly selected** worker, scheduler and CLI modes, as well as compiled web. Plain PHP graphs contain no Runwire inputs or implicit host, and an explicitly selected Runwire topology without the native dependency fails closed. `ExecutionScope::run` accepts the exact caller-supplied Runwire objects as ordinary InterMix input seeds; `assertHostRunwireSeeds` rejects missing runtime, wrong PID, wrong/completed request, host cancellation, or a coroutine scope that lacks native coroutine capability or live task ownership. Neither Foundation nor a selected package generates or releases the Runwire context. `ExecutionScope::withinBorrowedScope` can reuse the same scoped identities in a child carrier. No second native scheduler, transport, context wrapper or autoselected runtime is introduced.

**Tests:** `RunwireNonWebScopeTest` exercises worker/CLI/scheduler scopes and cross-Fiber borrowed identity; invalid PID/request/completed/cancelled boundaries; unselected production-capability topology. [Security & Standards #38500187821](https://github.com/infocyph/Foundation/actions/runs/38500187821) **SUCCESS** on exact implementation SHA [`e256cbc0`](https://github.com/infocyph/Foundation/commit/e256cbc08838605faca5e1ff2e279acb7cb3ef12): PHPForge QA **4/4** (PHP 8.4/8.5, lowest/stable), PHPStan **2/2**, production consumer installs **4/4**, benchmarks **2/2**, clean install and release evidence. The preceding `3db28588` source QA exposed one PHPStan cognitive complexity limit in host-seed validation; `e256cbc0` separated the native coroutine scope guard without removing any validation or disabling a rule. The preceding actual coroutine test at `6aa49f0c` passed [Security & Standards #38499222816](https://github.com/infocyph/Foundation/actions/runs/38499222816): QA 4/4, analysis 2/2, production consumers 4/4, benchmarks 2/2, clean install and release evidence.

**Acceptance remains Batch 3 0/4; overall 14/37** until full runtime/host/cancellation/ownership requirements are verified. Keep PR #20 draft.

### 2026-10-14 — Batch 3 real Runwire coroutine/structured child scope (pending QA)

**Added:** `RunwireCoroutineScopeIntegrationTest` executes an actual Runwire `CoroutineRuntime::runRequest` with a supported native coroutine `RuntimeContext`, retaining the caller-created request and coroutine scope. InterMix's published `RunwireIntegration::withinRequest` seeds the request scope; its `wrapChild` carries the live InterMix context through **native `CoroutineScope::spawn`**, not an independently scheduled PHP Fiber. The child verifies exact runtime/request/coroutine identities, CacheLayer sharing, ArrayKit cooperative traversal, OTP and ReqShield/UID cancellation checkpoints, DBLayer `withRunwire` and Omnibus `withRunwire`; the parent verifies restored state and absence of retained contexts after structured completion. This is a contract test of upstream Runwire, InterMix and selected libraries, with no Foundation-owned loop/task/scheduler.

**Cancellation test correction:** InterMix's scope entry intentionally does not replace the host's cancellation gate when no coroutine is present. The regression now asserts native host token cancellation and native module checkpoints, rather than attributing an unenforced cancellation guarantee to InterMix. The earlier code-head `fb9deee8` QA failed this overstrict assertion; corrected in `6827109b`. Keep historical QA failures traceable.

**Strict tracker:** Batch 3 **0/4** accepted, overall **14/37** until exact-head QA and cross-module host route, wrong-host, lifecycle and teardown requirements are complete. PR remains draft; do not merge/tag/publish.

### 2026-10-14 — Batch 3 native selected-library child/failure boundaries (source pending QA)

**Source milestone:** `RunwireSelectedNativeBoundaryTest` now executes a *real* InterMix `withinRequest` scope with the caller's exact Runwire runtime and request. From an intermediary closure it captures the native `ScopeContext`, attaches it in a child Fiber via `RunwireIntegration::withinScopeContext`, and invokes the actual selected-library Runwire APIs: CacheLayer's currently shared request, DBLayer `Connection::withRunwire` with a SQLite descriptor but no query, Omnibus `RunwireBinding::withRunwire`, Epicrypt, OTP, Pathwise, ReqShield and UID checkpoints, ArrayKit's lazy traversal and TalkingBytes request binding. The test checks request identity in parent/child, unchanged restored state, detach with no leaked DBLayer/Omnibus/CacheLayer state, and rejection of the expired InterMix handle. It also asserts a host-cancelled request is rejected by InterMix, ReqShield and UID. This uses the **native** Runwire/InterMix/CacheLayer lifecycles and no Foundation proxy task scheduler or fallback.

**Acceptance deliberately unchanged:** Batch 3 **0/4**, overall **14/37**, pending exact-source full QA, true host coroutine scope, native end-to-end module route integrations, wrong-host/generation guards, deferred output, and cancellation/teardown across nested and concurrent boundaries. Batch 1/2 remain accepted and PR #20 draft.

### 2026-10-14 — Batch 3: independent host-held release generation lease (pending QA)

**Fix after analysis:** `5470cc28` used a closure capture solely to hold the release identity, which PHPStan correctly rejects as unused. The revised implementation gives each native Runwire host its **own shared `ReleaseGenerationLease::acquireShared`** for its loaded Foundation generation; the host releases exactly its lease after native InterMix release on shutdown (or failed construction), and the lease destructor is the final guard for abandoned hosts. The ordinary source-only release without loaded generation does not need an outer generation lock. The lock regression now proves original generation metadata can be garbage-collected **while the host's distinct prune-protection lease remains held**, and that the lock is exclusively acquirable after host shutdown. This avoids duplicate Runwire/runtime/scheduler ownership and avoids competing host objects releasing each other's generation lease.

**Acceptance status:** Batch 3 **0/4**, overall **14/37**, until exact-source tests/analysis/benchmarks pass and native host + module + child-task contracts are fully evidenced.

### 2026-10-14 — Batch 3 compiled host dispatch green; generation lease hardening underway

**Verified exact-source release:** [Security & Standards #38496464415](https://github.com/infocyph/Foundation/actions/runs/38496464415) at [`8f216b8b`](https://github.com/infocyph/Foundation/commit/8f216b8b06926eb88c97ef7dd2414907e86edc9b) completed **SUCCESS**: PHPForge QA **4/4** across PHP 8.4/8.5 stable/lowest (including 570+ Pest tests, native library API matrix, compiled Runwire request dispatch, explicit no-Runwire rejection), PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install and release evidence. The route regression checks exact host `RuntimeContext` and `RequestContext` DI identities, separate Webrick `Request` parameter binding, request path, single response writer end, native request completion, and no implicit runtime creation.

**Root-cause fix retained:** Webrick passes its own HTTP Request under named route argument `request`. Using that name for the distinct Runwire `RequestContext` in a test handler caused a 500/type mismatch; [`8f216b8b`](https://github.com/infocyph/Foundation/commit/8f216b8b06926eb88c97ef7dd2414907e86edc9b) now uses the typed `runwireRequest` name and tests both separately. No native dispatch or library API was bypassed. Full CI green.

**Further runtime hardening (new HEAD pending QA):** `WebReleaseRuntime::runwireApplication` now keeps the loaded `LoadedReleaseGeneration` (and its shared `ReleaseGenerationLease`) strongly held by the native Webrick/Runwire application's request callback. This prevents a discarded temporary Foundation bootstrap loader from dropping the advisory generation lock while the host continues handling requests. `WebReleaseRuntimeTest` adds a lock-based lifetime proof: cannot acquire exclusive prune lock while the host lives; can acquire after it is destroyed. No alternate host or release manager was created.

**Tracker strict acceptance remains Batch 3 0/4, total 14/37.** Direct selected-library APIs and compiled dispatch are verified, but native context fanout through every actual module call, nested/child tasks, cancellation, cleanup after failing reporters, and generation/PID cross-boundaries still need full acceptance.

### 2026-10-14 — Batch 3 selected native-package API regression coverage

**New test (QA pending):** `RunwireSelectedNativeBoundaryTest` exercises exact native host `RuntimeContext`/`RequestContext` identity through ArrayKit LazyCollection, Epicrypt, Pathwise, ReqShield, UID, TalkingBytes and Omnibus, and verifies OTP's native CacheLayer-derived Runwire checkpoint and DBLayer's direct `Connection::withRunwire` type contract. It additionally verifies that native Webrick, InterMix and CacheLayer Runwire entry points exist and that a completed host request is rejected by selected direct library operations. No new per-library adapters, duplicate cancellation source or fallback Runwire runtime are introduced. This is API and direct invocation coverage; full hosted route/child/concurrency and integration fan-out remain separate gates.

**Tracker:** Batch 3 **0/4**, overall **14/37**, until exact-source QA and the complete required host and lifecycle cases. Latest `44f0a35e` generated a **single PHPStan callback signature error**; `e4cffbc2` corrects this without altering integration semantics. No failing analysis is ignored.

### 2026-10-14 — Batch 3: explicit compiled Runwire host recipe (QA pending)

**Implementation:** `WebReleaseRuntime::runwireApplication(RuntimeContext, ?coroutineScopeResolver, ?scopeContextResolver)` constructs only Webrick's existing `RunwireRuntimeApplication`, `RunwireRuntimeAdapter` and `RuntimeServer` around a verified compiled Foundation kernel, using InterMix's native `RunwireIntegration` bound to the supplied **host-owned** runtime. InterMix registers the exact `RuntimeContext`, `RequestContext` and `CoroutineScope` inputs only when web release capability `runwire` is selected at compile time; plain PHP/FPM web releases do not carry extra Runwire DI inputs and reject late host creation. A shutdown callback releases only the InterMix runtime binding, with no new listener, worker pool, request, context or cancellation source. Added Runwire **^2.1.1 only to require-dev** for the feature/CI matrix; production remains optional and explicitly selected. New feature regressions compile both topologies and verify successful native host start/shutdown and rejection of implicit fallback.

**Runwire support inventory:** ArrayKit `LazyCollection::withRunwire`; CacheLayer `RunwireIntegration::share`; DBLayer `Connection::withRunwire`; Epicrypt `RunwireExecutionContext`; InterMix `RunwireIntegration::withinRequest/withinScopeContext`; Omnibus `RunwireBinding::withRunwire`; OTP `RunwireState`; Pathwise `RunwireExecutionContext`; ReqShield `Validator::validateWithRunwire`; TalkingBytes `RunwireBinding`; UID `RunwireBinding`; Webrick `RunwireRuntimeAdapter/RunwireRuntimeApplication`. These are upstream-owned API boundaries; Foundation must explicitly forward only the selected service's actual context, not invent substitute host interfaces.

**Acceptance unchanged:** Batch 3 **0/4**, overall **14/37** until exact-head QA and true host request/child/cancellation/module integration have been demonstrated. In particular, this milestone does not yet prove that the entire optional-library fan-out is wired, or that generation replacement is tested. Track the above before checking any criterion.

### 2026-10-14 — Batch 3 native scoped-subtask forwarding verified

**Code-head acceptance evidence:** [Security & Standards #38492996211](https://github.com/infocyph/Foundation/actions/runs/38492996211), source [`828180f4`](https://github.com/infocyph/Foundation/commit/828180f41d96979d684a4fd203fe62de906d3756), completed **SUCCESS**. All PHP 8.4/8.5 lowest/stable PHPForge QA **4/4** passed, including cross-Fiber borrowed-scope and thrown-child regressions; PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install and release evidence passed. Existing plain-PHP/Runwire-absent production consumers remain usable. No quality rule was disabled.

**Precise milestone:** `ExecutionScope::withinBorrowedScope` accepts an *already active, host-supplied* InterMix `ScopeContext`, verifies the runtime mode and original `ExecutionId`, delegates attachment/detachment to native InterMix `withinScopeContext`, and leaves original scope cleanup, task spawning and host cancellation with the owner. It does **not** construct a second scope/Runwire runtime, take ownership of the scope, or overwrite request seeds. This is **only a partial Batch 3 #1/#2 input-boundary milestone**, not proof of full Runwire `RuntimeContext`/`RequestContext`/`CoroutineScope` module fan-out or host HTTP lifecycle. The current concrete upstream native contract matrix is documented in [runtime hosting](../runtime-hosting.md). All four Batch 3 checkboxes correctly remain unchecked.

**Next host-native work:** select and bind actual passed Runwire runtime/request/scope through Webrick's `RunwireRuntimeAdapter` and native `RunwireInterMixScopeBridge`, then exercise direct, intermediary and child-task consumers in DBLayer, CacheLayer, Epicrypt, Omnibus, OTP, TalkingBytes, UID and Pathwise without implicit runtime creation. Preserve host generation/PID identity, cancellation precedence, borrowed cleanup and compiled-release integrity. PR #20 is still draft; do not tag/merge/publish.

### 2026-10-14 — Batch 3 started: explicit borrowed InterMix execution scopes

**Source milestone (not yet QA-accepted):** `ExecutionScope::withinBorrowedScope(ScopeContext, callable)` attaches an already-active InterMix scope handle passed by its host without `withinScope()`, a new `ExecutionId`, or duplicate `RuntimeExecutionState::cleanup()`. It verifies that borrowed runtime mode and scope-provided execution ID match the Foundation application; Webrick continues to own web request scopes, so this non-web entry rejects web mode. The host retains scope teardown, cancellation and timing responsibility. This uses InterMix's published opaque `ScopeContext` directly, not an invented context wrapper or a Runwire fallback. `ExecutionScopeIsolationTest` adds a same-scope child-Fiber identity test, stale-handle rejection and thrown child callback preserving original owner state.

**Strict acceptance unchanged:** Batch 3 **0/4**, overall **14/37** until exact-source full QA and the complete host-specific propagation tests. Next: prove native Runwire `RuntimeContext`/`RequestContext`/`CoroutineScope` forwarding through Webrick/InterMix bridge, direct/indirect/child paths, no-Runwire path, host-owned cleanup and cancellation. Runwire stays optional; PR #20 stays draft, no tag/merge/release.

### 2026-10-14 — Batch 2 #5 accepted; Batch 2 now 8/8

**Accepted on exact code head:** [Security & Standards #38491340356](https://github.com/infocyph/Foundation/actions/runs/38491340356) at `994c1e36`, completed **success**: PHPForge QA 4/4 (PHP 8.4/8.5 × lowest/stable), PHPStan 2/2, production consumer installs 4/4, benchmarks 2/2, clean installation and release evidence. No production/native mechanism was weakened to pass the gate. The complete recursive source tree contains **654 production PHP source files**, assigned to **31 top-level owners and 32 more specific authored responsibility prefixes** (notably DBLayer/Epicrypt/OTP/WebAuthn, auth workflows, auth policy, HTTP presentation). The declaration-level `NativeProductionSymbolOwnerInventoryTest` covers each declared class, trait, interface, enum, named method and function, and fails on missing areas, duplicate declared types or unclassified files. `NativePrivateMethodUseTest` rejects ordinary unreferenced private methods outside consuming traits; `NativeInternalSymbolReachabilityTest` rejects orphaned internal owner classes and graph factories; `LegacyRuntimePathBoundaryTest` rejects previously removed legacy paths. This is an explicit bounded source-owner and retained-contract audit, **not** a claim that static text references prove all possible reflective caller behavior.

**Delete/delegate dispositions:** eight redundant stateless one-hop factory classes were removed from the historical 662-file census. Their native factory recipe signatures are validated by `NativeFactoryOwnershipTest`, `NativeNotificationOwnerTest`, `AuthNativeRegistrarRecipeTest`, and compiled/development `FoundationGraphTest`. Retain the nontrivial app-policy adapters and factories for native cache/transaction routing, Webrick routes and signed URLs, scoped sessions, auth principal precedence, protected webhook replay, Omnibus dispatch maps, ReqShield schema registration and Epicrypt/OTP key/credential mapping. External public contracts and framework extension seams are intentionally retained even without in-repository callers; no blanket "unused" inference was made from public symbol names. For scope, evidence, and explicit reasoning see [the Batch 2 symbol ownership acceptance record](foundation-4.0-batch2-symbol-ownership-acceptance.md).

**Acceptance boundary:** Batch 1 **6/6**, Batch 2 **8/8**, overall **14/37**. Runwire borrowed/owned execution context is Batch 3; persistent account/crypto/storage races and protocol security are Batch 4; external application migration/API contract rehearsals are Batch 5, and release certification is Batch 6. Do not expand Batch 2 sign-off to those future gates. PR stays draft; no merge/tag/publication.

### 2026-10-14 — Batch 2 #5: eight-class pruning and source-head QA green

**Exact-head verified:** [Security & Standards #38488980416](https://github.com/infocyph/Foundation/actions/runs/38488980416) at [`1352ca37`](https://github.com/infocyph/Foundation/commit/1352ca37b8ff4d35bb228fe8f407f320b7ddcfb0) **completed SUCCESS**. PHPForge PHP 8.4/8.5 lowest/stable **4/4** (Pest, Pint, PHPCS, Rector, Deptrac, reference integrity), PHPStan **2/2**, production-consumer installs **4/4**, benchmarks **2/2**, clean install and release evidence **passed**. The native backed-enum `RuntimeMode::from` directly supports development and compiled graph factory construction. The refreshed symbol-ownership architecture test now rejects unnamed/no-declaration source files and verifies all 31 intended source-owner areas, rather than requiring an obsolete absolute source-file/method count that would punish valid deletions.

**Source-tree reconciliation:** GitHub's complete nontruncated source tree at `1352ca37` has **654 tracked PHP files** under `src/`; the original 662-file snapshot has **eight genuine source deletions**: `DatabaseGraphFactory`, `NotificationGraphFactory`, `AuthRuntimeGraphFactory`, `AuthStoreGraphFactory`, `AuthNotificationGraphFactory`, `FilesystemGraphFactory`, `WebrickRuntimeFactory` and `RuntimeModeFactory`. Static factories were moved to the domain owner except `RuntimeMode`, which now uses the native enum directly. No release trust fallback was introduced.

**Retained boundaries reviewed:** `AuthHttpGraphFactory::requestPrincipalResolver` is nontrivial Foundation composition of credential resolvers, optional OAuth and their deterministic precedence, not a duplicate Webrick mechanism. `SecurityGraphFactory::passwordOptions` and `positiveInt` validate selected Epicrypt password policy before instantiating the native options. `HttpRuntimeFactory` composes development-only Webrick error handlers with Foundation-specific exception rendering and type guards. These are meaningful application policy boundaries, not stateless one-line relays, so deleting them solely to reduce class count is unwarranted.

**No false closure:** A structural owner namespace test and these focused retain/delete decisions are **not** independent proof of every other retained symbol's necessity, indirect/reflective consumers, or absence of duplicate native mechanisms. Criterion #5 stays unchecked pending that full review. Strict acceptance **Batch 1 6/6, Batch 2 7/8, overall 13/37**. Keep PR draft; do not merge, tag or publish.

### 2026-10-14 — Batch 2 #5: direct native RuntimeMode factory contract (pending QA)

**Implementation:** The one-method `Application/RuntimeModeFactory` was a pure `RuntimeMode::from()` trampoline. `FoundationGraph` now declares the backed enum's own public static `from` method as the native InterMix factory, removing the extra production class and autoload. `FoundationGraphTest` asserts the exact exported recipe; its existing development and compiled production tests cover all four runtime modes, including a source-compiled graph. InterMix's native `FactoryDefinition::staticFactory` requires a public static method, which PHP's backed enum `from()` provides. Retired-wrapper assertions prevent reintroduction. **Do not accept this symbol removal until code-head QA verifies compiled and development parity.**

**Acceptance:** Batch 2 #5 remains open for all other symbol/caller dispositions; strict **7/8**, overall **13/37**.

### 2026-10-14 — Batch 2 #5 incremental pruning accepted on exact-head QA

**Fully green code-head gate:** [Security & Standards #38488180932](https://github.com/infocyph/Foundation/actions/runs/38488180932), source [`dadc4354`](https://github.com/infocyph/Foundation/commit/dadc43540f669a30319fab207938b0e93b01458d), completed **SUCCESS**: PHPForge PHP 8.4/8.5 lowest/stable **4/4**, PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install and release-evidence publication passed. The earlier [#38487541946](https://github.com/infocyph/Foundation/actions/runs/38487541946) was also fully green at `27d035a0`. No test, formatter, analysis, or release-gate bypass was needed; auth import sorting and the new native recipe tests passed.

**Concrete symbol reductions:** Seven unneeded, otherwise stateless forwarding graph factories present in the original source census are now deleted: `DatabaseGraphFactory`, `NotificationGraphFactory`, `AuthRuntimeGraphFactory`, `AuthStoreGraphFactory`, `AuthNotificationGraphFactory`, `FilesystemGraphFactory`, and `WebrickRuntimeFactory`. Their selected runtime recipes are exported by owning providers/registrars; replacement definitions are regression-locked and native development/compiled paths pass the full matrix. Current recursive GitHub source tree at `dadc4354` has **655 production PHP files**, versus the dated 662-file inventory. Do not reinterpret that historical source snapshot as a current count.

**Acceptance boundary:** This is meaningful audited pruning **within** Batch 2 criterion #5, not proof that all retained classes, public/protected/private methods, functions and reflective consumers have individual necessity/owner dispositions. The broader `NativeProductionSymbolOwnerInventoryTest` validates structural ownership coverage but is insufficient alone to certify all those dispositions. Thus **Batch 1 6/6, Batch 2 7/8, overall 13/37** until the rest of #5 is actually reviewed, its true duplicates removed, and exact-head CI remains green. PR stays draft; no merge/tag/publication.

### 2026-10-14 — Batch 2 #5: development Webrick factory ownership

**Source change:** Removed `WebrickRuntimeFactory`, whose two methods only returned the existing `WebrickRouterFactory::router()` and `routes()` values. `RoutingServiceProvider` now exports those exact static factory recipes with unchanged inputs and development-only semantics; the compiled/native Webrick release loader remains untouched. `NativeFactoryOwnershipTest` verifies both InterMix definitions and rejects the removed symbol. This is a per-symbol **delete/delegate-to-provider** decision, not an alternate route engine or an additional compatibility layer.

**Status:** Batch 2 #5 still requires final complete source-owner/caller coverage and exact QA. Acceptance stays **7/8**.

### 2026-10-14 — Batch 2 #5: retire redundant notification and filesystem recipe helpers

**Source change pending exact-head QA:** Removed `AuthNotificationGraphFactory` and `FilesystemGraphFactory`, which were only stateless InterMix recipe relays to the already-owning registrar/provider. Their exact signatures and lifetimes remain on `AuthNotificationRegistrar` and `FilesystemServiceProvider`; new `NativeFactoryOwnershipTest` verifies registered factory signatures for TalkingBytes auth notification and native Pathwise download/upload/operator. Legacy symbol exclusion assertions prevent reintroduction. No new container, adapter, fallback or transport was created. The existing `SecurityGraphFactory` remains intentionally separate because it validates configured Epicrypt password options; it is not a mere forwarding relay.

**Remaining gate:** this incremental pruning does not establish individual caller/necessity disposition for the rest of the framework. Batch 2 **7/8**, overall **13/37**, until thorough symbol-level evidence and the complete exact-head pipeline succeed.

### 2026-10-14 — Batch 2 #5 audit continuation (not yet accepted)

**Current candidate:** `feature/foundation-3`; strict Batch 2 **7/8**, overall **13/37**. The last acceptance criterion remains the exhaustive responsibility and caller audit for all retained/added production classes, methods and functions. The `NativeProductionSymbolOwnerInventoryTest` assigns namespace-level owner categories to declared symbols, but this is structural census evidence only: it does **not** demonstrate method-level reachability, actual necessity, absence of native duplication, or an explicit retain/delegate/delete decision. The original 662-file inventory is a dated snapshot; the source tree has since changed through factory removals, so historical counts must not be treated as exact live totals.

**Most recent code cleanup:** `30274337` removed the otherwise stateless `AuthRuntimeGraphFactory` and `AuthStoreGraphFactory` and registered their static InterMix recipes on the owning registrars, with `AuthNativeRegistrarRecipeTest` and forbidden-legacy-source assertions. `7ef8cf1f` similarly removed `NotificationGraphFactory`. Earlier `fc744609` removed `DatabaseGraphFactory`. Last completely successful CI source `93a8b825` passed QA 4/4, PHPStan 2/2, production consumers 4/4, benchmarks 2/2, clean install and release evidence. Current auth-factory changes first failed **Pint only** at `92c42e1`; Pest, PHPCS, Rector, Deptrac and analysis passed. `6d3c7a10` removes the extra method separator in `AuthStoreRegistrar`; exact-source [Security & Standards run #38486370544](https://github.com/infocyph/Foundation/actions/runs/38486370544) must complete before claiming QA is restored.

**Next acceptance:** verify all current production declarations and indirect/reflective callers against selected native owners; explicitly justify every retained public/protected factory, method and helper, prune only demonstrated redundant behavior, keep native release-identity and security contracts, validate exact-head CI, and reconcile the live tracker/PR description. **Do not check #5 or start Batch 3 on incomplete evidence.** PR remains draft; no merge/tag/publication.

### 2026-10-13 — Batch 2 #4: built-in service owner consolidation accepted

**Exact-source gate:** [Security & Standards #38431864047](https://github.com/infocyph/Foundation/actions/runs/38431864047) at [`fc744609`](https://github.com/infocyph/Foundation/commit/fc744609eb8bd5919990bd14f2e2756693160000) **SUCCESS**, with PHPForge 8.4/8.5 lowest/stable QA **4/4**, PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install and release-evidence publishing. The new DBLayer exportable recipe and actual per-scope DBLayer access passed.

**Built-in ownership sign-off:** InterMix 11.2 owns DI, scope/compiled ABI and release artifact validation; Webrick 6.0.3 owns HTTP routing/matching/request emission; ArrayKit owns layered env/config caching and generation; CacheLayer owns caches, atomic counters/locks, cluster invalidation and PDO outbox; DBLayer 6.1 owns connections/pools/migrations/query transport. Foundation retains provider selection, application policy, identity/generation trust fences, app-bound cache/database selection, per-execution rollback/lease cleanup and module installation/activation. Auth composes one selected graph via `AuthServiceProvider` and feature-specific registrars; Epicrypt/OTP/WebAuthn own cryptographic and MFA protocol mechanisms, and `AuthProductionGuard` forbids insecure simple/memory production drivers. Browser `SessionServiceProvider` owns cookie/flash/CSRF request policy, separate from durable auth/account/device session identity; native InterMix request scope and `SessionExecutionState` dispose state without forbidden dynamic fallback. TalkingBytes, Omnibus, Pathwise and ReqShield own their transport/messaging/filesystem/validation primitives; Foundation retains presentation/installation policy. Runwire native host-context forwarding remains Batch 3, not a competing Foundation supervisor.

**Concrete duplicate removal:** `src/Database/DatabaseGraphFactory.php` was an otherwise stateless one-method trampoline. `DatabaseServiceProvider::scopedConnection()` now supplies exactly the same immutable InterMix staticFactory recipe, mapping one scoped `DBLayerFactory` connection without a new container/connection engine. `DatabaseIntegrationTest` locks the exported signature and rejects the obsolete class; real scoped database and compiled release tests passed. Previously removed duplicate Webrick route caches/counters and dynamic scope-leave hooks remain absent. The next **criterion #5** separately requires accounting for **every** retained/added symbol and removing any further unnecessary forwarding/helper classes; this accepted #4 is a framework-mechanism ownership decision, not a claim that every symbol was audited.

**Strict acceptance:** Batch 2 **7/8** (#1/#2/#3/#4/#6/#7/#8), overall **13/37**; #5 remains open. Continue #5 obsolete-symbol pruning → full QA → tracker. PR #20 draft; no merge/tag/release.

### 2026-10-13 — Batch 2 #3 complete: applicable published-native package contracts

**Exact-head QA accepted:** [Security & Standards #38430686929](https://github.com/infocyph/Foundation/actions/runs/38430686929) at [`df2c349f`](https://github.com/infocyph/Foundation/commit/df2c349fb50416e77ef6a220906008743f321e97) **SUCCESS**: PHP 8.4/8.5 stable/lowest QA **4/4**, PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install and release-evidence job passed. New `ModuleCatalogModelTest` and `ModuleStateResolverTest` cover Omnibus 3.0's portable core: ordinary messaging has zero mandatory PCNTL/POSIX extensions; these are optional platform diagnostics; truly missing required extensions still block enabled services. New version floor changes were **not** needed from any external library.

**Contract coverage and explicit scope:** Foundation's **declared, installed, selected** published library generations: production ArrayKit `^5.3.1`, CacheLayer `^4.0`, InterMix `^11.2`, UID `^6.0`, Webrick `^6.0.3`, PSR Log `^3.0.2`; selected module integrations at declared floors DBLayer `^6.1`, Epicrypt `^3.2`, Omnibus `^3.0`, OTP `^7.0` (when configured), Pathwise `^4.2`, ReqShield `^3.3`, TalkingBytes `^2.3` and WebAuthn `^5.3.9` when selected. The module catalog, production composer consumers, native per-library tests, source-free web/non-web compiled runtimes, fail-closed optional-module topology and real CI-provisioned MySQL/PostgreSQL/Redis/Valkey/Memcached contracts pass. Every named dependency in the current package register has an ownership/consumer and test disposition; no known required published API blocker remains.

**Not falsely included:** Runwire host borrow/propagation, long-lived gRPC/worker host contexts and host-specific tracing are **Batch 3**; production concurrency, persisted 2.1.1 migration schemas/ID/token material, crypto key rotation, security/transport replay, and storage provider selections are **Batch 4** and release-migration gates; optional SQS/AMQP/cloud/filesystem transports that the current application does **not select** are not certified or auto-enabled and require separate selection-scoped integration. The above does not assert all external adapters and deployments work. New selections must be tested before claiming their compatibility. This is a bounded compatibility acceptance for the **actual Foundation 4.0 selected native API surface**, while preserving those later gates.

**Acceptance after exact QA:** Batch 1 **6/6**, Batch 2 **6/8** (accepted #1/#2/#3/#6/#7/#8), overall **12/37**. Remaining Batch 2 **#4 service redesign/owner consolidation** and **#5 per-symbol obsolete/required responsibility**. Keep PR #20 draft; do not merge, tag or publish.

### 2026-10-13 — Interactive subprocess cleanup: full CI accepted as Batch 2 #4/#5 evidence

**Authoritative exact-source proof:** [Security & Standards #38428696176](https://github.com/infocyph/Foundation/actions/runs/38428696176) at [`9c09c83f`](https://github.com/infocyph/Foundation/commit/9c09c83fae1206951b088b4b50656a84b775331d) **COMPLETED SUCCESS**: PHPForge PHP 8.4/8.5 lowest/stable QA **4/4**, PHPStan **2/2**, production consumer installation **4/4**, benchmarks **2/2**, clean installation and release-evidence publication all succeeded. New throwing-heartbeat/cancellation subprocess regressions passed. Earlier failing CI at `4d64dfb2` caught invalid PHP `void` arrow syntax; it was fixed in `70d15e34` before this green run. This is a verified source fix, not a pending claim.

**Specific accepted ownership disposition (not an entire batch criterion):** `ProcessRunner::runInteractive` owns the execution boundary for Foundation CLI/module/scheduler child processes. It now uses existing Foundation `CleanupGuard` to preserve the originating lifecycle exception while terminating and reaping the child and restoring signal handlers. Native Runwire host/worker supervision stays delegated to Runwire/Omnibus and remains in Batch 3's host verification scope.

**Tracker unchanged:** Batch 2 **5/8**, overall **11/37**. Criterion #4 still requires the complete built-in service ownership consolidation audit; #5 still requires all production public/protected symbols and indirect callers. Keep PR #20 draft; no merge/tag/release.

### 2026-10-13 — Batch 2 #4 substantive cleanup: interactive process failure lifecycle

**Observed ownership defect:** Foundation's own `ProcessRunner::runInteractive()` restored signal handlers when a user-supplied `heartbeat` or `cancelled` callback threw, but previously skipped `ProcessTree::terminate()` and `proc_close()`; this could orphan an interactive subprocess and leak process handles. This is actual Foundation CLI/subprocess lifecycle policy, **not** a missing Runwire/Webrick native library feature. The existing normal exit/timeout logic remains unchanged.

**Implemented:** [`4d64dfb2`](https://github.com/infocyph/Foundation/commit/4d64dfb2f04c8500752b02d3ace686514635e307) adds cleanup in the exceptional interactive path using existing `CleanupGuard::run($primaryFailure, ...)` to preserve the originating callback exception, terminate the process, close/reap the child and still restore signal handlers. [`70d15e34`](https://github.com/infocyph/Foundation/commit/70d15e34bdf60ee60143ba1194c59150f97b785b) uses an explicit void closure for PHP syntax/type correctness. A new two-case `InteractiveProcessLifecycleTest.php` exercises throwing heartbeat and cancellation callbacks against a real child, checks the original error propagates and confirms its PID is no longer alive on POSIX. No new subprocess engine or external dependency was introduced.

**QA:** New exact-head [Security & Standards #38428581193](https://github.com/infocyph/Foundation/actions/runs/38428581193) is pending at `70d15e34`; previous [#38425613023](https://github.com/infocyph/Foundation/actions/runs/38425613023) passed all jobs at docs-only `6ac6dd08`. No acceptance change until runtime tests, PHPForge, consumers and benchmarks are green again.

**Strict tracker:** Batch 2 **5/8**, overall **11/37**, #3/#4/#5 open. The process cleanup fix advances #4 and #5 with one concrete class/method disposition but is not a substitute for complete native-owner audits. PR #20 stays draft, no merge/tag/release.

### 2026-10-13 — Batch 2 #4/#5 native-boundary declaration register (85 files)

**Source-level symbolic discovery:** From the complete 662-file inventory, signature-level enumeration has now been recorded for **85 high-risk PHP source files / 489 named method/function declarations** at source `182b5de4` (unchanged in the current candidate). This includes `Bootstrap/Container/Runtime` (16 files, 75 methods), `Routing/` (18, 132), `Release/` (7, 70), browser `Session/` (18, 109), and authentication registrars `Auth/Internal/` (26, 103). Each record links the native owner and actual declaration names; it is a **source-text discovery pass**, not a PHP AST/reachability proof or a final retain/delete disposition. **577 production PHP files remain outside this method-level pass** (including most of `Auth/` and specialist adapters). No green job or method-name census alone closes #4/#5.

**Reviewed records:** [Core runtime/DI](foundation-4.0-batch2-runtime-methods.md), [Webrick routing](foundation-4.0-batch2-routing-methods.md), [trusted publication](foundation-4.0-batch2-release-methods.md), [browser sessions](foundation-4.0-batch2-browser-session-methods.md), [auth registrars A](foundation-4.0-batch2-auth-registrars-a.md), [auth registrars B](foundation-4.0-batch2-auth-registrars-b.md). The [complete file census](foundation-4.0-batch2-native-ownership-inventory.md) remains the 662-file coverage list. Next inspect actual direct/reflective consumers, owner-specific behavior, persisted state and native-method duplication; record retain/delegate/redesign/delete per symbol before acceptance.

**Exact-head QA proof:** [Security & Standards #38424269111](https://github.com/infocyph/Foundation/actions/runs/38424269111) at `e2321309` **completed SUCCESS**, including QA 4/4 PHP 8.4/8.5 lowest/stable, PHPStan 2/2, consumers 4/4, benchmarks 2/2, clean install and release evidence. The only modifications in this declaration register are Markdown; recheck current HEAD after commit as usual.

**Acceptance:** Batch 2 **5/8**, total **11/37**. #3–#5 remain open; no further published-library failure is currently evidenced. PR #20 remains draft and unmerged, no tag or release.

### 2026-10-13 — Exact-source native binding and secret-isolation QA accepted as evidence

**Fully successful exact-source run:** [Security & Standards #38423337232](https://github.com/infocyph/Foundation/actions/runs/38423337232) at [`182b5de4`](https://github.com/infocyph/Foundation/commit/182b5de43ade527ce427d5c1bf347a0d65efd3ee): PHP 8.4/8.5 × lowest/stable **4/4 functional/quality QA passed**; PHPStan **2/2**, production consumer installs **4/4**, benchmarks **2/2**, clean install and release evidence passed. This supersedes the prior red test-only run `57500fe8`. The complete 662-source-file census and revised tracker were present at this exact tested SHA.

**Contract-level advancement toward #3:** `InterMix11NativeBindingPolicyTest.php` passes 12 Foundation runtime/mode combinations, exercising default-disabled native class `fromBoundValue()` conversion and explicit named `0`, `false`, `null` precedence, with source-free compiled/prevalidated loads and exact deployment-environment identity. `TalkingBytes21SecretIsolationTest.php` now checks Foundation's actual metadata and the InterMix 11.2 immutable-generation `manifest.json`, rejects missing files, checks HTTP bearer/webhook/SMTP sentinels are absent, and no longer reads stale `.meta.json`; the full QA run passed. These are **new verified per-library security/ownership proofs**, not blanket sign-off for unselected SQS/AMQP transports or untested host/persisted-data migration variants.

**#4/#5 work:** [Native-owner inventory](foundation-4.0-batch2-native-ownership-inventory.md) enumerates 662 source files over 31 areas and records important retained/delegated/deleted boundaries, including the already removed competing Webrick counter and dynamic scope-hook implementation. It is a true file-level census; a complete class/method/function-level responsibility and caller graph must still be reviewed before marking #4/#5.

**Acceptance unchanged at this exact-source evidence checkpoint:** Batch 2 **5/8**, overall **11/37**; remaining #3–#5. PR #20 remains draft, no merge/tag/release.

### 2026-10-13 — Batch 2 #3–#5: complete file-level census and native binding policy work

**Complete source inventory prepared:** [Native ownership/source inventory](foundation-4.0-batch2-native-ownership-inventory.md) enumerates **all 662 production PHP files across 31 top-level source areas** from the full, non-truncated source tree at `57500fe8`. Each group maps its provisional Foundation policy role versus the native specialist owner. This is file-level census only: review still must account for individual classes, public/protected methods, functions, actual consumers, persisted data and dynamically registered APIs before #4/#5 are accepted. No blanket class deletion or artificially reduced audit scope.

**Specific inspected dispositions:** `Session/` browser state differs from `Auth/Authentication/Session/` durable account/device identity. `Process/ProcessRunner.php` is used for Foundation CLI/module/scheduling subprocess actions, not evidence of a competing Runwire host supervisor; host propagation remains Batch 3. `Scheduling/CronExpression.php` is application schedule expression parsing, not a competing native queue transport. `Config/EnvironmentLoader.php` delegates EnvParser to ArrayKit; `Cache/CacheLayerFactory.php` delegates atomic locks/cluster/outbox to CacheLayer. `Auth/Internal/AuthProductionGuard.php` rejects simple tokens, simple MFA, in-memory storage and passkeys in production; development in-memory implementations must not become production fallbacks. Obsolete `Runtime/RuntimeScopeCleanup.php`, `Cache/WebrickAtomicCounter.php`, `Auth/Adapter/CacheLayer/CacheLayerCounterStore.php` and `Routing/WebRouteGraphEnricher.php` are absent on the actual source tree (GitHub's code-search index may contain stale paths).

**Native policy regression:** [`57500fe8`](https://github.com/infocyph/Foundation/commit/57500fe8e97a6cb1f36f3be652bf9d5f0847bd9b) adds a 12-combination Foundation/InterMix test (web/CLI/worker/scheduler × development/compiled/prevalidated), verifying optional class `fromBoundValue()` conversion is disabled by default, while named literal `0`, `false`, `null` retain type precedence. [`15bf38d9`](https://github.com/infocyph/Foundation/commit/15bf38d96a75675a0180e36f814ba70c77d5f1f1) corrects the test's compiled-deployment environment identity; the earlier [CI #38422301026](https://github.com/infocyph/Foundation/actions/runs/38422301026) failed exactly four compiled-fixture cases for the missing expected test environment, not a runtime production regression. [`42bdaa81`](https://github.com/infocyph/Foundation/commit/42bdaa818b10d6782653d9b18cf8109699e42a14) corrects TalkingBytes secret-isolation assertions to inspect the **actual InterMix ABI 3 immutable manifest** instead of a nonexistent `.meta.json` file. Missing metadata now fails, rather than producing a tolerated PHP warning. **Exact-source QA passed** at [#38423337232](https://github.com/infocyph/Foundation/actions/runs/38423337232) on `182b5de4`; retain this as #3 evidence without closing the complete criterion.

**Strict checkpoint:** Last completely green [Security & Standards #38419957079](https://github.com/infocyph/Foundation/actions/runs/38419957079) at `2b31f351`. Keep **Batch 2 5/8, total 11/37**. #3 remains open for complete applicable module/consumer compatibility; #4 for every built-in framework/owner mechanism; #5 for per-symbol ownership, callers and obsolete paths. PR #20 draft; no merge/tag/release.

### 2026-10-13 — Coordinated native cutover: full PHPForge release evidence green

**Authoritative exact-source pass:** [Security & Standards #38418473098](https://github.com/infocyph/Foundation/actions/runs/38418473098) at `4ddaf18a` **completed SUCCESS**. PHP 8.4/8.5 × lowest/stable **all four functional/quality configurations passed** (Pest, Pint, PHPCS, Rector, reference integrity, Deptrac); PHPStan 2/2, consumers 4/4, benchmarks 2/2, clean install and **release evidence** passed. Release evidence artifact was published by the workflow. This validates published InterMix `^11.2`, Webrick `^6.0.3`, optional DBLayer `^6.1` across the CI-selected service topologies, not every possible optional adapter in every external host. Post-evidence documentation-only commits need a fresh head CI confirmation; the tested PHP source remains `4ddaf18a`.

**Closing source fixes:** [59874487](https://github.com/infocyph/Foundation/commit/59874487af0b74120a8075c6d2a41d492151da77) removed dynamic web `onScopeLeave` registration, using native scoped `RuntimeExecutionState` cleanup and request-owned browser-session release, with `SessionExecutionState::__destruct()` as fail-safe and obsolete `RuntimeScopeCleanup.php` removed. [abb83dcd](https://github.com/infocyph/Foundation/commit/abb83dcd2d1a46820b8f253c77acb32b3b16ba09) restored the native session runtime import. [48beb552](https://github.com/infocyph/Foundation/commit/48beb5526ea3053cd16707cb3ba31e1b8e9b433c) added assertions that the compiled InterMix web artifact has **no frozen fallback requirement** and an abandoned session is finalized at request-scope exit. [4ddaf18a](https://github.com/infocyph/Foundation/commit/4ddaf18a5e7495bd2226360ec0ab7458d7d2e266) registered the declared scheduler scope input, removed recycled `spl_object_id()` test false-positives, and closed Pint/Rector reports with native constructor promotion. No external library patch or lowered validation was used.

**Acceptance with evidence:** Batch 2 **#1** (InterMix native graph/input/scope/compiled parity), **#2** (Webrick 6/CacheLayer 4 and CI-selected specialist native APIs, compiled routing, cluster/SQL outbox), **#6** (dependency/documentation floors, now reconfirmed), **#7** (full native PHPForge QA and release evidence), **#8** (real service/session-contention provisioning) accepted on the exact tested source and scope. **5/8 accepted; overall 11/37.** This is **not** 8/8: #3 requires closing the entire applicable published-library/consumer compatibility ledger including selected external hosts/state migrations; #4 requires an exhaustive native framework-service duplication/ownership review; #5 requires complete per-symbol obsolete-path and required-responsibility accounting. Do not check these merely because CI is green. No merge/tag/publication.

### 2026-10-13 — Retire duplicate native cutover diagnostic workflow

**CI simplification:** Remove `.github/workflows/native-cutover-diagnostics.yml` now that the four focused feature files (`AdvancedCapabilityIntegrationTest`, `BrowserSessionIntegrationTest`, `GeneratedNonWebRuntimeTest`, `MessagingIntegrationTest`) are part of the unchanged **Security & Standards / PHPForge full Pest suite**. Keep every feature/regression test intact; no test exclusion, weaker assertions, or acceptance bypass. Main PHPForge PHP 8.4/8.5 prefer-lowest/prefer-stable, service integration, analysis, consumers, benchmarks and release evidence remain the authoritative gates. The last focused green runs [#38413637485](https://github.com/infocyph/Foundation/actions/runs/38413637485) and [#38414223881](https://github.com/infocyph/Foundation/actions/runs/38414223881) remain **historical evidence**, not new ongoing jobs. The latest full run [#38414535194](https://github.com/infocyph/Foundation/actions/runs/38414535194) at `ae93b473` is RED; clean full-QA acceptance is still pending.

**Tracker acceptance unchanged:** Batch 2 **2/8**, overall **8/37**. This retires redundant diagnostics only; web compiled-release cleanup ownership and end-to-end parity remain blockers. Keep PR draft. Subsequent QA → tracker updates use the **full PHPForge matrix**, not a separate focused workflow.

### 2026-10-13 — Batch 2 implementation checkpoint: published dependency floors

**Committed at [4537705c](https://github.com/infocyph/Foundation/commit/4537705ce53c3183a0da686a65d7fec62f69cbd5):** Composer now directly requires InterMix `^11.2` and Webrick `^6.0.3`; selected optional DBLayer floor is `^6.1`. ModuleCatalog, module/Composer tests, database docs and direct 2.1.1-to-4.0 migration guide were synchronized. These edits are **committed but require exact-head QA**; no Batch 2 criterion newly accepted.

**Pre-change QA detail:** [Security & Standards 38404617890](https://github.com/infocyph/Foundation/actions/runs/38404617890) at `feb9d706` resolved all three new releases in prefer-stable (18 failed/519 passed), but lowest resolved InterMix 11.1/Webrick 6.0.2/DBLayer 6.0 (19 failed/516 passed). Consumers 4/4, PHPStan 2/2, benchmarks 2/2, clean install and Pint passed. The new Composer floor is meant to remove this mixed-version ambiguity. ABI 3 compiler/test alignment, web release identity and worker seed declaration remain unfinished.

**Strict acceptance:** Batch 1 6/6; Batch 2 2/8 (#6/#8 previously accepted; #6 pending re-verification against the new floor); overall 8/37. Keep PR #20 draft, no merge, tag, or publication.

### 2026-10-13 — ABI 3 compiler and test migration checkpoint

**Committed on `feature/foundation-3`:** [ff0ab1b4](https://github.com/infocyph/Foundation/commit/ff0ab1b4be0cf31a49de203c7a66e20732ad7edc) rejects all skipped dynamic InterMix compilation definitions rather than exempting old scoped inputs; ABI 3 must compile declared inputs. [a21640ee](https://github.com/infocyph/Foundation/commit/a21640ee277925057ce9c0f7eb020995af1dd92c) migrates generated web/non-web fixture expectations to native scoped compilation, retaining negative manifest assertions. These are implementation changes only, not accepted contracts.

**Latest exact-head QA:** [Security & Standards #38411854377](https://github.com/infocyph/Foundation/actions/runs/38411854377) at `a21640ee` pending when recorded; [Native Major Cutover Diagnostics #38411843449](https://github.com/infocyph/Foundation/actions/runs/38411843449) queued. The older diagnostic run [#38411723437](https://github.com/infocyph/Foundation/actions/runs/38411723437) was red at `ff0ab1b4` before the fixture migration. Do not mark the updated source as passing or infer acceptance from the older run.

**Still open:** Webrick/InterMix compiled release-identity fallback, missing explicit worker custom input, per-scope isolation, exact manifest/digest trust checks, four PHPForge variants, optional DBLayer 6.1 real-service behavior and owner contracts. Strict Batch 2 **2/8**, total **8/37**, PR draft and unmerged.

### 2026-10-13 — Batch 2 continuation: native contract green, full QA pending

**Applied on existing `feature/foundation-3`:** [f26344f3](https://github.com/infocyph/Foundation/commit/f26344f31750f330733b818dc0b8fee698d2f2fa) aligns DBLayer 6.1 module lifecycle, package ownership/readiness, and dry-run Composer fixture expectations, and explicitly declares worker-specific scoped inputs in generated fixture providers. [762c08d9](https://github.com/infocyph/Foundation/commit/762c08d977dd4abcccd0608e33ee7257d78f20c7) registers the actual test-only `stdClass` Omnibus message input; no general-purpose arbitrary scope-seed bypass is introduced. [d6e778f6](https://github.com/infocyph/Foundation/commit/d6e778f6d9abd33e0fd25d24cddbba9507e4df1d) supplies an explicit matching InterMix fallback release identity **only to an intentionally non-exportable development-graph test fixture**; this does not make runtime releases eligible for fallback.

**Verified evidence:** [Native Major Cutover Diagnostics #38413637485](https://github.com/infocyph/Foundation/actions/runs/38413637485), source `762c08d9`, completed **4/4 green** (generated non-web runtime, advanced capabilities, browser session, messaging). Earlier source [#38413210329](https://github.com/infocyph/Foundation/actions/runs/38413210329) failed only because the fixture did not declare `stdClass`; this is resolved by the newer focused run. The last **completed full CI**, [Security & Standards #38411940968](https://github.com/infocyph/Foundation/actions/runs/38411940968) at `e7a9093c`, remains RED with four PHPForge jobs failing, while consumers 4/4, PHPStan 2/2, benchmarks 2/2 and clean install passed. Subsequent full runs [#38413653780](https://github.com/infocyph/Foundation/actions/runs/38413653780) and [#38414235782](https://github.com/infocyph/Foundation/actions/runs/38414235782) have not supplied confirmed successful acceptance at the time of this checkpoint. New full exact-head verification is essential.

**Newly isolated native web release blocker:** Web graph composition registers scope-leave hooks in `Bootstrapper::registerScopeCleanup()` and `SessionServiceProvider::registerCore()`. InterMix 11.2's `StaticRuntimeRequirements::fallbackReasons()` marks native `onScopeLeave` hook scopes as dynamic fallback. Therefore `report['skipped'] === []` alone does **not** establish source-free eligibility; production web release loaders still hit `Static runtime fallback requires an explicit matching release identity`. This is now a Foundation cleanup-ownership and compiled-host integration task, distinct from the fixed InterMix 11.2 scoped-input ABI. Do not simply set a dummy release identity, omit cleanup, permit source fallback, skip tests, or loosen manifest integrity. Prove strict request-level cleanup, session containment, DB rollback/lease release, failure/cancellation and persistent host parity before changing the web graph lifecycle.

**Acceptance unchanged pending full evidence:** Batch 1 **6/6**, Batch 2 **2/8** (#6/#8 previously accepted, recheck #6 on new floors), total **8/37**. Keep PR #20 **draft**; do not merge, tag or publish. Continue batch → exact-head QA → tracker.

### 2026-10-13 — Published InterMix 11.2 / Webrick 6.0.3 / DBLayer 6.1 cutover plan

**Status: plan synchronized first; no Foundation Composer, source, catalog, documentation, or test modifications in this tracker-only commit.** Work only on existing `feature/foundation-3` / draft [PR #20](https://github.com/infocyph/Foundation/pull/20). Keep all QA, security, artifact-integrity and performance gates unchanged. Confirm Composer/Packagist package availability before adoption; GitHub tagging alone did not guarantee immediate package-index visibility during the Webrick 6.0.2 cutover.

| Dependency | Published upstream | Currently declared by Foundation | Coordinated next minimum | Native compatibility / constraints |
| --- | --- | --- | --- | --- |
| [InterMix 11.2](https://github.com/infocyph/InterMix/releases/tag/11.2) (11.2.0 series) | Released 2026-10-13 08:37 UTC; compiled ABI **3**; native source-free scoped `input()`, including compiled/prevalidated seed isolation; opt-in `enableAutoBind()` for `fromBoundValue()` | Production `^11.1` | **`^11.2`** | Input-only scopes must compile without a frozen source graph; verify missing/undeclared inputs and singleton capture fail closed, per-request/job isolation, tamper and graph/digest/environment/release-identity rejection. Keep auto-bind disabled unless a narrowly justified Foundation feature explicitly enables it; preserve named `0`, `false`, `null`. Optional Runwire must be **2.1.1+ in 2.x**. |
| [Webrick 6.0.3](https://github.com/infocyph/Webrick/releases/tag/6.0.3) | Released 2026-10-13 09:45 UTC; explicitly requires InterMix `^11.2` | Production `^6.0.2` | **`^6.0.3`** | Coordinated InterMix **ABI 3** rebuild; Webrick manifest format **3** remains. Recheck compiled request seeds, host-owned optional `enableAutoBind()`, named primitive/nullable/default precedence, numeric service IDs, request/matcher invoker, FPM and persistent Runwire dispatch, generated/fused/sharded route artifacts, signed URL/tenant isolation. Avoid Foundation router/binding duplication or implicit auto-bind enablement. |
| [DBLayer 6.1](https://github.com/infocyph/DBLayer/releases/tag/6.1) | Released 2026-10-13 13:19 UTC; optional `TableRepository::fromBoundValue()` with explicit `save()`/`delete()`; stricter missing/out-of-scope record failures | Selected `require-dev` and `ModuleCatalog` `^6.0` | **`^6.1`** | Retain native query/transaction/cache/outbox and application lifetime ownership. Table-row parameter injection is **optional** and needs InterMix 11.2 opt-in auto-binding; don't introduce eager fetch, hidden persistence or implicit save/delete. Confirm new ArrayKit `^5.3.1` transitive constraint (Foundation already declares it), schema/migrations, real MySQL/PostgreSQL services, cancellation, fork safety and cache identity. |

**Next implementation sequence (one coordinated candidate, not isolated floors):**
1. **Dependency and single-floor contract update:** Change `composer.json` production InterMix/Webrick together and optional DBLayer; synchronize `ModuleCatalog`, `ModuleCatalogModelTest` floor assertions, `ConfigCacheIntegrationTest`, `docs/modules.md`, `docs/database.md`, `docs/foundation-4-migration.md`, any release examples and dependency compatibility register. Search source/CI/configuration guards for old *active* floors; preserve historical entries as dated snapshots. Validate Composer resolution for PHP 8.4/8.5 lowest/stable and no-dev consumers. Published package tag names are `11.2`, `6.0.3`, `6.1`; use Composer floors `^11.2`, `^6.0.3`, `^6.1`.
2. **ABI 3 and trust migration:** Rebuild **all** coordinated InterMix and Webrick artifacts and immutable generation pointers; do not load ABI 2 artifacts. Re-run source-free `GeneratedNonWebRuntimeTest`, `GeneratedPhase7RuntimeClosureTest`, `Phase9RuntimeParityTest`, `WebReleaseRuntimeTest`, `WebrickInterMixIntegrationTest`, `FoundationReleaseEndToEndTest`, `Phase10ReleaseSourceIsolationTest`, and Epicrypt generated-secret isolation. Check both production and prevalidated loaders, exact release graph/manifest identity, numeric route IDs, tamper rejection, rollback/last-good pointer and stale ABI rejection. **Do not** bypass fallback, weaken identity, accept undeclared input seeds or rewrite the loader.
3. **Runtime and optional DB compatibility:** Audit host-owned builder `enableAutoBind()` behavior in ordinary and compiled dispatch; default must remain disabled, unless explicitly selected by the app policy. Test InterMix 11.2 named `0`/`false`/`null` binding precedence and `fromBoundValue()` negative paths. Upgrade the selected DBLayer test/development contract to `^6.1`, verify `TableRepository` optional explicit save/delete and out-of-scope missing records only if integrated; preserve current DBLayer 6 transaction, outbox, cache, persistence and migration policies with real services.
4. **QA → tracker acceptance:** Run full PHPForge/Pest/Pint/Rector/PHPCS, PHPStan, clean/no-dev consumer matrix, native focused diagnostics, generated/fused/sharded host integration and benchmarks on a **single exact source SHA and resolved dependency manifest**. Confirm service-backed tests and failure/cancellation/reset behavior. Record precise result per Batch 2 criterion (#1–#5, #7); only accept additional boxes after their whole contract passes.

**Known last pre-upgrade QA at head `7d9c784f`:** [Security & Standards #38273413037](https://github.com/infocyph/Foundation/actions/runs/38273413037), completed RED, PHP 8.4 stable **19 Pest failures / 518 passes** (source-free scoped fallback 9; release identity fallback 10), no Pint finding; consumer installs **4/4**, clean install, PHPStan **2/2** and benchmarks **2/2** passed. This is an **old-dependency baseline only**; it does not prove the newly published versions work. The [previous #38272556938](https://github.com/infocyph/Foundation/actions/runs/38272556938) had the same 19 failure families. InterMix 11.2 specifically targets their underlying source-free input ABI; it is a **credible fix to verify**, not an automatic 19/19 pass claim.

**Acceptance and evidence policy:** Keep **Batch 2 at 2/8** (historically #6/#8 accepted) and **overall 8/37** until the updated floors pass. **Criterion #6 is previously accepted on the earlier published dependency floor and is explicitly flagged for revalidation after this coordinated upgrade**; the old green install matrix cannot certify new minimum versions. Criteria #1/#2/#7 are now *upstream capability available; Foundation adoption + CI unverified*, rather than *upstream feature absent*. Criterion #3/#4/#5 still need selected specialist and host/service/owner proof. The user target 3/8–4/8 is the next acceptance objective, not today's status. Keep PR draft, no new branch, no merge/tag/publication.

### Latest exact-head native release QA — 2026-10-12

**Source:** [`abaab177`](https://github.com/infocyph/Foundation/commit/abaab1777e4cfaf5c11313e7c6e26b1dafe806c8); **CI:** [Security & Standards #38272556938](https://github.com/infocyph/Foundation/actions/runs/38272556938) — full matrix completed. PHP 8.4 and 8.5 prefer-stable each **19 Pest failures / 518 passes** (1 warning); prefer-lowest each **19 failures / 516 passes** (two deprecations, 1 warning). **Exactly two native InterMix failure families in every QA configuration:** **nine** `Static runtime requires its frozen fallback graph.` and **ten** `Static runtime fallback requires an explicit matching release identity.`. No other Pest failures, and **Pint passed**. Production consumers **4/4**, clean install, PHPStan **2/2**, benchmarks **2/2** passed. PHPForge QA **0/4 red** from those 19 runtime-loader failures, release evidence and security report skipped. These completed exact-head results supersede earlier source QA counts without deleting the historical audit.

**Source fixes verified:** Native InterMix 11 symlink pointers survive Foundation generation publication; all four earlier unreadable artifact errors disappeared. Matching the coordinated web compile-time `ConfigRepository(compiled: true)` to the trusted release's actual compiled repository resolved all four `Static runtime graph identity does not match the configured frozen graph` errors without loosening identity verification. The FoundationReleaseCompiler Pint finding also passed.

**Historical upstream contract blocker at the 2026-10-12 QA checkpoint (superseded by published InterMix 11.2):** InterMix 11.1 and then-current `main` had identical `StaticRuntimePlanner`, `StaticRuntimeRequirements` and `StaticRuntimeGenerator` files; no fix was published **at that time**. InterMix 11.1 explicitly excludes standalone loading of scoped inputs requiring dynamic fallback. Foundation cannot secure Webrick Request, CLI/worker/scheduler scoped inputs, compiled host isolation and source-free release while that ABI remains unsupported. The next release must compile declared `InputDefinition` inputs as isolated native production scope seeds (without a source fallback) and preserve fail-closed graph/digest/ABI/environment/unknown-input checks. Once published, update Foundation's InterMix floor and rerun exact-head full native/security/host QA. User target **3/8 or 4/8** remains an objective, not an evidence-supported count.

**Acceptance at this checkpoint:** **Batch 2 2/8** (criterion #6 and #8); overall **8/37**. Criterion #3 has a complete *tracked package list* below but still contains unverified selected optional hosts and source-free contracts; criterion #1/#2/#7 are blocked by the upstream input ABI. Criterion #4/#5 still require their own exhaustive service/owner review. Do not mark any as accepted until every stated criterion is actually verified; keep PR #20 draft and unmerged, no tag/publication.

### Criterion #3 — native library and consumer compatibility matrix (active verification register)

**Scope:** Foundation 4.0's direct and selected optional package contracts on `feature/foundation-3`. This is the **completed, selection-scoped contract register** for Batch 2 #3 at `df2c349f`. A green individual test means only its tested configuration works; it does *not* prove every published specialist API, host, backend or release path. Exact dependency declarations are in `composer.json` and `ModuleCatalog`; the consumer and native test names below are code evidence, not version assumptions. InterMix 11.2 ABI 3 and Webrick 6.0.3 release graph/identity now pass full exact-head CI, but unselected external hosts, retained-state migrations and adapter-specific proofs still need per-library sign-off.

| Published / optional dependency | Foundation consumer and owned capability | Native contract / failure policy | Test / QA evidence | Remaining #3 sign-off |
| --- | --- | --- | --- | --- |
| ArrayKit `^5.3.1` | `ConfigRepository`, `ConfigLoader`, config cache, numeric path safety | Reuse ArrayKit native layered config and strict numeric/reference inspection; reject unsafe cache/symlink paths, no duplicate parser | `ArrayKitIntegrationTest`, `Foundation4ArrayKitPublicationSafetyTest`, `ConfigCacheIntegrationTest`; no failures in [QA #38271792014](https://github.com/infocyph/Foundation/actions/runs/38271792014) | Confirm direct upgrade persistent config cache/reuse against 2.1.1, final source SHA |
| CacheLayer `^4.0` | `CacheLayerFactory`, `CacheManager`, native store/locks, Valkey/Redis cluster identities, SQL outbox | Use native store and atomic locking; preserve default/named identity and durable exactly-once policy where applicable | `CacheLayer4ClusterHistoryIntegrationTest`, `CacheLayer4PdoOutboxIntegrationTest`, `CacheManagerIdentityTest` | Verify real backend topology, concurrent commit/rollback and tenant isolation on final runtime |
| InterMix `^11.2` **committed/published** | `FoundationGraph`, `ExecutionScope`, `GeneratedRuntimeCompiler`, all four production graphs | Native `factory()`, `input()`, `build()`, `withinScope()` and immutable compiled graph; fail closed on identity, undeclared seeds and unsupported dynamic fallback | `FoundationGraphTest`, `GeneratedNonWebRuntimeTest`, `Phase9RuntimeParityTest`; **all four runtime modes and source-free compiled release now green** at [QA #38418473098](https://github.com/infocyph/Foundation/actions/runs/38418473098) | ABI 3 native compiled parity passed; ensure optional long-lived host adapter contract is carried into Batch 3 and external selected integrations are independently signed off |
| UID `^6.0` | Native execution/identifier generation and session/auth/message IDs | Use UID owner for IDs, preserve collision/namespace and stream isolation | `UidIntegrationTest`, `UidRuntimeBoundaryTest` pass in current QA | Confirm host process/fork state and historic ID decoding if stored |
| Webrick `^6.0.3` **committed/published** | `WebReleaseCompiler`, `WebReleaseRuntime`, `WebrickRouterFactory`, signed URL, middleware and native invoker | Webrick 6 native matcher/request scope, format-3 manifest and trusted route artifact; reject tampering, no Foundation sharded/fused cache fork | `WebrickInterMixIntegrationTest`, `WebReleaseRuntimeTest`, `FoundationReleaseEndToEndTest`; 6.0.3 loader, generation pointer relocation and source-free ABI 3 passed full QA at `4ddaf18a` | Generated/fused/sharded compiled routing/identity now pass CI; persistent Runwire host adoption is Batch 3, unselected external configurations require separate selection-specific evidence |
| PSR Log `^3.0.2` | `LoggingServiceProvider`, HTTP exception logger | Native PSR logger, explicit DI override/replacement, no eager resolution | `LoggingIntegrationTest` pass | Release-mode and host-specific exception correlation check |
| DBLayer `^6.1` **committed/published (selected)** | `DBLayerFactory`, `DatabaseMigrationManager`, persistent/CLI/worker DB execution | Native connections, PDO/migrations/query cache, per-execution ownership; fail closed if migration-lock cache capability absent | `DatabaseIntegrationTest`, `DatabaseMigrationCapabilityCompositionTest`, `PersistentDatabaseLifecycleTest`, DBLayer query cache tests | Real MySQL/PostgreSQL CI service tests passed at `4ddaf18a`; retained 2.1.1 schema migration rehearsal, host fork isolation and cancellation still require release-selection evidence |
| Epicrypt `^3.2` (selected) | `EpicryptTokenFactory`, OAuth/PAT/DPOP adapters, environment secret policy | Native signing/rotation and explicit key owner; reject mismatched secrets, stale tokens and replay | `Epicrypt31PersonalAccessTokenConcurrencyTest`, `Epicrypt31DpopIntegrationTest`, `AuthEpicryptPolicyCompositionTest`; generated-secret isolation passed in full ABI 3 QA | Secret-source-free release proofs, persisted token compatibility, key-rotation across workers |
| Omnibus `^3.0` (selected) | `MessagingServiceProvider`, `OmnibusWorkerFactory`, `MessagingSystemCommand`, failure store/outbox | Native bus/envelope/failure, worker lifecycle and transport registry; prevent hidden failure-store or replay fallback | `Omnibus26DurableIntegrationTest`, `Omnibus26ReleaseClosureTest`, worker lifecycle tests; failure CLI regression corrected by `2eba5818` | Generated worker source-free runtime, retry/cancellation/failed queue with real transports, Runwire propagation |
| OTP `^7.0` (auth feature) | `AuthOtpServiceProvider`, `OtpManager`, MFA factors and replay store | Native OTP/secret boundaries, protected enrollment/recovery, replay rejection | `Otp61FactorIntegrationTest`, `OtpIntegrationTest`, `OtpPasskeyIntegrationTest`; no current direct failures | Persistence/replay contention and compiled host request state |
| Pathwise `^4.2` (selected) | `PathManager`, `FilesystemTransferFactory`, `FilesystemResponseFactory` | Native untrusted path, transfer/offload/range safety; no custom filesystem trust bypass | `FilesystemPathwise4IsolationTest`, `FilesystemPathwise41TrustBoundaryTest`, `FilesystemHttpBridgeTest` | Real adapter/host range and multipart upload ownership verification |
| ReqShield `^3.3` (selected) | `ValidationServiceProvider`, `ValidatorFactory`, DB-backed rules | Native validation/DB owner, strict shape and SQL identifier guards | `ReqShieldDatabaseValidationTest`, `ValidationIntegrationTest` | Selected validation middleware behavior under native compiled web request |
| TalkingBytes `^2.3` (selected) | `CommunicationServiceProvider`, gRPC worker, HTTP client and email | Native gRPC/HTTP, per-execution resource closure, timeout, cancellation, secret isolation | `TalkingBytes21GrpcWorkerIntegrationTest`, `TalkingBytes21RuntimeIsolationTest`, `TalkingBytes21SecretIsolationTest` | Long-lived gRPC/Runwire host and source-free compiled worker boot |
| Runwire (optional, unpinned) | `WorkerManager` and Omnibus pool binding | Optional native fork/process lifecycle; never fork live PDO, HTTP or other process-owned resources | `WorkerPoolForkSafetyTest` passed in four QA configurations at `730f39f5`; `Omnibus26WorkerLifecycleCompatibilityTest` | Pin/verify supported published Runwire major when selected and test real fork/cancel/restart process |
| WebAuthn library `^5.3.9` (auth passkey feature) | `PasskeyManager`, `AuthPasskeyGraphFactory` | Native passkey ceremonies and credential counter state; reject origin/replay mismatches | `OtpPasskeyIntegrationTest`, `AuthDomainIntegrationTest` | Real credential migration, multi-request contention and compiled web release |
| AWS SDK / php-amqplib (optional Omnibus transport selections) | Native Omnibus SQS/AMQP selected transport providers | Native transport ownership, no extra Foundation polling/fallback for unavailable backend | ModuleCatalog feature/guard declarations and messaging provider regression tests | Selected real SQS/AMQP backend, retry/order/visibility/failure proof; not yet certified |
| PHPForge `dev-main@dev` (test-only) | PHPForge PHPStan/Pest/Pint/Rector/PHPCS/CI consumer and benchmark checks | No lowered QA budgets, no skipped failures or release gate bypass | [#38271792014](https://github.com/infocyph/Foundation/actions/runs/38271792014): analysis/consumers/benchmarks green, full QA red | Require 4/4 full QA green at one final exact SHA |

**Version-refresh completion (2026-10-13):** InterMix 11.2, Webrick 6.0.3 and DBLayer 6.1 are adopted in Composer and ModuleCatalog, and the new ABI/manifest/compiled-graph integration **passed** at `4ddaf18a` with full QA and release evidence. Any earlier failure counts in dated snapshots below remain historical. Criterion #3 is accepted only for the explicitly selected published-native integration topology after QA #38430686929; unselected transports are not claimed, and host/persistence/security scenarios remain explicit Batch 3/4/release gates.

**Contract sign-off policy:** The table provides per-package native method/consumer/test evidence for the selected Foundation 4.0 topology. For each selected module, verify its native method calls, errors, fallbacks (fail-closed), persistence and lifecycle under the final published dependency set, real relevant backends and appropriate host. Document updated package versions and exact source SHA when rechecking. A newly selected, previously untested adapter or blocked release runtime must reopen its selection-specific compatibility gate. Current scoped acceptance is **6/8**, and host/persistence test work explicitly remains in later planned batches; do not treat this as universal external-backend certification. Preserve prior accepted #6/#8 and leave the rest open.

### Batch 2 identity-parity correction — coordinated compiled web graph (2026-10-12)

**Last exact-head completed QA:** [Security & Standards #38271792014](https://github.com/infocyph/Foundation/actions/runs/38271792014), source `edbc0a95`, remained **RED**: PHP 8.4/8.5 stable **19 failed / 518 passed**, lowest 19 failed / 516 passed; all four consumer installs, clean install, PHPStan 2/2 and benchmarks 2/2 green. The empty promoted constructor Pint problem is gone, but modifying the release publisher exposed one `blank_line_before_statement` finding immediately before its native symlink-publication `try` block. This incoming source correction adds the required blank line without changing runtime behavior. **Do not claim green QA until this new SHA passes.**

**Identified Foundation graph identity drift, pending this commit's exact-head QA:** Coordinated `FoundationReleaseCompiler::buildAndActivate()` compiles the Webrick/InterMix web graph using `WebReleaseCompiler::compile(array $config)`, whose `WebGraphFactory::compose()` constructs a development `ConfigRepository` with `compiled: false`. The same release's `FoundationReleaseConfig::load()` marks its trusted config snapshot **`compiled: true`**, which `WebReleaseRuntime::boot()` uses when recreating the production graph. `FoundationGraph::registerConfigRepository()` injects this flag as a typed factory constructor argument; native InterMix graph identity includes the argument, so the graphs differ legitimately despite equal normalized config values. The four post-pointer `Static runtime graph identity does not match the configured frozen graph` failures are consistent with this mismatch.

**Correction:** `WebReleaseCompiler::compile()` now accepts its already-supported `array|ConfigRepository` input shape from `WebGraphFactory` without changing ordinary callers. **Only the coordinated release compiler** normalizes its input with `ConfigLoader` and constructs `ConfigRepository($normalized->all(), compiled: true)` before compiling the immutable web artifact; this matches the exact type/flag of the trusted published release snapshot. Standalone source-development web compilation still uses the ordinary uncompiled graph. No graph checks, SHA-256, xxh128 digests, release fingerprint, source-discovery restrictions, or frozen-fallback requirements are bypassed. Existing real web/fused/sharded/source-isolation release tests must prove the identity now matches on the next source SHA.

**Acceptance accounting stays strict: Batch 2 2/8 (criteria #6/#8); overall 8/37.** If the four configured-graph identity errors clear, the remaining 9 required frozen fallback and 6 explicit matching release identity failures in InterMix 11.1 still block full #1/#2/#7 and the requested 3/8 or 4/8 milestone. The per-library compatibility record (#3), native service host redesign (#4), and per-symbol ownership (#5) may be advanced independently but cannot be certified without their explicit exhaustive evidence. No temporary branches, merge, tag or release.

### Verified release-pointer outcome / upstream ABI closure request (2026-10-12)

**Exact-head post-fix QA:** [Security & Standards #38270739773](https://github.com/infocyph/Foundation/actions/runs/38270739773), source `6131832a`, all four PHPForge QA combinations still RED. PHP 8.4/8.5 prefer-stable **19 Pest failed / 518 passed**, prefer-lowest **19 failed / 516 passed**; PHPStan **2/2**, production consumer installs **4/4**, benchmarks **2/2**, clean installation all passed. Pint now flags the pre-existing empty promoted constructor in the newly edited `FoundationReleaseCompiler` (`braces_position`, `single_line_empty_body`); this commit restructures its constructor into explicit readonly fields with the *same injected defaults* and needs exact-head verification.

**Proof of progress, not acceptance:** The four previously broken activation pointers now resolve after generation publication. Previous `Static runtime artifact is not readable` and missing web container-path assertions are **absent**; those paths now reach trusted InterMix manifest identity checks and report **four** `Static runtime graph identity does not match the configured frozen graph` errors. The other **15** failures comprise **nine** `Static runtime requires its frozen fallback graph` and **six** `Static runtime fallback requires an explicit matching release identity`. This is a concrete movement from invalid artifact paths to native graph validation, not a complete release fix.

**Upstream InterMix release blocker:** InterMix [11.1](https://github.com/infocyph/InterMix/releases/tag/11.1) is the latest published release and explicitly documents that self-contained artifacts with inputs/runtime factories/hooks requiring a fallback graph are rejected. Native `StaticRuntimePlanner` marks scoped `InputDefinition` as `definition requires the dynamic runtime`, which `StaticRuntimeRequirements::fallbackReasons()` includes in mandatory fallback reasons. Foundation *must* seed `Request`, `ExecutionId`, `ScheduledCommand`, CLI command state, worker `Envelope` etc. inside scoped execution; simply deleting inputs or accepting an untrusted source graph would be a security regression.

**Required native InterMix change before Batch 2 #1/#2/#7 can pass:** Support declared `InputDefinition` as a **source-free, compiled scoped-input plan** (explicit input identity, strict seed validation and isolation, per-execution scope enter/leave/reset) with no fallback graph *for inputs alone*. Keep actual runtime factories, dynamic hooks, undeclared seeds and unsupported features fail-closed. Preserve ABI, graph/build/artifact/digest/environment/release identity checks and add compiler→loadCompiled/loadPrevalidated tests on PHP 8.4/8.5 with CLI, Webrick Request, worker Envelope and scheduler ScheduledCommand scopes, including concurrency and tamper negatives. Consume only a **published upstream corrective version** in Foundation, then verify development/compiled parity and rerun full exact-head QA. The four configured frozen graph identity mismatches also need graph composition parity inspection; do not erase them by disabling the identity check.

**Acceptance remains Batch 2 2/8 and total 8/37.** The user's target of 3/8 or 4/8 requires genuinely closing one or two complete criteria, not accumulating source-only corrections; current #1/#2/#7 depend on that ABI. #3 per-library compatibility and #5 per-symbol inventory remain open until exhaustive recorded evidence, and #4 requires retained service/host contract proof. No merge/tag/release, no temporary branch and no relaxed QA.

### Batch 2 release publication fix — relocatable InterMix 11 activation pointers (2026-10-12)

**Verified exact-source baseline:** [Security & Standards #38269961174](https://github.com/infocyph/Foundation/actions/runs/38269961174), source `2eba5818`: PHP 8.4 prefer-stable **19 Pest failures / 518 passes**, and PHP 8.5 prefer-stable **19 failing cases**. Prior `25757bc1` had **21 failures / 516 passes**. The Omnibus CLI override and scheduler/Phase9 scoped-input skip assertions are absent from the new failure lists. PHPStan 2/2, production consumers 4/4, clean installation and benchmarks 2/2 passed; full PHPForge QA is still **RED**. Final remaining-variant completion is checked separately; no full QA acceptance claimed.

**New production fix, pending exact-head QA:** InterMix 11.1's `StaticRuntimeGenerator::activateBuild()` creates a symlink pointing to an **absolute staging build path**, e.g. `.staging-.../web/container.php.builds/<build>/runtime.php`. Foundation's `FoundationReleaseCompiler::publishStage()` then atomically **renames the entire staging generation**, leaving the original absolute symlink target nonexistent. This explains four `Static runtime artifact is not readable` errors plus the old end-to-end `is_file(web/container.php)` failure. Foundation owns **relocation of its generation**, not InterMix runtime compilation; it now validates the four runtime activation pointers (`web`, `cli`, `worker`, `scheduler`) against their exact in-generation `container.php.builds/<build>/runtime.php` and accompanying `manifest.json`, rejects escaping/invalid links, then replaces each with a **relative, atomic same-build symlink** before moving the generation directory. Digests and trusted InterMix manifests are unchanged. This is not a fallback or manifest bypass. Any invalid pointer **aborts publication**; the prior activated generation remains untouched.

**Test contract:** `FoundationReleaseEndToEndTest` now asserts each published pointer is a relative `container.php.builds/` activation link resolving to an immutable runtime and adjacent InterMix `manifest.json`; it preserves separate Foundation `.foundation.json` identities, web router artifact assertions, generation identity and source-free boot tests. Removes obsolete `container.php.meta.json` assertion. Verify compiled+fused+sharded+tamper release paths in new exact-source CI before counting any criterion.

**Acceptance:** Batch 2 **2/8**, overall **8/37** (criteria #6 and #8 only). The remaining native InterMix 11.1 scoped-input **frozen fallback** and matching-release-identity rejection (14 failures at `2eba5818`) is a separate upstream ABI blocker. Even if the four path failures clear, **do not close #1/#2/#7** until source-free compilation, trusted loader, full native QA and final host/service matrix are green.

### Batch 2 next independent acceptance target — source-owned QA regressions (2026-10-12)

**Exact-head baseline:** [Security & Standards #38268339653](https://github.com/infocyph/Foundation/actions/runs/38268339653) at `25757bc1` completed **failure**: PHP 8.4 and 8.5 stable **21 Pest failed / 516 passed**; lowest **21 failed / 514 passed**; PHPForge QA 0/4, while Pint, PHPStan 2/2, production consumer installs 4/4, clean install and release benchmarks 2/2 passed. Two failures removed since `55bb65be`, confirming some InterMix 11 manifest tests now validate the current structure. Native focused diagnostics still fail on `GeneratedNonWebRuntimeTest`.

**Failure distribution:** 7 `Static runtime requires its frozen fallback graph`, 6 `Static runtime fallback requires an explicit matching release identity`, 4 `Static runtime artifact is not readable` (generated release symlink/path ABI), 3 stale array assertions, 1 end-to-end old container-path assertion. The 17 runtime artifact/fallback issues have an **upstream InterMix 11.1 source-free scoped-input limitation**, compounded by Foundation release pointer/path expectations. None are an authorization to bypass the frozen graph or graph identity checks.

**This commit (QA pending):** 
- `Omnibus26ReleaseClosureTest` asserts that the test's override explicitly `unbind()`s the default `FailureStore` and `TransportRegistry` registrations before replacing them with `factory()`. Prior CI at `25757bc1` exposed the actual failure: `Definition 'Infocyph\\Omnibus\\Failure\\FailureStore' is already registered; call unbind() before replacing it.` This is a fixture correction, not a runtime transport fallback.
- `GeneratedPhase7RuntimeClosureTest` expects exactly the two scheduler scoped inputs `ExecutionId` and `ScheduledCommand` with native `definition requires the dynamic runtime` classification.
- `Phase9RuntimeParityTest` expects exact InterMix 11 scoped-input definitions for CLI (five command context inputs plus `ExecutionId`), worker (`ExecutionId`) and scheduler (`ExecutionId`, `ScheduledCommand`). It still boots generated runtimes, compares actual scope instances and aborts on any untrusted fallback. This fixes *old-major test expectations only*; it does not claim the source-free production ABI now works.

**Next independent acceptance beyond #6/#8:** complete the per-library compatibility record (#3) with published native API, all applicable Foundation consumers, behavior/fallback, service/host/lifetime evidence and exact-current dependency results; **do not count it accepted** until the InterMix/Webrick runtime and selected specialist paths are verified. Criterion #7 requires all four PHPForge QA variants green; still blocked upstream. **Batch 2 acceptance remains 2/8; total 8/37** until a complete criterion is proven. Single `feature/foundation-3` draft PR #20; no merge/tag/release.

### Batch 2 sequential QA item — InterMix 11 immutable artifact test contracts (2026-10-12)

**Completed exact-source QA at `55bb65be`:** [Security & Standards #38267234211](https://github.com/infocyph/Foundation/actions/runs/38267234211) executed all four PHPForge QA configurations and remains **RED**: PHP 8.4 and 8.5 prefer-stable each have **23 Pest failures / 513 passes**; lowest each have **23 failures / 511 passes**; PHPForge Pint passes. Production consumers **4/4**, PHPStan **2/2**, benchmarks **2/2**, clean installation passed; release evidence/security report skipped. The Omnibus CLI failed-message case still exits 1 after its fixture's `bindFactory()` → `factory()` conversion; **do not mark it fixed**.

**This commit — test-contract migration + diagnostic, pending exact-head QA:**
- `GeneratedNonWebRuntimeTest` now locates InterMix 11's **immutable activated build `manifest.json`** as `dirname(realpath($artifact)) . '/manifest.json'`, not obsolete `artifact.php.meta.json`; it retains Foundation's separately signed `.foundation.json` metadata. The negative tampering test still mutates `digest` and insists the **InterMix production loader reject** the artifact. Last-good-build integrity hashes now cover the *real* native build manifest, not the removed sidecar.
- Compile-report tests assert exactly the **declared** scoped `InputDefinition` names (CLI `CommandContext`, `CommandDefinition`, `CommandDescriptor`, `CommandIO`, `ParsedInput`, `ExecutionId`; scheduler `ExecutionId`, `ScheduledCommand`; worker `ExecutionId`, `Envelope`, `omnibus.message`) and exactly InterMix's `definition requires the dynamic runtime` reason. This is not blanket acceptance of skipped factories or services: production `GeneratedRuntimeCompiler::assertNoSkippedDefinitions()` must still reject any non-input skips.
- The still-failing `Omnibus26ReleaseClosureTest` now asserts that its CLI `errors` collection is **empty before checking success**, so the next CI failure exposes the actual dispatcher error instead of only an exit-code mismatch. No queue, auth, failure-store or command assertions are removed.

**Unresolved upstream ABI:** Under InterMix 11.1, *declared* scoped inputs still carry a frozen-fallback requirement in its compiled artifact and reject trusted source-free loading. Expect remaining integration tests to fail on `Static runtime requires its frozen fallback graph` or identity mismatch until native InterMix compiled-input support is available. Do not forge a fallback or relax manifest/graph identity. Source change here is limited to test expectations and diagnostic evidence; **no acceptance counted until the real CI suite passes**.

**Acceptance remains Batch 2 2/8, overall 8/37.** Next verify this commit's exact-head PHPForge jobs and native cutover run; then fix the newly exposed Omnibus root cause and the upstream native InterMix source-free loader contract separately.

### Batch 2 sequential QA item — Omnibus CLI native provider API (2026-10-12)

**Completed worker-fork regression proof:** [Security & Standards #38266436284](https://github.com/infocyph/Foundation/actions/runs/38266436284), exact prior head `730f39f5`. All **four** PHPForge QA variants executed: PHP 8.4 and 8.5 stable **23 failed / 513 passed** each; lowest **23 failed / 511 passed** each. The two `WorkerPoolForkSafetyTest` failures at previous `bd197a18` are **absent in all four completed QA failure lists**. Pint passes; PHPStan 2/2, production consumers 4/4, benchmarks 2/2 and clean install pass. Whole PHPForge QA remains **red**, release evidence/security report were skipped. Record these two corrected, not accepted as the entire Batch 2 criterion #7.

**Prior native factory correction, still RED at `55bb65be`:** `Omnibus26ReleaseClosureTest`'s provider still called the removed InterMix 11 `ContainerBuilder::bindFactory()` API twice to inject test-owned FailureStore/TransportRegistry fixtures. Its command-dispatcher test fails on its first `queue:failed` invocation with exit code 1 rather than 0, consistent with a provider registration exception before dispatch. Replace only the two fixture registration calls with native `ContainerBuilder::factory()` (accepts `Closure|FactoryDefinition`) without changing production messaging, failed-message persistence, CLI security policy, queue semantics, or test assertions. The other 22+ failures belong to native compiled artifact inputs/identity and release parity; see full logs for grouping. **Do not claim the Omnibus failure fixed until new exact-source Pest executes it.**

**Acceptance:** Batch 2 **2/8**, total **8/37**. Criterion #6 and #8 remain accepted; #1–5 and #7 remain open until full contract/QA verification. One active PR branch, draft; no merge/tag/publication.

### Batch 2 sequential QA item — worker pre-fork tests (2026-10-12)

**Completed exact-source baseline:** [Security & Standards #38265562524](https://github.com/infocyph/Foundation/actions/runs/38265562524), head `bd197a18`, **PHPForge Pest 25 failed / 511 passed (PHP 8.4 stable)**, no Pint regression; all **four** PHPForge QA variants remain red from Pest. Production consumers **4/4**, PHPStan **2/2**, release benchmarks **2/2**, clean install all pass. Release evidence/security report skipped. The known compiled InterMix 11 static-input trust/frozen fallback problem remains separate.

**One bounded correction set, source pending QA:** In `WorkerPoolForkSafetyTest`, establish the database pre-fork hazard using DBLayer 6's actual **process-wide `DB::addConnection()` registry** (the exact registry that `WorkerManager::assertPoolParentClean()` verifies). Previously the fixture invoked `DBLayerFactory::connection()` outside an execution scope, which rightly throws under InterMix 11 instead of creating the intended global resource. The HTTP pre-fork test now resolves `HttpClient` inside a valid **native worker execution scope**, and checks the existing fork-rejection assertion while the client remains process-bound. No production `ForkSafetyTracker`, DBLayer scope, runtime cleanup, or fail-closed fork condition has been loosened. **Do not mark these two tests passing until an exact-head Pest run confirms both cases.**

**Tracker:** Batch 1 6/6; **Batch 2 2/8 (#6, #8 only)**; total 8/37. The next independent targets remain worker fork-safety test proof, native InterMix compiled-input ABI / graph identity, non-web metadata expectation migration and the Omnibus CLI failure administration case. Do not merge/tag/release; retain draft PR #20 and the single `feature/foundation-3` branch.

### Criterion #6 VERIFIED ACCEPTANCE — exact-source QA #38264552371 (2026-10-12)

**ACCEPTED:** Batch 2 criterion #6 — Composer, ModuleCatalog, dependency guards, publishable configuration, CI consumers, examples and test expectations all use the same published native floors. Source [`38db3a51`](https://github.com/infocyph/Foundation/commit/38db3a5132776f76ea50709918b2bf7a3472af03), [Security & Standards #38264552371](https://github.com/infocyph/Foundation/actions/runs/38264552371). Four production consumer jobs (PHP 8.4/8.5 × lowest/stable) **passed**, clean install **passed**, PHPStan **2/2 passed**, release benchmarks **2/2 passed**. All four Pest configurations ran the new `ModuleCatalogModelTest` floor/template coverage, existing `ModuleStateResolverTest`, `ModuleConfigPublicationTest`, `ModuleLifecycleIntegrationTest` and `DocumentationCommandContractTest`; none are among the failing tests. Their current package constraints and the direct Foundation 2.1.1→4.0 migration guide are committed and independently verified. No silent optional-package activation, compatibility shim, relaxed constraint, or disabled test was introduced.

**Important isolation:** The **full** PHPForge QA job is still RED in all four configurations for **25 failing Pest tests per configuration** plus a WebReleaseCompiler `ordered_imports` Pint violation; these are tracked under Batch 2 criteria **#1, #2 and #7**, not falsely marked green. Focused native diagnostics remain **3/4**; `GeneratedNonWebRuntimeTest` is still blocked on InterMix 11 scoped-input/frozen-fallback trusted ABI. The adjacent formatting-only import-order fix is staged in the next code commit and needs exact-head QA. **The completed CI was at `38db3a51`; this acceptance is criterion-scoped evidence, not whole-PR release certification.**

**Live checklist:** Batch 1 **6/6**, Batch 2 **2/8** (criteria **#6 and #8**), Batches 3–6 **0/4, 0/7, 0/4, 0/8**; **overall 8/37 accepted**. Prior log paragraphs showing 1/8 or earlier index errors are **historical source-at-time snapshots** and not current acceptance status. PR #20 remains draft; no merge/tag/publication.

### Criterion #6 — one-floor dependency/configuration/examples closure work (2026-10-12)

**Targeted Batch 2 criterion:** “Composer, ModuleCatalog, guards, publishable configuration, CI, examples and tests on one floor.” **Implementation is not acceptance.** This checkpoint adds a direct **2.1.1 → 4.0** candidate migration guide and wires the README/index to it (Foundation 3 material remains explicitly historical). Active configuration guidance now says Foundation 4, and the Pathwise 4.2 template comment replaces stale 4.1 wording. Existing module documentation already reflects OTP 7, TalkingBytes 2.3, DBLayer 6, Pathwise 4.2, Omnibus 3, Epicrypt 3.2 and ReqShield 3.3.

**Cross-contract tests added:** `ModuleCatalogModelTest` now compares each production core requirement (`arraykit:^5.3.1`, `cachelayer:^4.0`, `intermix:^11.1`, `uid:^6.0`, `webrick:^6.0.2`) and **every** managed specialist/selected feature package in `ModuleCatalog::managedPackages()` against the candidate `composer.json` require / require-dev floors; checks each catalog-managed publishable config template exists and is listed in `docs/configuration.md`. Prior catalog role/alias/feature/conditional dependency tests, `ModuleConfigPublicationTest` atomic overwrite/cache-invalidation tests and `ModuleStateResolverTest` direct-vs-transitive/range guards are retained. No fallback, optional-package autoactivation or looser version checks added.

| #6 sub-contract | Source/audit status | Evidence needed for acceptance |
| --- | --- | --- |
| Composer five core floors and seven specialist + auth feature floors | **Source aligned** with Webrick `^6.0.2`; dynamic cross-check added | PHP 8.4/8.5 × lowest/stable install and resolved-version manifest, after package index refresh |
| ModuleCatalog aliases, required/feature/optional roles, activation guards | Catalog and existing guard tests retained, single-floor comparison added | Exact-head module state/activation tests and negative outdated range tests |
| Publishable templates and non-overwriting config | Template/documentation reference check added; existing atomic/symlink/invalidation tests retained | Exact-head config publication tests and no-data-loss checks |
| CLI examples and direct migration guidance | 2.1.1→4.0 draft guide linked from README/docs index; retired config switches and artifact rebuild documented | Documentation command contract test and human API/host-path verification |
| CI consumers and optional capability isolation | 4-way production consumer matrix retained; no exclusions | All four consumers, full PHPForge, clean installs and release constraints at one exact SHA |

**Live exact-head blocker:** [Security & Standards #38261415557](https://github.com/infocyph/Foundation/actions/runs/38261415557) at `2789c63c` completed **failure**: 6.0.2 unavailable in the Composer package-index response at 14:14 UTC; several consumer, analysis, install and QA jobs failed **before Pest**. Previous [#38260879666](https://github.com/infocyph/Foundation/actions/runs/38260879666) at `23e306a6` failed for the same indexing reason. Webrick [6.0.2](https://github.com/infocyph/Webrick/releases/tag/6.0.2) itself exists and fixes the format-3 loader; dependency retrieval, not GitHub tagging, is the external #6 blocker. Do not add VCS version aliases or lower the dependency floor to hide this. Re-run and record exact-new-head jobs once Composer exposes the release.

**Criterion #6 accepted at source `38db3a51` by exact-run scoped evidence; Batch 2 2/8, total 8/37.** The final native compiled web/non-web production ABI and full performance/security acceptance remain separately open in Batch 2/remaining criteria. Preserve one PR branch and draft status.

### Webrick 6.0.2 publication / Composer index gate — verified 2026-10-12

**GitHub released:** `infocyph/Webrick` [6.0.2](https://github.com/infocyph/Webrick/releases/tag/6.0.2) at 2026-10-12 14:05:13 UTC; tagged `ReleaseManifestLoader` source really uses `ReleaseCompiler::RELEASE_FORMAT` and fixes the format-3-vs-format-2 defect. **Foundation commit:** [`23e306a6`](https://github.com/infocyph/Foundation/commit/23e306a69a8ac83ce1b13cca6828906e004b9a82), with `composer.json` minimum `^6.0.2` and version assertion in `ConfigCacheIntegrationTest`; old InterMix singleton fixture, Omnibus message input and PHPForge import-order fixes also included.

**New exact-head CI evidence:** [Security & Standards #38260879666](https://github.com/infocyph/Foundation/actions/runs/38260879666) at `23e306a6`: at 14:09–14:10 UTC, PHP 8.4 lowest/stable production-consumer installs and PHPForge QA jobs could not resolve `infocyph/webrick:^6.0.2` because Composer's package index enumerated only versions up to `6.0.1`; a benchmark also failed at Composer resolution. PHP 8.5 production consumer jobs, PHPStan analysis and clean-install stages passed, but this **does not** establish full dependency or runtime compatibility. This is an **external package-index propagation/registration blocker**, not evidence that Foundation's manifest loader regressed. Do not misclassify Composer install failure as a Pest failure or mark the web manifest regression fixed under the fully resolved production dependency set. The final CI result must be checked at completion.

**Native focused run:** [#38260874362](https://github.com/infocyph/Foundation/actions/runs/38260874362): browser sessions, messaging, and advanced capability checks **3/4 PASS**; `GeneratedNonWebRuntimeTest` fails on separate InterMix 11 source-free compiled scoped-input/frozen fallback behavior and an old skipped-input assertion. No fallback/trust bypass.

**Action required before Batch 2 acceptance:** confirm Packagist/Composer lists Webrick 6.0.2 (trigger the package's Packagist update/webhook if indexing has stalled), then rerun the complete PHP 8.4/8.5 lowest/stable, PHPForge, consumer, native diagnostic and benchmark gates at an exact unmodified source SHA. Keep the real `^6.0.2` floor; don't add a temporary VCS alias, dev-main bypass or lower dependency floor to make tests pass. **Strict acceptance remains Batch 2 1/8, overall 7/37.**

## Webrick 6.0.2 cutover and sequential QA checkpoint — 2026-10-12

**Verified upstream release:** [Webrick 6.0.2](https://github.com/infocyph/Webrick/releases/tag/6.0.2) published 2026-10-12. Its native `ReleaseManifestLoader` now checks `ReleaseCompiler::RELEASE_FORMAT` (format **3**). The tag includes PHP/JSON manifest tests and trusted artifact loading, and it preserves numeric route names/aliases and normalizes integer-keyed middleware requirements. Format-2 manifests are intentionally rejected; rebuild all old release artifacts with Webrick 6.0.2. Optional Runwire bridge floor is 2.1.1. No Foundation-side manifest compatibility shim is permitted. Foundation production Composer floor and the production-dependency test are advanced to **`webrick:^6.0.2`**. This is not proof that the full Foundation release matrix passes yet.

**Last completed Foundation full QA before this commit:** [Security & Standards #38253289807](https://github.com/infocyph/Foundation/actions/runs/38253289807) at source `7724f7f2`, 2026-10-12: PHP 8.4 stable Pest **27 failed / 507 passed** (vs 33/501 at `14b8c88f`); PHPForge QA **0/4** due to Pest plus Pint (`WebReleaseCompiler` import order); PHPStan **2/2**, production consumer installs **4/4**, benchmarks **2/2**, clean install passed; release evidence and security report skipped. Exactly **8** failures are the upstream Webrick format 3-vs-2 loader defect. Other remaining families: InterMix 11 source-free scoped input / frozen-fallback ABI, two retired test provider `singleton()` calls, one undeclared per-message scope seed, worker fork-safety test scope, generated-metadata parity, and Omnibus CLI failure administration. No failure counted as fixed before a new exact-head CI run.

**This commit's Foundation-owned changes (pending QA):** bump Webrick minimum to `^6.0.2`, update strict core-dependency regression test, migrate the release-worker fixture off removed InterMix `singleton()` to native `factory()`, declare `FoundationPersistentMessage` as a scoped input in its test-owned graph (previously an undeclared Omnibus message seed), fix the WebReleaseCompiler PHPForge import order and the outdated Omnibus `^2.6` error contract. Existing security/trust/performance/functional gates remain enabled.

**Acceptance accounting:** Batch 1 **6/6**, Batch 2 **1/8**, total **7/37** unchanged until whole-criterion tests and compatibility evidence pass at this source SHA. Next: check the four PHPForge QA results with Webrick 6.0.2 actually resolved; run native web release PHP/JSON loader + generated/sharded/fused parity + hostile format-2 rejection; address remaining Foundation test-owned issues; resolve InterMix compiled-input ABI upstream without frozen source fallback; verify real host/retained state/security and release gates. Single branch `feature/foundation-3`, PR #20 remains draft; do not merge/tag/publish.

## Live implementation status — 2026-10-12, PR #20

**Reconciled against:** open draft [PR #20](https://github.com/infocyph/Foundation/pull/20), single active implementation branch `feature/foundation-3`, merged native source [`36bf9107`](https://github.com/infocyph/Foundation/commit/36bf9107bbdd14dc82100d46822e8071e47bc12f) (parents: tracker head `7b0cb2a1` and cutover source `2b01c2ba`). **Latest exact-source full QA at `abaab177`: [Security & Standards 38272556938](https://github.com/infocyph/Foundation/actions/runs/38272556938) ran all four PHPForge configurations (19 Pest failures each: 9 missing frozen fallback, 10 missing explicit release identity; no Pint errors). Four consumer installs, clean install, 2/2 PHPStan and 2/2 benchmarks passed, while release evidence and security report were skipped. All other source-level Pest cases passed; compiled source-free InterMix 11.1 remains blocked. Criterion #6 remains accepted; Batch 2 #1/#2/#7 cannot close until a native published ABI fix passes trusted runtime and full QA.** The cutover code is integrated and the old temporary branch is deleted. The separately evidenced earlier native source `2b01c2ba` is RED on four PHPForge QA configurations; last fully green *old-major* baseline was `b64a683b` / [38213535071](https://github.com/infocyph/Foundation/actions/runs/38213535071). The merge retains ten prior obsolete-file removals. No release publication, PR-to-main merge or tag. Historical paragraphs below describe their source SHAs and must not supersede this live status.

**Acceptance checklist, independently counted against the six batch lists:** Batch 1 **6/6 accepted**, Batch 2 **2/8 accepted** (criteria #6 and #8), Batch 3 **0/4**, Batch 4 **0/7**, Batch 5 **0/4**, Batch 6 **0/8**; total **8/37** checked. These are *verified acceptance criteria*, not percentages of implementation effort. Batch 1 closes only its **branch-owned reuse/removal/reconciliation scope**; full per-symbol framework audit, native-major contract acceptance, persisted data rehearsal, security and performance remain expressly open in Batches 2–6.

### Native InputDefinition ABI reconciliation (next focused source changes)

**Completed exact-source QA:** [workflow 38252449854](https://github.com/infocyph/Foundation/actions/runs/38252449854), `14b8c88f`: PHP 8.4 stable Pest **33 failed / 501 passed** (down from 34/499 at `56a9ef94`), PHPForge QA 0/4 due to Pest and Pint; PHPStan 2/2, production consumers 4/4, benchmarks 2/2 and clean install passed. The source-doc floor check and secret-isolation path correction exercised successfully as far as their relevant assertions; the latter now progresses to the blocked frozen-fallback path. Focused native [38252442889](https://github.com/infocyph/Foundation/actions/runs/38252442889) still 3/4 green: only GeneratedNonWebRuntimeTest fails.

**New correction set (exact-head QA pending):**
- `WebReleaseCompiler` retains both optional typed dependency-injection parameters but uses explicitly assigned readonly fields in the constructor, removing the PHPForge constructor-bracing conflict; no route/compile changes.
- Persistent-worker fixture checks InterMix root-scope misuse using native `ContainerException`, preserving the prohibition on accessing scoped session state outside execution.
- Web release assertions require the *exact native* `Request::class => 'definition requires the dynamic runtime'` skip report from InterMix 11, not the incompatible old-major empty report.
- `FoundationReleaseCompiler` no longer re-rejects native scoped inputs after `GeneratedRuntimeCompiler::assertNoSkippedDefinitions()` has already allowed only declared `InputDefinition` entries and rejected everything else. This removes duplicate validation policy but **does not** allow other dynamic definitions or bypass the upstream frozen-fallback/manifest identity boundary.

**Remaining blockers remain explicit:** native InterMix 11 source-free compiled input ABI/fallback support, complete generated web/non-web artifact and epoch identity contracts, old metadata test fixtures, request/worker fork-safety scope tests, runtime parity, real service/cancellation/security/release QA. **Batch 2 stays 1/8; overall 7/37.** No merge, tag or publication.

### Batch 2 exact-source QA progression and documentation alignment — 2026-10-12

**Latest verified completed run:** [Security & Standards #38251511566](https://github.com/infocyph/Foundation/actions/runs/38251511566), source `56a9ef94`: PHP 8.4 stable **34 Pest failed / 499 passed**, PHP 8.5 lowest **34 failed / 497 passed**, PHPForge QA 0/4 (Pest + Pint). PHPStan 2/2, consumer install 4/4, benchmarks 2/2, clean install successful; security report/release evidence were skipped. The prior `f16b16d6` run had 40 failures and 493 passes on PHP 8.4 stable; 6 additional failures were removed without disabling tests. **The unresolved InterMix 11 static-input fallback issue still fails focused native diagnostics.**

**Next source correction (QA pending):** remove the WebReleaseCompiler empty-body constructor format conflict with an explicit readonly constructor; perform out-of-scope principal reset checks in a fresh native execution scope rather than dereferencing scope-owned state in root; assert MfaManager is observed *after* it is explicitly resolved in a native Webrick request; verify the actual InterMix 11 immutable-build `manifest.json` instead of the retired `cli.php.meta.json` in the Epicrypt secret-isolation test. No assertion or quality gate is disabled.

**Criterion #6 audit finding / current source work:** The curated `ModuleCatalog` and `composer.json` contain current specialist constraints, but active user-facing docs/modules.md still listed pre-4.0 floors and docs/database.md claimed DBLayer 5. This commit aligns docs/modules.md to `otp:^7.0`, `talkingbytes:^2.3`, `dblayer:^6.0`, `pathwise:^4.2`, `omnibus:^3.0`, `epicrypt:^3.2`, `reqshield:^3.3`, and the database guide to DBLayer 6. A catalog-driven regression test now asserts every required or selected feature-package constraint listed by `ModuleCatalog::managedPackages()` is documented. Historical Foundation 3 migration records remain historical; the new 2.1.1→4.0 full migration guide and final example/guard/consumer audit remain open. **Do not close criterion #6 or change 1/8 without those missing acceptance checks.**

### Batch 2 source-owned QA remediation and criterion accounting — 2026-10-12

**Current exact-source red baseline:** [Security & Standards 38249332344](https://github.com/infocyph/Foundation/actions/runs/38249332344), `f16b16d6`: Pest **40 failed / 493 passed** in PHP 8.4 stable and **40 failed / 491 passed** in PHP 8.5 lowest; PHPForge Pint failure in `WebReleaseCompiler`; Rector/PHPCS/reference and duplicate checks passed, PHPStan 2/2, production consumer installs 4/4, benchmarks 2/2 and clean install passed. Do not replace this baseline with earlier green old-major runs.

**This remediation commit (QA pending):** removes four test-only calls to protected InterMix `getRepository()`, replacing mutable repository inspection with native public `onResolved()` observers and capability registration checks; explicitly `unbind()`s the logger before a test's intentionally custom PSR override; uses the infrastructure-owned DB connection for out-of-scope fixture setup; moves two Webrick integration fixtures to request scope; adjusts WebReleaseCompiler constructor formatting. Assertions about deferred resolution, native provider policy, database ownership and request isolation remain in place. *All tests and quality gates need new exact-head evidence; no accepted box changes from committing this code.*

**Criterion #6 gap audit:** Composer core minimums are updated (`arraykit:^5.3.1`, `cachelayer:^4.0`, `intermix:^11.1`, `uid:^6.0`, `webrick:^6.0.1`). This is only one portion of the composite criterion: `ModuleCatalog`, optional guards, publishable configs, examples, migration docs, consumer CI constraints and dependency compatibility records still need a full evidence pass. **Remain open, do not turn 1/8 into 2/8 merely by matching Composer.**

**Blocker separation:** Native InterMix 11 scoped `InputDefinition` currently requires frozen fallback in trusted production artifacts; generated non-web/source-free boot and stage release remain red. No fallback, untrusted source discovery, softened compilation, or CI skip is authorized. Record upstream ABI status separately from Foundation-owned test and documentation remediation.

### October 12 — current Batch 2 source/QA checkpoint

**Single working branch:** `feature/foundation-3` / draft PR #20. **Acceptance strictly unchanged:** Batch 1 `6/6`, Batch 2 `1/8`, total `7/37`; remaining batches pending. **Webrick floor:** `^6.0.1`.

| Revision / evidence | Completed work or result | Acceptance |
| --- | --- | --- |
| [QA 38247574397](https://github.com/infocyph/Foundation/actions/runs/38247574397) (`72c52bb0`) | PHP 8.4 Pest 49 failed / 484 passed stable, 49 / 482 lowest; PHPForge Pint also red; consumers/analysis/install green | RED, not accepted |
| [`1471dd10`](https://github.com/infocyph/Foundation/commit/1471dd1032c352ad03dcd2eb7bbe0052cc6a3c49), [QA 38248489044](https://github.com/infocyph/Foundation/actions/runs/38248489044) | Native gRPC seed, persistent-worker fixture, environment-conditional injection, ordered messaging provider; PHP 8.4 stable 46 failed / 487 passed, Pint only `WebReleaseCompiler` remaining; analysis, consumer install, benchmarks and clean install passed | RED, 3 fewer Pest failures; no full acceptance |
| [`061349c0`](https://github.com/infocyph/Foundation/commit/061349c034ac002f7b51a31f5b26112956dc0343), `31834e77` | Native Webrick request-scope fixtures for OTP, ReqShield database validation and Epicrypt PAT, follow-up indentation cleanup | Committed; exact-head full QA not certified |
| [`4a5920fa`](https://github.com/infocyph/Foundation/commit/4a5920fa21675d9519a881d6a1968cde64fad676) | Foundation web-release compiler now accepts a skipped definition **only when it is the native Webrick `Request` input declared as InterMix `InputDefinition`**; all other skipped definitions remain fatal | Committed; full QA and trusted runtime boot still pending |

**Important trust boundary:** accepting Webrick's explicit dynamic request-scope input in the compile report does **not** change InterMix artifact loading, frozen-fallback rejection, manifest identity verification, or the requirement for source-free trusted production execution. InterMix 11.1 still cannot load scoped-input artifacts without its frozen fallback graph. This is a **blocking upstream ABI problem** until verified fixed; do not add a Foundation bypass.

**Remaining source-owned QA:** root-scope test fixtures in auth/DB/worker paths, four test files calling protected InterMix `getRepository()`, logging override collision, generated artifact parity/equality tests, OAuth/Omnibus lifecycle cases, and PHPForge WebReleaseCompiler formatting. Continue defect-by-defect with CI at exact SHA and update the matrix after completion.

### Native cutover follow-up — sequential defect ledger (2026-10-12)

**Scope:** PR #20, one branch `feature/foundation-3`; releases/merges/tags forbidden. **Strict acceptance stays Batch 2 1/8; overall 7/37.** A committed defect correction is not treated as verified or accepted before exact-source QA.

| Defect / subsystem | Commit | Resolution at source | QA evidence / residual risk |
| --- | --- | --- | --- |
| Duplicate Webrick 6.0.1 `Request` input in compiled release graph | [`b9e11e49`](https://github.com/infocyph/Foundation/commit/b9e11e4906836421f7adde4dd6b99cd5e97539be) | The native Webrick `ReleaseCompiler` owns the request `input()` declaration; Foundation unbinds only the compile-side duplicate and preserves the development and loader graph | Needs full web-release runtime verification; focused native diagnostics remain red on InterMix's separate non-web artifact ABI |
| Legacy scoped test provider methods — generated worker cancellation, Omnibus worker | `e29cc35e`, `1b9a07cd` | Converted obsolete `scoped()` to InterMix 11 `factory(..., LifetimeEnum::Scoped)` | Pending Pest 8.4/8.5 exact-head results |
| Legacy `singleton()` and `scoped()` registrations in end-to-end release fixture | `1f94f0f8` | Native `factory()` with default singleton and explicit scoped lifetime | Pending end-to-end exact-head results; do not relax persistence assertions |
| Retired `Container::unset()` call in operations release regression | `ecbbc155` | Removed obsolete cleanup entry point (the local app is released on function exit) | Pending regression |
| PHPForge Pint failures: messaging registration order and compiler class-member separation | `c89fd6d2`, `c18fdfad` | Alphabetized private methods and removed trailing excess class member spacing | Pending current head Pint |
| Outdated InterMix/Webrick production dependency assertion | `748fb1d0` | `ConfigCacheIntegrationTest` asserts `intermix:^11.1`, `webrick:^6.0.1` | Pending current head QA |

**Baseline QA:** [run 38245184478](https://github.com/infocyph/Foundation/actions/runs/38245184478) at `9e162051` **FAILED**: PHPForge 0/4; one PHP 8.4 configuration 61 failed/470 passed Pest, two Pint findings. PHPStan 2/2, consumer installs 4/4, benchmark 2/2, clean install passed. This verifies fewer Pest failures than parent `9f5596de` (78 failed/453 passed) but **does not certify any subsequent head**. If a newer workflow is cancelled due to subsequent tracker commits, use the next exact-head workflow, never mark cancelled as passed.

**Subsequent native-scope test reconciliation (source committed, unverified):**

| Test contract | Commit | Boundary used | Evidence |
| --- | --- | --- | --- |
| Database migration provider selection | `d0a05f84` | Explicit `Application::execution()->run()` CLI scope before resolving database-backed migration runner | Full PHPForge QA pending |
| DBLayer migration/transaction integration | `d3b593a3` | Native CLI execution scope around the existing persistence assertions, without allowing global state | Full PHPForge QA pending |
| Auth credential/token lifecycle and MFA/passkey flows | `10dc6794` | Explicit Webrick `webrick.request` scope around previously root-executed browser auth tests | Full PHPForge QA pending |
| Auth verification notification | `13a5bd79` | Explicit Webrick request scope with unchanged notification/replay assertions | Full PHPForge QA pending |

**Native custom provider regression:** [`15afe46a`](https://github.com/infocyph/Foundation/commit/15afe46aad64652dd650df9fa5dc9bf827e8bf66) makes `WebrickInterMixIntegrationTest` explicitly `autowire()` its environment-selected `FoundationTestGateway` before applying the InterMix 11 native `bindInterfaceForEnv()` variants. This follows InterMix 11.1's documented immutable graph contract. **Exact-head focused and full QA pending; no acceptance credit.**

**2026-10-12 next scope-closure changes (pending exact-head QA):** The security-preserving web request test fixtures for OTP enrollment / AOTP / GridOTP / MobileOTP (`OtpIntegrationTest.php`, `Otp61FactorIntegrationTest.php`), ReqShield database-backed validation (`ReqShieldDatabaseValidationTest.php`), and Epicrypt personal access token database operations (`Epicrypt31PersonalAccessTokenTest.php`) now enter a Webrick `webrick.request` scope. All existing replay, secret-redaction, SQL identifier validation, revocation and persistence assertions are preserved. This resolves tests which previously accessed request-scoped services from the root container; it is a **test migration**, not a production lifetime change. Source commit contains this tracker entry. The four-mode QA, runtime integrity and performance gates remain **unaccepted** until a completed exact-SHA run.

**Latest exact-source source-QA baseline:** [Security & Standards #38247574397](https://github.com/infocyph/Foundation/actions/runs/38247574397), `72c52bb0`: 49 Pest failures / 484 passes on PHP 8.4 stable; 49 failures / 482 passes on PHP 8.4 lowest; PHPForge Pint also flagged member order in `MessagingServiceProvider` and formatting in `WebReleaseCompiler`. Analysis and consumer install stages passed. Known 15-cascade compiler failures are the separate Webrick-scoped `Request` static-artifact constraint, not 15 independent errors. [Source correction `1471dd10`](https://github.com/infocyph/Foundation/commit/1471dd1032c352ad03dcd2eb7bbe0052cc6a3c49) addresses gRPC undeclared seed, persistent scoped test registration, environment-conditional injection test, and messaging method order; exact-head test acceptance not assumed.

**CI workflow scheduling note:** repeated small corrections on the same PR caused prior Security & Standards runs to be cancelled, not passed. Treat only a **completed, successful run at the final exact source SHA** as release QA. The focused native diagnostics continue to fail on the known upstream scoped-input compiled runtime. The final post-ledger source run is required; avoid further nonessential tracker-only pushes while QA executes.

**Latest migration resolution correction:** [`2a3c531c`](https://github.com/infocyph/Foundation/commit/2a3c531c3a0850954edac84fd75b32448313e79f) updates `DatabaseMigrationManager::resolve()` to preserve explicitly bound services (`get()`) and use native `RuntimeContainerInterface::make()` only for unregistered concrete class names from the configured migration/seeder lists. InterMix 11 does not implicitly `get()` unregistered classes. This addresses the three `FoundationDestructiveCommandMigration` fixture failures; **functional and full QA pending**, with exact-schema and no-eager-instantiation assertions retained. It does not alter release-runtime trust policy.

**Native behavior remains strict:** no root-scope fallback, no global singleton conversion for request state, and no bypass of InterMix scope validation. Do not treat scoped fixture changes as conclusive until the exact-source runtime regression tests pass.

**Still open, not downgraded:** native compiled scoped-input ABI in InterMix 11.1, root-scope resolution in auth/DB/worker boundaries, remaining test-only InterMix internal access, provider registration parity, Webrick 6.0.1 trusted generated/fused/sharded matcher behavior, persisted data/security/migration/release gates. Do not add compatibility shims or untrusted frozen fallback.

### Live Batch 2 remediation ledger — Webrick 6.0.1 and CI diagnosis (2026-10-12)

**Acceptance unchanged: Batch 2 1/8, total 7/37.** This section explicitly separates committed source from exact-head verification; none of these new fixes is accepted solely because it was committed.

| Work | Source commit | Verification / next gate | State |
| --- | --- | --- | --- |
| Webrick 6.0.1 production minimum | `114c46c7` | Composer fresh lowest/stable resolve and Webrick 6.0.1 behavior matrix | Committed; QA pending |
| Native scheduler `ScheduledCommand` input declaration | `c7184fcc` | Scheduler execution and scoped-seed tests; static compiled input ABI remains upstream blocked | Committed; QA pending |
| PHPForge messaging resolver constructor style | `86cdb349` | Pint/PHPCS full matrix | Committed; QA pending |
| Scheduler, worker and gRPC fixtures migrate removed `scoped()` API to native scoped factories | `cf8a546c`, `3f0b071a`, `9d74f7c5` | Pest across 8.4/8.5 lowest/stable | Committed; QA pending |
| Remove obsolete InterMix `Container::unset()` calls from two cache identity regression suites | `244d5397`, `4abf752c` | Cache identity and isolation regression tests | Committed; QA pending |
| Immutable definition-graph assertion in web prebuild test | `7322ee06` | Web prebuild provider test | Committed; QA pending |

**Latest exact-source baseline:** [Security & Standards 38240935918](https://github.com/infocyph/Foundation/actions/runs/38240935918), `9f5596de`, completed **failure**. PHPForge QA 0/4; Pest example 78 failed/453 passed (PHP 8.4 prefer-lowest); Pint flags `MessagingRuntimeResolver`. PHPStan 2/2, consumer installs 4/4, benchmark jobs 2/2, clean install passed; release evidence and security report skipped. Distinct outstanding failure families include duplicate Webrick Request registrations, root-scope lookups, failed custom provider registration, other stale InterMix APIs (`singleton()`, etc.), and trusted InterMix static-scoped-input fallback ABI. Preserve the full QA gate; do not mark the entire Batch 2 complete without proof.

**Webrick 6.0.1 compatibility:** [published 2026-10-12](https://github.com/infocyph/Webrick/releases/tag/6.0.1). HTTP cache freshness, conditional/HEAD/Vary handling, forwarded/proxy HTTPS trust, response body limits, signed URL authority and cache namespace change `webrick.hr.v4.` → `webrick.hr.v5.` require focused verification. Foundation must not reimplement Webrick mechanics.

**Next sequence:** exact-head CI → classify remaining source vs fixture issues → fix web request/scope/root graph contracts → solve upstream compiled InterMix InputDefinition ABI without insecure fallback → verify all four modes, service matrix and performance → close individual acceptance criteria. Single working branch `feature/foundation-3`; keep PR #20 draft, do not merge/tag/publish.

### Batch 2 — implemented work vs accepted criteria (single active branch, 2026-10-12)

**Why the count stayed 1/8:** Eight Batch 2 checkboxes require *whole-criterion release evidence*. Significant source changes and incremental green jobs do **not** close an entire composite checkbox. The previous tracker exposed only that strict count, hiding the actual implementation. Report three separate facts now: (a) **source work landed**, (b) **QA and contract evidence**, and (c) **acceptance**. Do not invent an implementation percentage.

**Branch consolidation:** the cutover source history (27 commits) and later main-tracker history (two commits) were combined in merge commit [`36bf9107`](https://github.com/infocyph/Foundation/commit/36bf9107bbdd14dc82100d46822e8071e47bc12f) with *both parent commits*, without discarding either history. **Only `feature/foundation-3` is now the working/PR branch.** The redundant remote `feature/foundation-4-native-cutover` ref was **deleted** after the exact source `2b01c2ba` was proven an ancestor of the consolidated branch. [One-time cleanup run 38240721104](https://github.com/infocyph/Foundation/actions/runs/38240721104) succeeded, a direct GitHub ref lookup returned 404, and the cleanup workflow was subsequently deleted at `61f78132`. There is **one active working branch and no temporary branch**. Focused diagnostics now target `feature/foundation-3`.

| Batch 2 acceptance criterion | Implemented / landed on the single branch | Remaining QA or implementation work | Acceptance |
| --- | --- | --- | --- |
| 1. Complete InterMix graph, finalization, lifetimes, inputs, scopes and custom providers | Native `factory()`, lifetimes, `input()`, `build()` boot, scope guards, deterministic providers; native release symlinks and compiled config graph identity now verified | **Exact-head QA #38272556938:** 9 frozen-fallback and 10 release-identity rejections, all 4 PHPForge variants red; InterMix 11.1 requires upstream source-free scoped-input support and full web/CLI/worker/scheduler parity | **Open — native input ABI missing upstream** |
| 2. Webrick 6 and latest module native APIs (including cluster/outbox) | Webrick `^6.0.2` and CacheLayer 4/SQL outbox, native matcher/route compiler; format-3 loader, relative release activation links, and compiled config graph identity corrected with QA #38272556938 | Native Webrick/InterMix source-free compiled Request requires unsupported dynamic fallback/release identity; verify full fused/sharded/generated trusted host and specialist contracts after upstream InterMix published fix | **Open — release pointer/graph verified, compiled ABI blocked** |
| 3. Per-library and consumer compatibility record | Native consumer/service matrix, selected version floors, migration work and ongoing ownership ledger | Exhaustive published native methods, cancellation, topology and runtime consumer proof, per-library contract sign-off | **Open — partial** |
| 4. Native framework service redesign/removal of duplicate mechanisms | Auth/OTP/MFA composition, messaging/database/notification ownership, browser session, runtime graph, developer test overrides and native scopes | Finish all retained auth/session/host modes and remove remaining redundant paths after verification | **Open — partial** |
| 5. Per-symbol ownership and obsolete-path deletion | Ten confirmed obsolete production files removed earlier; 35 premature graph probes removed; obsolete `WebRouteGraphEnricher` deleted on cutover; other symbol and fixture cleanup | Complete every retained/added class/function/method disposition, stale test fixtures, legacy glue and duplicate owners | **Open — partial** |
| 6. Composer, ModuleCatalog, guards, publishable configuration, CI, examples and tests on one floor | Published InterMix 11.1/Webrick 6.0.2/core and specialist floors, native ModuleCatalog + guard/template/docs/consumer consistency tests and direct migration guide | **Criterion-scoped evidence passed:** [exact-source QA 38264552371](https://github.com/infocyph/Foundation/actions/runs/38264552371): consumers 4/4, clean install, PHPStan 2/2, benchmarks 2/2, no failures in catalog/config/guard/docs tests; unrelated 25 Pest + Pint failures remain criterion #7 | **ACCEPTED — 2/8** |
| 7. Rector, complexity and current-major API failures | [#38272556938](https://github.com/infocyph/Foundation/actions/runs/38272556938) exact-head PHP 8.4/8.5 stable 19 failed/518 passed, lowest 19/516; Pint and PHPStan pass, consumers 4/4, benchmarks 2/2, clean install pass; all Foundation-owned manifest pointer and compiled config graph identity regressions fixed | **Only remaining 19 Pest cases**: 9 InterMix frozen-fallback graph, 10 InterMix explicit matching release identity; no source-free scoped-input ABI in published 11.1/current main. Requires native upstream release + exact-head 4/4 PHPForge QA, no bypass | **Open — 19 exclusively upstream ABI failures** |
| 8. Required real services and session contention | Real MySQL, PostgreSQL, Redis, Valkey, Memcached and session contention/service-backed regressions previously passed on same supported matrix | Reconfirm during final release run, retain prior evidence | **Accepted — 1/8** |

**Exact-source native cutover evidence (before consolidation):**
- [Security & Standards 38235846590](https://github.com/infocyph/Foundation/actions/runs/38235846590), source [`2b01c2ba`](https://github.com/infocyph/Foundation/commit/2b01c2ba6f6d6e34d2e2f7450b5ffe02b6e028f6): **failed overall**, PHPForge QA **0/4**, PHPStan **2/2**, production consumers **4/4**, benchmarks **2/2**, clean install passed; final release evidence not certified.
- [Focused native contract 38235845685](https://github.com/infocyph/Foundation/actions/runs/38235845685), same source: 3/4 passed (advanced capability, browser sessions, messaging). `GeneratedNonWebRuntimeTest` fails: InterMix 11's static compiler treats scoped `InputDefinition` as requiring a frozen fallback graph; source-free `StaticRuntimeGenerator::load()` reports **“Static runtime requires its frozen fallback graph”**. Foundation must not introduce an untrusted source fallback to mask this failure. Native upstream compiled-input ABI support and signed graph validation are required; prior attempt to open upstream InterMix fix branch met GitHub permission 403.
- **Post-merge native focused run [38240442400](https://github.com/infocyph/Foundation/actions/runs/38240442400) at `36bf9107`:** 3/4 success (messaging, advanced capability, browser sessions), `GeneratedNonWebRuntimeTest` **failed** on the same upstream static scoped-input contract. The corresponding full run [38240448251](https://github.com/infocyph/Foundation/actions/runs/38240448251) was cancelled by later tracker/cleanup pushes; do **not** treat cancellation as green QA.
- **Single active branch and cleanup evidence:** cleanup [38240721104](https://github.com/infocyph/Foundation/actions/runs/38240721104) **passed**, obsolete ref confirmed missing, one-time workflow removed by `61f78132`. QA at consolidated pre-remediation head `9f5596de` is RED (PHPForge QA 0/4; see live remediation ledger above). Every subsequent source commit needs its own exact-SHA QA evidence.

**Next sequence:** resolve the upstream compiled scope-input ABI, preserve trusted artifacts; prove compiled and development web/CLI/worker/scheduler parity; close remaining native-major specialist contracts; run unchanged full PHPForge 8.4/8.5 lowest/stable, service matrix, consumers, benchmarks, security and release evidence on one exact SHA; then mark criteria individually. **Batch 2 remains 1/8 accepted, overall 7/37; PR draft, no publication.**

### Live integration and verification ledger

| Owner / integration | Landed in branch (source evidence) | Open contract and evidence | Status |
| --- | --- | --- | --- |
| ArrayKit 5.3.1 | Native numeric-key read-cache fix is released upstream; Foundation requires `^5.3.1`, and retains generation/symlink regressions | Re-run security, compiled configuration and retained-state migration checks with final dependency set | Implemented; final acceptance open |
| CacheLayer 4 + UID 6 | Requires `^4.0` / `^6.0`; native cache/lock/counter consumers, `NodeCacheConfig(options: CacheOptions)`, mandatory durable `transport_identity`, fail-closed history tests; removed local counter duplicates | Redis/Valkey history and MySQL/PostgreSQL transactional outbox now service-tested; reconnect/cursor races, session serialization and sustained host performance remain open. The earlier factory complexity failure is resolved | Partially integrated; acceptance open |
| DBLayer 6 + Omnibus 3 | Dev floors `^6.0` / `^3.0`; module/CI floors, DB cache/pool and native Epicrypt refresh-store work; stale test fixtures corrected | Full DB query/pool/transaction/outbox/settlement, rollback/cancellation, reconnect and persistent scope with real MySQL/PostgreSQL | Partially integrated; acceptance open |
| Epicrypt 3.2 + OTP 7 | Native refresh grant/store mapped to DBLayer; removed seven legacy refresh files and WebAuthn sentinel, imported regression scenarios; `^3.2` / `^7.0` dev floors | HOTP/TOTP/OCRA typed contracts now pass PHPStan; still prove live MFA replay, transactional refresh races, WebAuthn and retained binary/secret state | Partially integrated; acceptance open |
| Pathwise 4.2 + ReqShield 3.3 | Updated dev/module floors; Pathwise HTTP range/preparation and secure download integration reconciled | Native upload/path/stream validation, DB bridge, policy boundary, error/abort and service-backed coverage | Partially integrated; acceptance open |
| TalkingBytes 2.3 | Updated dev/module floors; native HTTP configuration and host-owned gRPC worker boundaries reconciled | Actual HTTP/gRPC/email transports, TLS/SSRF policy, cancellation and failure behavior with required service topologies | Partially integrated; acceptance open |
| InterMix 11.1 | **Source adopted on PR branch**; Composer `^11.1`, native provider `factory()`/lifetimes/`input()`/`build()` and scopes | Compiled scoped-input ABI cannot boot trusted source-free artifact without frozen fallback; exact-head PHPForge red; upstream fix and final runtime/host verification required | **Implemented in source; native ABI/QA blocked, not accepted** |
| Webrick 6 | **Source adopted on PR branch**; Composer `^6.0`, native router invoker, separate production graph build/load, native artifact activation | Complete sharded/fused/compiled web trust verification alongside InterMix static graph ABI and full HTTP mode QA | **Implemented in source; integrated release QA blocked** |
| Runwire 2.1.1 | Host-ownership contract and scope acceptance cases documented; not a Foundation production dependency | Direct/intermediary/child propagation, request isolation/cancel/deferred output/DI cleanup in an actual native persistent host and absence-of-Runwire fallback | Batch 3 open |
| Foundation framework surface | Ownership audit, selective hardening branch import, removed 10 obsolete PHP sources; auth/browser-session boundaries mapped | Full symbol-level native-duplication audit, 2.1.1 persisted data rehearsal, security/data preservation and real Infbyte consumer remain separately required | Batch 1 branch-owned scope accepted; Batches 2–5 open |

**Batch 2 authentication provider ownership and runtime policy resolution — verified 2026-10-12:** `c52b6856` removes the conditional token-secret and passkey-lockout graph probes. `4140583e` deterministically selects optional auth managers from `AuthTokenDriver` and the native Epicrypt `PurposeToken` capability. Source `da85b867` resolves PHPForge's no-adjacent-same-condition rule and passed [workflow 38211940867](https://github.com/infocyph/Foundation/actions/runs/38211940867). `e2618da8` removes eight more `AuthMfaRegistrar` definition probes and lets an auth-owned OTP driver register support exactly once; `b0c87337` resolves the PHP 8.4 Rector syntax rule and passed [workflow 38212722059](https://github.com/infocyph/Foundation/actions/runs/38212722059). `9759723e` removes the last remaining auth pre-finalization policy lookup and obsolete shared `hasExplicitBinding()` helper: a declarative Gate recipe now resolves optional provider-owned `PolicyResolverInterface` from the completed runtime container, with positive regression covering a resolver contributed after the built-ins. `b64a683b` fixes a strict PHPCS unused test parameter by validating the routed resource; [exact-source workflow 38213535071](https://github.com/infocyph/Foundation/actions/runs/38213535071) **completed success**: QA 4/4, PHPStan 2/2, production consumers 4/4, benchmarks 2/2, clean install, release evidence; security report skipped. **This proves native-graph readiness on InterMix 10/Webrick 5 only**, and does not certify their simultaneous 11.1/6.0 upgrade. Batch 2 still **1/8** and overall **7/37** accepted.

**Batch 2 removal of partial-builder inspection (2026-10-12):** [`7565be77`](https://github.com/infocyph/Foundation/commit/7565be775aca1e889c237ae9bafa8431326f1433) removes **24** `definitions()->has()` probes from built-in security, logging, notifications, communication and auth-core registrations; [workflow 38209358905](https://github.com/infocyph/Foundation/actions/runs/38209358905) passed on that exact source. [`858fd70c`](https://github.com/infocyph/Foundation/commit/858fd70cb64d2e3f0d87a06a29f03368e2eb083e) removes **eight** more messaging registration probes and adds a regression that bars unfinished graph introspection across six built-in registrar/provider files. [`55b7b361`](https://github.com/infocyph/Foundation/commit/55b7b3611e9cfb9fbf22d958073bee9fa9c94114) removes its remaining **three** policy probes, replacing database readiness with explicit `FoundationBuildContext::permitsCapability('database')` plus the installed DBLayer `Connection` class, and requiring an explicit configured failure-store policy for database workers/consumers; no implicit prebound-store bypass remains. [`a6cc989d`](https://github.com/infocyph/Foundation/commit/a6cc989df1691c18b704f69ec5a67460bbe66914) aligns the validation message/formatting and [`b41443fc`](https://github.com/infocyph/Foundation/commit/b41443fc7dfb8c690883fefdf180f102901b7b87) synchronizes the durable-consumer regression. **35 premature-definition probes removed in total.** [Exact-source workflow 38210149649](https://github.com/infocyph/Foundation/actions/runs/38210149649) **completed success** at `b41443fc7dfb8c690883fefdf180f102901b7b87`: 4/4 PHPForge QA (8.4/8.5 lowest/stable), 2/2 PHPStan, 2/2 benchmarks, 4/4 production consumers, clean install and release evidence; security report skipped. Composer still requires InterMix `^10.1.1`/Webrick `^5.4`, while remaining auth conditional bindings, mutable builder APIs, Webrick native route invoker, input declarations and compiled graph ABI await *atomic* 11.1/6.0 migration. This is **not Batch 2 closure**; acceptance remains **1/8, 7/37 overall**, and PR remains draft with no merge/tag/publication.

**Batch 2 benchmark entrypoints (2026-10-12; exact-head QA verified):** Source commits [`318f7554`](https://github.com/infocyph/Foundation/commit/318f7554eb856fb3e78e456d55ebba59290e1cec) and [`8c188772`](https://github.com/infocyph/Foundation/commit/8c188772468d7c87d2b31f463821a68190991e20) rename eight specialist benchmark scripts from past-major-specific names to stable package entrypoints, update matching Composer `benchmark:*` invocations and preserve workload implementations. The historical runtime-support document references were synchronized, and a regression assertion checks each script path exists without reinstating obsolete file names. No performance improvement is asserted from this rename, and the actual baseline/fixture audit plus native InterMix/Webrick cutover remain open. **Exact-head workflow 38203341247 passed the changed benchmark entrypoints and architecture tests; the overall Batch 2 checklist remains 1/8 because complete native API, baseline and class ownership acceptance are still open.**

**Batch 2 compiled-loader and scope ownership hardening (2026-10-12):** `85d8d2e3` changes `WebReleaseRuntime::boot()` and `GeneratedRuntime::bootValidated()` to assemble their production builder independently from the materialized development runtime, matching the previously separated release compiler. `daa242bb` restores the existing Webrick loader formatting after the first workflow exposed a Pint-only formatting violation. `11e82096` removes the redundant implicit `RuntimeMode` singleton seed from `ExecutionScope::run()`, rejects caller attempts to replace the host-owned mode via scoped input, and adds a CLI runtime-mode regression; both PHP 8.4/8.5 PHPStan jobs diagnosed a 13/12 method complexity exceedance on that change. `c0cd41c1` moves the fail-closed input guard into a small private helper, keeping quality limits unchanged. **Exact-source [Security & Standards workflow 38205251627](https://github.com/infocyph/Foundation/actions/runs/38205251627) at `c0cd41c1ad9229e34b863bd4de27aafe7994c3c1` completed success:** full PHPForge QA 4/4 (PHP 8.4/8.5 lowest/stable), PHPStan 2/2, production consumers 4/4, clean installation, benchmark 2/2, release evidence. Security report remained skipped. This is a tested *InterMix 10/Webrick 5 preparation*, **not** 11.1/6.0 native runtime acceptance. Native `factory/autowire/input/build`, immutable DI scopes, Webrick 6 invoker and graph/build/artifact trust acceptance remain blocking. Batch 2 **1/8**, overall **7/37**; no merge/tag/publication.

**Batch 2 provider/build split and QA (2026-10-12):** `40a5fa62` pre-registers development Application/Container identity before materialization and attaches once. `0fb04673` enables the existing provider registry to contribute to an independent build graph, and `0d8e6d2c` separates mutable development runtime creation from compiled web and CLI/worker/scheduler artifact graphs. Initial [workflow 38203044273](https://github.com/infocyph/Foundation/actions/runs/38203044273) failed exactly one stale pre-registration unit assertion while production consumers, PHPStan and 525/526 stable Pest tests passed; that assertion was revised in `074c072a`. **Verified source-head [workflow 38203341247](https://github.com/infocyph/Foundation/actions/runs/38203341247) at `074c072aafb1b577b7f09e7acbccc7ecdd61f30c` completed success:** four PHPForge QA combinations, two PHPStan, all four production consumer combinations, clean installation, PHP 8.4/8.5 benchmark jobs, and release evidence. Security-report job was skipped. This validates preparation on the *existing* InterMix 10/Webrick 5 dependency set; it **does not certify** InterMix 11.1/Webrick 6. The remaining native builder API replacement, scoped runtime semantics, compiled ABI and specialist compatibility tests block Batch 2. Acceptance remains **1/8 for Batch 2; 7/37 overall**. No merge, tag or publication.

**Post-tracker head verification (2026-10-12):** [Security & Standards run 38201570877](https://github.com/infocyph/Foundation/actions/runs/38201570877) completed **success** at `66464d5e231f3a622a80977dcb7d0bf01ce09ee3`, the tracker-only successor of source SHA `0591d61e`. Four PHPForge QA combinations, two PHPStan jobs, four production consumers, clean installation, two benchmark jobs, and release evidence passed; security report was skipped. No additional acceptance box is closed by this repeat run. **Next native boundary verified against upstream InterMix 11 source:** `ContainerBuilder::build()` finalizes the graph, and the 11.1 builder exposes `factory(..., LifetimeEnum)`, `autowire(...)`, `input()`, `alias()`, `value()`, `enableDebugTracing()`, and `unbind()`; the present Foundation code still calls removed `singleton()`, `scoped()`, `bind()`, `definitions()`, `options()`, and `development()` APIs. That earlier late-binding design was subsequently replaced by pre-registration and attach-once application identity at source `40a5fa62`, with corrected lifecycle regression `074c072a`. The native InterMix 11 `build()` and scoped APIs still require a coordinated full cutover. Do not bump Composer floors until the entire graph, compilation and runtime test matrix works on the same source. Ten historical automated review threads were still open when checked, despite green current PHPStan; reconcile each against current source before resolving.

**Exact-head verification:** [Security & Standards run 38201207857](https://github.com/infocyph/Foundation/actions/runs/38201207857) at `0591d61efeb1ebb0c00bed3cd164016c97a7f13a`, **completed success**. This run verifies provider-first composition across direct Application, web, CLI, worker and scheduler creation. Previous [38199450854](https://github.com/infocyph/Foundation/actions/runs/38199450854) passed the prior provider/runtime cleanup source. This run includes all routing/HTTP pre-build native recipes, fail-closed PSR container type checks, removal of the old ServiceProvider accessor, unconditional production graph pruning, and direct owned development-runtime bindings. Source `ee129cbbf257415df4723f0c4cc25f9a280d6339` separately passed [38198647179](https://github.com/infocyph/Foundation/actions/runs/38198647179) before the final cleanup. This run includes deletion of obsolete registrar methods and the auth pre-build graph composition regression. Prior [38196881116](https://github.com/infocyph/Foundation/actions/runs/38196881116) also completed successfully for the deleted-methods-only source. This run contains the latest auth-policy decoupling and JWT regression tests. Prior [38156739748](https://github.com/infocyph/Foundation/actions/runs/38156739748) also passed the earlier registrar fix. Previous [38156336766](https://github.com/infocyph/Foundation/actions/runs/38156336766) passed the session/replay source `51fd0c0e32a23e0bfd169b0241462657f78c5cb2`. This source includes the earlier green native-service tests and the new deterministic provider-capability/DB migration-lock regression. This includes the native Redis/Valkey cluster history tests and MySQL/PostgreSQL transactional outbox integration with the service-backed session contention suite. Batch 1 had already been certified at [38151205667](https://github.com/infocyph/Foundation/actions/runs/38151205667) on `db2af8fa05f1fb80412fc2fa9a5458f8e7917838`. Four production consumer combinations (PHP 8.4/8.5 × prefer-lowest/stable), clean install, both analysis/PHPStan jobs, **all four complete PHPForge QA jobs** (Pest/Pint/PHPCS/Rector, reference/duplicate/comment/dependency checks), both benchmark jobs, and release-evidence job passed. The security-report job was skipped. This verifies baseline code/quality integration, **not** full Foundation 4 native API/backends, the Infbyte migration or sustained host throughput/security acceptance. The prior [38148454270](https://github.com/infocyph/Foundation/actions/runs/38148454270) red diagnostics are now superseded, not erased from audit history.

**Resolved Q0 blocker record:** `OtpMfaVerifier::otpConfig()` typed shape fixes removed 21 original type/return diagnostics; `WebReleaseRuntime::boot()` manifest/data boundary now meets strict method budget and native typing; the consolidated `WebAuthnConfigValidator` and `MemcachedConnector` are cohesive existing responsibilities moved out of 82/80 and 87/80 classes. One Pint-only residual was diagnosed with a **temporary** Actions formatter job, fixed by deleting exactly one extra blank before `ConfigValidator`'s class close, and the debug workflow was **removed in the same source correction**. Both analyzers are now green with unchanged 12/80/120 budgets, and all four complete QA permutations green. No compatibility suppression, test skip or gate weakening.

### Historical Q0 implementation checkpoint — source fixes (subsequently verified)

- `24ea74e3162febd47070333671b43eace721217f`: moved the existing exact shape `array{algorithm:string,counter:int,digits:int,look_ahead:int,period:int,secret:?string,suite:?string,window:int}` from `OtpMfaVerifier::ocraCounterAccepted(): bool` to its actual owner `otpConfig(): array`. No runtime code/OTP security contract changed. This directly addresses the erroneous Rector/comment-policy PHPDoc and is expected to re-enable native HOTP/OCRA/TOTP static argument inference. **Unverified until the new exact-head PHPStan and PHPForge QA completes.**
- `8a92cbbf02017085b9283a713fda96776c3e1fde`: moved `WebReleaseRuntime::boot()` array/ConfigRepository and capabilities PHPDocs from their accidental attachment to `assertManifestIdentity()` back onto `boot()`; kept its manifest-identity parameter contract immediately above the assertion method. This targets the missing iterable annotations and native `WebGraphFactory::compose()` type error. No generated runtime/trust logic changed. **Pint method ordering, 13/12 boot complexity and both class-complexity findings remain unverified/open.**
- [Exact-head workflow 38149407200](https://github.com/infocyph/Foundation/actions/runs/38149407200) was queued when recorded for `8a92cbbf`. Do not infer a pass from a queued workflow, or from the prior QA. Complete Q0 only after reviewing its actual logs and resolving the remaining real failures.

### Batch 1 closeout — branch-owned native reuse and removal (2026-10-11)

**Accepted source:** `db2af8fa05f1fb80412fc2fa9a5458f8e7917838`; [CI 38151205667](https://github.com/infocyph/Foundation/actions/runs/38151205667). Scope is the *two reconciled hardening/ArrayKit acceptance branches and confirmed obsolete local engines*, not a claim that every remaining Foundation framework class or upgraded optional module is release-accepted.

| Prior responsibility / obsolete path | Retained native owner + Foundation obligation | Regression and migration disposition |
| --- | --- | --- |
| `Cache/WebrickAtomicCounter.php` and `Auth/Adapter/CacheLayer/CacheLayerCounterStore.php` | Webrick `Interop\\CacheLayer\\AtomicCounterAdapter` with CacheLayer native `AtomicCounterStoreInterface`; Foundation configures counter identity and host/cluster lifetime | `FoundationArchitectureBoundaryTest` asserts no local duplicate; auth lockout and shared-counter integration retained. Do not use process-only counters for shared persistent identity |
| Seven locally implemented OAuth refresh result/record/store/coordinator paths | Epicrypt 3.2 `RefreshTokenManager`/grant/value objects, with DBLayer-backed `DBLayerEpicryptRefreshTokenStore` retaining Foundation's persistence mapping and bounded transaction policy | `OAuth21RefreshRotationTest`, `OAuth21RefreshConcurrencyTest` (process contention), HTTP/audit tests; original `oauthRefreshTokens` durable schema and native records **not deleted**. Live stored-token migration/backup/rollback rehearsal remains Batch 5 |
| Empty `Auth/Adapter/WebAuthn/WebAuthnRuntime.php` sentinel | Native OTP/WebAuthn selected feature ownership; Foundation retains enrollment, policy, credential store/compare-and-swap; no duplicate ceremony | Architecture check enforces absence; retained passkey/OCRA tests and factor-store boundaries; stored credentials still require Batch 4–5 migration/race evidence |
| Named cache, lock, DB query/migration, scheduler, webhook, worker, session, MFA/passkey consumers | Foundation `CacheManager` preserves canonical store identity while specialist CacheLayer handles locks/counters; no alternate cache engine | `CacheManagerIdentityTest`, `Foundation4CacheIdentityRegressionTest`, `FoundationArchitectureBoundaryTest` and persistent execution isolation checks; native CacheLayer4 backend matrix still Batch 2 |
| HTTP range/upload/download and communication composition | Pathwise 4.2 `DownloadProcessor` prepares response metadata once; Webrick owns file-body/range response; TalkingBytes 2.3 native `HttpClientConfig::fromArray` | `FilesystemHttpBridgeTest` (conditional, ranges, prepare-once), `CommunicationIntegrationTest`, architecture assertions; full service TLS/SSRF and persistence tests still Batch 4 |
| ArrayKit numeric paths / cache generations | Published ArrayKit 5.3.1 owns materialization and generation pinning; Foundation owns trusted publication and cache-directory containment | `Foundation4ArrayKitPublicationSafetyTest` retains symlink sentinels and pinned readers, `ArrayKitIntegrationTest`; no unsafe recursive symlink traversal or local parser |

**Acceptance boundary:** all ten confirmed obsolete PHP files are absent, selected branch changes are reconciled with native owners, the migration/retained-state boundaries above are explicit, and all tested PHP 8.4/8.5 QA/consumer combinations passed on this source SHA. Do not infer completed global redundancy review (Batch 2), production incident/security concurrency certification (Batch 4), old persisted data upgrade rehearsal (Batch 5), or sustained host throughput (Batch 6). Core 4.0 release status stays **blocked**.

### Ordered execution queue (working tasks, **not** additional acceptance checkboxes)

| Order | Work | Evidence required before status advances | Status |
| --- | --- | --- | --- |
| Q0 | Repaired OTP types/doc, compiled web boot typing/manifest checks, cache and auth validator class budgets, and Pint spacing | [Source-head workflow 38151205667](https://github.com/infocyph/Foundation/actions/runs/38151205667): 4/4 QA, 2/2 PHPStan, 2/2 benchmarks, 4/4 consumers green | **Complete** |
| B1-close | Completed branch-owned native reuse/removal and documented persisted migration responsibilities, with unchanged security-sensitive feature tests | [Branch-owned native replacement disposition below](#batch-1-closeout--branch-owned-native-reuse-and-removal-2026-10-11); exact-head Pest/PHPForge QA + consumers green. Separate full security/data rehearsal still B4–5 | **Accepted (6/6)** |
| B2-graph | Native InterMix 11.1 + Webrick 6 coordinated cutover | Merge `36bf9107` lands native graph, scopes and router on this PR branch. [38235846590](https://github.com/infocyph/Foundation/actions/runs/38235846590) passed PHPStan/consumers/benchmarks but failed 4/4 PHPForge QA; generated scoped input static ABI blocks trusted release boot. No fallback shim permitted | **Source landed; native ABI + QA blocked** |
| B2-specialists | Close per-package compatibility and service-backed native functionality (CacheLayer4/DBLayer6/Omnibus3/OTP7/ReqShield3.3/Pathwise4.2/Epicrypt3.2/TalkingBytes2.3) | Published API/call-path/fallback + real backend and concurrency evidence, same exact candidate SHA | Open |
| B2-cleanup | Symbol-level duplicated implementation audit and version-neutral benchmark entrypoints | Eight specialist entrypoint paths and Composer invocations verified by [38203341247](https://github.com/infocyph/Foundation/actions/runs/38203341247); full symbol ownership audit and actual baseline fixture retargeting remain | Open (path cleanup QA passed; full symbol and baseline acceptance outstanding) |
| B3→B6 | Host-owned Runwire; security/concurrency; direct 2.1.1→4.0 and Infbyte migration; final published-dependency ABI, host RPM and immutable release QA | Complete all batch checklist items independently and record required exact-SHA workflows | Open |

**Tracker synchronization rule:** For each source commit update this live ledger, mark only individually evidenced acceptance boxes, link corresponding immutable workflow and dependency set, and preserve unresolved failures. Do not claim Batch 2 by Composer resolution or count tests from superseded SHAs. Keep PR **draft**, with no merge, tag or publication until explicit owner action.

## Engineering contract

Follow [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md)
throughout implementation. Correctness, security, public contracts, data
integrity, and lifecycle ownership precede throughput. Foundation owns application
composition and policy; specialist libraries own their mechanisms.

**Foundation is to Infbyte what Illuminate is to Laravel.** Foundation provides
the reusable framework services, application lifecycle, built-in application
features, and extension contracts; Infbyte supplies the application skeleton,
bootstrap/configuration, and application-specific code. This relationship does
not require copying Illuminate's classes, facade system, or internal architecture.

**No specialist mechanism may be duplicated in Foundation.** Use its native owner
and retain only the plugin/integration needed for framework configuration, policy,
persistence mapping, lifecycle, and application ergonomics. This is a release
requirement, not optional cleanup. A differently named implementation, legacy
fallback, or inactive production class still counts as duplication. Where a
native owner lacks a required mechanism, fix or extend that owner and consume its
published API; do not maintain a second implementation in Foundation.

Prefer cohesive methods and private helpers over additional wrappers, interfaces,
or pass-through files. A framework service must add meaningful application policy,
workflow, or integration; a generic forwarding facade does not establish a new
ownership boundary. Do not edit vendor code, add quality suppressions, weaken test gates,
or lower complexity thresholds to make the release pass. Keep the current
function/class/dependency-tree budgets of 12/80/120.

The user explicitly authorizes a broad breaking redesign across Foundation's
public surface, namespaces, contracts, providers, built-in services, and internal
structure. Existing code and backward compatibility are not design constraints
for 4.0. Keep only responsibilities justified by the complete framework design;
remove or replace obsolete APIs and implementations rather than defaulting to
compatibility aliases. Preserve security/data-integrity requirements and identify
persisted-data consequences separately from source compatibility. Document the
new contracts and migration; avoid speculative layers even within this broader
scope. Separate required changes below from optional improvements.

### Library upgrade implementation standard

The following rules govern the actual implementation, not only this document's
wording. The objective is **100% compatibility with the updated libraries across
Foundation's complete framework and integration contracts**, with no missing
required behavior and no unnecessary additional surface. A small diff, an
almost-compatible adapter, or passing a subset of the old tests is not success.
Change size is not an acceptance criterion; redesign or replace whole integration
surfaces where required to adopt the native contracts correctly.

For every updated library, review its complete applicable public surface and
capabilities against every Foundation consumer, including built-in services,
plugins, providers, generated production artifacts, configuration, persistence
mappings, execution lifetimes, and Runwire composition. Use the latest published
native contracts directly. Updating Composer constraints, renaming removed
methods, or preserving legacy wrappers does not complete a library upgrade.

Build one compatibility record in this plan covering each package/version,
required capability, Foundation call path, native contract, failure/fallback
behavior, and verification evidence. Assess newly available capabilities as well
as current calls. Where a capability is outside Foundation's responsibility,
leave it with its native owner rather than adding a facade solely to mirror the
library API. Every applicable contract must be verified; an unresolved,
unsupported, or untested required contract blocks the compatibility claim.

Replace obsolete Foundation code and APIs outright where necessary. Do not add
legacy-method emulation, alternative implementations, generic wrappers,
speculative extension points, or extra class/method/function layers to keep old
code working. Every retained or added production symbol must implement an
explicitly required framework responsibility or plugin boundary. Remove
redundant symbols after consolidating their unique behavior. Tests must prove
the resulting native integration and framework behavior, not require the old
implementation shape to survive.

Verify the complete integration under every declared supported PHP version,
application mode, build/runtime path, module topology, backend and host contract,
including direct/intermediary Runwire propagation and ordinary capability
fallback. Use real backends where concurrency or persistence semantics require
them. Optional installation is not optional compatibility once a feature is
selected. Missing services, skipped cases, unchecked adapters, or one successful
example cannot stand in for full acceptance.

Record the precise published dependency set and immutable Foundation revision
for this evidence. Fix defects in the owning library and consume its published
corrective version; do not cover them with Foundation workarounds. Refresh the
matrix when a version changes. Claim 100% compatibility only after all required
entries and release gates pass; do not claim it from this audit or plan alone.

## Baseline and cumulative changes

| Reference | Verified revision | Meaning |
| --- | --- | --- |
| Published Foundation 2.1.1 | `f6749ebe0aa1f93c6b3586e8cb63ecc1f552d98b` | Required migration and comparison baseline |
| `main` / `origin/main` | `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` | All post-2.1.1 main changes are part of Foundation 4 |
| `feature/foundation-3` at original audit | `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` | **Historical snapshot only**; current working SHA is in live status above |
| `foundation-3/library-reuse-hardening` | `2f1e19e1f089e95d3a81fec62cda66a5cd1751d8` | 126 commits beyond current head; 65 changed files |
| `origin/arraykit-5.3/acceptance` | `8f1bc1097e0543f08209a226f1a76fe41459c82b` | Seven commits beyond current head; six changed files |

At the original audit, both named branches forked from the then-current main, and neither was imported by the read-only audit. Their applicable changes were subsequently selectively reconciled on PR #20; see the live status and integration log. Historical branch SHAs are not current working revisions.

The 2.1.1-to-current comparison spans 1,261 commits and 496 changed files
(42,104 insertions, 11,372 deletions). Production PHP source grew from 580 to
668 files; 378 current files belong to authentication. This is a cumulative
release, rather than a dependency bump. Review the complete tree diff alongside
public contracts, configuration, generated artifacts, persistence, and lifecycle
changes. File counts are scope evidence, not a reason to split or merge types.

Foundation's web, CLI, worker, and scheduler modes already exist in 2.1.1.
Runwire/FPM and other servers are host choices, rather than additional Foundation
application modes. The existing Foundation 3 migration, runtime, and evidence
documents are useful implementation history; their certification does not carry
over to Foundation 4.

Local 3.0 and 3.0.1 tags exist, but the remote has no 3.x tags and Packagist's
latest published Foundation version remains 2.1.1. Prepare one direct
2.1.1 → 4.0 migration. Do not publish an intermediate 3.x release as part of
this plan.

### Main changes since the last published tag

Foundation 4's audit and implementation starting point is the complete current
main tree, including every change after published 2.1.1. The two named branches
provide additional work to assess. Every change must be accounted for, but its
code/API may be retained, redesigned, delegated, or removed under the approved
breaking redesign. Do not accidentally discard a security invariant or recreate
an older defect when reconciling branches. Main and the working branch matched at the **initial audit**, before PR #20 implementation; they no longer share a head.

The following inventory makes main's release scope explicit. It is based on
`git diff 2.1.1 main`, first-parent merges, current source, and tests; historical
Foundation 3 notes are supporting context, not the authoritative version list.
The inventory identifies responsibilities and security invariants to evaluate;
it does not freeze existing classes, signatures, or implementation structure.

| Main change group | Current source anchors | Foundation 4 responsibility and verification |
| --- | --- | --- |
| Builder-first provider and runtime composition | `Application/ServiceProviderInterface.php`, `Application/FoundationBuildContext.php`, `Container/FoundationGraph.php`, `Runtime/NonWebGraphFactory.php` | Preserve the move from `register(Application)` to graph `contribute(...)`, immutable boot semantics, and explicit mode isolation while adopting InterMix 11.1 |
| Unified production release generation | `Release/FoundationReleaseCompiler.php`, `Release/FoundationReleaseManifest.php`, `Release/FoundationReleaseRuntime.php`, `Release/FoundationReleaseBootstrap.php` | Preserve staged/serialized publication, deployment-controlled manifest trust, config/dependency identity checks, and generated web/non-web artifacts; rebuild for the new ABI |
| Generation lifetime and operations | `Release/ActiveGeneration.php`, `Operations/RuntimeControl.php`, `Worker/WorkerManager.php` | Preserve draining-generation leases, safe pruning/build coordination, worker replacement, readiness, activation, and rollback boundaries |
| Native config materialization and export validation | `Config/ArrayKitConfigCache.php`, `Config/ConfigLoader.php`, `Config/ConfigExportValidator.php` | Preserve native ArrayKit handling of delayed/lazy values and validated exports; reconcile 5.3 generations and the confirmed defects without reinstating a Foundation parser |
| Generation-owned HTTP matcher artifacts | `Routing/WebReleaseCompiler.php`, `Routing/WebReleaseConfiguration.php`, `Routing/WebReleaseRuntime.php` | Preserve generated/fused/sharded matcher publication and runtime loading through Webrick, contained cache paths, and native trust checks; verify missing/tampered/stale artifacts |
| Core cache and specialist module lifecycle | `Cache/CacheSchemaManager.php`, `Module/ModuleCatalog.php`, `Module/ModuleActivationManager.php`, `Module/ModuleStateResolver.php` | Preserve CacheLayer as core infrastructure, explicit installation versus activation, package/version/platform readiness, dependent-removal guards, and capability-aware schema execution |
| Authentication, OAuth/OIDC, MFA and passkey ownership | `Auth/Internal/`, `Auth/Adapter/Epicrypt/`, `Auth/Adapter/Otp/`, `Database/AuthSchema/` | Preserve native protocol/ceremony ownership, policy and persistence mappings, production key guards, secret protection, replay/rotation atomicity, and credential compare-and-swap; rehearse retained data |
| Browser session and execution state hardening | `Session/BrowserSession.php`, `Session/Middleware/SessionMiddleware.php`, `Runtime/CleanupGuard.php`, `Runtime/RuntimeExecutionState.php` | Preserve finite leases/waits, stale-owner mutation checks, scope isolation and cleanup; extend failure precedence to a throwing reporter |
| Notifications and optional communication | `Notifications/NotificationGraphFactory.php`, `Notifications/NotificationServiceProvider.php`, `Module/ModuleCatalog.php` | Preserve native notifications independently of optional TalkingBytes, statically compilable factories, and auth-delivery capability dependencies when adapting TalkingBytes 2.3 |
| Database, validation, messaging, filesystem and worker integration | `Database/`, `Validation/`, `Messaging/`, `Filesystem/`, `Scheduling/`, `Worker/` | Preserve native specialist bridges, durable/outbox and transaction boundaries, file/HTTP policies, and scheduler/worker execution lifetimes; apply latest APIs and passed Runwire contexts |

Main's first-parent history includes runtime-architecture integration, final
runtime readiness work, both close-26.6 merges, and PR #17. The latter main
integration also contains config materialization and generation-owned matcher
changes alongside notification compilation fixes. The range from local 3.0
commit `441ccd7` to current main alone changes 23 files (871 insertions,
125 deletions); it must not disappear when skipping the 3.x release name.

Existing release, generated-runtime, module, OAuth, session, and state-isolation
tests are evidence of required behavior for these main changes. Adapt meaningful
behavioral coverage to the new design and replace tests that enforce obsolete
APIs or duplicate implementations. Add missing boundary cases rather than
treating historical merged status or test names as current certification. Batch 1
reconciles branch intent with this inventory, and the final release gates cover
the combined tree.

## Complete framework ownership and public surface

Review all Foundation responsibilities before choosing the final 4.0 surface.
This is broader than upgrading integration calls. Built-in authentication,
authorization, sessions, notifications, console commands, scheduling, and
operations are first-class framework services where they provide unique
application behavior. Library reuse does not justify reducing Foundation to
bootstrap alone or moving reusable framework implementation into Infbyte.

For each production capability, record its native mechanism owner, the unique
Foundation responsibility, its proposed public API, configuration and persistence
contract, execution lifetime, and the disposition of existing code: retain,
redesign, delegate, or delete. Resolve the owner from actual published source and
contracts, not class names. Consolidate this record in this plan rather than
creating a second collection of overlapping architecture plans.

| Framework area | Foundation responsibility to design | Mechanisms delegated to native owners |
| --- | --- | --- |
| Application and extensions | Bootstrap, application topology, provider/plugin registration, module activation and application paths | InterMix graph construction, DI lifetimes, scope implementation and compiled container loading |
| Configuration and release operations | Application defaults/policy, trusted outer release identity, deployment activation/readiness and coordinated artifact publication | ArrayKit configuration parsing/materialization; each owner validates its own native artifacts |
| HTTP and presentation | Application route/middleware policy, auth/session placement, framework responses/resources and exception presentation | Webrick requests, routing/matching, middleware execution, HTTP semantics, response emission and native rate-limit bridges |
| Identity and authentication workflows | Accounts/devices, login/logout, lockout policy, password change/reset, verification, passwordless, remember-me, step-up and impersonation | Epicrypt passwords, purpose tokens, signing/encryption and supported token protocols; UID identifiers; CacheLayer atomic state; DBLayer persistence mechanics |
| Authorization | Principal resolution, roles/permissions, gates/policies, delegation and tenant/resource decisions | Native cache/database/identifier mechanisms; do not add a second policy engine if an installed owner already supplies the required behavior |
| Browser sessions | Browser state, flash data, cookie/session policy, route participation, regeneration/invalidation, commit and lifecycle coordination | CacheLayer locks/stores, DBLayer queries/transactions, Pathwise file operations where applicable, and one selected native CSRF token/verification implementation |
| Authentication sessions | Account/device login state, idle/absolute expiry, recent-auth/step-up state and revocation policy | Shared native persistence/atomicity mechanisms; unify framework identity and lifetime coordination without confusing it with browser key/value state |
| OAuth/OIDC, application tokens, MFA/passkeys | Account/client consent, application claims/policy, schema/store mapping, enrollment/recovery workflows and HTTP presentation | Epicrypt protocol/token/password mechanisms; OTP challenge/replay/grid/passkey ceremonies; native security-store contracts |
| Data, validation and filesystem | Application connection/store/profile selection, schema lifecycle, form/request policy, upload/download authorization and response integration | DBLayer SQL/transactions/schema execution; ReqShield validation/sanitization/database bridge; Pathwise storage/transfers/archive processing |
| Notifications and communication | Notification recipients/templates/channels, framework delivery policy and profile composition | TalkingBytes HTTP/email/webhook/gRPC transport, parsing, retries and bounded request pools |
| Events, jobs, schedules and console | Application handlers/commands, schedule definition and execution policy, execution context and framework observability | Omnibus dispatch/durable messaging/retry mechanics; CacheLayer leadership locks; Runwire host supervision and supported concurrency |
| Runtime and persistent lifecycle | Application/request/task context propagation and cleanup of Foundation-owned resources | Runwire workers/listeners/event loops/capabilities/cancellation; Webrick HTTP scope; InterMix DI scope and child-context implementation |

### Built-in auth and sessions: review one complete flow

Review `AuthServices`, `Auth/Authentication`, `Auth/Authorization`, `Auth/Principal`,
`Auth/Mfa`, `Auth/Passkey`, `Auth/OAuth`, `Session`, and their providers/stores as one
composition. The current `Auth/Authentication/Session/SessionManager` and
`Session/SessionManager` represent different concepts; their public names and
coordination may change. Do not merge semantics just because both are called
sessions, or retain parallel uncoordinated principal/login state.

Specify the web flow from request and browser-session entry through credential
verification, native security checks, account/device/auth-session creation,
principal binding, authorization, session rotation/commit and response output.
Specify equivalent bearer/API, CLI, queue/job, and concurrent-task flows. Define
one execution-local principal and explicit transitions for logout/revoke,
suspension, impersonation, password changes, recovery and step-up. Evaluate
tenant isolation, race behavior, retry/idempotency, failures and cancellation at
every state transition. Persisted browser state, authentication state and issued
credentials need an explicit compatibility or migration decision.

Review password/token fallbacks and legacy OAuth implementations against Epicrypt
3.2; OTP/WebAuthn/passkey ceremony code against OTP 7.0; browser CSRF generation
and validation against Webrick/Epicrypt; cache/counter/lock code against
CacheLayer/Webrick; and all storage/validation/transport helpers against their
native owners. These are mandatory review candidates, not a claim that every
class in those directories is duplicate. Choose one owner per mechanism and
adapt its contract once. Do not stack equivalent validators, parse the same
immutable input twice, or execute two replay/rotation implementations.

An optional dependency being unavailable must not activate a duplicate
Foundation security/transport engine. Leave unselected features cold; for a
selected feature, require its native owner and fail clearly when unavailable.
Runwire capability fallback uses the native library's ordinary supported path,
with the same framework policy and security guarantees.

## Published dependency target

Versions were checked against [Packagist's package metadata API](https://packagist.org/apidoc)
and the exact released source archives, rather than uncommitted sibling trees.
These are the latest published versions **at the audit date**; refresh them before
freezing the release. An ArrayKit corrective release is required below.

| Package | Published version | Released source reference |
| --- | --- | --- |
| `infocyph/arraykit` | **5.3.1** | `e77bf72a40c64b94f62e69f3b2662ce2dc47efec` |
| `infocyph/cachelayer` | 4.0 | `58ae96dfe14ee45a528247c00c6a3d727160f833` |
| `infocyph/dblayer` | 6.0 | `85b9a6373628692f1bf74ba20a31649fad767f2a` |
| `infocyph/epicrypt` | 3.2 | `8b1cdf370a8676e536db96b7bd0ae66347141512` |
| `infocyph/intermix` | 11.1 | `b5954a41d96c885cfa14aba241cb6c760a3a6237` |
| `infocyph/omnibus` | 3.0 | `053e5550522236e660fd85a94cf34c0a39830c29` |
| `infocyph/otp` | 7.0 | `2cf37029cbc667a63b620aee4b45d0ffdbfc76a0` |
| `infocyph/pathwise` | 4.2 | `f37e99ae41fbb9ab5c632beef266f146a4dc662c` |
| `infocyph/reqshield` | 3.3 | `427601b27ad270bbac401643c9197d3b13862669` |
| `infocyph/talkingbytes` | 2.3 | `1096b05c143db1167a69fb1ea78ab1ece99f4004` |
| `infocyph/uid` | 6.0 | `759b8cdf21c5b5d204210b3701eec508ffd5c70b` |
| `infocyph/webrick` | 6.0 | `f5eeaafe075c2016dbe29bcaa5a827cf246189c7` |
| `infocyph/runwire` | **2.1.1 exactly** | `745b1c2bd7caa56aa5abf6d742c1aa8318fec494` |

Raise runtime dependency floors to the accepted latest stable releases and
exercise both lowest and freshly resolved stable combinations. Keep optional
libraries optional in production: update their development coverage, module
installation constraints, capability errors, and documentation together. Add
Runwire `2.1.1` exactly to development/integration verification; the ordinary
production installation must continue to work without Runwire installed.
Composer `suggest` descriptions do not enforce module installation versions.

Also revalidate other direct dependencies. The fresh candidate resolves
`psr/log` 3.0.2, `opis/closure` 4.5.0, and `web-auth/webauthn-lib` 5.3.9.
Required PHP extensions and 64-bit requirements must match enabled modules.

PHPForge is a development toolchain, not a production capability. Its latest
stable tag is 1.02, but this repository already uses `dev-main`; downgrading to
the older stable tag would discard current quality policy. Retain that contract
and record/pin the verified toolchain and reusable workflow revision for release
evidence. The disposable fresh resolution used PHPForge
`ddcc701f340c59efbbdc20eddee40389b101f740`.

## Audit evidence and limitations

| Check | Result | Interpretation |
| --- | --- | --- |
| Current Composer validation and stable runtime constraints | Passed | Existing metadata is internally valid; it still selects old major versions |
| Current dependency security audit | No advisories; abandoned `doctrine/annotations` | Abandonment is a development-toolchain maintenance issue, not an advisory; audit command exits nonzero |
| Current full local quality run | Deterministic source checks passed; Pest 464 passed / six failed | Required database/cache services unavailable locally; full suite is not green |
| Unsandboxed Psalm retry | No errors | Initial socket failure was environmental; retry completed successfully |
| 2.1.1 production smoke | All four application modes booted | Disposable install with recorded InterMix 9.2/Webrick 4.0.2; not a full historical lock/suite reproduction |
| Fresh latest-library candidate | Dependency resolution/install succeeded | 143 packages; no advisories; does not imply source compatibility |
| Latest-library candidate Pest | 215 passed / 255 failed | Many failures cascade from obsolete InterMix APIs; not 255 independent defects |
| Latest-library candidate PHPStan | 327 diagnostics | Includes missing/changed APIs and 38 complexity findings under the refreshed toolchain |
| Fresh hosted current-head verification | Failed | Source and test failures remain even when CI provisions services |

The local runtime was PHP 8.5.4. PHP 8.4 was not installed locally. Disposable
candidate tests were restored explicitly because production `git archive`
exports omit tests. Candidate dependency changes occurred only in `/tmp`.

The fresh hosted run is [38105311540](https://github.com/infocyph/Foundation/actions/runs/38105311540)
on current head `fc9b325`. Production consumer and benchmark jobs passed, but
quality jobs failed Rector; stable Pest reported 462 passed / eight failed;
analysis on PHP 8.4 and 8.5 reported 38 complexity diagnostics. Release evidence
was skipped. Earlier green runs at the same source revision do not supersede
fresh failures with newer dependencies/tooling.

Historical branch runs
[36202903395](https://github.com/infocyph/Foundation/actions/runs/36202903395)
and [37278415963](https://github.com/infocyph/Foundation/actions/runs/37278415963)
passed at their respective heads. They predate this combined release candidate
and do not cover the newly reproduced boundary defects.

The audit covered cumulative source/dependency changes, branch patches, ownership
and native integration contracts, existing tests/CI, and focused adversarial
probes. Real service concurrency, all enabled module combinations, native host
performance, and migration rehearsal remain explicit release gates. A clean
dependency advisory scan and static analysis cannot establish absence of source
vulnerabilities.

## Required findings

### F4-01 — migrate container composition before enabling latest dependencies

**Owner:** Foundation using InterMix 11.1/Webrick 6.0. **Priority:** release blocker.

`Application`, `NonWebGraphFactory`, service providers, and registration helpers
call removed InterMix builder methods such as `development`, `singleton`,
`scoped`, `transient`, `definitions`, `options`, and `registration`. Some runtime
operations also rely on methods that are now protected or removed.

Do not mechanically replace `development()` with `build()`: the new `build()`
finalizes the graph. Complete configuration, recipes, module/provider
contributions, aliases, and input declarations before finalization. Resolve
runtime services only afterward. Use native `factory`/`autowire` definitions with
explicit lifetimes, `definitionGraph`, and the public `RuntimeContainerInterface`
scope APIs. Keep `ScopeContext` opaque. Avoid a Foundation clone of the old DI API.

Acceptance: all four modes; development and compiled production graphs; custom
providers; lazy optional modules; scope reset; nested/concurrent execution;
artifact rejection/rebuild; actual production-only consumers. Public provider
and parameter changes need migration examples.

### F4-02 — fix published ArrayKit numeric config lookup upstream

**Owner:** ArrayKit. **Priority:** release blocker.

ArrayKit 5.3 `BaseConfigTrait::isReadCacheSafePath()` passes a numeric-string
segment into `ReflectionReference::fromArrayElement()` after `array_key_exists`
has accepted it as an integer array key. The reflection operation throws.

Minimal reproduction against the published release:

```php
$config = new \Infocyph\ArrayKit\Config\Config();
$config->loadArray(['app' => ['servers' => ['first']]]);
$config->get('app.servers.0'); // ReflectionException: Array key not found
```

This affects Foundation configuration and is already visible in fresh hosted
configuration/OAuth integration tests. Fix PHP array-key semantics in the native
owner, add numeric/nested/reference/missing-key regressions, and publish a
corrective ArrayKit patch. Foundation then consumes that latest published
release. No vendor edits, shadow parser, or Foundation getter override.

### F4-03 — reconcile ArrayKit generation caches without following symlinks

**Owner:** Foundation `Config/ArrayKitConfigCache`. **Priority:** required
compatibility fix plus conditional high-impact filesystem defect in the branch.

Current `writeSharded()` checks namespace files at the cache root, although
ArrayKit 5.3 publishes them under the active generation. A normal write throws
`Unable to finalize ArrayKit namespace config cache .../app.php` after publishing
the generation pointer.

The acceptance branch understands `.arraykit-generation`, generation directories,
and `.arraykit-flat.php`. However, `removeDirectory()` recursively follows
`is_dir()` symlinks and generation discovery can include symlink directories.
A probe linking `cache/.arraykit-gen-deadbeef` to an outside directory and calling
`writeSingle()` deleted the outside sentinel before throwing on link removal.
This requires a writable/planted cache link; it is not evidence of an
unauthenticated remote exploit.

Reuse native generation APIs where available. Validate publication roots and
generation pointers; never recurse through root or nested symlinks; retain
restrictive permissions and delete only owned artifacts. Preserve the previous
valid cache if staging/publication fails. Test single/sharded transitions,
legacy layouts, malformed pointers, root/nested symlinks with outside sentinels,
concurrent readers, and injected failures. Correct this before importing the
branch cleanup into current source.

### F4-04 — carry forward canonical cache and lock ownership

**Owner:** Foundation `CacheManager`, `CacheLayerFactory`, `DBLayerFactory`, and
cache consumers. **Priority:** required correctness fix; reuse-branch F8/F9.

Reproductions on current source confirmed that `store(null)` differs from the
named configured default, and DBLayer query caching bypasses the named `db-query`
registry. Clearing that named store leaves the query-cache marker intact.

Import and adapt the branch's canonical store naming, explicit store/lock-store
resolution, replacement invalidation, and shared cache registry. Memoize reusable
lock providers by resource, never acquired lock handles. Include session, MFA,
passkey, webhook, migration, scheduler, and worker coordination consumers.
Infrastructure PDO ownership remains generation-scoped; transactions, leases,
and outbox operations remain execution-scoped with the exact transaction PDO.

CacheLayer 4 also requires cluster `transportIdentity` and changes node fail-open
configuration. Provide explicit validated identity and failure policy, preserving
durable history/generation fencing. Do not supply one universal reused identity
to satisfy a constructor. Test cluster identity rotation, history recovery,
store replacement, invalidation, and lock/provider isolation.

### F4-05 — preserve primary failures when cleanup reporting fails

**Owner:** Foundation `Session/Middleware/SessionMiddleware`. **Priority:** required
reliability fix.

Session release/context removal run through `CleanupGuard`, but reporting the
secondary failure afterward is unguarded. A throwing logger replaced an existing
handler exception in a probe; the session stack was still correctly cleared.
Guard secondary reporting so it cannot replace the primary throwable. Preserve
the defined cleanup-failure behavior when no primary exists. Extend the existing
session feature tests with a throwing logger, exact throwable identity,
exactly-once release/reset, and redacted diagnostics.

### F4-06 — reconcile specialist reuse without weakening security

**Owner:** Foundation integration policy. **Priority:** required branch integration.

Carry forward these cohesive changes from `library-reuse-hardening`, adapting
them to the latest published owner APIs:

- Native Webrick `AtomicCounterAdapter`; shared authentication counters must be
  atomic and fail closed when the required backend is unavailable. Current
  fallback performs read-modify-write. Production validation already requires
  appropriate Redis/Valkey configuration; preserve it and enforce shared-store
  semantics consistently in other environments. Process-local stores may use
  native in-memory counters.
- One Epicrypt OAuth protocol validation/result, preserving approved redirects,
  error presentation, audit hooks, and token/replay semantics. Legacy Foundation
  refresh/protocol machinery must be removed where it duplicates the native
  owner, including dormant compatibility implementations.
- Native Pathwise download preparation, preserving ranges, HEAD, headers,
  authorization, and deferred response completion. Prove invocation counts with
  runtime instrumentation rather than source-text assertions.
- TalkingBytes typed resolved HTTP configuration: parse once, retain TLS policy,
  and keep mutable request/client state inside its execution lifetime.
- Native bounded DBLayer transient-conflict retries for refresh transactions;
  no blanket retries of non-idempotent effects. Preserve atomic token rotation.
- A real Infbyte consumer of the redesigned framework, adapting its bootstrap
  and application-facing calls to 4.0. Verify intended application behavior; old
  call shapes need not survive. Do not silently use published Foundation 2.1.1.

Do not carry old API wrappers, version floors, or assertions into the new major
merely because they passed historical branch CI.

### F4-07 — restore current quality gates under the current toolchain

**Owner:** Foundation. **Priority:** release blocker.

Fix the 34 function and four class complexity findings through cohesive ownership
and private methods where sufficient. Review fresh Rector changes and changed
dependency signatures at their actual call boundaries. Run the required backend
tests with provisioned services. Investigate abandoned QA dependencies through
PHPForge upstream; do not mislabel abandonment as a vulnerability or suppress it.
No baseline additions, skipped backend cases, or relaxed thresholds.

### F4-08 — eliminate duplicate mechanisms and settle the complete framework surface

**Owner:** Foundation framework architecture and each native specialist owner.
**Priority:** release blocker under the user's clarified scope.

Complete the ownership/public-surface review above for the whole production tree,
including built-in auth, both session concepts, authorization, notification,
scheduling and console services. Remove duplicated algorithms, protocol engines,
parsers, stores/lock implementations, native lifecycle handling and generic
forwarding layers. Retain only meaningful framework services and plugin glue.
Existing code size, branch status, legacy names and backward compatibility are
not reasons to keep an implementation.

If a mechanism belongs to a sibling package but its published API lacks required
behavior, implement and release the native fix/extension there, then use that
published version. A temporary Foundation clone is not an accepted release
solution. Keep intentional Foundation policy/security checks distinct from the
specialist mechanism rather than deleting them indiscriminately.

Acceptance: one documented owner and implementation per mechanism, an explicit
disposition for each reviewed capability, revised public/configuration contracts,
and end-to-end application behavior through native owners. Prove removed paths
are unreachable because they are removed, not merely disabled. Verify the
Infbyte-facing framework surface without importing skeleton-specific business
logic into Foundation or framework engines into Infbyte.

## Runwire 2.1.1 composition contract

Foundation currently suggests Runwire but does not implement active
`RuntimeContext`/`RequestContext`/`CoroutineScope` propagation. The new release
must accept the host's instances and pass them through direct and intermediary
library calls. This is a required feature, not a dependency advisory.

Design the public application/execution boundary around native host and DI scope
ownership; existing `Application`/`ExecutionScope` APIs may be replaced. A scoped
public entry point such as `Application::withRunwire(runtime, callback, request, scope)`
is a proposed API, not an existing one; settle its typed signature after checking
the native Webrick/InterMix bridges and the new framework surface. Add an internal
binding only where it provides the required framework borrowing/restoration glue.

The contract is:

1. Borrow the exact active runtime, optional request/task context, and optional
   live coroutine scope. A CLI/worker task need not invent an HTTP request.
2. Validate runtime identity, PID/generation, request completion, cancellation,
   deadlines, and scope ancestry/liveness. Reject stale or mismatched bindings;
   missing capabilities do not permit bypassing these checks.
3. Restore prior bindings in `finally`, including nested calls and throwing
   callbacks. Use native Fiber/task-local propagation; mutable request/tenant
   state must not remain in singleton profiles or process-global slots.
4. Select supported native capabilities automatically. Cooperative operations
   require both the relevant capability and a live scope; otherwise execute the
   normal supported synchronous path. Do not promise asynchronous PDO merely
   because cancellation/deadlines are forwarded.
5. The host owns workers, listeners, event loops, requests, and borrowed scopes.
   Foundation must not create a runtime implicitly, drive or close its loop,
   complete its request, install competing signal handlers, or release its scope.
6. Preserve response-producer lifetime until the owning host finishes output.
   Define browser-session commit/release separately from deferred response
   production and verify both lifetimes under failure and cancellation. Foundation
   cleans up only resources/DI executions it created.
7. Keep the ordinary installation and execution path working with Runwire absent.

| Boundary | Native integration to reuse | Required forwarding/ownership |
| --- | --- | --- |
| Webrick HTTP → Foundation | `RunwireRuntimeBinding` / `RunwireInterMixScopeBridge` | Borrow the host DI scope and exact active contexts; avoid a second request scope |
| InterMix scope | `Integration/Runwire/RunwireIntegration` | Register native inputs before graph build; borrow opaque `ScopeContext`; restore nested bindings |
| CacheLayer | Native `bind` / `share` | Bootstrap binding only by its owner; share exact request/scope; never release host binding on request exit |
| DBLayer | `Connection::withRunwire` | Forward into query/transaction operations; preserve previous binding and transaction ownership |
| ReqShield → DBLayer | `validateWithRunwire` / native database bridge | Same instances through validation and batched database operations |
| Omnibus → DBLayer | `withRunwire`; managed worker lifecycle | Same contexts into durable operations; `runManaged` under host supervision |
| TalkingBytes | Context-bound native factories and request pool | Scope-bound clients; bounded concurrency; TLS/retries/deadlines retained |
| Pathwise | Native `RunwireExecutionContext` / scoped operation | Forward checkpoints; preserve deferred producers and host ownership |
| Epicrypt | Native `RunwireExecutionContext` on supported operations | Use only consuming APIs; retain cryptographic/protocol boundaries |
| OTP → CacheLayer | Native `CacheLayerRunwireExecutionContext` | Preserve lock lease, atomic replay/terminal-state, and cancellation semantics |
| UID | Native binding on waiting generators where applicable | Ordinary immediate UUID/ULID calls need no artificial runtime hop |
| ArrayKit | Ordinary config APIs | No unnecessary runtime integration for pure configuration operations |

CacheLayer's native bootstrap binding is process-wide. Never overwrite another
host's binding to simplify composition. Use its native request/Fiber-local sharing
and explicit binding ownership. Dynamic factories may be generation-owned, but
context-bound clients with cookies/auth/retry state must be execution-scoped.

Acceptance must exercise Foundation → library and Foundation → intermediary →
library chains, a host invoking Foundation as an intermediary, nested restoration,
two concurrent requests, child tasks, cancellation/deadline expiry, stale
generation, completed requests, missing capabilities, no coroutine scope, and
Runwire absent. Assert exact instance identity, no cross-tenant state, exactly-once
cleanup, and zero competing host lifecycle actions.

## Batch 1 — complete capability-level ownership and migration disposition

**Inventory baseline:** the reviewed current production tree contains **666 PHP source files** (including 377 under `Auth/`). These counts classify ownership and work size, not API-retention requirements. All listed areas are part of the 2.1.1 → 4.0 cumulative review; the exact per-symbol removal list and ABI changes are completed during Batch 2 rather than using the old signatures as compatibility requirements.

| Source area (PHP files) | Unique Foundation framework responsibility | Native owner / implementation disposition for 4.0 |
| --- | --- | --- |
| `Application/` (8), `Bootstrap/` (1), `Foundation.php` (1) | Four application modes, application bootstrap, provider contributions and application-facing entry points | **Redesign** graph assembly before InterMix finalization; keep meaningful framework facade, remove obsolete builder forwarding |
| `Container/` (2), `Runtime/` (13) | Execution-scoped context, Foundation resource cleanup, passed-instance host bridge | **Delegate** graph, scope, compile and execution primitives to InterMix 11.1 and Runwire 2.1.1; never create competing lifecycle/host loops |
| `Auth/` (377) | Account/device lifecycle, application login/consent/MFA/passkey policy, authorization, principal, OAuth HTTP presentation | **Retain framework policy; delegate** crypto/protocol/token internals to Epicrypt 3.2, OTP 7, UID 6 and native atomic stores; delete dormant duplicate refresh/counter engines |
| `Session/` (18) | Browser state, cookies, flash, rotation, CSRF policy and scoped HTTP session orchestration | **Retain distinct browser-session feature**, delegate locks/transactions/Webrick scope, coordinate but never conflate account/device authentication sessions |
| `Cache/` (9) | Capability activation, configured store identity and application-backed services | **Retain thin registry only**; delegate caching, counters, lock handles, clusters and invalidation to CacheLayer 4; remove local Webrick counter implementation |
| `Config/` (30) | Config selection, validation, deployment defaults, trust policy and cache publication | **Delegate** parsing, interpolation, cache generations and materialization to ArrayKit; keep outer manifest and trusted release path policy; no shadow parser |
| `Release/` (7), `Operations/` (6) | Staged application release, generation/fence/lease policy, readiness, activation and operational control | **Retain** outer atomic publication and operational policy; trust only owner-validated native InterMix/Webrick/ArrayKit artifacts |
| `Http/` (30), `Routing/` (18) | Middleware selection, authentication/authorization placement, application responses and route registration | **Delegate** request parsing, matcher execution, routing, rate-limits and emission to Webrick 6; no sharded/fused route clones |
| `Database/` (16) | Schema policy, named application connections, migration deployment and auth data mappings | **Delegate** transactions, leases, retry policy and query caching to DBLayer 6; preserve one transactional owner per request/job |
| `Filesystem/` (11) | Application upload/download permissions, disk/profile choices, HTTP response integration | **Delegate** storage, archive, ranges, integrity, streaming and file containment to Pathwise 4.2; avoid preparing one download twice |
| `Validation/` (5) | Form requests, application rule and error presentation | **Delegate** rule implementations/sanitization to ReqShield 3.3; no second native validator engine |
| `Communication/` (5), `Notifications/` (12) | Application profile selection, templates, recipients, notification channels and delivery policy | **Delegate** HTTP, email, webhook, gRPC transport and retry mechanics to TalkingBytes 2.3; retain framework notifications even without optional communications |
| `Messaging/` (14) | Job declarations, middleware and application outbox/dispatch policy | **Delegate** broker, delivery, retry and durable messaging to Omnibus 3; preserve transaction/outbox atomicity |
| `Scheduling/` (6), `Worker/` (5), `Process/` (8) | Application schedules, command/job execution, leadership policy, worker management and operational reporting | **Retain framework orchestration**, delegate timer/host supervision/concurrency to Runwire, leadership locks to CacheLayer; never borrow then close host contexts |
| `Command/` (31) | Command registration, built-in operational commands, application CLI parsing and output policy | **Retain** useful reusable console framework; do not duplicate native process execution or scheduler mechanics |
| `Module/` (11), `Generator/` (1) | Installation/activation/readiness, dependencies and developer scaffolding | **Retain** module/catalog policy and generation orchestration; align native published versions rather than accepting obsolete floors |
| `Security/` (4), `Logging/` (5), `Diagnostics/` (1) | Trusted environment/secret policy, safe application-level logging, failure precedence and readiness | **Retain policy**; use specialist security primitives and PSR logging, never replace root failures with secondary reporting |
| `Testing/` (5), `Exception/` (5), `Support/` (1) | Framework test fixtures, domain errors and genuinely shared policy utilities | **Retain only meaningful framework contracts**; remove generic duplicate wrappers and native error/normalizer clones when moving APIs |

**Auth subdomain disposition:** `Auth/Adapter/` (69) is a published-owner boundary, not a place for alternative crypto/DB/cache engines. `OAuth/` (60) keeps Foundation consent/client/audit/HTTP/persistence mapping and delegates protocol, DPoP and refresh-family mechanics to Epicrypt. `Authentication/` (55) retains account workflows including login, verification, recovery, impersonation, passwordless and device/session policy. `Authorization/` (25) retains application gates, tenancy and resource decisions. `Mfa/` (14), `Passkey/` (13), `Otp/` (4) retain enrollment/step-up/use policy but delegate native OTP and WebAuthn ceremonies. `Principal/` (6) keeps exactly one execution-local identity; `Internal/` (26) registers only needed services; `Support/` (42) and `Contract/` (22) require individual removal/replacement checks during Batch 2 rather than blanket retention. `Account/`, `Device/`, `Audit/`, `Http/`, `Notification/` and `Driver/` provide the remaining application contracts and policy.

### Exact authentication and browser-session composition contract (design accepted for implementation)

1. **Web request entry:** the host/Webrick owns the request and HTTP scope. `SessionMiddleware` resolves one browser-session container, obtains a native lock if required, and binds only browser state. `CsrfMiddleware` verifies browser-originated mutating operations according to application policy.
2. **Login:** `Auth/Http/AuthActions::login()` delegates to Foundation authentication/account workflows, which enforce native Epicrypt password/token decisions, deployment-visible native atomic lockout counters, account status, device verification and selected MFA/step-up. No account/device auth session is committed if validation or atomic conditions fail.
3. **Persistence and identity:** `Auth/Authentication/Session/SessionManager` creates/rotates/updates/revokes **account/device authentication sessions**, using DBLayer transaction ownership and native atomic security state. That record is distinct from `Session/SessionManager`'s browser key-value/cookie state. The browser can reference an auth-session ID but cannot itself become the authority for authenticated account state. Set exactly one `CurrentPrincipalContext` instance for that execution.
4. **Authorization:** `Auth/Authorization/Gate/` and application policies resolve account, tenant, role, delegation and resource decisions from the current execution's principal. Prevent stale principal/permissions from crossing persistent worker tasks, tenants or nested contexts.
5. **Response/cleanup:** rotate browser identifiers where required by login/logout; apply cookie, CSRF, expiry and persistence policy before Webrick output. On error preserve the primary throwable even if lock release, session reset or logging fails; release/reset exactly once. Deferred output and native body streaming belong to Webrick/Pathwise, not a second Foundation execution loop.
6. **Logout/revocation/recovery:** revoke selected auth-session or device/account records and derived credentials according to application policy, separately invalidate browser presentation and clear the execution principal. Account suspension/password change/refresh reuse triggers appropriate family/device invalidation. Reuse the same DBLayer transaction for atomic writes and selected CacheLayer invalidation/leases.
7. **Bearer/API and non-web:** bearer authentication resolves tokens through the chosen native protocol validator, maps them to one execution-local principal and authorizes without creating browser state; CLI/worker/scheduler start with an unauthenticated or explicitly delegated principal, no invented HTTP request or implicit Runwire host.
8. **Concurrency/migration:** native Runwire and InterMix scopes remain host-owned; nested/child tasks explicitly inherit only allowed identity and cancellation context, and restore the previous principal. Retained account password hashes, browser cookies/sessions, auth-session/device records, refresh families, OTP/recovery/passkey credentials and outbox messages require schema/data migration and failure/race tests in Batches 4–5, **not legacy compatibility shims**.

### Source-range and branch disposition

| Reviewed change group | Retain / redesign / delegate / delete | Behavior carried forward / evidence |
| --- | --- | --- |
| Post-published-2.1.1 main: four-mode builder/provider graph and generated runtimes | **Redesign** InterMix graph and ABI | Provider ordering, scope isolation, compiled/development parity; Batch 2 and migration |
| Main: release generations, maintenance, Webrick fused/sharded routes and native configuration | **Retain trust policy; delegate native mechanisms** | Atomic staging, manifest/fence, reader lifetimes, no symlink traversal, native route matching |
| Main: auth, MFA, passkey, OAuth, notification, messaging and application services | **Retain application policy; delegate protocols and storage** | Credential/replay/race security and real database state, tested in Batches 2–5 |
| Main: DB, cache, worker, HTTP, files, CLI, scheduler and configuration endpoints | **Retain framework services; delegate storage/host engines** | No process/request resource leaks, cold optional providers, native lock/transaction scope |
| `foundation-3/library-reuse-hardening`: canonical cache identities, lock registry, query cache, session/MFA/passkey/migration consumers | **Integrated on PR #20** | Current cache/DB/session/auth registrars plus named-store, named-lock and query-cache regressions |
| Hardening branch: Webrick native atomic adapter, shared auth counters | **Integrated**; redundant Foundation `WebrickAtomicCounter` and unsafe `CacheLayerCounterStore` **deleted** | Host/cluster auth counters require native atomic resources; process-only uses native in-memory counters |
| Hardening branch: Epicrypt validated OAuth protocol and DBLayer bounded refresh transaction retries | **Integrated current-version intent; redesign old refresh duplicates later** | One protocol evaluation per request, approved redirect/error/audit mappings and bounded transactions; tests imported |
| Hardening branch: Pathwise native HTTP range preparation, TalkingBytes resolved HTTP configuration | **Integrated** | Avoid duplicate download preparation and repeat immutable HTTP parsing; TLS remains production fail-closed |
| `arraykit-5.3/acceptance`: cache generation discovery, root/flat artifacts | **Selectively redesigned** | Native generation-aware validation, pinned readers and safe mode transitions, with no recursive symlink traversal |
| Acceptance branch: recursively deleting `.arraykit-gen-*` with `is_dir()` | **Rejected/deleted as unsafe** | Planted root and nested symlink sentinels must survive; never eagerly unlink pinned generations |
| ArrayKit published 5.3 native numeric read-cache lookup | **Fix/publish upstream, not in Foundation** | Correct PHP numeric-string offset handling before `ReflectionReference::fromArrayElement()`; still blocked by repository write permission |

### ArrayKit F4-02 — upstream correction published and consumed

ArrayKit **5.3.1**, published 2026-10-11 ([release](https://github.com/infocyph/ArrayKit/releases/tag/5.3.1), commit `e77bf72a40c64b94f62e69f3b2662ce2dc47efec`), includes the canonical numeric-string array-key fix from [ArrayKit PR #36](https://github.com/infocyph/ArrayKit/pull/36). Native `BaseConfigTrait::isReadCacheSafePath()` now normalizes integer-string array offsets before `ReflectionReference::fromArrayElement()` without altering literal string keys, reference detection or strict typing. ArrayKit's four PHP 8.4/8.5 Pest QA variants and both benchmarks passed on that PR. Its pre-existing complexity gate is independent.

Foundation `composer.json` now requires `infocyph/arraykit:^5.3.1`, excluding the defective 5.3.0 from both lowest and stable resolution. The existing numeric-path and OAuth environment integration regressions are retained. **F4-02 upstream cause fixed and published.** Foundation-side batch acceptance remains contingent on the final supported dependency matrix, unaffected security/ownership behavior and real CI proof.

## Active Batch 1 execution log

### Batch 1 — post-ArrayKit-5.3.1 checkpoint (2026-10-11)

- Verified official 5.3.1 published tag and corrected numeric-key source. Foundation core minimum version changed to `^5.3.1`; dedicated ArrayKit upstream PR #36 has native regression coverage.
- The first post-upgrade Foundation run [38126272273](https://github.com/infocyph/Foundation/actions/runs/38126272273) removed both historical `ReflectionException` failures, passing **492/493 tests** on PHP 8.4 stable dependencies. Its remaining test failure was a stale literal Composer baseline `^5.2`; this expectation is corrected. PHPForge Pint also flagged conflicting multiline empty-constructor formatting: the CacheLayer factory now uses an explicit readonly-closure property assignment.
- Lowest-version QA exposed `TalkingBytes 2.1` missing the native typed `fromResolvedConfig(..., baseConfig:)` API already used by Foundation. The dev requirement and communication module floor were updated to published `TalkingBytes ^2.3` with matching module/config tests. No old-version fallback was added. Production dependency sets stay narrowly scoped.
- Hardening-branch cache ownership is now unified for scheduler/worker leadership, webhook replay, browser session, MFA/passkeys, migration/query cache, native auth counters and config topology validation. Carried forward source-architecture, runtime identity, OAuth rotation and communication integration regressions.
- **Outstanding evidence:** new exact-head PHP 8.4/8.5 lowest/stable QA, benchmarks and scope regression inspection. Pre-existing 34 function / four class PHPStan cognitive-complexity findings remain tracked under Batch 2, with thresholds unchanged. Batch 1 remaining checkboxes are not marked complete until their own evidence is confirmed.
- InterMix 11.1 native builder/graph/finalization and all other specialist major upgrades remain Batch 2, not part of this isolated ArrayKit 5.3.1 acceptance increment.



- **2026-10-11 — Draft PR:** [Foundation #20](https://github.com/infocyph/Foundation/pull/20), `feature/foundation-3` → `main`. The PR remains draft; merge, tagging, and publication are excluded.
- **Cache reproductions and implementation:** `tests/Feature/Foundation4CacheIdentityRegressionTest.php` now covers default/named identity in both access orders, configured default changes, replacement, native lock provider identity, and distinct acquired lock handles. The cache manager/factory/DI graph and DBLayer query-cache integration now share one application-managed registry. The existing DBLayer query-cache feature suite was reconciled with the hardening branch and covers registry identity, cache replacement, and PDO infrastructure isolation. **F4-04 implementation is partial until latest-API migration and QA.**
- **Throwing diagnostics regression:** `SessionMiddleware` protects an original request throwable against secondary cleanup-logger failures; `BrowserSessionIntegrationTest` now checks exact exception identity, exactly two releases over two requests, one recorded warning, and no active session afterward. **F4-05 pending QA.**
- **ArrayKit publication safety:** `ArrayKitConfigCache` and `ConfigLoader` now reject symlinked publication roots before writing. `Foundation4ArrayKitPublicationSafetyTest.php` covers linked roots and both root-level and nested planted native-generation symlinks with an external sentinel. This preserves a regression against the unsafe acceptance-branch deletion behavior but does **not** resolve the generation-layout defect or certify full F4-03.
- **Historical, resolved upstream blocker — F4-02:** ArrayKit 5.3 initially failed on numeric-string keys, and the GitHub installation initially refused upstream writes. Permissions were later resolved; ArrayKit PR #36 produced the native fix, published as **5.3.1**, which Foundation now requires. This historical failure is no longer an active blocker.
- **QA:** PR-triggered [Security & Standards runs](https://github.com/infocyph/Foundation/actions?query=branch%3Afeature%2Ffoundation-3) execute on pushed commits; the current rollout is not certified. Current main's unrelated Rector/complexity failures and published ArrayKit 5.3 incompatibility remain blockers. Do not mark the grouped Batch 1 acceptance checkboxes complete on source edits alone.

### Batch 1 continuation — Foundation cache-generation fixes and QA (2026-10-11)

- **Generation-aware Foundation fix:** `ArrayKitConfigCache::writeSharded()` now validates the native `.arraykit-generation` target rather than looking for outdated root-level namespace files. The integration suites use native generation-aware artifact assertions. It validates native directory/link containment and rejects malformed/linked generation metadata without traversing it.
- **Pinned-reader safety:** switching to single cache mode retires the active generation pointer only after single-cache validation. Existing generations are deliberately retained for readers pinned to their old native generation; eager recursive deletion is forbidden. Added an in-process pinned-reader transition regression. Native/release-owned generation garbage collection and leases require further certification, so **F4-03 remains open**.
- **Rector:** applied the reported nullsafe/coalesce modernization in `Auth/OAuth/Authorization/AuthorizationRequestValidator.php` without changing its result contract.
- **Cold lock configuration:** an explicitly configured native lock driver no longer eagerly resolves the default cache backend. A new regression checks the PDO-backed cache remains cold while obtaining a native file lock.
- **CI distinction:** Previous finalized PR run [38118514862](https://github.com/infocyph/Foundation/actions/runs/38118514862) at SHA `197f68c` had **471 passing / 8 failing Pest tests**, quality and complexity failures; the eight clustered in ArrayKit cache artifacts and the native numeric-path issue. Subsequent source commits invalidate that run as certification. The current PR run should be evaluated **only after** the latest source/tracker SHA stabilizes.
- **Historical checkpoint, superseded:** The ArrayKit numeric-key issue and upstream repository access were later resolved; Batch 1 now has five checked acceptance items and Batch 2 has begun. The current blockers and actual version floors are in the live status above.

- **Follow-up validation:** `tests/Feature/Foundation4ArrayKitPublicationSafetyTest.php` now also asserts that linked, malformed, and missing native generation pointers fail safely without modifying external sentinel files. The previous security check exceeded the PHPForge per-function cognitive-complexity threshold; the pointer and directory checks were separated before the latest run. This is an **unverified fix**, not a passing QA result.
- **Current commit-specific gate:** evaluate the most recent PR run after the tracker is updated; passing consumer-install/benchmark jobs from superseded commits are informative only. No approval to progress past Batch 1 until upstream ArrayKit and required behavioral evidence are available.

### 2026-10-11 verification checkpoint — reconciled native branch changes

The hardening branch's MFA, passkey, browser-session and database-migration consumers now resolve shared cache state through `CacheManager` rather than bypassing the store identity with new native instances. `AuthCacheRegistrar` selects native atomic counters for host/cluster state. The redundant `CacheLayerCounterStore` and `WebrickAtomicCounter` are deleted and Webrick's native `AtomicCounterAdapter` is used. Imported tests cover cache registry replacements, concurrent refresh rotation/replay/audit, Pathwise prepare-once/ranges, Epicrypt validate-once/approved redirects and TalkingBytes typed configuration/TLS policy.

On [CI run 38120431178](https://github.com/infocyph/Foundation/actions/runs/38120431178), the PHP 8.5 stable test suite produced **491 passed, two failed**, both caused by the published ArrayKit `ReflectionReference` numeric-key defect. Four PHP 8.4/8.5 production consumer variants and clean install passed; Rector, reference checks and architectural boundaries passed. That run also detected Pint ordering/braces violations in newly edited `CacheLayerFactory` and `ArrayKitConfigCache`, and PHPCS unused test callback parameters; all three have since been corrected in subsequent commits. PHPStan still reports 34 function and four class complexity violations distributed across the broader 4.0 redesign, without new API/type diagnostics. None of these commits relaxes a quality gate.

**Historical Batch 1 checkpoint, superseded:** At this point the first two inventory items were complete and ArrayKit repository access was still blocked. ArrayKit 5.3.1 has since been published and adopted; **five of six** Batch 1 acceptance items are now checked. The remaining native-reuse/removal acceptance evidence is still open and source-head QA must be used.

### Provisional mechanism dispositions (Batch 1 ownership review, incomplete)

These are concrete starting decisions, not a claim that every production symbol has been audited or that APIs are frozen. The final ownership/public-surface record must include all built-in services and persisted-data effects.

| Source boundary | Native mechanism owner | Unique Foundation responsibility | Disposition for 4.0 |
| --- | --- | --- | --- |
| `Application/Application.php`, `Container/FoundationGraph.php`, `Runtime/NonWebGraphFactory.php` | InterMix graph, lifetimes and compiled scopes | Explicit four-mode application bootstrap, provider/capability policy, release binding | **Redesign** to complete inputs and providers before graph finalization; remove old builder calls, no compatibility facade |
| `Auth/AuthServices.php`, `Auth/Internal/` | InterMix direct resolution of registered services | Feature selection and coherent auth workflow registration | **Review/remove** generic forwarding surfaces; keep only meaningful policy and workflow entry points |
| `Auth/Authentication/Session/`, `Session/`, `Auth/Principal/` | Native CacheLayer/DBLayer state and Webrick HTTP lifecycle | Distinct account/device login-session and browser-session policies with one execution-local principal | **Redesign coordination**, never merge distinct persisted state semantics |
| `Auth/OAuth/`, `Auth/Adapter/Epicrypt/`, `Auth/Adapter/DBLayer/OAuth/` | Epicrypt OAuth/OIDC protocol, token and security mechanisms; DBLayer transactions | Application consent/claims, HTTP presentation, schema and policy mapping | **Delegate** duplicate protocol/refresh logic; preserve atomic rotation, replay and audit policy |
| `Cache/CacheManager.php`, `Cache/CacheLayerFactory.php` | CacheLayer stores, locks, counters, clusters and outbox | Configured store identity, capability activation and lifecycle ownership | **Retain integration**, canonical registry changes underway; migrate CacheLayer 4 cluster semantics next |
| `Database/DBLayerFactory.php` | DBLayer connection, transaction, pool, retry and query cache semantics | Configured application connection topology and execution/generation ownership | **Retain integration**, share cache registry and migrate DBLayer 6 contracts |
| `Config/ArrayKitConfigCache.php`, `Release/` | ArrayKit parsing/materialization and its native generation; native InterMix/Webrick artifacts | Trusted outer release manifest, staging/activation/readiness and ownership of deployed paths | **Redesign generation glue**; never follow symlinks or replace a valid generation on failed publication |
| `Routing/WebReleaseCompiler.php`, `Routing/WebReleaseRuntime.php` | Webrick routing and matcher compilation | Application route registration/policy and coordinated release publication | **Delegate matching**; preserve native fused/sharded Webrick paths and trust checks |
| `Notifications/`, `Messaging/`, `Scheduling/`, `Command/`, `Process/` | TalkingBytes transports, Omnibus messaging, Runwire process/host mechanisms where applicable | Framework notification templates, app jobs, schedules, CLI policy and observability | **Review individually** for native reuse; unresolved symbols and persistence contracts remain open |

### Auth/session composition acceptance sketch

- Webrick/host owns the HTTP request and request DI scope. Foundation coordinates browser-session cookie and CSRF policy, resolves one execution-local principal from an explicitly selected browser/auth-session or bearer path, and never silently shares mutable principal state between requests/tasks.
- Account login must validate native Epicrypt credentials, lockout/account policy and relevant MFA/step-up requirements before committing device/auth-session state. Use DBLayer transactions and CacheLayer native atomicity. Rotate browser identifiers as required, then bind the resulting principal for only that execution.
- Logout, account/device revocation, password recovery and impersonation must update the proper account/auth-session/credential records and invalidate browser bindings without confusing authentication state with browser key/value storage.
- Browser-session commit/release is distinct from deferred Webrick response emission. CLI/worker/scheduler operations need no invented browser session or HTTP request. Borrowed Runwire/InterMix contexts remain host-owned.
- Pending: trace every actual controller/provider/store path and migration of persisted browser sessions, auth-session records, OAuth families, OTP factors and passkeys; verify concurrency, race failure precedence, and exact host/context ownership before closing this design.

## Implementation batches and acceptance

Checkmarks indicate verified scope/design decisions, not final source certification.
Unchecked items remain release blockers until the specific acceptance evidence is present.
Keep only unresolved findings and required release evidence after release.

### Batch 1 — preserve reproductions and reconcile branch intent

- [x] Complete the framework ownership/public-surface review and built-in auth/
  session flow design before choosing replacement APIs; close F4-08 through the
  subsequent implementation batches.
- [x] Account for every post-2.1.1 main change group and both branches with an
  explicit retain/redesign/delegate/delete decision, migration notes, and
  meaningful behavioral coverage; retain security invariants when replacing code.
- [x] Convert the confirmed ArrayKit numeric-path, generation/symlink, cache
  identity, and throwing-logger probes into owner-appropriate regressions.
- [x] Resolve and publish the native ArrayKit patch; refresh the dependency table.
- [x] Reconcile both named branches against the intended 4.0 design, keeping
  applicable fixes and correcting unsafe generation cleanup before adoption.
- [x] Reuse branch-owned cache/protocol/download changes and remove redundant
  implementations, aliases and compatibility glue with explicit replacement/
  removal and migration evidence.

### Batch 2 current integration checkpoint — exact-head native CacheLayer 4 services (2026-10-11)

- **Source implementation:** `a430a7a` added `tests/Feature/CacheLayer4ClusterHistoryIntegrationTest.php`, exercising Foundation `CacheLayerFactory` composition against **real Redis and Valkey stream transports**, two isolated native SQLite node caches, published cross-node key invalidation and native `ClusterRecoveryManager` fail-closed invalidation when `transport_identity` changes.
- **QA remediation:** [initial run 38152547747](https://github.com/infocyph/Foundation/actions/runs/38152547747) revealed a **fixture-only** cache-root mismatch (direct `PathManager` default `cache/` vs configured node under `storage/cache/`) and an unused lambda parameter. `2314316b9f698114aa60e8ab90065f493e7dbdbe` corrected the test's explicit PathManager root and callback without altering production code or weakening the path-containment enforcement.
- **Exact-head acceptance:** [workflow 38152803162](https://github.com/infocyph/Foundation/actions/runs/38152803162) **completed success**: all four supported PHP 8.4/8.5 lowest/stable QA jobs, PHPStan 2/2, benchmarks 2/2, production consumers 4/4, clean install and release evidence passed. Real Redis and Valkey scenarios are included in Pest on the same SHA, not skipped.
- **Scope:** source `2314316b` added backend evidence toward Batch 2 criterion 2; subsequent `e742683a` closes the **service provision + session contention acceptance criterion 8** with the real MySQL/PostgreSQL outbox, Redis/Valkey history and existing Redis/Valkey/Memcached/MySQL/Postgres lock-contention datasets. Full transaction/outbox race, optional native-major APIs, generated graph and release host acceptance remain open. Batch 2 **1/8** accepted; this does not certify criterion 2.

**Native DBLayer 6 / CacheLayer 4 PDO outbox QA checkpoint:** `a887f812` introduced `tests/Feature/CacheLayer4PdoOutboxIntegrationTest.php` with committed cross-node invalidation and rolled-back event isolation for **real MySQL and PostgreSQL**. [First run 38153789911](https://github.com/infocyph/Foundation/actions/runs/38153789911) reproduced missing `app.base_path` in the test's explicit `ConfigRepository`, which masked the initial error during teardown, plus an unused callback argument under PHPCS. `e742683a` added the intended path base, guarded transport-table cleanup until native schema creation succeeded and made the optional callback name meaningful. **No production cache, DB, transaction or security behavior was loosened.** [Run 38154078591](https://github.com/infocyph/Foundation/actions/runs/38154078591) passed **4/4 QA, 2/2 PHPStan, 2/2 benchmarks, 4/4 production consumers, clean install, release evidence**. Existing `SessionLockContentionTest` covers five separate configured backends (Redis, Valkey, Memcached, MySQL PDO, PostgreSQL PDO); PHPForge requires available integration services and `fail_on_skipped_tests: true`. The workflow provisions all required services. **Accept criterion 8 only**. Native broader API adoption (criteria 1–7) remains open; these integrations do not prove Redis/SQL parallel cursor races, actual 4.0 compiled graph parity, Runwire host runtime, or final release throughput.

**Coordinated InterMix 11.1 + Webrick 6 migration boundary (verified against native upstream source):**

| Native change | Blocking Foundation source seam | Required working acceptance |
| --- | --- | --- |
| Webrick 6 **requires `infocyph/intermix:^11.1`** | `composer.json` still pins InterMix `^10.1.1` and Webrick `^5.4` | Update both floors in one solvable Composer/ModuleCatalog/CI step, *after* compatible implementation, never a disconnected version bump |
| InterMix11 `ContainerBuilder::build()` freezes the graph; no `development()`, `definitions()`, `bindFactory()`, `singleton()` or `scoped()` registrations | `Application::create`, `WebGraphFactory`, `NonWebGraphFactory`, `ServiceProvider::application`, `DevelopmentRuntimeBindings`, `FoundationGraph` and built-in providers/registrars | Configure providers/explicit lifetime/inputs and native scope callbacks **before one final build**, with application boot after construction and no mutable-container emulation |
| Webrick6 accepts native `RuntimeContainerInterface` directly as the router invoker; InterMix11 removed the legacy `DI\\Invoker` facade | `WebrickRouterFactory`, `RoutingServiceProvider`, `HttpServiceProvider` | Borrow the finalized native runtime for HTTP request scope; preserve native route matching, provider order, no global request state |
| InterMix11 compiled artifact includes `graph`, `build` and `artifact` identities, plus `digest`; `StaticRuntimeGenerator` has explicit final graph/trust arguments | `GeneratedRuntimeCompiler`, `GeneratedRuntime`, `WebReleaseCompiler`, `WebReleaseRuntime`, trusted-generation metadata | Regenerate and validate web + CLI/worker/scheduler artifacts with immutable graph identity; never load partial, mismatched or prevalidated fallback sources |
| Runtime scope seed declarations use `input()` or scoped definitions; enter/leave APIs replaced with native `withinScope()` and `withinScopeContext()` | `ExecutionScope`, worker/scheduler/CLI runtime, route middleware, custom providers | Verify host-owned request/task identities, cancellation, cleanup and compiled/development parity for all four modes |

**Implementation sequencing:** migrate the **whole graph ABI** before switching both direct dependency constraints, then reconcile optional-library consumers and persisted/runtime tests, then update examples/config/docs/benchmark filenames and full native compatibility matrix. Preserve all prior green baseline evidence as regression comparators; this checkpoint is not a claim of completed InterMix/Webrick integration.

### Batch 2 native provider-composition checkpoint — deterministic capabilities (2026-10-11)

**Source `448a2050c22cc064311dc3d4581e3bb7bd9254a3`** removes **three partial-builder definition lookups** from Foundation's built-in provider-composition path and makes bootstrap use one explicit capability policy. `FoundationBuildContext::permitsCapability()` preserves explicit `app.capabilities`, per-module overrides and implicit discovery semantics without asking an unfinished `ContainerBuilder` to resolve service ownership. `CacheServiceProvider` and `ValidationServiceProvider` use that policy plus DBLayer's actual installed `Connection` class; `DatabaseServiceProvider` now contributes its `DatabaseMigrationManager` reference to `CacheManager` **before** the later cache provider registers it. Native migration locks are resolved against the *final graph* and are absent when cache is disabled. The forward native `ServiceReference` is an existing InterMix feature, not a fallback/adapter.

**Regression `tests/Feature/DatabaseMigrationCapabilityCompositionTest.php`:** builds a real CLI graph with cache+database enabled and a named migration lock; verifies a native `MigrationRunner` resolves the lock, then uses a database-only capability topology to enforce the same `DatabaseMigrationManager` fail-closed error, and verifies implicit, explicit and module-disabled policy selection. [First run 38154714866](https://github.com/infocyph/Foundation/actions/runs/38154714866) passed both PHPStan analyzers, PHPCS, Pint and Rector but revealed **one flawed test assertion**: `Application::has(CacheManager::class)` may return true from InterMix autowiring even if the cache provider is absent. **Source `8643fe28d46cdd8438fd62d0bb66defe1bc264b8`** checks `ServiceRegistry::classes()` instead so the test verifies *actual provider composition*. It did not bypass the cache-disabled negative migration lock test.

**Exact-head QA:** [workflow 38154992790](https://github.com/infocyph/Foundation/actions/runs/38154992790) **completed success**: PHP 8.4/8.5 × lowest/stable QA **4/4**, PHPStan **2/2**, benchmarks **2/2**, production consumers **4/4**, clean install and release evidence passed. This is meaningful incremental progress towards Batch 2 criterion 1, but **not** its completion: `ServiceProvider::application`, remaining `Messaging/Security/Logging` builder introspection, runtime-only factories, native InterMix11 immutable `build()`, Webrick6 routing and all compiled-graph identities remain unadopted. **Batch 2 stays 1/8**; no feature checklist is marked complete by the partial graph refactor.

### Batch 2 policy-graph and auth preparation — current exact-head QA (2026-10-11)

**Source `7f5d4102ba3810f0669843f4b4a42b145c5565fb`** removed partial-container `definitions()` checks from the session store/locking policy (four sites) and protected inbound webhook replay (one site). `SessionServiceProvider` derives cache/database ownership from `FoundationBuildContext::permitsCapability()`; database use additionally requires installed DBLayer `Connection`. `CommunicationServiceProvider` now rejects replay when explicit cache capability is disabled. No fallback, hidden cache engine or permissive security policy was introduced.

**Regression `tests/Feature/NativeCapabilityPolicyIntegrationTest.php`:** disabling cache-backed sessions, session locks, database-backed sessions and protected webhooks triggers fail-closed composition with their original error contracts; a declared database capability resolves `SessionManager` and `SessionDatabaseSchema`. [Initial run 38156066124](https://github.com/infocyph/Foundation/actions/runs/38156066124) passed Pest, PHPStan, PHPCS and Rector but **Pint** found one `ordered_imports` issue. **Source `51fd0c0e32a23e0bfd169b0241462657f78c5cb2`** corrected only the import order; [run 38156336766](https://github.com/infocyph/Foundation/actions/runs/38156336766) **completed success**, with 4/4 PHPForge QA, 2/2 PHPStan, 2/2 benchmarks, four consumers, clean installation and release evidence.

**Source `8ba714f9194060f245098fa4ac233540f186e891`** removed premature `builder->development()->get(ConfigRepository::class)` from `AuthCoreRegistrar`, accepting the already-loaded application `ConfigRepository` during contribution. The new `tests/Feature/AuthPasswordPolicyGraphIntegrationTest.php` verifies configured password minimum/maximum length enforcement. [Run 38156739748](https://github.com/infocyph/Foundation/actions/runs/38156739748) **completed success** on that source: 4/4 full QA, 2/2 PHPStan, 2/2 benchmarks, four consumers, clean installation and release evidence.

**Acceptance boundary:** these are verified *incremental immutable-graph preparation*, **not** InterMix 11.1 adoption. Remaining: `ServiceProvider::application()` still resolves an interim development runtime while contributing; remaining auth/messaging/security/logging/notifications custom-binding checks; four runtime graph modes, native scope and cleanup lifetimes; native Webrick 6 router invoker; compiled graph/build/artifact trust. Composer remains **InterMix `^10.1.1` + Webrick `^5.4`** until their coordinated native cutover. **Batch 2 remains 1/8, total 7/37**, without release compatibility certification.

### Batch 2 auth policy normalization and obsolete-runtime ownership (2026-10-12)

- **`18b9dd3e2010833a6c786416d05ba5d109e53045`**: `AuthProductionGuard` and `EpicryptTokenPolicyResolver` now consume `ConfigRepository` directly rather than retaining `Application` during contribution; `AuthServiceProvider` supplies its already normalized configuration. Existing environment/secret/algorithm/production-guard conditions remain unchanged. `tests/Feature/AuthEpicryptPolicyCompositionTest.php` asserts HS512 key floor/issuer/audience/leeway/lifetime and rejects malformed JWT algorithm without a live container. This avoids linking two configuration policies to the current mutable-runtime boot sequence.
- [**QA run 38195209447**](https://github.com/infocyph/Foundation/actions/runs/38195209447) passed Pest, PHPStan, PHPCS, Rector, both benchmarks and four production consumers but failed Pint on `AuthProductionGuard` style only. `59f1dad2045390398fcc4e963aea14ff989b71dc` collapsed its trivial constructor; [run 38195506505](https://github.com/infocyph/Foundation/actions/runs/38195506505) still failed Pint because the configuration import was not alphabetically placed. **`78ea1b6a6f9a72b37959b3b256bcec3f38849534`** corrected the import ordering with no behavior changes.
- [**Source-head QA 38195791151**](https://github.com/infocyph/Foundation/actions/runs/38195791151) **completed success**: four full QA permutations (PHP 8.4 and 8.5 × lowest/stable), PHPStan 2/2, benchmarks 2/2, production consumer 4/4, clean install and release evidence all green. The security-report job remained skipped; not equivalent to security certification.
- **Native-graph deletion completed (verified):** `AbstractAuthRegistrar` has twelve unused protected methods (`accountProvider`, `accountStore`, `auditStore`, `clock`, `container`, `idGenerator`, `notifier`, `passwordHasher`, `passwordPolicy`, `passwordVerifier`, `service`, `singleton`) without any subclass call sites. They were deleted by `e65345f9a8bfc52152c53b02ec53a8e0ae282cee` (alongside 11 dead imports), eliminating both dormant `builder->development()` and `builder->bindFactory()` code paths. [Workflow 38196881116](https://github.com/infocyph/Foundation/actions/runs/38196881116) completed success. No compatibility shim was introduced. Remaining **live** `ServiceProvider::application()` is now confined to routing and HTTP provider contributions; auth registrar override-introspection, web/nonweb runtime finalization and immutable graph ABI must still be redesigned together.

**Acceptance scope:** Batch 2 criterion 1 remains open because the canonical InterMix 11.1 `build()` and Webrick 6 invoker/graph compilation have not been integrated; no dependency floor or acceptance checkbox is changed by policy-only preparation. **Batch 2 1/8, overall 7/37**.

### Batch 2 auth provider pre-build graph migration — verified (2026-10-12)

**Source `19983d92b741c7234f4424347ea43819a67a952e`** eliminates two live calls to `ServiceProvider::application()` from `AuthServiceProvider` and `AuthOtpServiceProvider`. These providers now construct read-only composition policy from the already normalized `FoundationBuildContext` config snapshot and compiled status, instead of resolving an interim development container. `AbstractAuthRegistrar`, its remaining concrete subclasses, and `AuthTokenRegistrar` take the typed `ConfigRepository` directly; all prior security-sensitive cache/OTP/JWT/OAuth/passkey/storage config accesses use the same normalized snapshot. This modifies 11 production files atomically; no legacy shim, mutable singleton or alternate security policy added.

**Regression `tests/Feature/AuthPrebuildGraphCompositionTest.php`** explicitly creates an auth-only graph with `FoundationGraph::compose()`, confirms that `Application` is not registered, then calls `AuthServiceProvider::contribute()` and checks the auth driver and password policy definitions. It would fail on the prior early `ServiceProvider::application()` call, so it positively proves pre-runtime auth contribution. Existing OTP, OAuth, password, replay and production security suites exercise the migrated registrars.

**Exact-head evidence:** [workflow 38197297737](https://github.com/infocyph/Foundation/actions/runs/38197297737) finished **success** at `19983d9`: four complete PHPForge QA permutations, PHPStan 2/2, benchmarks 2/2, production consumers 4/4, clean install and release evidence pass. Deletion-only checkpoint `e65345f` also passed [workflow 38196881116](https://github.com/infocyph/Foundation/actions/runs/38196881116). The independent release security report remains skipped and security certification is not inferred.

**Still open under Batch 2 criterion 1:** auth custom-definition introspection still uses InterMix 10 `definitions()`; routing/HTTP providers still resolve interim Application runtimes; web/CLI/worker/scheduler factories, native scope/cleanup, Webrick6 matcher and trusted compiled-generation ABI must be migrated together. Composer remains InterMix `^10.1.1` and Webrick `^5.4`. **Acceptance count remains Batch 2 1/8, overall 7/37** until a complete criterion passes.

### Batch 2 web graph and runtime identity preparation — verified (2026-10-12)

**Web/HTTP contribution:** `89c4fe230253e5a4367b27f9267457afa4856272` eliminated the remaining early `ServiceProvider::application()` and `builder->development()` calls in `RoutingServiceProvider` and `HttpServiceProvider`. Webrick middleware now borrows its container from the host-owned PSR interface rather than retaining Application. Declarative native-compatible construction recipes replace development closures capturing an in-flight Application. `WebrickRuntimeFactory` provides the needed `Registrar` and `Collection` from the actual router (no replacement matcher), while `HttpRuntimeFactory` builds existing Webrick `RouterKernel` and error-handler semantics. `tests/Feature/WebPrebuildProviderCompositionTest.php` checks both providers can contribute before any `Application` binding.

**First QA and type correction:** [run 38198170815](https://github.com/infocyph/Foundation/actions/runs/38198170815) detected five true PHPStan type errors when resolving logger/renderer/cache manager through PSR `get(): mixed`. `62bf9365ab66dacb8d3b8c5464c784ed71627596` inserted fail-closed interface/class verification, preserving middleware/HTTP security behavior; the cancelled interim run `38198324311` is **not acceptance evidence**. The subsequent Pint-only `ordered_class_elements` / ternary / blank-line corrections were committed as `171d727b209484bc9ef899985e4dc88bc8c32a6c` and `ee129cbbf257415df4723f0c4cc25f9a280d6339`.

**Exact evidence for web source:** [workflow 38198647179](https://github.com/infocyph/Foundation/actions/runs/38198647179) passed 4/4 full PHPForge QA, 2/2 PHPStan, both benchmarks, all four production consumer combinations, clean install and release evidence on `ee129cbbf`. This proves the *existing Webrick 5* runtime path remains tested; it **does not** adopt Webrick 6.

**No obsolete provider/runtime graph introspection:** `3a0ed2d1fc7bfad11f5e5e3c9ea66c6af134a485` removed `ServiceProvider::application()` after verifying all 17 service-provider implementations lacked callers, and changed `WebProductionGraph` / `NonWebProductionGraph` to unbind their development-only IDs idempotently without old `definitions()->has()` inspection. `tests/Unit/Container/ProductionRuntimeIdentityTest.php` asserts both identities are purged even on a repeated cleanup. `8d78047f5809e899b7eda4cefe56b960a4c5385d` fixed the PHPForge class brace layout. `ae889b73ee676f3536681c7b0206220fbe73887a` removed the final partial-builder `definitions()` checks from `DevelopmentRuntimeBindings`: it directly registers only the Foundation-owned development application/container values before user providers are contributed.

**Current source-head acceptance:** [workflow 38199450854](https://github.com/infocyph/Foundation/actions/runs/38199450854) **completed success**: 4/4 QA (PHP 8.4/8.5, lowest/stable), 2/2 PHPStan, 2/2 benchmarks, 4/4 production consumers, clean install and release evidence passed. Security report was skipped. No test exclusions, compiler fallback, algorithm duplication or quality-budget weakening.

**Coordinated native-major cutover still required (do not certify by source cleanup alone):**

| InterMix 11.1 / Webrick 6 contract | Remaining exact Foundation seam | Completion evidence |
| --- | --- | --- |
| Native `ContainerBuilder::autowire()/factory()/input()/build()`, `LifetimeEnum` instead of mutable `singleton/scoped/bind`, no `definitions()/registration()/options()` | `FoundationGraph`, remaining auth/messaging/security/notifications providers and registrars, `Application::create` | All providers and extension contributions accepted **before one immutable build** in web/CLI/worker/scheduler; no development-container access during configuration |
| Host-owned `RuntimeContainerInterface` invocation, `withinScope()` and `withinScopeContext()` | `ExecutionScope`, `WebrickRouterFactory` still uses the old `Invoker`, worker/CLI/scheduler and native path-scoped input seeds | Request/worker isolation, scope exit, cleanup and cancellation on native runtimes, with no hidden host creation |
| Webrick 6 native compiled/development router plus InterMix 11 graph/build/artifact/digest identities | `WebReleaseCompiler`, `WebReleaseRuntime`, `GeneratedRuntimeCompiler`, `GeneratedRuntime`, `WebRouteGraphEnricher`, `WebProductionGraph`, `GeneratedRuntimeMetadata` | Compile and trust-load native graph + route artifacts; matcher, config, generation and scopes match for compiled and dev in all modes |
| Published dependency and consumer migration | `composer.json` retains InterMix `^10.1.1` and Webrick `^5.4`; CI/README/module matrix still await simultaneous change | Raise both version floors in a **single tested** candidate after native code and source/consumer tests have been migrated; no cross-major shim |

**Count remains Batch 2 1/8 and overall 7/37.** The old APIs are not yet replaced everywhere and current green QA cannot stand in for new-major compatibility. Batches 3–6 and release status remain blocked; no merge/tag/publication.

### Batch 2 provider-first composition for all four native entry points — verified (2026-10-12)

**Source:** `0591d61efeb1ebb0c00bed3cd164016c97a7f13a` refactors three entry points: `Application::create()`, `WebGraphFactory::compose()` and `NonWebGraphFactory::compose()`. Each now runs `Bootstrapper::compose()` and contributes every configured/built-in provider **before** obtaining any `builder->development()` container. It then resolves the typed configuration from the completed contributions, creates Application, and registers Foundation-owned development-only identities as before. No provider fallback, cross-major adapter or host runtime leak was added.

**Regression:** `tests/Feature/ProviderBeforeRuntimeFinalizationTest.php` now verifies web, CLI, worker, scheduler graph composition and direct `Foundation::cli()` creation retain Application identity after the provider-first sequence. Earlier pre-build auth and routing/HTTP regressions independently prove services are contributed before an Application binding exists.

**Exact-source quality:** [workflow 38201207857](https://github.com/infocyph/Foundation/actions/runs/38201207857) completed **success** on `0591d61e`: 4/4 PHP 8.4/8.5 full QA, 2/2 PHPStan, 2/2 benchmarks, 4/4 production consumer installs, clean install and release-evidence jobs passed. Security report skipped; security and sustained host-throughput certification not inferred.

**Important remaining native ABI difference:** InterMix `11.1` **removes** `development()`, `singleton()/scoped()/bind()`, `options()`, mutable `definitions()` and the old `Invoker` integration in favor of native `build()`, lifetime-specific `autowire()/factory()`, `input()`, immutable definition graph and `RuntimeContainerInterface`. Webrick `6.0` requires that native InterMix `^11.1` runtime as its router invoker. `FoundationGraph`, built-in specialist providers/registrars, `ExecutionScope`, web and non-web graph factories, `GeneratedRuntimeCompiler/Runtime` and `WebReleaseCompiler/Runtime` **still require one coordinated API conversion**. The development runtime registry needs a native post-build/host-owned replacement, and all generated artifact build/graph/trust identities require the new ABI. Version floors stay **InterMix `^10.1.1` / Webrick `^5.4`** until this source migration and the exact-candidate QA can succeed together.

**Acceptance:** This is verified architectural progress, **not** Batch 2 signoff. Batch 2 **1/8** accepted; overall **7/37**. Do not mark any of criteria 1–7 complete on the strength of previous-major QA. No merge, tag or publication.

### Batch 2 implementation log — start of native-major adoption (2026-10-11)

**Order and acceptance:** complete Foundation-owned regression/formatting QA from Batch 1; then migrate InterMix graph construction across **all four application modes** and development/compiled artifacts as one coherent native contract. Subsequent native-major integration changes must use the package's published contract directly and preserve production-only optionality. Never compile or resolve a partial graph in the middle of provider contribution.

**Confirmed InterMix 11.1 contract and affected Foundation call sites:**

| Native 11.1 interface | Foundation current call sites | Required change / no compatibility fallback |
| --- | --- | --- |
| `ContainerBuilder::create()`, `factory(id, Closure|FactoryDefinition, LifetimeEnum, tags)`, `autowire()`, `value()`, `alias()`, `input()` | `FoundationGraph`, `Application::create()`, `Bootstrapper`, built-in and custom service providers/registrars | Compose explicit recipes, lifetimes and scoped seed identities **before** `build()`; replace `singleton`, `scoped`, `bind`, and `bindFactory` with native definitions. No mutable-container emulation. |
| `ContainerBuilder::build()`, `definitionGraph()`, `compile()`, `production()` | `Application`, `NonWebGraphFactory`, non-web release compiler and Webrick release compiler | Provider contributions cannot follow `build()`; compiling finalizes the same exact graph. No `development()` or pre-finalization runtime resolutions. |
| `RuntimeContainerInterface`, `ScopeContext` | `ExecutionScope`, worker/CLI/scheduler runtime and generated container binding | Borrow native runtime instances and scope contexts, preserve exact identity, reject stale/foreign scopes, and don't assume mutable `Container`/generated `ProductionContainer` subtypes. |
| `RuntimeFactoryDefinition`, `FactoryDefinition::construct/staticFactory` | Foundation services requiring runtime-only Webrick objects, live error handlers, route registrars, generated runtime recipes | Keep compile-safe data/recipes in the exportable graph; register live closures only for explicitly unsupported development operations. Remove builder inspection and early `development()` calls from provider helpers. |
| No `ContainerBuilder::definitions()`, no mutable `options()` | Path/Auth/Communication/Messaging/Logging and 12+ other providers, `FoundationGraph::createBuilder()` | Replace implicit live-definition inspection with deterministic composition-time ownership/topology and native `enableDebugTracing()`. Don't finalize `definitionGraph()` while contributions remain. |

**Historical UID 6.0 isolation attempt (superseded by coordinated-major upgrade):** A focused upgrade attempt set `infocyph/uid:^6.0` and reused `UidIntegrationTest` / `UidRuntimeBoundaryTest` coverage for native `Id::ulid()` and `Id::uuid7()`. Composer's release matrix **rejected this isolated upgrade**: installed `infocyph/omnibus:2.6` requires `infocyph/uid:^5.0`. Published Omnibus 3.0 requires UID 6.0 **and explicitly conflicts with CacheLayer below 4.0 and DBLayer below 6.0**. Thus `UID 6.0 + Omnibus 3.0 + CacheLayer 4.0 + DBLayer 6.0` is one coordinated native-major resolution and corresponding API migration; do not force an unsatisfiable dependency graph or add a compatibility fallback. The isolated UID-only change was reverted **at that time**. A later coordinated Composer update successfully selected **UID ^6.0, Omnibus ^3.0, CacheLayer ^4.0, and DBLayer ^6.0** together; consult the live package table, not this historical stage, for current floors. The retained UID runtime tests cover monotonic output, fork uniqueness, supplied IDs and worker/scheduler isolation; UID 6 Sonyflake/Randflake/decimal and optional GenerationContext remain specialist-owned.

**PHPForge complexity remediation:** `RuntimeCoreValidator::notifications()` now delegates channel-map validation to one cohesive policy helper while preserving first-error semantics; `CacheTopologyValidator::validateClusters()` delegates configured-node-store, shared-transport and stable-node-ID predicates without duplicating storage mechanics. Baseline was **34 method + 4 class** findings; intermediate analysis confirmed one method issue removed and reduced cluster validation from complexity 15 to 13; a further extraction targets the strict 12 budget. Final-head analysis must confirm the net counts before claiming closure.

**Do not infer full Batch 2 completion:** The **version floors** for CacheLayer 4, DBLayer 6, Epicrypt 3.2, Omnibus 3, OTP 7, Pathwise 4.2, ReqShield 3.3, TalkingBytes 2.3, and UID 6 were subsequently updated. InterMix 11.1/Webrick 6 are still on older floors, and **every upgraded specialist still requires complete applicable API/consumer acceptance**, provider/host lifecycle redesign where relevant, real-backend tests and compatibility evidence. `ModuleCatalog`, configured defaults, documentation and CI constraints must be updated together with each final native graph, without retaining old branches as compatibility aliases.

### QA remediation checkpoint — latest diagnosed failures

- **Pest and Rector:** all four PHP 8.4/8.5 lowest/stable combinations passed in the completed [38129825567](https://github.com/infocyph/Foundation/actions/runs/38129825567) test run; production consumers, clean install and both benchmark jobs passed. That run's red QA jobs were **Pint only**, specifically `CacheLayerFactory` brace position and `RuntimeCoreValidator` class-method separation. A subsequent commit uses a single-line constructor declaration with its body on the following line, plus the required method spacing.
- **PHPStan:** the 2026-10-11 analysis after the validated refactors reports **26 function cognitive-complexity findings, four class findings, and zero additional type diagnostics**, improved from 34 function findings. Strict thresholds are unchanged; the quality gate is still **red** until the remaining methods/classes are decomposed and verified.
- **Additional remediation committed for next-run verification:** focused OTP issuer/algorithm policy, typed module dependency and alias checks, reverse log tail buffer, compiled config provider validation, bounded session expiration scan, sanitized OAuth audit predicate, terminal numeric choice and completion option enumeration. These extracts keep all public behavior and error contracts. Do not mark QA green from passing functional tests alone.
- **Major migration sequencing:** UID 6 requires coordinated Omnibus 3/CacheLayer 4/DBLayer 6; InterMix 11.1 and Webrick 6 require native graph finalization and provider redesign before the version bump. Preserve a solvable `composer.json` until a complete coordinated migration can be tested.

### 2026-10-11 QA follow-up — 19 method / 3 class findings

- GitHub Actions [38130507296](https://github.com/infocyph/Foundation/actions/runs/38130507296) on `c166cbe` passed the functional Pest, Rector, and PHPCS gates for the inspected PHP 8.4 stable job, four production consumer configurations and clean install. QA jobs remained red **only due to Pint** on constructor/multiline brace placement and method grouping in `CacheLayerFactory`, `RuntimeProcessRegistry` and `CommandExecutionPolicy`. This commit fixes those exact locations, aligning multiline function braces with the verified PHPForge house style and sorting private methods.
- PHPStan reported **19 function complexity and three class complexity** findings, improved from the opening 34 function / four class findings; no other type diagnostics. Static analysis remains blocked. The native InterMix/Webrick/CacheLayer/DBLayer/Omnibus major migration and authentication/provider simplification remain required and unchecked.
- Existing regression suites, production-only installation, and native ownership constraints remain mandatory. Check only the newest source-head workflow before certifying any checklist or declaring QA green.

### Confirmed functional QA — source revision 079f6d75 (2026-10-11)

- [GitHub Actions 38130823344](https://github.com/infocyph/Foundation/actions/runs/38130823344) **passed all four PHPForge QA jobs** (PHP 8.4/8.5, lowest/stable), including Pest, Pint, PHPCS and Rector. All four production-consumer configurations, clean install, and both benchmark jobs passed. The prior source-level formatting failures in `CacheLayerFactory`, `CommandExecutionPolicy` and `RuntimeProcessRegistry` are resolved without silencing rules.
- **PHPStan remains red** on both PHP versions: exactly **19 function** and **three class** cognitive-complexity findings under the unchanged 12/80 engineering budgets; no other type/API diagnostics. The full workflow therefore fails. Do not describe the PR or Batch 2 as release-ready.
- The source revision tested is `079f6d75a00d09f6ee03b22ce869af8ec1ccea97`. This tracker-only update does not modify tested source. Unresolved native-major migrations, auth/DI scope lifecycle, real-service release certification and performance acceptance stay unchecked.

### Obsolete-file removal — native Epicrypt refresh-token boundary (2026-10-11)

The 4.0 runtime and `AuthOAuthRegistrar` already use Epicrypt's native `RefreshTokenManager` and `DBLayerEpicryptRefreshTokenStore`, with tested replay, rotation and audit semantics. The old parallel Foundation coordinator/record/issue/rotation/store-interface and `DBLayerOAuthRefreshTokenStore` are **not selected by the runtime** and exist only as a dead legacy mechanism. Removed all seven obsolete production PHP files, together with their unused OAuth fixture field/import/instantiation. Updated `FoundationArchitectureBoundaryTest` to require those files to be absent, not merely marked `@deprecated`. Retained Epicrypt OAuth refresh, concurrency and audit test suites and existing persisted-data migration schema; **schema/data removal is not authorized by class removal**.

**QA gate:** native OAuth refresh/rotation/replay/revocation tests plus full PHPForge supported matrix on the new commit. This one removal closes a clear legacy cluster, **not** the whole Batch 2 library/ABI audit; other suspected obsolete files require reachability and persisted-data evidence first.

### Native specialist adoption checkpoint — Pathwise 4.2 and Epicrypt 3.2 (2026-10-11)

- Updated **published Pathwise 4.2 and Epicrypt 3.2** version floors consistently in `composer.json`, `ModuleCatalog` and both configuration/module contract tests. These are separately published native improvements, not compatibility shims or unrelated feature development.
- Pathwise 4.2 retains 4.x APIs while hardening upload byte budgets, archive/path containment, scanner deadlines, and optional host-owned Runwire contexts. Epicrypt 3.2 retains 3.x APIs while tightening JOSE/JWKS public-unicast destinations, replay retention and streaming cancellation semantics. Foundation still owns app policy and config; native libraries own transport/crypto/storage/streaming internals.
- **CI acceptance pending:** require supported PHP 8.4/8.5 lowest/stable QA, complete real release dependency graph and relevant upload/range, OAuth/replay/JWKS regressions. Changed on this source SHA; previous jobs cannot certify it.
- Retained-binary auth/security data compatibility and changed destination policy require explicit migration/race test coverage in Batch 4. Remaining coupled major upgrades InterMix 11/Webrick 6, CacheLayer 4/DBLayer 6/Omnibus 3/OTP 7/UID 6, and ReqShield 3.3 remain **open**.

- **Obsolete WebAuthn sentinel deleted:** `src/Auth/Adapter/WebAuthn/WebAuthnRuntime.php` was an empty `@deprecated` compatibility placeholder explicitly stating OTP owns ceremonies, with no runtime registrations. The cold-resolution test now covers actual lazy auth services rather than an unused placeholder; the architecture test enforces its absence. No OTP ceremony/persisted data owner was removed. Native OTP 7 remains a coordinated CacheLayer 4 upgrade.

### Focused complexity regressions — package features, sanitizer pipelines, scheduler (2026-10-11)

- `ModuleCatalog::requestFeature()` delegates only the shared-package ambiguity decision to a typed private policy method; the same one-feature fast path, intersection semantics, and explicit-feature errors remain.
- `ValidatorFactory::sanitizerMap()` delegates normalized per-field pipelines to a cohesive filter, preserving scalar/callable and permitted step ordering and omission of empty or invalid definitions.
- `ScheduleManager::work()` delegates its bounded interrupted sleep loop without changing runtime token checks, heartbeat ordering, process registry ownership or primary error preservation.
- These are **source edits awaiting exact-head Pest/Pint/Rector/PHPStan QA**. Strict 12/80 complexity limits stay enabled and the native-major migration still blocks Batch 2 completion.

### Formatter QA — module and scheduler extraction (2026-10-11)

The four PHP 8.4/8.5 lowest/stable QA jobs on source `c31b6e8` passed Pest and Rector but failed Pint solely on `ModuleCatalog` method separation and `ScheduleManager` private method order/brace style. Corrected the exact source formatting, restored the original `@phpstan-impure` annotation to `interrupted()`, and kept scheduler polling/cancellation behavior unchanged. The full matrix must be checked again on the new SHA. PHPStan retains **16 method / 3 class** cognitive-complexity findings and no other diagnostics; these remain blocking under unchanged budgets.

### Prepared auth-grant and CLI parsing simplifications (2026-10-11)

- `GrantResolver::forPrincipal()` delegates an unchanged revoked/expiry/ability/resource predicate to a private eligibility check; preserves authorization semantics including wildcard and resource mismatch.
- `ParsedInput::consumeShortOptions()` delegates the existing short-option attached/following-token value handling, preserving token consumption, error text, unknown flags and grouped short options.
- These changes are separate from the specialist-major migration; require exact-head authorization and CLI parsing regression QA before checking their completion.

### 2026-10-11 exact-head QA — grant helper formatting and complexity

- [Workflow 38135271645](https://github.com/infocyph/Foundation/actions/runs/38135271645) on `15632476` passed production installation and all four core consumer combinations; its PHP 8.5 stable job passed Pest, Rector and PHPCS but Pint reported the missing method separation in `GrantResolver`. The source fix is committed here, without exceptions or rule changes.
- PHPStan counts improved to **14 function-level and three class-level complexity findings**, with zero unrelated type diagnostics. The analyzers remain red. Complete PHP 8.4/8.5 lowest/stable QA and both benchmarks must be checked on this latest commit.
- Native-core upgrade group and incomplete compatibility mappings remain unchanged. Obsolete files were removed only after verifying native Epicrypt/OTP owners and retained data migrations.

### Coordinated major native-dependency attempt (2026-10-11; QA pending)

- Set the **coordinated and Composer-compatible version group**: CacheLayer `^4.0`, DBLayer `^6.0`, Omnibus `^3.0`, UID `^6.0`, OTP `^7.0`, and ReqShield `^3.3`. The already adopted Pathwise `^4.2`, Epicrypt `^3.2`, TalkingBytes `^2.3`, and ArrayKit `^5.3.1` remain unchanged; Webrick `^5.4` and InterMix `^10.1.1` are explicitly still awaiting a separately coordinated runtime-graph migration.
- Updated **production constraints, development-only optional package constraints, ModuleCatalog module floors, and both dependency-contract test suites in one tree change**. Omnibus 3's UID6/DBLayer6/CacheLayer4 requirements are no longer contradictory; this does **not** certify unchanged PHP integration APIs.
- **Required QA before acceptance:** Composer lowest/stable resolution on PHP 8.4 and 8.5, production installation with only direct requirements, real service tests, native cluster configuration including transport identity and history, DBLayer6 cache visibility and pooling, Omnibus settlement/cancellation, OTP state migration and ReqShield DB bridge. Any failing contracts must be fixed natively without resurrecting 5.x/3.x fallback paths. Do not mark the Batch 2 native API items complete until verified.

### CacheLayer 4 native contract migration — cluster history identity (2026-10-11; pending QA)

- `CacheLayerFactory::cluster()` passes a **required explicitly configured** durable `transport_identity` to native `ClusterCacheConfig`; every history reset needs a fresh, never-used value. The value is **not inferred** from cluster, node ID, or transport name because that could silently reuse a stale history generation.
- `CacheTopologyValidator` rejects missing/invalid transport identities with the same format as native `ClusterInput::transportIdentity()`. Publishable `resources/config/cache.php` includes the key and an explicit history-reset comment; `ClusterCacheIntegrationTest` covers valid and missing identity.
- CacheLayer 4 `NodeCacheConfig` takes `options: CacheOptions` rather than deleted `failOpen:`. Foundation applies existing merged config through `cacheOptions($store)`, with native safe object/Closure deserialization defaults **false** rather than implicitly opting back into unsafe payloads.
- **Pending QA:** SQLite node/cluster/outbox/race tests, exact-head PHPStan and PHPForge formatting, and real shared transport history and invalidation tests. CacheLayer 4 stored signed envelopes are not interchangeable with 3.x; drain or invalidate previously signed records before 4.0 cutover.

### Coordinated-major Composer acceptance — consumer workflow defect

- The first coordinated-major attempt reached [workflow 38137321328](https://github.com/infocyph/Foundation/actions/runs/38137321328), whose optional `--no-dev` consumers failed Composer resolution before runtime because `.github/workflows/security-standards.yml` **still explicitly constrained DBLayer `^5.1`**. With Foundation's new `^6.0` optional integration floor this is a real incompatible test fixture, not a source API regression.
- Updated that CI consumer to DBLayer `^6.0` while preserving both PHP 8.4/8.5 and lowest/stable permutations and the core-only minimal topology. Retain the explicit Composer floor in tests and `ModuleCatalog`. Recheck exact-head jobs before closing any acceptance item.

- **Native cluster-identity QA cleanup:** clustered node identity and CacheLayer4 transport-history generation checks are isolated in one typed validation helper, preserving both exact errors and their order while avoiding a new PHPForge `validateClusters()` complexity violation. Acceptance awaits the next PHP 8.4/8.5 analysis.

### Native majors: module lifecycle regression reconciliation (2026-10-11)

- After the test-parser fix, PHP 8.5 lowest/stable matrix produced **484 passing / 16 failing Pest cases** on source `4a9707cb`. Fourteen failures clustered around stale mock Composer root constraints, module install command expectations, and old `ModuleCatalog` version assertions, which still referenced DBLayer 5.1 / Omnibus 2.6 / OTP 6.1 / CacheLayer 3.4. Updated the three affected module test suites to use published DBLayer 6 / Omnibus 3 / OTP 7 / CacheLayer 4 floors.
- Preserved **intentional negative tests** using stale DBLayer `^5.0`, as those still establish direct-constraint rejection under the new `^6.0` floor. The fake composer executable and real activation/schema behavior remain unchanged.
- Two **non-version** failures remain independent: the CacheLayer 4 node/cluster write-read returns null, and DBLayer/InterMix persistent execution memoization yields an unexpected extra call. These are native behavior acceptance blockers and cannot be fixed by changing version expectations.

### CacheLayer 4 recovery-policy test correction (2026-10-11)

- CacheLayer 4's native `ClusterRecoveryManager::recoverIfRequired()` **clears node cache on first untrusted or reset invalidation history** before binding the durable `transport_identity`. The previous cluster integration test wrote to the node cache *before* creating `ClusterRuntime`, so the first native cursor recovery correctly invalidated it. Initialize the cluster before writing cache data in the roundtrip test.
- Added a separate regression verifying that *pre-initialization writes do not survive a new cursor/history generation*. This preserves native fail-closed recovery; no bypass, no fallback to CacheLayer 3 semantics.
- The persistent execution memoizer result remains separately unexplained; no change to its expectations until its host/runtime ownership is identified.

### CacheLayer 4 memoization regression fix

- CacheLayer 4 `Memoizer` fingerprints Closure **instance identity**. The persistent-runtime test previously allocated a new Closure on each execution but expected a cache hit. `FoundationPersistentMemoCalls` now retains the callable across executions; the test continues to require the original one miss/one hit behavior and separate once-memoization, principal, session and transaction isolation.
- This is a test adaptation to a published native contract, not a production workaround. The exact-head matrix must verify it.

### Additional complexity cleanup, pending QA

- `ConfigLoader::loadCacheManifest()` delegates native sharded-cache namespace validation to one typed private method; invalid content still fails closed, and duplicate namespace identity and order are unchanged.
- `ModuleDependencyResolver::resolve()` delegates per-feature dependency resolution to a typed private method without altering module blocker order or capability readiness. Both edits target PHPForge's 12-point method complexity gate.
- New syntax, Rector, PHPStan, and feature tests are required before treating these as complete.

### PHPStan regression from manifest extraction (2026-10-11)

- Latest analysis on `5264626f` reduced method-complexity findings to **13** (classes remain three), but exposed an unintended `missingType.iterableValue` diagnostic because the `writeManifest()` PHPDoc moved to the new `validatedNamespaces()` method. Restored the typed payload contract at its original method.
- Separated the format/schema header test from `loadCacheManifest()` to bring that method under the 12-point PHPForge budget; schema fingerprint and invalid-manifest fail-closed behavior are unchanged. Check next exact SHA before claiming acceptance.

### PHPForge DB migration preview refactor — pending QA

- After the verified [workflow 38139110800](https://github.com/infocyph/Foundation/actions/runs/38139110800), exact-head PHP 8.4/8.5 lowest/stable functional QA, both benchmarks, four production consumers and clean install pass; PHPStan still blocks the full gate on **12 method / 3 class cognitive-complexity findings** with no unrelated type diagnostics.
- `DatabaseSystemCommand::migrate()` now delegates DBLayer6 `MigrationRunner::pretend()` presentation to a typed `showMigrationPreview()` handler, keeping CLI text/JSON output, binding redaction behavior, error semantics and unchanged migration execution.
- The new source/plan SHA needs its own quality and DB command test acceptance. No PHPForge rules are disabled.

### Batch 2 QA remediation — PHPForge and native boundaries (2026-10-11)

- Verified [workflow 38139493508](https://github.com/infocyph/Foundation/actions/runs/38139493508) at `bd027243`: four Pest/Pint/Rector QA jobs run successfully except for **Pint on `DatabaseSystemCommand`** (one `ordered_class_elements` / brace-layout issue). All four production consumers, clean install, and both benchmarks passed. PHPStan reports **11 method and three class complexity violations**, with no unrelated type errors; strict 12/80 budgets are unchanged.
- **Formatter fix in this commit:** moved `showMigrationPreview()` to its correct private-method ordering between `showDatabase()` and `showTable()`, retaining the DBLayer 6 native `MigrationRunner::pretend()` output and binding behavior. Requires exact-head Pint confirmation.
- **OAuth schema migration:** split `AuthOAuthEpicryptProtocolSchema::down()` into index-drop and column-drop operations. The same presence checks, deletion order, checkpoint, and persisted-data effects remain. Does not remove retained migration history.
- **Host-owned lifecycle:** `GrpcInboundWorker` now builds its TalkingBytes cancellation/heartbeat closure in a dedicated helper; the worker loop continues to borrow `WorkerRuntime` and never starts or closes a host runtime.
- **Console:** separated inline command execution/status/error recording from supervised `CommandExecutionCoordinator::run()`, preserving lock lifecycle and primary exceptions. No CLI ABI or output change.
- **Acceptance:** next exact-commit PHP 8.4/8.5 lowest/stable Pest/Pint/PHPCS/Rector, PHPStan and service integration jobs must validate these changes before any Batch 2 checklist is closed. InterMix 11.1/Webrick 6 runtime-graph redesign and the remaining class complexity remain open.

### Batch 2 OAuth signing configuration ownership and latest QA (2026-10-11)

- [GitHub Actions 38141265917](https://github.com/infocyph/Foundation/actions/runs/38141265917) at `9d3a44a`: Pest, Rector and PHPCS passed in the inspected QA runs; one Pint style issue in the extracted `CommandExecutionCoordinator::runInline()` requires a blank line before `try`. All four production consumer configurations and clean installation passed. PHPStan fell to **eight function-level and three class-level complexity findings**, without unrelated type errors.
- **OAuth validation separation:** isolated framework-owned signing-key locators, active key selection and key validity windows into `OAuthSigningConfigValidator`, leaving general OAuth grants/routes/database/rate policies in `OAuthConfigValidator`. The ordering and error messages of all produced `ConfigIssue` values are preserved, and native Epicrypt continues to own JOSE signing/token mechanics. This is intentional framework policy, not a compatibility wrapper.
- **Acceptance:** Run OAuth configuration/security and actual PHPForge PHP 8.4/8.5 lowest/stable tests on this new source commit; the signing split and command formatting remain **unverified** until that run. Remaining native InterMix 11.1/Webrick 6 graph migration and other complete Batch 2 criteria stay open.

### Native OAuth policy complexity continuation (staged, pending QA)

- General OAuth endpoint rate-limit checks now delegate each configured endpoint's required max/window shape to one Foundation-owned policy method; unsupported policy shapes and per-field errors remain ordered and unchanged.
- `OAuthManager::openIdInteraction()` now delegates execution-local principal/authentication metadata normalization to one typed `OpenIdInteractionState` factory method. OpenID protocol evaluation, clock default, consent decision, and all interactive errors remain native Epicrypt policy and unchanged.
- These code edits are **staged**, not accepted, until they are pushed and source-head PHPForge/CI runs validate them. Do not count them toward certified complexity improvement ahead of CI.

### OAuth signing split — QA corrections on staged follow-up (2026-10-11)

- [Workflow 38141784843](https://github.com/infocyph/Foundation/actions/runs/38141784843) passed Pest, PHPCS, all four production consumers, clean install and both benchmarks, while Rector required the PHP 8.4 `new Class(...)->method()` syntax and Pint required closing/spacing repairs in the extracted OAuth validators. These precise issues are corrected here without disabling tooling.
- PHPStan reported **seven method and two class complexity findings**, with no unrelated type diagnostics. Endpoint rate-policy and OpenID principal-state helper extractions are committed on this source but require exact-head QA before additional reduction claims.
- Full InterMix 11.1/Webrick 6 API and host graph migration remains unchecked; production release readiness is not established by the OAuth quality work alone.

### Epicrypt adapter simplification — 2026-10-11 (pending exact-head QA)

- OAuth scope-audience mapping delegates configured audience list normalization to one private helper, retaining first-seen order, de-duplication and invalid-list behavior before native Epicrypt scope validation.
- OAuth key resolver delegates the same environment locator validation, minimum 32-byte root, purpose derivation and key-ring entry metadata to a dedicated typed method; Epicrypt still owns cryptography and replay/rotation primitives.
- Both helpers require PHPStan, Pint/Rector and security integration verification on the source commit. Do not mark the native InterMix/Webrick or full Batch 2 acceptance criteria complete.

### Native MFA crypto/state adapter cleanup — pending exact-head QA

- The Foundation MFA secret adapter now isolates per-field Epicrypt decryption and legacy-marker handling, preserving the exact marker rules, key-ring fallback reporting and no-plaintext policy. No new encryption/key material mechanism was created.
- OCRA replay/counter acceptance remains native OTP verification plus Foundation's compare-and-swap factor persistence. The post-verification counter gate is now one explicit predicate with the same replay-failure reason and no counter advancement when a native result is not matched.
- These refactors target remaining strict PHPForge method-complexity findings; source-head MFA/OTP replay, migration and error-precedence tests must pass before acceptance. The broader InterMix 11/Webrick 6 migration remains open.

### Native Webrick compiled-release trust check extraction — pending QA

- `WebReleaseRuntime::boot()` now delegates the exact preexisting release environment and configuration-fingerprint trust check to `assertManifestIdentity()`. The same manifest is loaded through the same trusted path validation before the same exception condition is evaluated; no Webrick native matcher, route scope, runtime host, generation or fallback behavior changed.
- This targets the remaining 15-point bootstrap function complexity without relaxing PHPForge budgets. Test compiled/development web routing and fail-closed manifest tampering on the final source SHA. It is **not** the Webrick 6 / InterMix 11.1 runtime migration, which remains open.

### Batch 2 — adopt latest container and specialist APIs

- [x] Complete InterMix graph composition before finalization for every mode;
  migrate native lifetimes, inputs, scopes, compilation, and custom providers.
  **Accepted on source `4ddaf18a`:** all compiled/development web, CLI, worker and scheduler scope/seed, provider, prevalidated identity and release-isolation contracts passed full PHPForge QA 4/4, including source-free ABI 3 regression tests.
- [x] Adopt Webrick 6, CacheLayer 4, and remaining latest module APIs, including
  cluster identity/history policy and transaction/outbox ownership.
  **Accepted on source `4ddaf18a`:** compiled Webrick 6.0.3 release modes, CacheLayer 4 native cluster/history and PDO outbox, selected module integration, session contention and required CI service backends passed. Optional unselected host/transport expansion remains separately tracked under #3/Batch 3.
- [x] Complete the per-library compatibility record for the whole applicable
  native surface and all Foundation consumers; implement every required contract
  without retaining obsolete wrappers or limiting changes to a small diff.
  **Accepted at `df2c349f` (QA #38430686929):** all current selected native-module/consumer APIs have concrete owner and test evidence; Omnibus 3.0 portable core platform hard blockers corrected; unselected optional hosts/transports and persisted-state security migrations explicitly remain Batch 3/4/release gates, not untested implied coverage.
- [x] Redesign built-in framework services and remove duplicate native mechanisms
  according to the approved ownership map, including auth and session composition.
  **Accepted on `fc744609` / QA #38431864047:** native InterMix/Webrick/ArrayKit/CacheLayer/DBLayer ownership preserved; native auth/session and module providers audited at mechanism level; duplicate DBLayer forwarding factory removed and locked by QA. Exhaustive individual symbol dispositions remain #5.
- [x] Account for every retained/added production class, method and function by
  required framework responsibility or plugin boundary; remove unnecessary
  symbols and duplicated/legacy paths rather than adding compatibility layers.
- [x] Align Composer, `ModuleCatalog`, dependency guards, publishable configuration,
  CI consumer constraints, examples, and test expectations with the same floors.
  **Revalidated on source `4ddaf18a`:** [workflow 38418473098](https://github.com/infocyph/Foundation/actions/runs/38418473098) passed 4/4 consumers, full PHPForge QA, analysis, benchmarks, clean install and release evidence against the coordinated published floors. Historical initial acceptance: `38db3a51`.
- [x] Fix current Rector/complexity findings and all latest-candidate API failures.
  **Accepted on source `4ddaf18a`:** PHPForge 8.4/8.5 × stable/lowest 4/4 passed Pint, Rector, Pest, PHPCS; PHPStan 2/2 and final release evidence passed, without disabling rules.
- [x] Provision MySQL, PostgreSQL, Redis, Valkey, and Memcached for required tests;
  verify current session contention scenarios rather than skipping them.

### Batch 3 — accept and forward host-owned Runwire contexts

- [x] Implement complete scoped Foundation context propagation through native
  bridges, with only the required framework/plugin surface.
- [x] Forward exact runtime/request/scope through relevant native module APIs,
  direct calls, intermediary chains, and child tasks.
- [x] Verify capability fallback and absence of Runwire without implicit runtime
  creation; preserve generation/process/request boundaries and host ownership.
- [x] Cover borrowed versus owned DI cleanup, throwing callbacks/reporters,
  cancellation, nested contexts, deferred output, and concurrent state isolation.

### Batch 4 — verify security and persistence composition

- [x] Authentication/authorization: tenant/resource isolation, permission caches,
  session fixation/regeneration/expiry, CSRF, cookie policy, account suspension,
  rate limits, and atomic lock/counter behavior under concurrency.
- [x] Full built-in flows: login/logout, browser/auth-session coordination,
  account/device revocation, idle/recent-auth/step-up semantics, remember-me,
  verification, passwordless/reset/change, recovery, impersonation, gates/policies
  and delegation; verify one principal and one owner per native mechanism.
- [x] OAuth/tokens: approved redirects, PKCE, code consumption, refresh rotation
  and reuse detection, revocation, bounded retries, and auditing with real database
  races. Preserve Epicrypt ownership and Foundation policy/persistence mapping.
- [x] MFA/passkeys: OTP terminal state/replay prevention, recovery-code consumption,
  encrypted secret handling, credential counters, origin/RP validation, and leases.
- [x] Persistence/messaging: real-backend contention, transaction rollback/outbox
  atomicity, duplicate delivery, webhook replay, scheduler leadership, cancellation,
  reconnects, and generation reset without leaked transactions or locks.
- [x] HTTP/filesystem/communications: trusted proxies, redirects, upload/path
  containment, executable configuration/artifact trust, ranges/stream failures,
  TLS/SSRF policy boundaries, retries, request body limits, and redacted diagnostics.
- [x] Persistent lifecycle: per-request state reset across all modes, concurrent
  requests/tasks, failure cleanup, dependency replacement, and bounded resources.

Extend existing meaningful feature tests. Use real services where in-memory
tests cannot establish atomicity or isolation; keep pure definition/config checks
portable. Add tests for changed contracts and reproduced defects, not mirrored
implementation details.

### 2026-10-14 — Batch 5 executable migration example acceptance (CI pending)

The release-evidence workflow now **executes** both checked-in Foundation 4 migration samples on PHP 8.5 with resolved optional development packages: core-only CLI and explicitly selected host-passed Runwire worker. These run under an actual Composer installation after release constraints checks and fail CI if bootstrap/scoped DI contracts drift. This verifies sample executability rather than assuming it from a doc code fence. It does not replace the separate `--no-dev` production-consumer and Infbyte tests. **CI pending; acceptance unchanged.**

### 2026-10-14 — Batch 5 auth retained-data snapshot / restore rehearsal (source pending QA)

New `Foundation4RetainedDataRestoreTest` exercises a **file-backed** SQLite auth schema with retained password digest, active auth session, refresh-family identifier, MFA protected-field metadata and passkey counter. It snapshots DB bytes with SHA-256, runs Foundation 4 additive passkey/OAuth revisions using native DBLayer MigrationRunner, writes a candidate-only account, restores original bytes, then opens a fresh DB connection to verify all retained state and absence of new OAuth tables/account. **This is a synthetic 2.1.1-shaped schema snapshot, not an actual pre-upgrade production backup nor a queue/blob restore.** The remaining operator backup/restore rehearsal is explicit in the retained-data runbook. CI pending; no Batch 5 gate accepted (0/4, overall 25/37).

### 2026-10-14 — Batch 5 no-dev full topology and Infbyte candidate rehearsal (CI pending)

Extended the **four PHP 8.4/8.5 × stable/lowest** production-consumer matrix: besides core-only and DBLayer-only, a third isolated `--no-dev` project installs all published specialist majors and optional Runwire, then checks native owner symbols and an explicit host-passed Runwire worker execution. A fourth CI project is a fresh temporary clone of real `infocyph/Infbyte` with a local **candidate path repository override** replacing its published `^3.0.1` Composer constraint; it checks the skeleton CLI and trusted bootstrap without changing Infbyte main. Both reject development PHPForge leakage. **This is an initial candidate smoke, not a complete compiled Infbyte deployment or a restored 2.1.1 database. CI pending, Batch 5 0/4 and total 25/37.**

### 2026-10-14 — Batch 5 migration inventory and examples (source pending QA)

Compared source trees of published 2.1.1 (`f6749ebe0aa1f93c6b3586e8cb63ecc1f552d98b`) and current branch. `docs/foundation-4-api-map.md` enumerates **all 28 removed PHP source paths** and migration destinations, plus changed provider, DI, route cache, module, configuration, OAuth and passed-instance Runwire contracts. `docs/foundation-4-retained-data-rehearsal.md` explicitly distinguishes backup restoration from code rollback, inventories eight retained-data families and cites existing test evidence without asserting a real 2.1.1 backup has been restored. Both guides are linked from the migration guide and root/docs navigation; runtime-hosting documentation no longer claims Batch 3 is unfinished. Added runnable CLI and optional Runwire worker source examples. **CI pending; Batch 5 remains 0/4, overall 25/37.** An actual Infbyte application rehearsal is not done: its published root constraint currently points to Foundation `^3.0.1`.

### Batch 5 — direct migration and consumer rehearsal

- [ ] Write current Foundation 4 release/migration guidance and runnable examples;
  update documentation navigation and runtime claims together.
- [ ] Map every changed/removed 2.1.1 API and provider/configuration contract to its
  replacement. Explain dependency majors, compiled graph regeneration, and the
  passed-instance Runwire API. Rebuild generated artifacts for the accepted ABI.
- [ ] Rehearse retained password hashes, sessions/tokens, refresh families, MFA
  secrets/recovery codes, passkey credentials, queue payloads, and durable outbox
  data. Use additive migrations where possible; test backup restoration and
  explicit downgrade limits. Code rollback does not establish data rollback.
- [ ] Exercise production `--no-dev` consumers with both core-only and complete
  applicable module topologies, explicit Runwire hosts, and Infbyte against the
  candidate source and published dependencies. Adapt the skeleton to the approved breaking surface while
  preserving the Foundation-framework/Infbyte-application responsibility boundary.

### Batch 6 — certify one immutable release candidate

- [ ] Run PHPForge doctor/config diagnostics, then review necessary `ic:process`
  changes sequentially; run full `ic:tests` and required release checks without
  suppressions, missing-service exemptions, or skipped evidence.
- [ ] Verify supported PHP 8.4/8.5, lowest/latest dependencies, compiled/development
  graphs, production consumers, and required service matrices on the final SHA.
  Record toolchain/workflow references and the resolved dependency manifest.
- [ ] Close every required compatibility-record entry and verify no extra
  production surface or duplicate mechanism remains. Any unresolved integration
  contract blocks the 100% compatibility claim and the release.
- [ ] Establish comparable baseline/candidate sustained host measurements with
  valid response checks, repeated runs and medians, cold/warm routes, realistic
  authentication/database/cache workloads, concurrency 1/10/50, and one/four
  workers where the certified host supports them. Use stable production-equivalent
  infrastructure and keep the default maximum median successful-RPM regression
  at 2%; explain measured variance rather than silently changing the budget.
- [ ] Measure CPU, live process-tree RSS during load, p50/p95/p99 latency, errors,
  cache/query behavior, worker generation, and cleanup. Start measurement after
  readiness; do not substitute final-process RSS or a microbenchmark for host RPM.
- [ ] Certify the ordinary FPM path and a native Runwire 2.1.1 persistent host;
  include a bounded representative persistent-host soak with reset/cancellation/
  recycle checks. Claim other hosts only when their actual jobs pass. Existing
  execution-loop smoke tests alone do not certify a native server.
- [ ] Confirm all required jobs and evidence succeed on the exact final source
  and dependency set. Any source/dependency change invalidates affected evidence.
- [ ] Prepare reviewed 4.0 release notes/artifact and verify publication metadata.
  Keep implementation, CI readiness, merge, tag, and publication status separate.

## Completion boundary

Complete the required framework redesign, native-library adoption, removal of
duplication/unnecessary surface, and all compatibility/security/performance
gates. Do not add unrelated features, generic frameworks, wrappers, or speculative
classes/methods/functions beyond those responsibilities. Completeness is measured
by the accepted contracts and evidence, not patch size or feature count.
