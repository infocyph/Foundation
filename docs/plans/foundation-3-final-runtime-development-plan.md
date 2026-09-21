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
| 26.4 | OTP / Passkey | `^6.1` | **complete** |
| 26.5 | Pathwise | `^4.1` | **complete** |
| 26.6 | DBLayer | `^5.1` | **complete** |
| 26.7 | ReqShield | `^3.1` | **ACTIVE** |
| 26.8 | Omnibus | `^2.5` | open/deferred |
| 26.9 | TalkingBytes | `^2.0` | open/deferred |
| 26.10 | Epicrypt | `^3.1` | **complete** |
| 26.11 | standalone WebAuthn specialist pass | OTP 6.1 Passkey | **closed/subsumed** |

Current Foundation development graph intentionally contains:

```text
infocyph/epicrypt ^3.1
infocyph/otp ^6.1
infocyph/pathwise ^4.1
```

Epicrypt `3.0` was released on **2026-09-10** and established the stable 3.x boundary; Foundation now consumes the released `^3.1` line. Epicrypt's production requirements contain neither Pathwise nor OTP, so Epicrypt `^3.1` + OTP `^6.1` + Pathwise `^4.1` resolves normally without compatibility/VCS/path shims.

---

## 4. Current execution order

1. Execute 26.7 ReqShield 3.1 utilization and exact-head acceptance.
2. Execute 26.8 Omnibus 2.5 utilization and exact-head acceptance.
3. Execute 26.9 TalkingBytes 2.0 utilization and exact-head acceptance.
4. Run aggregate Phase 10 / Foundation release-readiness gates.
5. Validate the final InfByte consumption/handoff against the completed Foundation 3 lifecycle.

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

## 26.4 OTP 6.1 + Passkey/WebAuthn — complete

### Ownership

OTP owns TOTP/HOTP/OCRA/AOTP/GridOTP/MobileOTP mechanics, provisioning, replay/challenge semantics, recovery-code mechanics/contracts, secret-rotation primitives, and Passkey/WebAuthn ceremony validation/state. Foundation owns factor/account policy, capability activation, durable application persistence/CAS, principal mapping, secret-at-rest policy, deployment key lifecycle selection, audit/error mapping, and runtime composition.

### Closure evidence

- [X] Foundation OTP floor is `^6.1`; OTP is the sole lower-layer MFA/Passkey mechanics boundary.
- [X] Foundation Passkey routes through OTP `Passkey`; AOTP/GridOTP are native integrations and MobileOTP remains explicit legacy compatibility.
- [X] selected stateful modes fail closed without authoritative CacheLayer authentication state; HOTP/counter-OCRA and passkey credential transitions use revision-aware authoritative Foundation persistence.
- [X] recovery-code HMAC and MFA secret-protection keys use independent runtime-resolved lifecycles and Epicrypt domain separation.
- [X] DB-backed OTP `secret` / legacy MobileOTP `pin` values are protected through Epicrypt `StringProtector` under `foundation.auth.mfa-secret.v1`; AOTP public material remains unencrypted.
- [X] active/fallback MFA rotation, AAD binding, unknown/retired/tampered-key failure, explicit reprotection, and stale fallback reprotection versus newer counter revisions are covered.
- [X] legacy plaintext compatibility is opt-in migration-only, disabled by default, and the operational rewrite/disable/remove-fallback procedure is documented in `docs/security.md`.
- [X] recovery replacement/consumption is CAS-backed and double consumption is rejected.
- [X] TOTP/HOTP/OCRA/recovery/Passkey behavior, replay handling, sequential/Fiber reuse and fail-closed capability paths are covered by the Foundation suite.
- [X] `benchmark:otp` records direct OTP/Epicrypt work versus Foundation provisioning, secret-protection and recovery-state bridge overhead.
- [X] exact-head PHPForge QA/static analysis passed on PHP 8.4/8.5, prefer-stable and prefer-lowest; clean install and release benchmarks are green.

### Completion gate

26.4 is closed: OTP remains the mechanics owner, production state transitions are authoritative, durable symmetric MFA secrets are protected through Epicrypt 3.1, recovery/MFA key lifecycles are independent, rotation/concurrency/isolation tests pass, optional graphs remain cold, and performance attribution is recorded.

**Status:** [X] COMPLETE.
---

## 26.5 Pathwise 4.1 filesystem integration — complete

### Implemented

- [X] Foundation Composer floor is `^4.1`.
- [X] `StorageRegistry` is a thin application adapter over Foundation-owned Pathwise `StorageContext`.
- [X] old Pathwise process-global mount/default-namespace workaround removed.
- [X] application/generation owns explicit storage context; same-process/Fiber isolation coverage exists.
- [X] uploads adapt Webrick input through Pathwise `UploadSource::fromMover`.
- [X] downloads delegate exact byte/range iteration to `DownloadProcessor::streamChunks`; Webrick retains HTTP output ownership.
- [X] storage links delegate generic symlink safety to `SafeSymlinkManager`.
- [X] malware scanner composition uses Pathwise scanner/mode semantics.
- [X] Pathwise bridge benchmark coverage exists.
- [X] Foundation consumes released Epicrypt `^3.1`; the Epicrypt-2/Pathwise-3 conflict is removed.
- [X] normal Composer resolution has succeeded with Epicrypt 3 + OTP 6.1 + Pathwise 4.1 on the active integration branch.
- [X] stale `filesystem.uploads.require_malware_scan` usage/default is absent from the current tree.
- [X] Webrick-facing uploads use Pathwise 4.1 `UNTRUSTED_DATA`; finite chunk bounds, server-generated hash names and strict content validation cannot be downgraded by Foundation upload configuration.
- [X] trusted public/static resolution delegates canonical containment and symlink policy to Pathwise 4.1 `PublicFileResolver` before existing Pathwise download/Webrick response handling.

### Closure evidence

- [X] Exact-head filesystem/Pathwise coverage passes on PHP 8.4/8.5 stable/lowest.
- [X] `benchmark:pathwise` passes as part of `benchmark:release`.
- [X] the released dependency graph resolves without compatibility/VCS/path workarounds.

### Completion gate

26.5 closes when the exact final head proves normal released Epicrypt 3 + OTP 6.1 + Pathwise 4 resolution, the full filesystem suite and benchmark are green, no global Pathwise state/duplicate storage mechanics return, and Webrick remains the HTTP response/output owner.

**Status:** [X] COMPLETE — released Pathwise 4.1 resolves normally; the Foundation filesystem suite, static analysis, clean install and Pathwise 4.1 bridge benchmarks prove the final filesystem integration while Webrick remains the HTTP response/output owner.

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

**Status:** ACTIVE — next lower-library utilization pass.

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

## 26.10 Epicrypt 3.1 consumption, auth-protocol adoption and Foundation crypto-policy consolidation — complete

### Released baseline and ownership

- [X] Foundation consumes released Epicrypt `^3.1`; Epicrypt 3.x production dependencies contain neither Pathwise nor OTP.
- [X] frozen Epicrypt `ep2` protected formats remain the persisted-compatibility baseline where Foundation actually persists them.
- [X] Epicrypt owns generic crypto/key mechanics, purpose-bound tokens, protection, password primitives, JOSE/JWK/JWKS/PKI, signed-token/key-readiness mechanics, and transport-neutral OAuth 2.1/OIDC/PAT state machines.
- [X] Foundation owns Webrick/HTTP adaptation, application login/consent decisions, accounts/principals, authoritative DBLayer/CacheLayer adapters, application scope/audience policy, deployment key locators/rotation selection, audit/telemetry/config/CLI and runtime composition.

### 26.10.1 — dependency graph

- [X] Foundation `require-dev["infocyph/epicrypt"]` is `^3.1`.
- [X] Epicrypt `^3.1` + OTP `^6.1` + Pathwise `^4.1` resolves normally without compatibility shims.
- [X] exact-head clean install, dependency constraints, QA and analysis pass on PHP 8.4/8.5 stable/lowest.
- [X] Pathwise 4.1 filesystem acceptance remains closed.

### 26.10.2 / 26.10.3 — crypto consolidation and independent key domains

Stable Foundation domains:

```text
foundation.auth.mfa-secret.v1
foundation.auth.recovery-hmac.v1
foundation.auth.simple-token.<purpose>.v1
foundation.oauth.signing.v1
foundation.environment.file.v1
foundation.signed-url.v1
```

- [X] generic Foundation `HmacTokenCodec` mechanics were replaced with Epicrypt `PurposeToken`; claim/application mapping remains Foundation-owned.
- [X] generic KDF work delegates to Epicrypt `KeyDeriver`; canonical key generation delegates to `KeyMaterialGenerator`.
- [X] `EnvironmentFileProtector` is a thin path/config adapter over Epicrypt atomic `FileProtector`.
- [X] OAuth signing readiness/key eligibility/JWKS validation delegates to Epicrypt `AsymmetricSigningKeySet`.
- [X] signed-URL key lifecycle/rotation uses Epicrypt `KeyDeriver` + `KeyRing` under `foundation.signed-url.v1`; Webrick remains the sole URL canonicalization/sign/expiry/verification owner.
- [X] recovery HMAC and MFA protection use independent external runtime key locators; MFA active/fallback rotation is deterministic and bounded.
- [X] simple-token roots are resolved at runtime; generated InterMix artifacts are tested not to contain the runtime token root.
- [X] environment-file protection uses the independent external key-file/environment boundary and `foundation.environment.file.v1`.
- [X] legacy plaintext MFA migration-off and fallback-removal procedure is documented and tested with revision-authoritative reprotection.

### 26.10.4 — released Epicrypt OAuth/OIDC/PAT protocol core

- [X] authorization request validation, exact redirect matching, PKCE S256, scopes/audiences and safe protocol errors delegate to Epicrypt.
- [X] authorization interaction/code issue-consume, grants, refresh rotation/reuse, access-token issue/inspection/resource validation, revocation and introspection use Epicrypt protocol services with Foundation authoritative stores/adapters.
- [X] client authentication supports `client_secret_basic`, `client_secret_post`, public clients and registered-key-only `private_key_jwt`; replay state is authoritative and request-supplied/unregistered keys are not trust sources.
- [X] DPoP token/resource binding, replay and wrong-key rejection use Epicrypt.
- [X] OAuth metadata/JWKS are projected from Epicrypt-owned protocol/signing services.
- [X] OIDC request/interaction policy, nonce/`max_age`, `auth_time`/`acr`/`amr`, ID tokens, UserInfo and provider metadata use Epicrypt; Foundation retains application login/consent/account UX.
- [X] personal/API tokens use Epicrypt `PersonalAccessTokenManager`; only authoritative token state/metadata is persisted, never raw PAT JWT.
- [X] PAT `revokeAll()` and concurrent issue share a serialized subject-state boundary; the process-level race test uses an Epicrypt-valid 192-bit Base64URL token ID.
- [X] Foundation retains safe error/audit/HTTP mapping without restoring protocol state machines or unreleased compatibility shims.

### 26.10.5 — compatibility, protocol and security acceptance

- [X] MFA active/fallback/tamper/AAD/reprotection/legacy migration and DB-at-rest tests pass.
- [X] simple PurposeToken wrong-purpose/context/expiry/root-rotation behavior is covered.
- [X] environment-file protect/unprotect and failure-preservation behavior is covered.
- [X] password bcrypt/legacy Argon2i verification and Argon2id rehash compatibility is covered.
- [X] OAuth Authorization Code + PKCE, Client Credentials, Refresh, revocation/introspection/resource validation and authoritative status behavior are covered.
- [X] registered-key `private_key_jwt` and DPoP replay/binding negative paths are covered.
- [X] OIDC Authorization Code, nonce, prompts, `max_age`, `auth_time`, ACR/AMR, subject, ID Token, UserInfo and provider metadata are covered.
- [X] PAT issue/verify/list/revoke/revoke-all/abilities and concurrent issue-vs-revoke-all behavior are covered; raw JWT persistence is rejected by test.
- [X] generated-runtime secret isolation and sequential/Fiber cryptographic state isolation are covered.
- [X] malformed/missing production key configuration fails readiness/validation before normal traffic where the boundary can be validated ahead of requests.
- [X] exact-head QA/static analysis passes on PHP 8.4/8.5 stable/lowest.

### 26.10.6 — performance attribution

- [X] `benchmark:epicrypt` records direct-versus-Foundation attribution for signed URLs/key policy, MFA protection, PurposeToken, JWKS, environment-file protection, password verification, OAuth resource validation, OIDC UserInfo and PAT authoritative persistence.
- [X] `benchmark:otp` separately attributes OTP provisioning, Epicrypt MFA protection and recovery-state mapping.
- [X] `benchmark:representative` retains persistent-runtime request overhead/memory coverage.
- [X] both PHP 8.4 and 8.5 release benchmark jobs pass on the completed integration head.

### 26.10 completion gate

- [X] released Epicrypt `^3.1` + OTP `^6.1` + Pathwise `^4.1` resolves and passes exact-head PHPForge QA/analysis.
- [X] generic timed-token/KDF/protection/signing-readiness duplication is removed or reduced to explicit Foundation application/config adapters.
- [X] no Foundation OAuth/OIDC/PAT state machine remains where released Epicrypt owns the behavior.
- [X] production auth state adapters are authoritative/concurrency-safe; raw authorization-code JWE, refresh-token JWE and PAT JWT are not persisted.
- [X] independent key lifecycles exist for MFA, recovery HMAC, simple tokens, OAuth/auth purposes, environment-file protection and signed URLs.
- [X] bounded rotation, generated-artifact secret isolation, runtime isolation, protocol suites and direct-Epicrypt-versus-Foundation attribution are green.
- [X] the Epicrypt-dependent remainder of 26.4 is closed.

**Status:** [X] COMPLETE — exact-head run #1411 is green across PHP 8.4/8.5 stable/lowest QA, analysis, clean install and release benchmarks.
---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is the Foundation-facing WebAuthn ceremony/state boundary. Foundation retains application persistence, user-handle/principal mapping, passkey policy and authorization. It does not own WebAuthn ceremony construction/validation/signature-counter mechanics.

**Status:** [X] closed/subsumed.

---

# 27. Aggregate Foundation 3 release-readiness after lower-library passes

Run after the remaining active lower-library passes 26.7/26.8/26.9 are closed; 26.4, 26.5 and 26.10 are complete.

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

Begin **26.7 ReqShield 3.1 utilization**. Rescan Foundation validation against the released 3.1 API, freeze production schema topology, keep DB validation optional/lazy, prove non-DB/Fiber/persistent isolation, add direct-vs-Foundation attribution, and close it on exact-head PHPForge QA before proceeding to 26.8 Omnibus and 26.9 TalkingBytes.
