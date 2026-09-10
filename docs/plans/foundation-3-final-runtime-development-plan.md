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
- **DBLayer:** connections, leases/pools, queries, repositories, migrations, DB transaction/cache mechanics.
- **CacheLayer:** cache semantics, locks, atomic primitives, counters, backend coordination.
- **OTP:** OTP/AOTP/GridOTP/MobileOTP/Passkey mechanics and OTP-owned replay/challenge/recovery state semantics.
- **Pathwise:** storage contexts/adapters, uploads/downloads, safe filesystem mechanics.
- **ReqShield:** validation/sanitization/schema/rule execution.
- **Omnibus:** messaging/events/queues/workers/workflows.
- **TalkingBytes:** HTTP/email/webhook/gRPC communication mechanics.
- **Epicrypt:** cryptography, key generation/derivation/rotation, protection, passwords, JOSE/JWK/JWKS, generic signed-token/key-readiness mechanics, and the transport-neutral OAuth 2.1/OIDC/PAT protocol core with authoritative auth-state contracts.

Foundation must not add a second DI runtime, HTTP runtime, DB pool/query builder, validation engine, messaging runtime, storage engine, OTP/WebAuthn protocol runtime, cryptographic implementation, or OAuth/OIDC/PAT protocol implementation above the specialist package that already owns it.

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
7. Run aggregate Phase 10 / Foundation release-readiness gates.

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

Omnibus owns envelope/bus/routing/transports/consumer/retry/failure/workflow/worker/worker-pool/scheduled-message mechanics and its CacheLayer/DBLayer integrations. Foundation owns configured application IDs/routes/transports, graph inclusion, generation supervision, execution scopes, correlation and selection of lower-layer DB/cache services.

### Open work

- [ ] Rescan Foundation Omnibus 2.5 usage and remove duplicated mechanics.
- [ ] Keep durable DB/cache integrations lazy and selected explicitly.
- [ ] Require intentional durable failure-store policy for durable async workers.
- [ ] Bind after-commit behavior to the current execution connection; never capture scoped connections in singletons.
- [ ] Keep retry/settlement inside Omnibus Consumer and WorkerPool beneath Foundation generation supervision.
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

# 27. Aggregate Foundation 3 release-readiness after lower-library passes

Run only after 26.4/26.5/26.7/26.8/26.9/26.10 are closed.

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
