# Foundation 3 — Unified Runtime Development Plan

**Status:** Canonical implementation plan  
**Foundation target:** 3.x  
**Active branch:** `foundation-3/close-26.6`  
**Priority:** correctness → security/persisted compatibility → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This file is the single current source of truth for Foundation 3 runtime development. Completed historical passes are intentionally condensed; open passes retain ownership, implementation, security/correctness, performance and completion gates. If a specialist Infocyph package already owns a generic mechanism, Foundation consumes it rather than creating a Foundation-only substitute.

---

## 1. Architectural invariants

Foundation has four runtime paths: `web`, `cli`, `worker`, and `scheduler`. They share one composition source, while every independently active runtime uses a fresh InterMix builder/generated artifact. Webrick remains the sole web HTTP runtime/output owner.

Foundation owns application configuration, capability selection, provider/composition policy, application persistence policy, runtime orchestration, release-generation/activation, diagnostics, and application-facing adaptation.

Lower libraries own their specialist mechanics:

- **InterMix:** DI graph, lifetimes, scopes, generated containers, execution isolation.
- **Webrick:** HTTP route/runtime/request/middleware/response mechanics.
- **Runwire:** generic process execution/supervision, worker-process lifecycle, event loop, listener/network/connection mechanics, native HTTP wire transport, runtime-driver adaptation, signals/wait/reap and OS-process capability primitives.
- **DBLayer:** connections, leases/pools, queries, repositories, migrations, DB transaction/cache mechanics.
- **CacheLayer:** cache semantics, locks, atomic primitives, counters, backend coordination.
- **OTP:** OTP/AOTP/GridOTP/MobileOTP/Passkey mechanics and OTP-owned replay/challenge/recovery state semantics.
- **Pathwise:** storage contexts/adapters, uploads/downloads, safe filesystem mechanics.
- **ReqShield:** validation/sanitization/schema/rule execution.
- **Omnibus:** messaging/events/queues/workers/workflows.
- **TalkingBytes:** HTTP/email/webhook/gRPC communication mechanics.
- **Epicrypt:** cryptography, key generation/derivation/rotation, protection, passwords, JOSE/JWK/JWKS, generic signed-token/key-readiness mechanics, and the transport-neutral OAuth 2.1/OIDC/PAT protocol core with authoritative auth-state contracts.

Foundation must not add a second DI runtime, HTTP application runtime, generic process/server runtime, DB pool/query builder, validation engine, messaging runtime, storage engine, OTP/WebAuthn protocol runtime, cryptographic implementation, or OAuth/OIDC/PAT protocol implementation above the specialist package that already owns it. Runwire owns generic process/server/runtime mechanics; Webrick remains the HTTP application-semantics owner.

Stable execution scopes remain:

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

Execution/request/job/message IDs are scope seeds/correlation values, never synthesized scope names.

---

## 2. Completed runtime foundation

Phases 0–9 remain complete and must not regress:

- builder-first web/CLI/worker/scheduler composition;
- generated InterMix runtime model;
- compiled Webrick production runtime;
- execution-local principal/session/database bookkeeping;
- stable semantic scopes and Fiber-safe cleanup;
- route-first graph enrichment and coordinated web compile;
- frozen routing/URL registries;
- Webrick-only native response output;
- safe persistent reuse of generated non-web runtimes;
- immutable unified release generations with atomic activation/rollback;
- bounded persistent worker/scheduler state;
- correctness/static-analysis/performance acceptance from the completed runtime phases.

Phase 10 remains the final aggregate release-readiness pass after every open lower-library integration closes.

---

## 3. Lower-library tracker

| Point | Library | Foundation floor / target | Status |
| --- | --- | --- | --- |
| 26.1 | ArrayKit | `^5.2` | **complete** |
| 26.2 | UID | `^5.0` | **complete** |
| 26.3 | CacheLayer | `^3.4` | **complete** |
| 26.4 | OTP / Passkey | `^6.1` | core complete; Epicrypt-backed security acceptance **in progress** |
| 26.5 | Pathwise | `^4.0` | implementation complete; normal-package/final QA acceptance **in progress** |
| 26.6 | DBLayer | `^5.1` | **complete** |
| 26.7 | ReqShield | `^3.1` | open/deferred |
| 26.8 | Omnibus | `^2.5` | open/deferred |
| 26.9 | TalkingBytes | `^2.0` | open/deferred |
| 26.10 | Epicrypt | `^3.0` | **IN PROGRESS** |
| 26.11 | standalone WebAuthn specialist pass | OTP 6.1 Passkey | **closed/subsumed** |
| 26.12 | Runwire native process/server runtime | `^1.0` | **planned / Foundation 3 launch requirement** |

Current Foundation development graph intentionally contains:

```text
infocyph/epicrypt ^3.0
infocyph/otp ^6.1
infocyph/pathwise ^4.0
```

Epicrypt `3.0` was released on **2026-09-10**. Tag `3.0` resolves to commit `e11bb287900b2590954ef0c0ebba649bc5bf0793`. Its production requirements contain neither Pathwise nor OTP; Pathwise is development-only in Epicrypt. The old Epicrypt-2 → Pathwise-3 dependency conflict is therefore gone.

---

## 4. Current execution order

1. Finish 26.10.2/26.10.3 crypto-wrapper/key-domain consolidation and exact-head QA.
2. Close the remaining 26.5 normal-package/filesystem acceptance.
3. Close the Epicrypt-dependent remainder of 26.4 OTP/Passkey security/concurrency acceptance.
4. Execute 26.10.4 released Epicrypt OAuth/OIDC/PAT protocol-core adoption.
5. Execute 26.10.5 security/compatibility/protocol tests and 26.10.6 performance attribution.
6. Return to 26.7 ReqShield → 26.8 Omnibus → 26.9 TalkingBytes.
7. Execute 26.12 Runwire 1.0 native/runtime-driver integration and close its launch acceptance.
8. Run aggregate Phase 10 / Foundation release-readiness gates.

Do not reopen finalized lower-library architecture merely to make Foundation integration easier.

---

# 26. Subsequent lower-library utilization passes

## 26.1 ArrayKit 5.2 — complete

Foundation delegates generic env/config parsing, raw environment access, layered/lazy file configuration, dot notation and merge mechanics to ArrayKit. Foundation retains source order, host protection, hydration, release-artifact policy and application validation.

**Status:** [X] complete.

---

## 26.2 UID 5.0 — complete

Foundation uses UID monotonic ULID as the generated non-web correlation fallback while preserving supplied authoritative IDs. Release identities/security randomness remain with their owning mechanisms.

**Status:** [X] complete.

---

## 26.3 CacheLayer 3.4 — complete

Foundation consumes CacheLayer locks and true atomic operations (`setIfAbsent`, `getAndDelete`, CAS, counters) directly and does not emulate cache atomicity above the package.

**Status:** [X] complete.

---

## 26.4 OTP 6.1 + Passkey/WebAuthn — core complete; final security acceptance in progress

### Ownership

OTP owns TOTP/HOTP/OCRA/AOTP/GridOTP/MobileOTP mechanics, provisioning, OTP replay/challenge semantics, recovery-code mechanics/contracts, secret-rotation primitives, and Passkey/WebAuthn ceremony validation/state. Foundation owns factor/account policy, capability activation, durable application persistence/CAS, principal mapping, secret-at-rest policy, key lifecycle selection, audit/error mapping, and application runtime composition.

### Implemented

- [X] Foundation OTP floor is `^6.1`.
- [X] OTP is the sole lower-layer MFA/Passkey mechanics boundary.
- [X] Foundation passkey routes through OTP `Passkey`; direct ceremony duplication is removed.
- [X] AOTP/GridOTP integration exists; MobileOTP is explicit legacy compatibility.
- [X] selected stateful modes fail closed without required CacheLayer authentication state.
- [X] HOTP/counter-OCRA durable transitions use authoritative Foundation CAS.
- [X] passkey credential persistence uses revision-aware replacement.
- [X] sensitive OTP/private-key-shaped enrollment diagnostics are redacted.
- [X] Recovery-code HMAC has an independent lifecycle and no longer derives from the generic token-signing secret.
- [X] Recovery-code key material is resolved at runtime from `AUTH_OTP_RECOVERY_HMAC_KEY` (or an explicitly selected environment locator) and domain-separated with Epicrypt `KeyDeriver` under `foundation.auth.recovery-hmac.v1`.
- [X] Durable DB-backed OTP symmetric material is protected at the persistence boundary through Epicrypt `StringProtector` under `foundation.auth.mfa-secret.v1`.
- [X] MFA protection uses an explicit Epicrypt `KeyRing`: exactly one active write key, bounded fallback read keys, disabled/retired keys ineligible for read/write.
- [X] OTP `secret` and legacy MobileOTP `pin` are selectively protected; AOTP public-key material and unrelated metadata are not encrypted as secrets.
- [X] Protected MFA values bind account/factor/type/field through authenticated data, preventing ciphertext relocation between factors/fields.
- [X] MFA encryption/decryption preserves `MfaFactor` revision; DB CAS remains revision-authoritative rather than ciphertext-authoritative.
- [X] legacy plaintext reading is disabled by default and can be enabled only by explicit `auth.otp.secret_protection.allow_legacy_plaintext` migration policy; malformed/tampered protected values still fail closed.
- [X] secret values are resolved from environment locators at runtime rather than embedded in InterMix generated definitions.

### Remaining acceptance

- [ ] Let the new exact-head focused protection/rotation/DB-persistence tests pass full PHPForge QA/static analysis on PHP 8.4/8.5 stable/lowest.
- [ ] Add/verify an explicit operational migration path that turns legacy plaintext compatibility back off after old rows are rewritten.
- [ ] Prove active→fallback→new-active rotation under concurrent HOTP/OCRA counter updates cannot lose newer factor state.
- [ ] Re-audit recovery-store committed-count/replacement/atomic-consumption semantics against OTP 6.1 under the final independent key lifecycle.
- [ ] Preserve OTP result/reason taxonomy internally; keep credential/replay failures distinct from coordination/persistence/runtime failures.
- [ ] Prove every production MFA CAS store is authoritative under the supported concurrency/deployment matrix.
- [ ] Complete representative TOTP/HOTP/OCRA/recovery/Passkey sequential, interleaved Fiber and persistent-worker isolation tests.
- [ ] Complete direct-OTP-versus-Foundation protection/state overhead attribution.

### Completion gate

26.4 closes only when OTP remains the sole protocol/mechanics owner, production factor/credential stores are atomically authoritative, durable symmetric factor secrets are protected through Epicrypt 3, recovery/MFA key lifecycles are independent, rotation/concurrency tests pass, optional graphs remain cold, and final QA/performance evidence is green.

**Status:** core complete; security/key-domain implementation landed; acceptance in progress.

---

## 26.5 Pathwise 4 filesystem integration — implementation complete; acceptance in progress

### Implemented

- [X] Foundation Composer floor is `^4.0`.
- [X] `StorageRegistry` is a thin application adapter over Foundation-owned Pathwise `StorageContext`.
- [X] old Pathwise process-global mount/default-namespace workaround removed.
- [X] application/generation owns explicit storage context; same-process/Fiber isolation coverage exists.
- [X] uploads adapt Webrick input through Pathwise `UploadSource::fromMover`.
- [X] downloads delegate exact byte/range iteration to `DownloadProcessor::streamChunks`; Webrick retains HTTP output ownership.
- [X] storage links delegate generic symlink safety to `SafeSymlinkManager`.
- [X] malware scanner composition uses Pathwise scanner/mode semantics.
- [X] Pathwise bridge benchmark coverage exists.
- [X] Foundation now consumes released Epicrypt `^3.0`; the Epicrypt-2/Pathwise-3 conflict is removed.
- [X] normal Composer resolution has succeeded with Epicrypt 3 + OTP 6.1 + Pathwise 4 on the active integration branch.
- [X] stale `filesystem.uploads.require_malware_scan` usage/default is absent from the current tree.

### Remaining acceptance

- [ ] Complete exact-final-head filesystem suite on PHP 8.4/8.5 stable/lowest: context isolation, upload cleanup, scanner modes, ranges/early abort, links, persistent/Fiber reuse and capability absence.
- [ ] Re-run/finalize `benchmark:pathwise` attribution on the final integration head.
- [ ] Confirm no compatibility/VCS/path workaround is present in the final Composer graph.

### Completion gate

26.5 closes when the exact final head proves normal released Epicrypt 3 + OTP 6.1 + Pathwise 4 resolution, the full filesystem suite and benchmark are green, no global Pathwise state/duplicate storage mechanics return, and Webrick remains the HTTP response/output owner.

**Status:** implementation/dependency closure complete; final exact-head QA/performance acceptance open.

---

## 26.6 DBLayer 5.1 — complete

Foundation uses execution-owned DBLayer connections/`ConnectionRepository`, delegates generic DB mechanics to DBLayer, retains domain persistence/CAS policy, and keeps pooling opt-in with execution isolation.

**Status:** [X] complete.

---

## 26.7 ReqShield 3.1 utilization — open/deferred

### Ownership

ReqShield owns rule parsing/compilation/execution, sanitization/casting, nested/wildcard validation, limits, result/failure models, schema composition/JSON-schema export, bounded plan caching and optional database-rule batching. Foundation owns named application schemas/defaults/overrides, Webrick input adaptation, optional DBLayer provider selection and HTTP/application error mapping.

### Open work

- [ ] Rescan Foundation ReqShield usage against 3.1 and remove duplicated mechanics.
- [ ] Freeze production schema topology; normal execution must not mutate process-wide registration.
- [ ] Keep DB validation optional/lazy and reuse ReqShield database batching/DBLayer parameter limits.
- [ ] Preserve validation bounds as security controls and structured failure results internally.
- [ ] Prove non-DB validation does no DB I/O and persistent/Fiber validation retains no prior mutable state.
- [ ] Benchmark direct ReqShield vs Foundation schema/factory/database bridge.

**Status:** deferred until 26.10/26.4 closure.

---

## 26.8 Omnibus 2.5 utilization — open/deferred

### Ownership

Omnibus owns envelope/bus/routing/transports/consumer/retry/failure/workflow/message-worker/worker-pool policy/scheduled-message mechanics and its CacheLayer/DBLayer integrations. Runwire owns the generic OS-process supervision primitives beneath process-backed pools. Foundation owns configured application IDs/routes/transports, graph inclusion, release-generation policy, execution scopes, correlation and selection of lower-layer DB/cache services.

### Open work

- [ ] Rescan Foundation Omnibus 2.5 usage and remove duplicated mechanics.
- [ ] Keep durable DB/cache integrations lazy and selected explicitly.
- [ ] Require intentional durable failure-store policy for durable async workers.
- [ ] Bind after-commit behavior to the current execution connection; never capture scoped connections in singletons.
- [ ] Keep retry/settlement and queue-worker policy inside Omnibus Consumer/WorkerPool while delegating generic fork/signal/wait/reap/termination supervision to Runwire under Foundation release-generation policy.
- [ ] Prove sync/memory/durable topology, retries/failures, persistent isolation and capability-absent cold paths.
- [ ] Benchmark direct Omnibus versus Foundation bridge.

**Status:** deferred until 26.10/26.4 closure.

---

## 26.9 TalkingBytes 2.0 utilization — open/deferred

### Ownership

TalkingBytes owns HTTP client mechanics, inbound/outbound email/message chains, webhook protocol/signature behavior and gRPC request/response/stream mechanics. Foundation owns named profiles, capability selection, application service mapping, secure replay-store selection, worker-scope integration and application secret/redaction policy.

### Open work

- [ ] Rescan Foundation bindings against TalkingBytes 2.0.
- [ ] Classify mutable client/profile/resilience lifetimes and prove no cross-request/job/Fiber state leakage.
- [ ] Keep webhook replay atomic/fail-closed through CacheLayer.
- [ ] Route inbound gRPC through the existing Foundation worker lifecycle.
- [ ] Consume native inbound/outbound email/message-chain APIs rather than recreating protocol mechanics.
- [ ] Keep communication secrets out of logs/cache keys/generated metadata.
- [ ] Prove HTTP/webhook/gRPC/email behavior, optional cold graphs and direct-vs-Foundation overhead.

**Status:** deferred until 26.10/26.4 closure.

---

## 26.10 Epicrypt 3 consumption, auth-protocol adoption and Foundation crypto-policy consolidation — IN PROGRESS

### Released baseline

- [X] Epicrypt `3.0` is released and normally consumable.
- [X] Release tag `3.0` resolves to `e11bb287900b2590954ef0c0ebba649bc5bf0793`.
- [X] Released `PUBLIC_API_3.md`/3.0 source is the integration authority; intermediate architecture-plan APIs are not compatibility targets.
- [X] Epicrypt 3 production dependencies contain no Pathwise or OTP.
- [X] Frozen Epicrypt `ep2` protected formats remain the persisted-compatibility baseline where Foundation actually persists them.

### Ownership

Epicrypt owns generic crypto/key mechanics, purpose-bound timed tokens, signed-URL cryptography, asymmetric signing readiness/JWKS validation, password primitives, JOSE/JWK/JWKS/PKI operations, and the transport-neutral OAuth 2.1/OIDC/PAT protocol/state-machine core.

Foundation owns Webrick/HTTP adaptation, login/consent application decisions, accounts/principal mapping, DBLayer/CacheLayer production store adapters/transaction boundaries, application scope/audience/tenant policy, external secret references, rollout/rotation policy, sessions/cookies, Foundation rate limits/audit/telemetry/config/path/CLI and selection/configuration of Epicrypt services.

Foundation must not reimplement PKCE, grant state machines, authorization-code/refresh semantics, access-token claims/validation, OIDC nonce/hash rules, DPoP, PAT ability semantics, protocol error safety, generic crypto, KDFs, protection formats or signing readiness that Epicrypt 3 already owns.

### Batch 26.10.1 — consume Epicrypt 3 and close dependency graph

- [X] Release Epicrypt 3.0 and make stable 3.x normally consumable.
- [X] Re-fetch released Epicrypt 3 API/release surface before Foundation integration.
- [X] Foundation `require-dev["infocyph/epicrypt"]` is `^3.0`.
- [X] Normal Composer dependency resolution succeeds without Epicrypt/Pathwise compatibility shims.
- [X] Resolved development graph includes Epicrypt 3.x, OTP 6.1 and Pathwise 4.
- [X] Released Epicrypt production graph contains no Pathwise or OTP.
- [X] clean production install/check-platform/autoload has passed on the integration branch before the latest MFA slice.
- [ ] Re-prove all dependency/clean-install assertions on the exact final 26.10 head.
- [ ] Close the remaining exact-head 26.5 filesystem QA/performance gate.

**Batch status:** implementation complete; exact-final-head evidence pending.

### Batch 26.10.2 — remove generic Foundation crypto duplication

- [X] Replace generic `HmacTokenCodec` signing/timing/purpose mechanics with Epicrypt `PurposeToken`; `HmacTokenCodec` is removed.
- [X] `AbstractSimpleTimedTokenService` retains Foundation claim mapping only; generic issue/expiry/purpose/token-ID/signature mechanics are Epicrypt-owned.
- [X] Replace ad-hoc raw-HMAC recovery/application derivation with Epicrypt `KeyDeriver` and stable domain labels.
- [X] Keep OTP recovery semantics OTP-owned; only key derivation/lifecycle is Foundation/Epicrypt integration policy.
- [X] `EnvironmentSecretManager` uses Epicrypt `KeyMaterialGenerator` with explicit encoding for canonical auth-secret generation.
- [X] Removed Epicrypt-2 generator usage; no `MasterSecretGenerator`/`TokenMaterialGenerator` remains.
- [X] `EnvironmentFileProtector` is a thin Foundation path/config adapter over atomic Epicrypt `FileProtector`; duplicate staging/publication crypto protocol is removed.
- [X] OAuth signing readiness, pair coherence, key eligibility and public JWKS validation delegate to Epicrypt `AsymmetricSigningKeySet`; Foundation retains config/file loading and audit.
- [ ] Replace Webrick-only raw signed-URL key handling with an Epicrypt-backed dedicated `foundation.signed-url.v1` key lifecycle/rotation bridge without moving HTTP URL mechanics out of Webrick.
- [~] Preserve stable Epicrypt result/exception detail internally while mapping only safe non-sensitive Foundation failures externally; completed for the migrated token/key/MFA paths, still required during 26.10.4 protocol replacement.

**Batch status:** all generic token/KDF/file/signing-readiness duplication removed; signed-URL bridge and protocol-result continuation remain.

### Batch 26.10.3 — explicit Foundation key domains, protection and rotation

Stable domains:

```text
foundation.auth.mfa-secret.v1
foundation.auth.recovery-hmac.v1
foundation.auth.simple-token.<purpose>.v1
foundation.oauth.signing.v1
foundation.environment.file.v1
foundation.signed-url.v1
```

- [X] Recovery HMAC has a dedicated external deployment secret locator plus Epicrypt `KeyDeriver` domain separation; it no longer shares the generic token-secret lifecycle.
- [X] MFA encryption has a dedicated external active/fallback key ring independent from recovery/simple-token/OAuth/signed-URL domains.
- [X] Durable DB-backed OTP `secret`/MobileOTP `pin` values are protected through Epicrypt high-level string protection.
- [X] New MFA writes use only the active key; fallback keys are read-only and exact `kid` selection prevents trial decryption.
- [X] Bounded protection metadata/purpose/version is persisted; plaintext is exposed only in the narrow in-memory OTP execution model.
- [X] Fallback read does not automatically write; reprotection occurs only at an explicit Foundation write/policy boundary.
- [X] MFA/recovery raw keys are runtime-resolved from environment locators and are not DI-definition configuration values.
- [X] OAuth access-token signing uses the released dedicated Epicrypt auth key purpose rather than generic JWT signing.
- [ ] Move Foundation simple-token root-secret handling away from composition-time raw secret embedding if generated-artifact inspection proves the value is materialized in InterMix output.
- [ ] Finalize independent signed-URL key lifecycle/rotation under `foundation.signed-url.v1`.
- [ ] Inventory environment-file protection key lifecycle versus other domains and document whether it is external-only or derived; do not silently share unrelated roots.
- [ ] Add release-artifact/generated-container assertions proving no configured raw key, passphrase, token or decrypted MFA secret appears.
- [ ] Complete concurrent rotation/reprotection tests and explicit legacy-plaintext migration-off procedure.

**Persisted compatibility rule:** do not migrate crypto formats merely because the package major changed. Existing valid durable Epicrypt 2.x material remains readable where Foundation actually persists it. Any intentionally changed Foundation domain needs old/new fixtures and an explicit migration/read/write policy.

### Batch 26.10.4 — consume released Epicrypt OAuth/OIDC/PAT protocol core

First maintain a move/keep/replace inventory of every current Foundation OAuth/OIDC/API-token class, route, store, schema and test. Delete Foundation protocol mechanics only after the corresponding application adapter and migration/concurrency evidence exists.

- [ ] Replace Foundation OAuth authorization-request protocol validation with `OAuthAuthorizationRequestValidator`; preserve duplicate query occurrences so singleton duplicates are rejected, not collapsed.
- [ ] Delegate exact redirects, `response_type=code`, PKCE S256, scope/audience bounds and safe protocol errors to Epicrypt.
- [ ] Implement Foundation audience policy through released `OAuthAuthorizationAudienceResolverInterface` where multi-resource clients need it.
- [ ] Use Epicrypt authorization interaction/approval DTOs; Foundation owns login, consent UI/history and actual authorization decision.
- [ ] Delegate authorization-code issue/consume to Epicrypt; never persist raw authorization-code JWE.
- [ ] Implement authoritative DBLayer/CacheLayer adapters for released client/code/refresh/authorization/access-status/replay contracts.
- [ ] Reuse released `JwtReplayStoreInterface` for `private_key_jwt`/DPoP replay where applicable.
- [ ] Delegate client authentication to `OAuthClientAuthenticator`; unregistered/embedded key locators must never become trust sources.
- [ ] Replace Foundation grant mechanics with Epicrypt `OAuthTokenEndpoint` for Authorization Code, Client Credentials and Refresh Token.
- [ ] Consume Epicrypt refresh rotation/reuse semantics; raw refresh JWE must never be persisted and consume/replace must be atomic.
- [ ] Consume `OAuthAccessTokenService`, `OAuthAccessTokenInspector` and `OAuthResourceAccessTokenValidator`; add authoritative status storage only when selected.
- [ ] Route revocation/introspection through Epicrypt endpoints; Foundation retains HTTP protection/rate-limit/audit adaptation.
- [ ] Generate authorization-server metadata/auth JWKS through Epicrypt publisher/metadata services.
- [ ] Integrate Epicrypt DPoP validation/binding when enabled.
- [ ] Replace Foundation OIDC protocol mechanics with released Epicrypt request/interaction/ID-token/subject/UserInfo/provider-metadata services.
- [ ] Replace matching personal/API-token mechanics with Epicrypt `PersonalAccessTokenManager`; persist state/metadata only, never raw PAT JWT.
- [ ] Serialize PAT `revokeAll()` against concurrent issue for the same subject.
- [ ] Preserve Epicrypt protocol result/error taxonomy internally while exposing only safe non-oracular failures.
- [ ] Keep Epicrypt in-memory/conformance stores test-only; production Foundation binds authoritative stores explicitly.
- [ ] Do not retain shims for intermediate unreleased Epicrypt 3 APIs.

**Batch gate:** Foundation owns transport/application/persistence adapters only; released Epicrypt services own protocol validation, grants/state machines and auth cryptography.

### Batch 26.10.5 — compatibility, protocol and security tests

- [ ] Inventory durable versus ephemeral Foundation encrypted/signed/auth artifacts.
- [ ] Freeze/read Epicrypt-2 compatibility fixtures only for durable material that must survive upgrade.
- [X] Focused MFA protection tests cover active-key protection, AAD binding, exact fallback read, explicit reprotection, legacy plaintext policy and unchanged domain revision.
- [X] Focused DB test asserts raw TOTP/MobileOTP secret material is absent from durable metadata while application reads remain usable and revision CAS remains authoritative.
- [X] Focused key-resolver tests cover independent recovery/MFA secret locators, production missing-key rejection and multiple-active-key rejection.
- [ ] Let the above focused tests pass exact-head PHP 8.4/8.5 stable/lowest QA/static analysis.
- [ ] Add unknown/retired/tampered key handling and interrupted rotation/rollback coverage where not already lower-layer-proven.
- [ ] Re-audit recovery-code verification/rotation/atomic consumption under the final key lifecycle.
- [ ] Test simple PurposeToken flows for wrong purpose/context, expiry/not-before and rotation.
- [ ] Test environment-secret generation and protected-file round-trip/failure preservation.
- [ ] Test OAuth signing readiness/JWKS across Foundation-supported RSA/PSS/EC/EdDSA configurations.
- [ ] Test signed URL issue/verify/rotation through dedicated lifecycle.
- [ ] Test password legacy Argon2i/bcrypt verification/rehash compatibility where Foundation exposes it.
- [ ] Add end-to-end Webrick→Epicrypt→Foundation-store OAuth Authorization Code + PKCE coverage.
- [ ] Add Client Credentials/Refresh concurrency/reuse/revocation/wrong-client coverage.
- [ ] Add registered-key `private_key_jwt` audience/time/jti/replay coverage.
- [ ] Add access-token resource validation/status/revocation/introspection/non-oracular revocation coverage.
- [ ] Add DPoP positive/negative/replay/binding coverage when enabled.
- [ ] Add OIDC Authorization Code/nonce/prompt/max_age/auth_time/acr/amr/subject/ID Token/UserInfo/metadata coverage.
- [ ] Add PAT issue/validate/list/revoke/revoke-all/ability/wildcard/concurrent issue-vs-revoke-all coverage.
- [ ] Prove auth state stores cannot return stale active state after committed revocation/disablement.
- [ ] Prove generated runtime/release artifacts and normal logs/exceptions contain no raw key/auth-code/refresh/PAT/decrypted-MFA material.
- [ ] Prove sequential/Fiber/persistent-worker crypto/auth operations retain no prior plaintext/request/key/client mutable state.
- [ ] Fail build/boot before traffic for malformed/missing production security configuration wherever safe to validate ahead of requests.

### Batch 26.10.6 — performance attribution

Benchmark integration boundaries without optimizing away required security:

1. capability absent vs enabled-but-unused graph/boot cost;
2. direct Epicrypt `KeyDeriver` vs Foundation domain-key bridge;
3. direct string protect/unprotect vs MFA persistence bridge;
4. active read vs fallback read + explicit reprotection;
5. direct `PurposeToken` issue/verify vs Foundation claim mapping;
6. direct signing readiness/JWKS vs Foundation config/resolver bridge;
7. direct file protection vs environment-file path/config adapter;
8. direct signed URL crypto vs Foundation/Webrick policy bridge;
9. direct password verify/rehash vs Foundation adapter;
10. direct authorization validation/code issue-consume vs Webrick/Foundation/store bridge;
11. direct token endpoint/resource validation vs Foundation adapter/store overhead;
12. direct OIDC/PAT operations vs Foundation claims/principal/persistence bridge;
13. repeated representative auth/crypto operations under persistent runtime with memory measurement.

External secret-provider I/O, DB/cache I/O, HTTP adaptation and actual cryptographic/KDF cost must be attributed separately.

### 26.10 completion gate

26.10 closes only when:

- [ ] exact final Composer graph uses released Epicrypt 3.x + OTP 6.1 + Pathwise 4 on PHP 8.4/8.5 stable/lowest;
- [ ] 26.5 is closed;
- [ ] every Foundation crypto/auth site is classified as Epicrypt mechanics/protocol or Foundation application/transport/persistence policy;
- [ ] generic timed-token/KDF/protection/signing-readiness duplication is gone or explicitly justified;
- [ ] no Foundation OAuth/OIDC/PAT protocol implementation remains where released Epicrypt owns behavior;
- [ ] production Epicrypt auth stores are atomic/concurrency-safe and cannot return stale active state after committed revocation/disablement;
- [ ] raw authorization-code JWE, refresh-token JWE and PAT JWT are never persisted;
- [ ] `private_key_jwt`/DPoP replay is authoritative and unregistered key locators cannot choose trust material;
- [ ] OAuth/OIDC/PAT end-to-end suites pass for the selected capability set;
- [ ] independent key lifecycles exist for MFA, recovery HMAC, simple tokens, OAuth/auth purposes, environment-file protection and signed URLs;
- [ ] durable symmetric MFA secrets are protected at rest;
- [ ] bounded active/fallback rotation and deterministic key selection are proven;
- [ ] durable compatibility policy/fixtures are proven where applicable;
- [ ] release-artifact secret leakage and runtime-isolation tests pass;
- [ ] Foundation PHP 8.4/8.5 stable/lowest QA/static/security/protocol suites are green;
- [ ] direct-Epicrypt-versus-Foundation overhead attribution is recorded;
- [ ] the Epicrypt-dependent remainder of 26.4 is closed or only explicitly non-crypto work remains.

**Status:** [~] IN PROGRESS — 26.10.1 dependency implementation is landed; 26.10.2 generic crypto consolidation is nearly complete; 26.10.3 MFA/recovery key-domain implementation is landed and awaiting exact-head acceptance; signed URLs and 26.10.4 protocol-core adoption remain open.

---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is the Foundation-facing WebAuthn ceremony/state boundary. Foundation retains application persistence, user-handle/principal mapping, passkey policy and authorization. It does not own WebAuthn ceremony construction/validation/signature-counter mechanics.

**Status:** [X] closed/subsumed.

---

## 26.12 Runwire 1.0 — native process/server runtime and host-runtime drivers

**Status:** planned; Foundation 3 launch requirement.

Runwire is Foundation's generic process/server/runtime boundary. Webrick remains the HTTP application-semantics owner; Omnibus remains the messaging/queue-semantics owner. Runwire-native is the first-party server engine, while FPM, FrankenPHP, Swoole/OpenSwoole and RoadRunner are selectable host-runtime drivers behind one Runwire lifecycle contract. OPcache is an orthogonal accelerator capability, not a runtime driver.

### 1. New ownership map

Foundation's lower-library ownership map becomes:

```text
InterMix     DI graph/scopes/generated container
Webrick      HTTP application request/response/routing/middleware
Runwire      process/supervisor/event-loop/network/server mechanics
DBLayer      DB runtime/query/pool/transaction mechanics
CacheLayer   cache/locks/atomic coordination
Pathwise     filesystem/storage/upload trust boundaries
ReqShield    validation/sanitization/schema mechanics
Omnibus      messaging/events/queues/consumer/retry/workflow semantics
TalkingBytes outbound/inbound communication protocol mechanics
OTP          OTP/Passkey mechanics
Epicrypt     cryptography/auth protocol core
Foundation   application policy/composition/release/execution adaptation
```

Hard invariant:

> Foundation must not retain a second generic process supervisor, network event loop, native socket server, shell runner or OS process-management implementation above Runwire once Runwire 1.0 owns that mechanism.

---

### 2. Runwire is below Webrick, not a replacement for Webrick

Native web path:

```text
Foundation CLI / release selection
        ↓
Runwire Runtime + Supervisor
        ↓
Runwire TCP/TLS + HTTP/1 transport
        ↓
Webrick RunwireRuntimeAdapter
        ↓
Foundation fresh web execution scope
        ↓
Webrick compiled kernel
        ↓
Webrick Response
        ↓
Runwire response writer
```

Runwire owns bytes/connections/processes. Webrick owns HTTP application semantics.

Do not move into Runwire:

- routes;
- middleware;
- controllers;
- Webrick `Request`/`Response`;
- content negotiation;
- application cookies/security headers;
- application error rendering.

---

### 3. Native Foundation server

Foundation should expose a native serving path through its existing/selected CLI conventions, conceptually:

```bash
php foundation serve
```

Foundation command/config layer owns:

- host/port selection;
- Runwire runtime profile selection;
- worker count policy/defaults;
- selected Foundation release generation;
- compiled Webrick/InterMix artifact selection;
- logging/telemetry integration;
- operational status/control presentation;
- application-specific health/readiness policy.

Runwire owns the actual:

- bind/listen;
- fork/spawn;
- event loop;
- signal/wait/reap;
- connection accept/read/write;
- generic worker restart/reload/shutdown;
- transport limits/backpressure.

Foundation should not wrap these with another process engine.

---

### 4. Composer/release policy

During coordinated development, Foundation may use an explicit development Runwire constraint only on integration branches.

Final Foundation 3 release requirements:

```json
"infocyph/runwire": "^1.0"
```

The final release must not depend on a branch/dev alias for Runwire.

Webrick may keep Runwire optional for standalone installations; Foundation installs both because Runwire is Foundation's native server runtime.

---

### 5. Trusted master pre-fork cleanliness

Foundation already has process-bound services that must not be inherited from an application-booted master.

Before Runwire forks Foundation application workers, the master must not resolve/open process-bound application resources such as:

- DBLayer connections/leases/PDO;
- CacheLayer network clients/locks that are not fork-safe;
- Redis/Valkey clients;
- Omnibus durable transport connections;
- TalkingBytes outbound connection pools;
- application sockets;
- mutable transaction/session state;
- InterMix scoped application state.

Preferred lifecycle:

```text
minimal Foundation launch/bootstrap
        ↓
validate release/config/artifacts
        ↓
configure Runwire listeners/worker groups
        ↓
Runwire binds intended inheritable listeners
        ↓
Runwire forks child
        ↓
child signal/process normalization
        ↓
boot fresh Foundation worker application
        ↓
open child-owned DB/cache/broker resources
        ↓
serve/consume
```

Keep and evolve Foundation's existing `assertPoolParentClean()` / fork-safety checks where they know Foundation-specific container/config state. Runwire cannot inspect arbitrary host containers to prove this.

---

### 6. Application execution scopes remain Foundation-owned

Do not map Runwire worker lifetime onto Foundation request lifetime.

Stable Foundation scopes remain:

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

For native HTTP:

```text
one Runwire worker process
    ├─ request A -> fresh Foundation/web execution A -> cleanup
    ├─ request B -> fresh Foundation/web execution B -> cleanup
    └─ request C -> fresh Foundation/web execution C -> cleanup
```

Prove no principal/session/request/input/DB transaction/validation state leaks between keep-alive requests or Fibers.

---

### 7. Foundation process mechanics to move downward

Rescan all Foundation direct uses of:

```text
pcntl_*
posix_*
proc_open
popen
exec
system
shell_exec
passthru
```

Classify every site.

Generic mechanics should move to Runwire, including where currently Foundation-owned solely because no lower package exists:

- generic child spawn/fork;
- PID tracking;
- signal registration/dispatch;
- wait/reap;
- generic process-group/session operations;
- graceful terminate → forced kill;
- generic restart/backoff;
- generic worker generation/reload;
- bounded process I/O;
- timeout/output limits;
- structured executable + argv execution.

Foundation retains application/deployment policy:

- `RuntimeProcessRegistry`;
- `RuntimeControl` semantics;
- release-generation identity/activation;
- desired application process roles;
- application readiness/heartbeat meaning;
- runtime configuration/defaults;
- authorization to invoke registered privileged operations;
- mapping Runwire status into Foundation diagnostics/CLI.

---

### 8. Remove Foundation's Omnibus SIGALRM watchdog duplication

The current Foundation Omnibus parent supervision workaround must not survive the final Runwire/Omnibus architecture.

Current generic behavior such as:

```text
Foundation SIGALRM
  -> heartbeat
  -> stopRequested
  -> Omnibus WorkerPool::requestStop()
```

should be replaced by:

```text
Foundation application lifecycle/control adapter
        ↓
Omnibus queue Worker semantics
        ↓
Runwire generic Supervisor
```

Foundation supplies application-specific lifecycle state; it does not install a second signal timer just to wake a queue pool.

Coordinate this with Omnibus 2.6.

---

### 9. Omnibus relationship

Foundation Point 26.8 should target the Runwire-aligned Omnibus 2.6 plan rather than finalizing a duplicate process supervisor.

Final ownership:

```text
Foundation
  application messaging configuration / runtime control
          ↓
Omnibus
  queue/message Worker semantics
          ↓
Runwire
  OS process worker-group supervision
```

Omnibus owns retry/failure/settlement/prefetch/message recycle decisions. Runwire owns process fork/wait/reap/signals/restart/termination.

Foundation should not call raw `pcntl` around an Omnibus pool after this migration.

---

### 10. Scheduler and other long-running worker groups

Evaluate reusing Runwire's generic supervisor for Foundation scheduler/daemon process groups where this removes existing duplicate OS process mechanics.

Potential topology:

```text
Runwire Supervisor
   ├─ web group       -> Webrick/Foundation
   ├─ queue group     -> Omnibus/Foundation
   ├─ scheduler group -> Foundation scheduler callback
   └─ trusted custom group
```

Do not force these workloads into the same PHP worker or event loop. They may remain separate OS process groups with separate application boot/lifetime policies.

Foundation decides which groups exist; Runwire merely supervises them.

---

### 11. Structured privileged operations

For application features that need process execution, Foundation should expose **registered operations**, not arbitrary shell commands.

Correct conceptual flow:

```text
user/application input
        ↓
ReqShield validates operation ID + structured arguments
        ↓
Foundation authorizes capability
        ↓
Foundation resolves any Pathwise-managed artifact safely
        ↓
Foundation maps operation ID to trusted Runwire command definition
        ↓
Runwire executes executable + argv under selected execution policy
```

Example operation identifiers:

```text
image.thumbnail
pdf.inspect
git.status
```

Do not expose:

```text
command = "anything the requester typed"
```

as a normal Foundation API.

---

### 12. ReqShield boundary

ReqShield validates data/intent only.

Foundation must not ask ReqShield to make shell/PHP code "safe" by blocking substrings such as:

```text
exec
system
shell_exec
proc_open
pcntl_fork
posix_kill
```

Those are ordinary strings until interpreted/executed.

ReqShield can validate a registered operation identifier and its bounded structured arguments. Foundation authorizes. Runwire executes.

No Runwire dependency is required in ReqShield.

---

### 13. Pathwise boundary

Pathwise owns filesystem/upload containment and data-only storage safety.

For an uploaded/stored artifact:

```text
Webrick input
    ↓
Foundation upload policy
    ↓
Pathwise materialization/validation/scan/storage
    ↓
Foundation authorization
    ↓
Runwire registered process operation, if intentionally needed
```

Never use an upload path directly as a PHP `include`, script filename or shell fragment.

Runwire does not replace Pathwise root containment, symlink/race policy, archive limits or scanner contracts.

If an external executable malware scanner is needed, Foundation/application may implement Pathwise's scanner contract using Runwire's structured process runner. Pathwise itself remains independent of Runwire.

---

### 14. Trusted workers vs untrusted code execution

Foundation's normal Runwire web/worker/scheduler children execute **trusted deployed application code**.

A `pcntl_fork()` child is not an untrusted-code sandbox because it inherits the loaded PHP runtime/capabilities.

If Foundation intentionally supports uploaded/user-controlled scripts/plugins as executable code, route them through a separate execution profile:

```text
Foundation authorization
      ↓
Runwire structured spawned process
      ↓
separate PHP binary/php.ini / sandbox launcher
      ↓
dedicated UID/GID
      ↓
OS isolation
  seccomp / AppArmor / SELinux / namespaces / container / stronger boundary
```

Never execute hostile uploaded PHP in the normal persistent Runwire application worker.

---

### 15. PHP runtime capability separation

Document recommended deployment profiles conceptually:

```text
foundation-supervisor.ini
    trusted process supervision capabilities

foundation-worker.ini
    capabilities needed by trusted application workers only

foundation-untrusted-executor.ini
    separately restricted runtime used only behind OS sandbox
```

Foundation startup diagnostics should surface missing Runwire capabilities required by selected runtime features.

Do not use `disable_functions` alone as a claimed sandbox.

Do not run the long-lived Foundation master as root merely because Runwire exposes POSIX identity APIs.

If privileged bind/bootstrap is needed, keep it minimal and drop privileges before application processing wherever architecture permits.

---

### 16. Server configuration

Add a compact Foundation-owned configuration surface mapping into frozen Runwire definitions.

Potential configuration categories:

```text
server.enabled
server.host
server.port
server.workers
server.loop
server.backlog
server.tls
server.connections.max
server.timeouts.*
server.http.* hard/selected limits
server.reload.*
```

Do not mirror every Runwire internal option into Foundation configuration.

Foundation should expose stable application/deployment knobs and allow an advanced trusted configuration extension only where needed.

Validate incompatible Webrick/Runwire limits at startup.

---

### 17. Persistent HTTP request cleanup

Native server acceptance must prove cleanup on:

- normal response;
- exception;
- 404/405;
- middleware rejection;
- streaming response completion;
- client disconnect during request body;
- client disconnect during response;
- timeout/cancellation;
- worker drain/reload.

Every started Foundation request execution is closed exactly once.

No request-scoped DB transaction, cache lock, auth principal, validation context or session state remains reachable after cleanup.

---

### 18. After-response semantics

Review Foundation/Omnibus after-response behavior under native Runwire output.

Distinguish:

```text
Webrick Response produced
Runwire response queued
Runwire response flushed/completed
client disconnected
```

Define Foundation's after-response boundary intentionally.

For work that must happen after application response production but need not wait for all client bytes, document that semantics. For transport completion-sensitive work, use explicit Runwire/Webrick completion state.

Do not make DB transaction correctness depend on a client successfully reading all response bytes.

---

### 19. Release generation and Runwire worker generation

Keep identities separate:

```text
Foundation release generation
    application artifacts/config/release identity

Runwire worker generation
    supervisor process replacement/reload identity
```

Foundation may annotate/map a worker generation to the Foundation release it booted, but Runwire must not parse Foundation release manifests.

For release activation:

- build/validate new Foundation release;
- ask Runwire/Foundation supervisor policy to start replacement worker generation using new release;
- verify readiness;
- drain old generation;
- enforce grace deadline;
- terminate old generation;
- preserve rollback capability according to existing Foundation release policy.

Do not overwrite Foundation's existing release-generation semantics with a generic Runwire reload ID.

---

### 20. Runtime process registry integration

Keep `RuntimeProcessRegistry` Foundation-owned because it expresses Foundation release/application process identity.

Adapt Runwire lifecycle events/status into it rather than duplicating Runwire's child table manually.

Foundation registry may record:

- role/group;
- PID;
- Runwire worker generation;
- Foundation release generation;
- heartbeat/readiness;
- started/draining/stopping state.

Runwire remains authoritative for its actual live child/process state; Foundation remains authoritative for application/release meaning.

Avoid two independent sources both claiming ownership of generic child liveness.

---

### 21. Control plane

Foundation CLI should present user-facing operational commands, while Runwire supplies generic runtime control/status primitives.

Potential Foundation UX:

```text
serve
server:status
server:reload
server:stop
```

Exact command names should follow existing Foundation console conventions.

Foundation can translate these into Runwire control calls while adding release/application context.

Do not expose a Foundation command that forwards arbitrary Runwire control payloads or shell commands from untrusted input.

---

### 22. Webrick adapter integration

Foundation should consume Webrick's Runwire adapter rather than create its own parallel Runwire→HTTP request converter.

Hard rule:

> There must be one generic Runwire↔Webrick adaptation in Webrick, and Foundation only supplies application execution/composition around it.

Foundation may implement a request execution callback that:

1. starts `webrick.request` execution scope;
2. binds request/correlation state;
3. invokes compiled Webrick kernel;
4. maps application exceptions according to Foundation policy;
5. cleans execution state in `finally`;
6. returns Webrick response to adapter/Runwire writer.

---

### 23. Existing Workerman/Swoole/RoadRunner support

Do not remove Webrick compatibility adapters.

Foundation's **native/default persistent server** can be Runwire while advanced deployments may still integrate Webrick through another supported runtime where Foundation explicitly supports that mode.

Do not make Foundation's application semantics depend on Runwire-specific request objects.

Runtime portability remains a useful correctness check even though Runwire is the native path.

---

### 24. Omnibus/Runwire process groups

When Foundation runs queue workers under Runwire:

```text
Runwire child process
   ↓
boot Foundation worker app
   ↓
construct child-owned DB/cache/broker resources
   ↓
construct Omnibus Worker
   ↓
Omnibus consumes messages
```

Queue reservation occurs using the worker-process-owned transport before per-message Foundation execution scope as Omnibus requires.

Each message handler still gets a fresh Foundation `foundation.worker` execution.

Do not resolve DB/cache/broker resources in the Runwire master and inherit them into queue children.

---

### 25. Process operation registry

Foundation may add a small application-owned registry/policy mapping operation IDs to Runwire command definitions.

Requirements:

- registry is built from trusted application/config code;
- frozen before normal runtime handling;
- no user-controlled executable path;
- executable path/identity is trusted configuration;
- arguments are built structurally;
- environment keys are filtered;
- cwd is trusted/restricted;
- timeout/output/resource profile is mandatory or has secure defaults;
- operation authorization occurs before Runwire invocation;
- audit metadata records operation identity, not secrets/full argv by default.

Do not create another process runner inside Foundation; registry resolves policy and calls Runwire.

---

### 26. Security test matrix

Add Foundation integration tests proving:

- harmless request data containing `exec(` / `pcntl_fork` / shell-looking text remains data;
- unregistered process operation cannot execute;
- registered operation with invalid ReqShield args is rejected before Runwire;
- unauthorized registered operation cannot execute;
- Pathwise artifact cannot escape allowed storage/root policy before process use;
- no raw user shell string is constructed by process-operation bridge;
- master application resources remain clean before fork;
- child application resources are opened post-fork;
- repeated keep-alive requests do not share security/session/DB state;
- client cancellation cleans request execution;
- reload drains old release workers without serving new requests from stale application generation after cutoff;
- untrusted-script mode cannot silently fall back to normal trusted Runwire worker execution.

---

### 27. Performance acceptance

Keep performance attribution layered:

```text
Runwire raw HTTP
Runwire + Webrick
Runwire + Webrick + Foundation
Workerman + Webrick reference
existing Apache/FPM Foundation baseline where useful
```

Measure:

- RPS;
- p50/p95/p99;
- CPU;
- master/worker RSS;
- memory growth during soak;
- connections;
- keep-alive;
- large/streaming response;
- slow-client behavior;
- request execution bridge overhead;
- reload capacity dip;
- worker startup/release switch time.

Foundation optimizations must not bypass Webrick/InterMix/security semantics merely to improve benchmarks.

---

### 28. Fault/soak acceptance

Run native Foundation/Runwire soak scenarios:

- sustained small requests;
- keep-alive churn;
- mixed authenticated/unauthenticated requests;
- uploads/body streaming;
- large downloads/streaming;
- slow clients;
- worker crash;
- repeated worker recycle;
- rolling reload to a new Foundation release generation;
- DB/cache outage during active requests;
- control stop/reload during traffic.

Require:

- bounded RSS/FDs;
- no zombies;
- no stale execution state;
- no old release serving after completed drain;
- no parent-inherited DB/cache/broker resource use;
- no unbounded queues/buffers;
- deterministic shutdown/reload.

---

### 29. Development sequence

Foundation-side sequence should follow released/lower-layer readiness:

```text
1. Runwire process/supervisor/loop/network core
2. Runwire HTTP/1 transport
3. Webrick RunwireRuntimeAdapter
4. Foundation server config + native serve wiring
5. persistent request-scope/cancellation/streaming acceptance
6. Omnibus 2.6 Runwire delegation + Foundation queue integration
7. structured Foundation operation registry over Runwire ProcessRunner
8. Pathwise/ReqShield boundary integration tests
9. runtime/release-generation control integration
10. full benchmark + soak + security acceptance
11. Runwire 1.0 release
12. pin Foundation final graph to released ^1.0
```

Runwire can develop in parallel with remaining 26.7/26.8/26.9 specialist passes, but Foundation final aggregate release readiness is blocked until Point 26.12 closes.

---

### 30. Proposed canonical tracker update

When this addendum is reconciled into the canonical Foundation plan, add:

```text
| 26.12 | Runwire | ^1.0 | open / launch dependency |
```

Update the lower-library ownership list with:

```text
Runwire: process execution, worker/process supervision, event-loop, network listener/connection/server mechanics and generic runtime control.
```

Update the Foundation invariant so it also prohibits a second generic process/server runtime above Runwire.

Update the current execution order so Point 27/Phase 10 runs only after **26.12** is closed.

---

### 31. Proposed Point 26.12 completion gate

Foundation Point 26.12 closes only when:

- [ ] released `infocyph/runwire:^1.0` is in the final Composer graph;
- [ ] Webrick Runwire adapter is released/consumable and is Foundation's native HTTP server path;
- [ ] Foundation does not implement a duplicate generic socket/event-loop/process supervisor;
- [ ] generic Foundation process execution uses Runwire structured process APIs;
- [ ] normal web workers boot Foundation application resources only after fork;
- [ ] every HTTP request receives a fresh `webrick.request` execution and deterministic cleanup;
- [ ] keep-alive/Fiber/persistent isolation is proven;
- [ ] Runwire backpressure and transport limits compose correctly with Webrick/Foundation limits;
- [ ] Foundation release generations integrate with Runwire rolling worker generations without conflating identity;
- [ ] Omnibus process pool no longer requires Foundation raw-signal watchdog behavior;
- [ ] process operation authorization/ReqShield/Pathwise boundaries are proven;
- [ ] untrusted code cannot run in normal trusted workers by accidental fallback;
- [ ] PHP 8.4/8.5 stable/lowest QA is green;
- [ ] native runtime benchmarks and production-style soak/fault tests are recorded and acceptable;
- [ ] final release/security documentation describes capability profiles and OS sandbox boundary accurately.

---

### 32. Aggregate release gate change

Foundation's final Point 27 / aggregate Phase 10 must not run to completion until:

```text
26.4 / 26.5 / 26.7 / 26.8 / 26.9 / 26.10 / 26.12
```

are closed according to their final accepted plans.

Runwire is therefore a **Foundation 3 launch dependency**, not an optional post-release experiment.

---

### 33. Non-goals

Do not use this move to put into Foundation:

- Runwire event-loop internals;
- raw socket parser state;
- direct `pcntl` child tables;
- generic process-result/PID abstractions;
- a second Workerman-style server;
- Omnibus queue semantics;
- Pathwise upload mechanics;
- ReqShield shell scanning;
- a fake PHP sandbox;
- cluster/service-discovery orchestration.

Foundation is the orchestrating application framework; Runwire is the low-level process/network runtime.

---

### 34. Immediate handoff

Do not implement the Foundation adapter before Runwire's low-level HTTP transport contract and Webrick adapter boundary are sufficiently stable.

The first Foundation integration milestone should prove:

```text
Runwire prefork worker
  -> child boots Foundation application
  -> Webrick adapter receives one native request
  -> fresh Foundation request execution
  -> compiled Webrick route
  -> response streamed back through Runwire
  -> exact cleanup
  -> same connection serves second request with no leaked state
```

Only after that path is correct should Foundation add operational reload/control conveniences and broader process-operation APIs.

### Integrated runtime selection and OPcache requirements

### Foundation 3 — Runwire Runtime Selection Addendum

**Status:** normative addendum to `foundation-3-runwire-native-runtime-launch-plan.md`  
**Branch:** `foundation-3/runwire-launch-plan`  
**Runwire target:** 1.0

Foundation must not hard-wire Runwire 1.0 to only the Runwire-native server. Foundation selects a Runwire runtime driver while keeping the same Webrick/application execution semantics.

Supported selection values:

```text
auto
native
fpm
frankenphp
swoole
roadrunner
```

OPcache is configured separately:

```text
auto
on
off
required
```

Recommended Foundation configuration:

```php
'runwire' => [
    'runtime' => env('RUNWIRE_RUNTIME', 'auto'),
    'opcache' => env('RUNWIRE_OPCACHE', 'auto'),
];
```

Recommended CLI selection for server-capable modes:

```bash
php foundation serve --runtime=native
php foundation serve --runtime=frankenphp
php foundation serve --runtime=swoole
php foundation serve --runtime=roadrunner
```

FPM remains host-launched; Foundation detects/selects the FPM driver while executing inside the FPM request rather than spawning PHP-FPM itself.

Ownership rules:

- Runwire `native`: Runwire owns listener/event loop/HTTP transport/process supervision.
- FPM: PHP-FPM owns FastCGI listener and process pool; Runwire is request-bound adaptation/lifecycle only.
- FrankenPHP: FrankenPHP owns server/thread/worker mechanics; Runwire adapts classic/worker lifecycle.
- Swoole/OpenSwoole: host owns event loop/server/workers/coroutines; Runwire adapts lifecycle and request transport.
- RoadRunner: RR owns external server/process pool/worker dispatch; Runwire adapts worker lifecycle/transport.
- Webrick remains the application HTTP semantics owner in every mode.
- Foundation remains application graph, execution-scope, release-generation, authorization and deployment-policy owner.

Foundation must consume Runwire capability reporting instead of scattering runtime-name conditionals across application services.

Explicit runtime selection must fail fast when unavailable; production must never silently fall back to a different engine. `auto` may detect an already active host runtime and otherwise use Runwire-native only when the platform satisfies its required capabilities.

Persistent-mode acceptance is mandatory for FrankenPHP worker mode, Swoole/OpenSwoole and RoadRunner: every request gets a fresh Foundation execution state and cleanup runs in `finally`; globals/statics/application singleton state must not become accidental request state.

OPcache must be treated as a runtime acceleration policy, not a server choice. Foundation/Runwire may validate whether it is enabled, but must not claim it can always enable system-level OPcache settings from application code.

Add these items to the Foundation 26.12 completion gate:

- [ ] select Runwire driver through config/CLI without changing the application/Webrick API;
- [ ] support `auto|native|fpm|frankenphp|swoole|roadrunner`;
- [ ] support separate `opcache=auto|on|off|required` policy;
- [ ] explicit unavailable runtime fails before serving traffic;
- [ ] host runtimes do not get nested Runwire listener/event-loop/process pools;
- [ ] persistent driver request-state isolation passes;
- [ ] FPM remains request-bound and does not boot the native server;
- [ ] runtime capability diagnostics identify the selected driver and important supported features;
- [ ] benchmark direct host integration versus Foundation→Webrick→Runwire adapter overhead for each supported runtime.

This addendum must be reconciled into the canonical Foundation runtime plan before Foundation 3 release.

---

# 27. Aggregate Foundation 3 release-readiness after lower-library passes

Run only after 26.4/26.5/26.7/26.8/26.9/26.10/26.12 are closed.

- [ ] Composer normal install/release constraints pass on PHP 8.4/8.5, prefer-lowest and prefer-stable.
- [ ] PHPForge quality/static/security analysis is green.
- [ ] no unexpected skipped/deprecated tests remain under release policy.
- [ ] capability-absent graphs remain genuinely cold for every optional lower library.
- [ ] Fiber/persistent-worker isolation passes across auth, OAuth/OIDC/PAT, filesystem, validation, messaging and communication boundaries.
- [ ] aggregate release generation/activation/rollback tests remain green.
- [ ] final `benchmark:release` attributes lower-layer work versus Foundation policy/bridge overhead without hiding security/I/O costs.
- [ ] InfByte consumption/handoff is validated against the final Foundation 3 lifecycle.
- [ ] plan/tracker is reconciled and completed historical detail is archived/condensed.

---

## Immediate handoff

Continue **26.10.3 exact-head MFA/recovery acceptance**, then close the remaining signed-URL/domain-separation portion of 26.10.2/26.10.3. After those are green, begin the 26.10.4 move/keep/replace inventory and replace Foundation OAuth/OIDC/PAT protocol mechanics with the released Epicrypt 3 core while retaining Foundation transport/application/persistence ownership.
