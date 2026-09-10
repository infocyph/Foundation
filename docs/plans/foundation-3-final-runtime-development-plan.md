# Foundation 3 — Unified Runtime Development Plan

**Status:** Canonical implementation plan  
**Foundation target:** 3.x  
**Planning branch:** `foundation-3/close-26.6`  
**Priority:** correctness → security/persisted compatibility → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single current source of truth for Foundation 3 runtime development. The pre-Epicrypt-3 long-form lower-library audit is preserved at `docs/plans/archive/foundation-3-runtime-plan-pre-epicrypt3-integration.md`; that archive is evidence/history, not a second active plan.
>
> **Plan-maintenance rule:** completed passes may be condensed after evidence is recorded. An open pass must retain ownership, current findings, an implementation checklist, correctness/security acceptance, performance acceptance, and a completion gate. If a lower library already owns a generic mechanism, Foundation consumes it directly instead of creating a Foundation-only substitute.

---

## 1. Architectural invariants

Foundation has four runtime paths: `web`, `cli`, `worker`, and `scheduler`. They share one Foundation composition source, but every independently active runtime uses a fresh InterMix builder/generated artifact. Webrick remains the sole web HTTP runtime/output owner.

Foundation owns application configuration, capability selection, provider/composition policy, application persistence policy, runtime orchestration, release-generation/activation, diagnostics, and application-facing adaptation.

Lower libraries own their generic mechanics:

- **InterMix:** DI graph, lifetimes, scopes, generated containers, execution isolation.
- **Webrick:** HTTP route/runtime/request/middleware/response mechanics.
- **DBLayer:** connections, leases/pools, queries, repositories, migrations, DB transaction/cache mechanics.
- **CacheLayer:** cache semantics, locks, atomic primitives, counters, backend coordination.
- **OTP:** OTP/AOTP/GridOTP/MobileOTP/Passkey mechanics and OTP-owned replay/challenge state.
- **Pathwise:** storage contexts/adapters, uploads/downloads, safe filesystem mechanics.
- **ReqShield:** validation/sanitization/schema/rule execution.
- **Omnibus:** messaging/events/queues/workers/workflows.
- **TalkingBytes:** HTTP/email/webhook/gRPC communication mechanics.
- **Epicrypt:** cryptography, key generation/derivation/rotation, protection, password, JOSE/JWK/JWKS, generic signed-token/key-readiness mechanics, and the transport-neutral OAuth 2.1/OIDC/PAT protocol core with authoritative auth-state contracts.

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

## 3. Lower-library tracker and current dependency reality

| Point | Library | Foundation floor / target | Status |
| --- | --- | --- | --- |
| 26.1 | ArrayKit | `^5.2` | complete |
| 26.2 | UID | `^5.0` | complete |
| 26.3 | CacheLayer | `^3.4` | complete |
| 26.4 | OTP / Passkey | `^6.1` | core complete; Epicrypt-dependent acceptance remains |
| 26.5 | Pathwise | **actual branch: `^4.0`** | implementation complete; normal-package acceptance blocked by Epicrypt 2.1 constraint |
| 26.6 | DBLayer | `^5.1` | complete |
| 26.7 | ReqShield | `^3.1` | open |
| 26.8 | Omnibus | `^2.5` | open |
| 26.9 | TalkingBytes | `^2.0` | open |
| 26.10 | Epicrypt | **actual branch: `^2.1`; next target: `^3.0`** | **NEXT** |
| 26.11 | standalone WebAuthn specialist pass | OTP 6.1 Passkey | closed/subsumed |

Current Foundation development graph contains both:

```text
infocyph/epicrypt ^2.1
infocyph/pathwise ^4.0
```

That combination cannot resolve because Epicrypt 2.1 requires Pathwise 3.x. This was the real blocker behind 26.5; it was not missing Pathwise 4 implementation work.

Epicrypt 3 removes Pathwise from its production graph. Epicrypt 3 CI has already proven that the real `foundation-3/close-26.6` graph resolves when only the Foundation Epicrypt constraint is temporarily changed to `^3.0` and the candidate is injected as `3.0.0`, on both PHP 8.4 and 8.5. Final Foundation acceptance still requires a normally consumable released 3.x package.

---

## 4. Current execution order

The next work is deliberately linked:

1. **26.10 Epicrypt 3 consumption — package/dependency closure.**
2. **Close 26.5 Pathwise 4** under the normal Foundation dependency graph.
3. **26.10 Foundation crypto-wrapper consolidation, auth-protocol consumption and secret policy.**
4. **Close the Epicrypt-dependent remainder of 26.4 OTP/Passkey** (symmetric MFA-secret protection, recovery-key lifecycle, rotation/concurrency acceptance).
5. Return to open lower-library passes: **26.7 ReqShield → 26.8 Omnibus → 26.9 TalkingBytes**.
6. Run aggregate Phase 10 / Foundation release-readiness gates.

Do not reopen lower-library architecture already finalized in Epicrypt 3 or Pathwise 4 merely to make Foundation integration easier.

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

## 26.4 OTP 6.1 + Passkey/WebAuthn — core complete; crypto-dependent acceptance open

### Ownership

OTP owns TOTP/HOTP/OCRA/AOTP/GridOTP/MobileOTP mechanics, provisioning, OTP replay/challenge semantics, recovery-code mechanics/contracts, secret-rotation primitives, and Passkey/WebAuthn ceremony validation/state. Foundation owns factor/account policy, capability activation, durable application persistence/CAS, principal mapping, secret-at-rest policy, audit/error mapping, and application runtime composition.

### Already implemented

- [X] Foundation OTP floor is `^6.1`.
- [X] OTP is the sole lower-layer MFA/Passkey mechanics boundary.
- [X] Foundation passkey routes through OTP `Passkey`; direct ceremony duplication is removed.
- [X] AOTP/GridOTP integration exists; MobileOTP is explicit legacy compatibility.
- [X] selected stateful modes fail closed without the required CacheLayer authentication-state capability.
- [X] HOTP/counter-OCRA durable transitions use authoritative Foundation CAS.
- [X] passkey credential record persistence uses revision-aware replacement.
- [X] sensitive OTP/private-key-shaped enrollment diagnostics are redacted.

### Remaining after/during 26.10

- [ ] Protect every durable symmetric OTP/GridOTP/MobileOTP secret at rest through the finalized Epicrypt 3 policy.
- [ ] Decide recovery-code HMAC key lifecycle explicitly; do not silently tie it to an unrelated token-signing secret.
- [ ] Use Epicrypt `KeyDeriver` or a dedicated external key for the recovery-code domain.
- [ ] Prove active/previous key rotation cannot lose newer factor/counter/recovery state.
- [ ] Re-audit recovery-store committed-count/replacement/atomic-consumption semantics against OTP 6.1.
- [ ] Preserve OTP result/reason taxonomy internally; separate credential/replay failures from coordination/persistence/runtime failures.
- [ ] Prove every production MFA CAS store is authoritative under the supported concurrency/deployment matrix.
- [ ] Complete representative TOTP/HOTP/OCRA/recovery/Passkey sequential, Fiber and persistent-worker isolation tests.
- [ ] Complete final PHP 8.4/8.5 stable/lowest QA and direct-OTP-versus-Foundation stateful overhead attribution.

### Completion gate

26.4 closes only when OTP remains the sole protocol/mechanics owner, all Foundation factor/credential stores are atomically authoritative, symmetric factor secrets are protected through Epicrypt 3, recovery-key purpose/lifecycle is explicit, concurrency/rotation tests pass, optional graphs remain cold, and final QA/performance evidence is green.

**Status:** core implementation complete; final acceptance is linked to 26.10.

---

## 26.5 Pathwise 4 filesystem integration — implementation complete; final acceptance linked to 26.10

### Corrected baseline

The old plan said Foundation still used Pathwise `^3.1` and was waiting for the 4.0 tag. That is no longer true.

Current branch reality:

```text
infocyph/pathwise ^4.0
```

Foundation has already implemented the Pathwise 4 architecture on `foundation-3/close-26.6`.

### Implemented Foundation Pathwise 4 work

- [X] `StorageRegistry` reduced to a thin application adapter over Foundation-owned Pathwise `StorageContext`.
- [X] old Pathwise process-global mount/default-namespace workaround removed from the normal application path.
- [X] one application/generation owns its explicit storage context; same-process/Fiber isolation coverage exists.
- [X] uploads adapt Webrick input through Pathwise `UploadSource::fromMover` rather than a Foundation generic upload materializer.
- [X] downloads delegate exact byte/range iteration to `DownloadProcessor::streamChunks`; Webrick retains HTTP status/header/body/output ownership.
- [X] storage links delegate generic symlink safety to `SafeSymlinkManager`; Foundation retains application root/mapping policy.
- [X] malware scanner composition maps to the Pathwise scanner/mode model and has focused scanner-mode coverage.
- [X] Pathwise bridge benchmark coverage exists.
- [X] Foundation Composer floor is already `^4.0`.

### Actual blocker and proof that it is solved in Epicrypt 3

The unresolved Composer graph is:

```text
Foundation -> Epicrypt ^2.1 -> Pathwise ^3.1
Foundation -> Pathwise ^4.0
```

Epicrypt 3 removes its production Pathwise dependency. The Epicrypt 3 release-candidate acceptance has already proven Foundation composition against `foundation-3/close-26.6` by changing only the Epicrypt constraint to `^3.0` and injecting the candidate as `3.0.0`; full Foundation development dependency resolution passes on PHP 8.4 and PHP 8.5 and rejects the legacy Pathwise 3 edge.

Therefore 26.5 has no remaining architecture/implementation blocker. It needs normal-package acceptance once a consumable Epicrypt 3.x version exists.

### Remaining 26.5 acceptance

- [ ] After Epicrypt 3 stable is consumable, change the actual Foundation Epicrypt constraint to the selected 3.x floor and perform a normal Composer update.
- [ ] Prove the installed graph selects Epicrypt 3.x + OTP 6.1 + Pathwise 4 on PHP 8.4/8.5 without path/VCS injection.
- [ ] Run the full Foundation filesystem suite under that normal graph: context isolation, upload source cleanup, scanner modes, downloads/ranges/early abort, safe links, persistent/Fiber reuse and capability absence.
- [ ] Remove the stale ignored `filesystem.uploads.require_malware_scan` default from `FoundationDefaults`; the Pathwise malware-mode contract is authoritative.
- [ ] Re-run `benchmark:pathwise` and keep only Foundation application-policy/Webrick bridge attribution.
- [ ] Run final Foundation stable/lowest PHP 8.4/8.5 CI/static analysis.

### Completion gate

26.5 closes when the real Foundation Composer file consumes a published/otherwise normally consumable Epicrypt 3.x together with Pathwise 4; the filesystem suite/benchmark remains green; the stale boolean malware default is removed; no global Pathwise state or duplicate generic storage mechanics return; and Webrick remains the HTTP response/output owner.

**Status:** implementation complete; dependency-candidate composition proven; final normal-package acceptance remains.

---

## 26.6 DBLayer 5.1 — complete

Foundation uses execution-owned DBLayer connections/`ConnectionRepository`, delegates generic DB mechanics to DBLayer, retains domain persistence/CAS policy, and keeps pooling opt-in with execution isolation.

**Status:** [X] complete.

---

## 26.7 ReqShield 3.1 utilization — open/deferred until 26.10/26.4 closure

### Ownership

ReqShield owns rule parsing/compilation/execution, sanitization/casting, nested/wildcard validation, limits, result/failure models, schema composition/JSON-schema export, bounded plan caching and optional database-rule batching. Foundation owns named application schemas, application defaults/overrides, Webrick input adaptation, optional DBLayer provider selection and HTTP/application error mapping.

### Implementation checklist

- [ ] Rescan all Foundation ReqShield usage against 3.1.
- [ ] Finalize production schema topology before traffic; normal request/job paths must not mutate the process-wide registry.
- [ ] Keep per-call mutable validators unless a lower-layer immutable/reentrant compiled form is explicitly proven safe.
- [ ] Do not add a Foundation validation-plan cache above ReqShield's bounded cache without attribution evidence.
- [ ] Keep DB validation optional and lazy; validation-only graphs must not activate DBLayer.
- [ ] Preserve ReqShield `DatabaseProvider`/batching semantics and DBLayer parameter-limit handling in the narrow Foundation adapter.
- [ ] Treat `exists`/`unique` as validation observations, never replacements for DB constraints/transactions.
- [ ] Preserve depth/field/wildcard/path bounds as security controls.
- [ ] Keep application callable rules/sanitizers as explicit dynamic islands.
- [ ] Preserve structured validation results internally.

### Acceptance

- [ ] Cover named/custom schemas, composition, representative rules, sanitization/casting, nested/wildcard/strict modes and validation limits.
- [ ] Prove non-DB validation performs no DB I/O and DB rules batch correctly.
- [ ] Prove repeated/Fiber validation cannot retain previous execution state.
- [ ] Benchmark direct ReqShield vs Foundation factory/schema/database bridge, including warm plan-cache behavior and capability-absent cost.

### Completion gate

ReqShield closes when generic validation remains lower-layer-owned, optional DB behavior is genuinely lazy, production schema topology is stable, mutable state cannot leak across persistent executions, security limits remain intact, and direct-vs-Foundation attribution is recorded.

---

## 26.8 Omnibus 2.5 utilization — open/deferred until 26.10/26.4 closure

### Ownership

Omnibus owns envelope/bus/routing/transports/consumer/retry/failure/workflow/worker/worker-pool/scheduled-message mechanics and its CacheLayer/DBLayer integrations. Foundation owns configured application service IDs/routes/transports, graph inclusion, generation supervision, execution scopes, correlation and selection of lower-layer DB/cache services.

### Implementation checklist

- [ ] Rescan all Omnibus 2.5 integration and remove Foundation mechanics duplicated by native Omnibus APIs.
- [ ] Expose selected durable transports through Foundation configuration without activating DBLayer/CacheLayer for sync/memory-only graphs.
- [ ] Require an intentional durable failure-store policy for durable asynchronous workers.
- [ ] Bind DB after-commit behavior to the current execution connection; never capture a scoped connection in a process singleton.
- [ ] Keep retry/settlement decisions inside Omnibus Consumer.
- [ ] Validate handler/listener/middleware service IDs during composition while resolving scoped services only during execution.
- [ ] Compose Omnibus WorkerPool beneath Foundation generation supervision rather than creating competing supervisors.
- [ ] Keep durable serialization explicit and never serialize runtime/service/container objects.
- [ ] Redact sensitive envelope contents from diagnostics.

### Acceptance

- [ ] Test sync/memory/durable transport topology, retry/failure settlement, after-commit execution ownership, workflow/coordination and persistent-worker isolation.
- [ ] Prove messaging-absent graphs stay cold.
- [ ] Benchmark direct Omnibus versus Foundation bus/consumer/worker bridge and selected durable transports with lower-layer costs attributed separately.

### Completion gate

Omnibus closes when Foundation is only application topology/lifecycle policy, durable integrations reuse selected DBLayer/CacheLayer correctly, settlement/retry semantics remain Omnibus-owned, worker supervision is non-duplicative, sensitive envelopes are safe, and direct-vs-Foundation attribution is recorded.

---

## 26.9 TalkingBytes 2.0 utilization — open/deferred until 26.10/26.4 closure

### Ownership

TalkingBytes owns HTTP client mechanics, inbound/outbound email/message chains, webhook protocol/signature behavior and gRPC request/response/stream mechanics. Foundation owns named communication profiles, capability selection, application service-ID mapping, secure replay-store selection, worker-scope integration and application secret/redaction policy.

### Implementation checklist

- [ ] Rescan every Foundation TalkingBytes binding against 2.0.
- [ ] Classify profile/client/resilience-state lifetimes explicitly; cookie/session/auth mutable client state must not leak across requests/jobs/Fibers.
- [ ] Keep webhook replay keys on the Foundation security-key encoder and require CacheLayer atomic `setIfAbsent` for production replay.
- [ ] Preserve fail-closed webhook replay and composition-time validation.
- [ ] Route inbound gRPC through the existing Foundation worker execution lifecycle; do not create a fifth runtime graph.
- [ ] Add named inbound/outbound email profiles through TalkingBytes native email/message-chain APIs.
- [ ] Keep HTTP/webhook/gRPC/email protocol behavior lower-layer-owned.
- [ ] Keep communication secrets out of logs/cache keys/generated metadata.
- [ ] Keep communication capability absent from unselected graphs.

### Acceptance

- [ ] Test TLS/profile auth/cookie isolation/retry/rate-limit/circuit behavior.
- [ ] Test webhook signature, age and concurrent replay rejection.
- [ ] Test gRPC unary/stream dispatch and execution-scope isolation.
- [ ] Test inbound/outbound email/message-chain behavior and secret redaction.
- [ ] Benchmark direct TalkingBytes versus Foundation profile/dispatch/replay bridge with persistent-runtime memory/state checks.

### Completion gate

TalkingBytes closes when lifetimes are safe, replay is atomic/fail-closed, gRPC uses the existing worker lifecycle, email/message-chain behavior is consumed rather than recreated, secrets remain safe, optional graphs stay cold, and direct-vs-Foundation attribution is recorded.

---

## 26.10 Epicrypt 3 consumption, auth-protocol adoption and Foundation crypto-policy consolidation — NEXT

### Epicrypt 3 release baseline

Epicrypt 3 protocol implementation Phases A–G are complete; final 3.0 release hardening remains in Phase H before Foundation adoption begins.

Current source-of-truth baseline:

- repository: `infocyph/Epicrypt`;
- branch: `epicrypt-3/architecture-plan`;
- current architecture-branch head observed during this reconciliation: `c92e67be63616316f4b56df996898de15d6f5e42`;
- Epicrypt 3.0 is still unreleased and the final release-ready SHA is intentionally not frozen until its release gates are green;
- Epicrypt's public API inventory now defines a reusable transport-neutral OAuth 2.1, OIDC 1.0 and personal/API-token core in addition to the finalized crypto/JOSE surface.

Foundation must treat the released Epicrypt 3.0 API inventory/tag as authoritative at implementation time and recheck it before changing code. Do not pin Foundation integration behavior to an intermediate Epicrypt development SHA.

Persisted cryptographic compatibility remains separate from source/API compatibility: frozen Epicrypt 2.x `ep2` string/file and signed-payload-v2 formats remain readable/verifiable where Foundation actually persists them.

### Final ownership decision

Epicrypt owns generic cryptographic mechanics:

- entropy/key material generation and explicit encoding;
- purpose-labelled HKDF/subkey derivation;
- KeyRing/key purpose/key eligibility/rotation metadata;
- string/file/envelope protection and authenticated formats;
- generic purpose-bound timed signed tokens;
- signed URL cryptography/key selection;
- asymmetric signing-key readiness/coherence/public JWKS validation;
- password hashing/verification/rehash policy primitives;
- JOSE/JWK/JWKS cryptographic validation;
- certificate/key/PKI cryptographic operations.

Epicrypt also owns transport-neutral authentication protocol mechanics and state-machine semantics:

- OAuth client registration profile validation and client authentication, including `private_key_jwt` replay handling;
- OAuth authorization request validation, exact redirect handling, PKCE S256, scope/audience validation, safe protocol errors and interaction DTOs;
- authorization-code protected artifact issue/consume semantics and one-time authoritative code state contracts;
- Authorization Code, Client Credentials and Refresh Token grant mechanics through the token endpoint core;
- refresh rotation/reuse/revocation semantics;
- RFC 9068 access-token issue/inspection/resource validation and optional authoritative token-status checks;
- RFC 7009 revocation, RFC 7662 introspection, RFC 8414 authorization-server metadata and purpose-scoped JWKS publication;
- DPoP proof validation and access-token binding;
- OIDC request semantics, interaction requirements, ID Token issue, subject identifiers, UserInfo projection and provider metadata;
- generic PAT issue/validate/list/revoke/revoke-all and abilities/wildcard semantics;
- framework-neutral authoritative store contracts and safe request/result/error/capability DTOs for the above.

Foundation owns application policy and integration:

- Webrick routes, HTTP request/response adaptation and status/header mapping;
- login/authentication UI and acquisition of the authenticated application subject;
- consent UI, application authorization decision and consent-history persistence;
- account/user repositories and principal mapping;
- DBLayer/CacheLayer implementations of released Epicrypt client/code/refresh/authorization/access-status/PAT/replay contracts;
- backend transaction/locking boundaries required to make those stores authoritative;
- application scope/permission policy and multi-resource scope→audience mapping where required;
- tenant/client administration outside Epicrypt's immutable runtime protocol fields;
- external secret references/sources and process-boot resolution;
- application key purposes and rollout/rotation policy;
- storage schema/key-version metadata where application persistence needs it;
- when protected values are decrypted/reprotected;
- sessions/cookies;
- Foundation rate limits/audit/telemetry/diagnostics;
- Foundation config/path/CLI behavior;
- selecting/configuring Epicrypt services.

Do **not** move these into Epicrypt:

- Webrick routes or HTTP transport behavior;
- login/authentication UI or authenticated-subject acquisition;
- consent UI/application decision or consent-history persistence;
- account/user/principal repositories;
- concrete Foundation DB/cache store implementations and transaction ownership;
- Foundation application permission policy or tenant/client administration outside protocol fields;
- session/cookie wiring;
- Foundation rate limits/audit/telemetry policy;
- Foundation config/path/CLI behavior;
- WebAuthn/Passkey ceremonies or OTP domain mechanics.

Conversely, Foundation must not reimplement PKCE, OAuth grant state machines, authorization-code/refresh semantics, access-token claims/validation, OIDC nonce/hash semantics, DPoP, PAT ability semantics, protocol error safety or generic auth cryptography that released Epicrypt 3 already owns.

### Batch 26.10.1 — consume Epicrypt 3 and close the dependency graph

- [ ] Release Epicrypt 3.0 and make the stable 3.x package consumable through the normal Composer source used by Foundation.
- [ ] Refetch Foundation branch head and the released Epicrypt 3 public API inventory before integration; reconcile any final release-surface changes into this plan first.
- [ ] Change Foundation `require-dev["infocyph/epicrypt"]` from `^2.1` to the selected released 3.x floor (`^3.0` unless a later 3.x floor is deliberately chosen).
- [ ] Run a normal `composer update`; do not rely on aliases/replaces, Pathwise 3 compatibility packages, or a VCS/path workaround for final acceptance.
- [ ] Assert the resolved graph includes Epicrypt 3.x, OTP 6.1 and Pathwise 4 on PHP 8.4 and 8.5.
- [ ] Assert Epicrypt production dependencies contain no Pathwise or OTP.
- [ ] Run Foundation clean-install/release-constraint checks.
- [ ] Immediately execute the remaining 26.5 Pathwise normal-package acceptance and remove stale `filesystem.uploads.require_malware_scan`.

**Batch gate:** the real Foundation composer graph resolves normally against released Epicrypt 3.x and 26.5 can close without compatibility shims.

### Batch 26.10.2 — remove generic Foundation crypto duplication

Audit every Foundation crypto site before deleting wrappers. Preserve application/domain mapping where it is genuinely Foundation-owned.

- [ ] Replace generic `HmacTokenCodec` signing/timing/purpose mechanics with Epicrypt `PurposeToken`; remove `HmacTokenCodec` when no Foundation-specific behavior remains.
- [ ] Reduce `AbstractSimpleTimedTokenService` to Foundation domain claim mapping/application behavior; generic `iat`/`exp`/purpose/token-ID/signature mechanics remain Epicrypt-owned.
- [ ] Replace ad-hoc raw-HMAC recovery/application subkey derivation with Epicrypt `KeyDeriver` and explicit long-lived purpose labels.
- [ ] Keep OTP recovery semantics OTP-owned; only generic key derivation/lifecycle moves to Epicrypt.
- [ ] Keep `EnvironmentSecretManager` responsible for `.env` selection/editing/cache clearing, but use Epicrypt `KeyMaterialGenerator` and explicit `KeyMaterialEncoding` for canonical secret generation.
- [ ] Migrate all removed Epicrypt-2-style generator usage: no `MasterSecretGenerator`, no `TokenMaterialGenerator`, no boolean key-material encoding switches.
- [ ] Keep `EnvironmentFileProtector` as a thin application path/config adapter over Epicrypt `FileProtector`; delete any duplicate crypto-adjacent staging/publication protocol because Epicrypt local-file protection is already atomic.
- [ ] Refactor `OAuthSigningKeyResolver` / Foundation signing-key set so generic readiness, key-pair coherence, eligibility and public JWKS validation use Epicrypt `AsymmetricSigningKeySet`; keep Foundation config/file loading and audit.
- [ ] Use Epicrypt's dedicated signed-URL key domain/rotation support instead of treating signed URLs as generic signed payload keys.
- [ ] Preserve stable Epicrypt result/exception detail internally while mapping failures to non-sensitive Foundation behavior externally.

**Batch gate:** Foundation wrappers contain only application semantics; no generic MAC/KDF/protection/readiness/timed-token implementation remains duplicated.

### Batch 26.10.3 — explicit Foundation key domains, protection and rotation

Use stable domain names as data contracts. Initial required domains include at least:

```text
foundation.auth.mfa-secret.v1
foundation.auth.recovery-hmac.v1
foundation.auth.simple-token.<purpose>.v1
foundation.oauth.signing.v1
foundation.environment.file.v1
foundation.signed-url.v1
```

- [ ] Decide whether each domain receives a dedicated externally supplied key or a deliberately derived subkey from an explicit master key.
- [ ] Never let unrelated token-signing, recovery-HMAC, MFA-encryption or signed-URL functions silently share one key lifecycle.
- [ ] Protect every production durable symmetric MFA secret through Epicrypt high-level data protection.
- [ ] Configure released Epicrypt auth-specific key purposes independently from Foundation simple-token/recovery/MFA/signed-URL domains; do not alias unrelated auth artifact/signing purposes merely to reduce configuration.
- [ ] Persist only the bounded key-id/version/purpose metadata required for deterministic read/rotation.
- [ ] New writes use only the active key; bounded previous/fallback keys exist only for intended read/verify migration windows.
- [ ] Never trial-decrypt arbitrary unrelated keys.
- [ ] Reprotect on an explicit Foundation policy boundary after successful old-key read; do not make every read an uncontrolled write.
- [ ] Keep plaintext secrets only inside the narrow execution window that needs them.
- [ ] Keep raw keys/plaintext/passphrases/tokens out of generated InterMix artifacts, release manifests, cache keys, logs, exceptions and metric labels.
- [ ] Resolve deployment secret references at process/runtime boot where practical; never discover secret files/providers on steady request hot paths.

**Persisted compatibility rule:** do not migrate crypto formats merely because the package major changed. Existing valid durable Epicrypt 2.x material must remain readable where Foundation persists it. Any Foundation domain that intentionally changes format needs old/new fixtures and an explicit migration/read-write policy.

### Batch 26.10.4 — consume Epicrypt 3 OAuth/OIDC/PAT protocol core

First produce a move/keep/replace inventory of every existing Foundation auth/OAuth/OIDC/API-token class, route, store, schema and test against the released Epicrypt 3 public API. Delete Foundation protocol mechanics only after the corresponding application adapter and migration/compatibility evidence exist.

- [ ] Replace Foundation OAuth authorization-request protocol validation with Epicrypt `OAuthAuthorizationRequestValidator`; preserve duplicate occurrences from Webrick parsing so singleton duplicates are rejected rather than collapsed.
- [ ] Keep exact redirect, `response_type=code`, mandatory PKCE S256, scope/audience bounds and safe redirect/error semantics Epicrypt-owned; Foundation only maps validated results to Webrick responses.
- [ ] Implement Foundation `OAuthAuthorizationAudienceResolverInterface` policy for multi-resource clients; use `OAuthSingleAudienceResolver` only where the released client registration has exactly one audience.
- [ ] Use Epicrypt authorization interaction/approval types for the login-versus-authorization-decision handoff; Foundation owns login UI, consent UI, consent history and the actual application decision.
- [ ] Delegate authorization-code issue/consume to Epicrypt `OAuthAuthorizationCodeIssuer` / `OAuthAuthorizationCodeConsumer`; Foundation must never persist the raw authorization-code JWE.
- [ ] Implement authoritative production adapters for the released OAuth client, authorization-code, refresh-token and approved-authorization store contracts using DBLayer/CacheLayer as appropriate; exact client IDs and security-sensitive reads must not be weakened by case folding or stale caches.
- [ ] Reuse the released `JwtReplayStoreInterface` contract for `private_key_jwt` and DPoP replay where required; do not introduce a Foundation OAuth-specific replay abstraction unless application policy genuinely differs.
- [ ] Delegate client authentication, including client-secret and registered-key `private_key_jwt`, to Epicrypt `OAuthClientAuthenticator`; Foundation owns credential provisioning/admin only.
- [ ] Replace Foundation token-grant mechanics with Epicrypt `OAuthTokenEndpoint` for Authorization Code, Client Credentials and Refresh Token flows; Foundation maps transport, application subject/context and persistence adapters around it.
- [ ] Consume Epicrypt refresh rotation/reuse semantics and `RefreshTokenStoreInterface`; raw refresh JWE must never be persisted and atomic consume/replace must remain authoritative under concurrency.
- [ ] Consume Epicrypt `OAuthAccessTokenService`, `OAuthAccessTokenInspector` and `OAuthResourceAccessTokenValidator`; implement `OAuthAccessTokenStatusStoreInterface` only when Foundation selects authoritative JWT status/revocation.
- [ ] Route revocation/introspection through Epicrypt `OAuthRevocationEndpoint` and `OAuthIntrospectionEndpoint`; retain only Webrick endpoint protection, HTTP adaptation, rate limits and audit mapping in Foundation.
- [ ] Generate authorization-server metadata and auth-scoped JWKS through Epicrypt `OAuthAuthorizationServerMetadata` / `OAuthJwksPublisher`; Foundation owns route exposure and deployment/config selection.
- [ ] Integrate Epicrypt `OAuthDpopValidator` and DPoP token binding into protected-resource adaptation where the capability is enabled; do not duplicate proof parsing/claim/signature/replay logic.
- [ ] Replace Foundation OIDC protocol mechanics with Epicrypt OpenID request/interaction, ID-token issue, subject identifier, UserInfo projection and provider-metadata services; Foundation supplies account claims/principal mapping through the released provider interfaces.
- [ ] Preserve application ownership of public/pairwise subject source data and claim authorization; Epicrypt owns the protocol projection/signing rules.
- [ ] Replace generic Foundation personal/API-token mechanics with Epicrypt `PersonalAccessTokenManager`/policy/abilities/validation semantics where the use case matches Epicrypt PATs.
- [ ] Implement authoritative `PersonalAccessTokenStoreInterface` plus optional usage-store adapter; persist metadata/state only, never the raw PAT JWT.
- [ ] Serialize `revokeAll()` against concurrent PAT issue for the same subject using a deterministic production transaction/lock boundary.
- [ ] Preserve Epicrypt protocol result/status/error taxonomy internally for audit/diagnostics while mapping only safe non-oracular failures to external HTTP/application responses.
- [ ] Keep all Epicrypt auth in-memory/conformance stores test-only; production Foundation must bind authoritative DB/cache-backed adapters explicitly.
- [ ] Do not retain compatibility shims for intermediate unreleased Epicrypt 3 auth APIs; integrate only the final released 3.0 surface.

**Batch gate:** Foundation owns transport/application/persistence adapters only; OAuth 2.1/OIDC/PAT protocol validation, grant/state-machine behavior and auth cryptography execute through released Epicrypt 3 services with authoritative Foundation-backed stores.

### Batch 26.10.5 — Foundation compatibility, protocol and security tests

- [ ] Inventory which Foundation encrypted/signed/auth artifacts are durable versus ephemeral before migration.
- [ ] Freeze Foundation fixtures for any durable Epicrypt-2-backed protected value/file/signed payload that must survive upgrade.
- [ ] Prove those durable fixtures read/verify under Epicrypt 3.
- [ ] Test purpose/domain/key-id/AAD mismatches fail closed.
- [ ] Test active-key write + fallback read + explicit reprotection.
- [ ] Test interrupted rotation/rollback and unknown/retired key handling.
- [ ] Test MFA secrets persist only in protected form in every production store.
- [ ] Test recovery-code verification/rotation/atomic consumption under the final key lifecycle.
- [ ] Test simple password-reset/passwordless/email-verification flows after `PurposeToken` migration, including wrong purpose/context, expiry/not-before and key rotation.
- [ ] Test environment secret generation and protected-file round-trip/failure preservation.
- [ ] Test OAuth signing readiness/JWKS with RSA/PSS/EC/EdDSA configurations actually supported by Foundation and released Epicrypt 3.
- [ ] Test signed URL issue/verify/rotation through the dedicated signed-URL key purpose.
- [ ] Test passwords through the selected Epicrypt 3 policy, including legacy Argon2i verification/rehash and bcrypt compatibility where Foundation exposes it.
- [ ] Add end-to-end Webrick→Epicrypt→Foundation-store OAuth Authorization Code + PKCE coverage, including duplicate parameters, redirect mismatch, wrong verifier, replay, client mismatch, narrowed scopes and multi-audience resolution.
- [ ] Add Client Credentials and Refresh Token end-to-end coverage, including refresh rotation, reuse detection, revocation, wrong-client binding and concurrent consume/replace.
- [ ] Add `private_key_jwt` coverage for registered-key trust, assertion audience/time/jti bounds and atomic replay rejection; embedded/untrusted key locators must not become trust sources.
- [ ] Add access-token resource validation, optional authoritative status/revocation, introspection and non-oracular revocation coverage.
- [ ] Add DPoP positive/negative/replay/binding coverage when enabled.
- [ ] Add OIDC Authorization Code coverage for `openid`, nonce, prompt/max_age, auth_time/acr/amr, subject mapping, ID Token, UserInfo and provider metadata; keep encrypted ID Tokens excluded unless Epicrypt later exposes a released interoperable capability.
- [ ] Add PAT issue/validate/list/revoke/revoke-all/ability/wildcard and concurrent issue-vs-revoke-all coverage; prove raw PATs never reach persistence/logs.
- [ ] Prove authorization-code, refresh-token, authorization, access-token-status and PAT stores never return stale active state after revocation/disablement commits.
- [ ] Test generated runtime/release artifacts contain no raw configured key, auth code, refresh token, PAT or decrypted MFA secret.
- [ ] Test normal logs/exceptions do not contain key/plaintext/passphrase/raw-token material.
- [ ] Test sequential/interleaved Fiber/persistent-worker auth/crypto operations retain no prior execution plaintext, protocol request state or mutable key/client selection state.
- [ ] Fail build/boot before traffic for missing/malformed/unsupported production key/auth configuration wherever validation can be done safely without request-time secret work.

### Batch 26.10.6 — performance attribution

Benchmark only integration-relevant boundaries; do not benchmark away cryptographic or protocol security work.

1. crypto/auth capability absent versus enabled-but-unused graph/boot cost;
2. direct Epicrypt `KeyDeriver` versus Foundation purpose-key lookup/derivation;
3. direct string protect/unprotect versus Foundation MFA-secret persistence bridge;
4. active-key read versus fallback-read + reprotection policy path;
5. direct `PurposeToken` issue/verify versus Foundation simple-token domain mapping;
6. direct signing-key readiness/JWKS export versus Foundation config/resolver bridge;
7. direct file protect/unprotect versus Foundation environment-file path/config adapter;
8. signed URL direct Epicrypt versus Foundation application-policy bridge;
9. password verify/rehash direct Epicrypt versus Foundation auth adapter;
10. direct Epicrypt authorization-request validation/code issue-consume versus Webrick/Foundation route+policy+store bridge;
11. direct Epicrypt token endpoint/access-token validation versus Foundation adapter/store overhead;
12. direct Epicrypt OIDC/PAT operations versus Foundation principal/claims/persistence bridge;
13. repeated representative crypto/auth operations under persistent runtime with memory measurement.

External secret-provider I/O, database/cache I/O, HTTP adaptation and actual cryptographic/KDF cost must be attributed separately before optimizing Foundation wrapper code.

### 26.10 completion gate

26.10 closes only when:

- [ ] the actual Foundation Composer graph uses a normal released Epicrypt 3.x together with OTP 6.1 and Pathwise 4 on PHP 8.4/8.5;
- [ ] 26.5 Pathwise 4 is closed and its stale malware boolean is removed;
- [ ] every Foundation crypto/auth site is classified as Epicrypt mechanics/protocol or Foundation application/transport/persistence policy;
- [ ] generic timed-token, KDF, protection and signing-readiness duplication is removed or explicitly justified;
- [ ] no Foundation OAuth/OIDC/PAT protocol implementation remains where released Epicrypt 3 owns the behavior;
- [ ] Foundation production implementations of the selected Epicrypt authoritative stores are atomic, concurrency-safe and cannot return stale active state after committed revocation/disablement;
- [ ] raw authorization-code JWE, refresh-token JWE and PAT JWT values are never persisted;
- [ ] `private_key_jwt`/DPoP replay state uses authoritative atomic semantics and no unregistered key locator can select trust material;
- [ ] OAuth Authorization Code + PKCE, Client Credentials, Refresh Token, revocation, introspection, metadata/JWKS and selected DPoP paths pass end-to-end Foundation integration tests;
- [ ] OIDC Authorization Code, ID Token, UserInfo/discovery and subject/claims-provider integration pass for the released Epicrypt capability set;
- [ ] PAT issue/validate/revoke/revoke-all and ability semantics pass with authoritative persistence and deterministic issue-vs-revoke-all serialization;
- [ ] explicit independent key purposes/lifecycles exist for MFA protection, recovery HMAC, simple tokens, OAuth/auth signing/artifacts as required by Epicrypt, environment-file protection and signed URLs;
- [ ] durable symmetric MFA secrets are protected at rest;
- [ ] bounded active/fallback rotation and deterministic key selection are proven;
- [ ] persisted compatibility fixtures/read policy are proven for durable material;
- [ ] secret/redacted-token release-artifact/runtime-isolation tests pass;
- [ ] Foundation PHP 8.4/8.5 stable/lowest QA, static analysis and focused security/protocol suites are green;
- [ ] direct-Epicrypt-versus-Foundation attribution records final adapter/policy/persistence overhead;
- [ ] the Epicrypt-dependent remainder of 26.4 is either closed in the same integration PR or has only explicitly non-crypto work remaining.

**Status:** [ ] NEXT — begin with Batch 26.10.1 only after Epicrypt 3.0 is released and normally consumable.

---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is the Foundation-facing WebAuthn ceremony/state boundary. Foundation retains application persistence, user-handle/principal mapping, passkey policy and authorization. It no longer owns WebAuthn ceremony construction/validation/signature-counter mechanics.

**Status:** [X] closed/subsumed.

---

# 27. Aggregate Foundation 3 release-readiness after lower-library passes

Run only after 26.4/26.5/26.7/26.8/26.9/26.10 are closed.

- [ ] Composer normal install and release constraints pass on PHP 8.4/8.5, prefer-lowest and prefer-stable.
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

**Next Foundation implementation session starts at 26.10 Batch 26.10.1 after Epicrypt 3.0 is released.**

Before changing Foundation code, verify the released Epicrypt 3 tag/version and final public API inventory, refetch the Foundation branch head, then update the real Composer constraint, resolve the graph normally, run 26.5 acceptance, and proceed through crypto-wrapper consolidation, explicit key policy, OAuth/OIDC/PAT protocol adoption, compatibility/security tests and performance attribution.