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
| 26.7 | ReqShield | `^3.2` | **complete** |
| 26.8 | Omnibus | `^2.6` | **complete** |
| 26.9 | TalkingBytes | `^2.1` | **complete** |
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

1. Run aggregate Phase 10 / Foundation release-readiness gates.
2. Validate the final InfByte consumption/handoff against the completed Foundation 3 lifecycle.

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

## 26.7 ReqShield 3.2 utilization — complete

### Ownership

ReqShield owns rule parsing/compilation/execution, sanitization/casting, nested/wildcard validation, input limits, result/failure models, schema snapshots, frozen compiled validators, bounded execution-plan caches, JSON-schema export, database-rule batching and the native DBLayer 5.1 provider. Foundation owns application schema/default/override selection, Webrick/request adaptation, graph activation, configured connection selection and HTTP/application error mapping.

### Closure evidence

- [X] Foundation Composer/module floor is `^3.2`.
- [X] Foundation consumes ReqShield's native instance-owned `SchemaRegistry`; application/base schemas and configured extensions are composed once and the registry is frozen before normal execution.
- [X] the duplicate Foundation `ValidationSchemaRegistry` was removed.
- [X] Foundation consumes ReqShield 3.2 `DBLayerDatabaseProvider` directly with an execution-time DBLayer connection resolver; the duplicate Foundation database provider was removed.
- [X] ReqShield remains authoritative for SQL identifier allowlisting, logical validation batching, DBLayer-safe physical sizing, NULL/ignore/soft-delete semantics and raw-SQL-policy fallback.
- [X] database validation remains optional/lazy; non-database validation proves it does not create/open the configured SQLite database.
- [X] configured input-depth/field/wildcard/flattened-path limits remain Foundation-exposed security policy over ReqShield enforcement.
- [X] ReqShield 3.2 frozen `CompiledValidator` reuse is covered across sequential and Fiber-interleaved execution without prior request-state leakage.
- [X] direct provider tests cover exists/unique, constrained bind limits, ignored owners, soft deletes and malicious table/column identifier rejection.
- [X] `benchmark:reqshield` records direct ReqShield compiled/factory/database work versus the Foundation profile/schema adapter, and is part of `benchmark:release`.
- [X] module installer/docs and Composer metadata consistently advertise ReqShield `^3.2`.
- [X] exact-head PHPForge run #1421 is green across PHP 8.4/8.5 prefer-stable/prefer-lowest QA, PHPStan/Psalm analysis, clean install and both release benchmark jobs.

### Completion gate

26.7 is closed: Foundation adds only application composition/profile policy, while ReqShield 3.2 owns reusable frozen validation topology, plan caching and the DBLayer database-rule bridge. Optional DB capability stays cold until selected, persistent/Fiber isolation is proven, and direct-versus-Foundation attribution is recorded.

**Status:** [X] COMPLETE.
---

## 26.8 Omnibus 2.6 utilization — complete

### Released baseline

Omnibus **2.6** is published at commit
`17a86f28215b36f237a9db3e014c5425c4d6ea0a`. Foundation consumes released
`^2.6` only; no VCS/path compatibility alias is used.

Selecting Omnibus 2.6 carries its mandatory `ext-pcntl` + `ext-posix`
runtime floor. Foundation itself keeps Omnibus optional and does not duplicate
extension-availability probing around Omnibus process APIs. Runwire 1.x remains
an optional, explicitly selected WorkerPool backend; installation/presence alone
must never switch Foundation away from Omnibus's native PCNTL/POSIX backend.

### Ownership

Omnibus owns envelope/bus/routing/transports/consumer/retry/failure/workflow,
single-worker and WorkerPool mechanics, native/Runwire pool supervision,
lifecycle polling, restart/recycle budgets, child reaping, scheduled-message
mechanics and its CacheLayer/DBLayer integrations. Foundation owns configured
application IDs/routes/transports, optional graph inclusion, release-generation
policy, heartbeat/stop decisions, execution scopes, correlation and selection
of lower-layer DB/cache services.

Foundation may provide a `WorkerLifecycle` carrying generation heartbeat and
stop policy, but Omnibus must own the timing/signal/supervision mechanics that
invoke it.

### Open work

- [X] Raise `infocyph/omnibus` from `^2.5` to released `^2.6`; no
  VCS/path compatibility alias is used.
- [X] Rescan Foundation messaging against the released 2.6 API; native/Runwire
  backend selection, parent lifecycle polling and managed-child worker behavior
  are now taken from the released package surface.
- [X] Remove `WorkerManager::watchPool()` and its Foundation SIGALRM watchdog;
  Foundation heartbeat/generation-stop policy now passes through Omnibus
  `WorkerPool(..., lifecycle: ...)` directly.
- [X] Preserve the parent-clean fork boundary: `OmnibusWorkerFactory` now
  resolves `ConsumerFactory` lazily only when constructing a worker, so parent
  pool configuration reads do not resolve transport/failure/consumer resources;
  child applications still build the real worker graph after fork.
- [X] Keep Omnibus's native WorkerPool as the default. Foundation does not
  auto-select or expose a Runwire backend merely because Runwire is installed;
  explicit backend selection remains an Omnibus/application concern.
- [X] Remove Foundation-side PCNTL/POSIX availability/fallback logic around
  Omnibus workers/pools; `WorkerManager` contains no pool `pcntl_*`,
  `posix_*` or watchdog path.
- [X] Keep durable integrations lazy and explicit: DBLayer queue/failure/workflow
  services exist only under `messaging.durable.enabled`; Foundation owns no
  duplicate message uniqueness/overlap layer, so Omnibus CacheLayer coordination
  remains an explicit application/Omnibus opt-in instead of being auto-enabled.
- [X] Treat the 2.5 -> 2.6 durable-storage upgrade as a coordinated cutover:
  documentation requires draining all 2.5 readers/writers before the first 2.6
  writer, and acceptance coverage proves 2.6 reads legacy unwrapped payloads
  while retaining the no-mixed-reader/no-unverified-rollback rule.
- [X] Require intentional durable failure-store policy for database consumers
  and workers; `database` and deliberately volatile `memory` are the only
  accepted configured policies.
- [X] Bind Omnibus `AfterCommitDispatcher` as an execution-scoped service that
  resolves the current DBLayer execution connection at scope resolution time;
  durable transport/failure/workflow stores separately use the process-owned
  infrastructure connection required by Omnibus.
- [X] Keep retry/settlement, restart budgets and child lifecycle mechanics
  inside native Omnibus Consumer/WorkerPool; Foundation supplies only routing,
  DI/execution-scope and generation heartbeat/stop policy.
- [X] Prove lifecycle ownership at the correct layer: Foundation covers
  single-worker/pool lifecycle adaptation and child-only graph creation, while
  released Omnibus 2.6's native/Runwire backend contract suite proves heartbeat,
  crash-backoff responsiveness, clean recycle, restart exhaustion, graceful/
  forced drain and child reaping/no-zombie behavior. Foundation does not expose
  Runwire selection, so no duplicate Runwire integration suite is added here.
- [X] Prove sync/memory/durable topology, terminal failure persistence, legacy
  payload compatibility, sequential/Fiber execution isolation, schema lifecycle
  and cold non-durable DB graphs.
- [X] Update 2.5-specific runtime guards/test names/messages to 2.6 and keep the
  bridge on released 2.6 APIs without Foundation compatibility branches.
- [X] Benchmark direct Omnibus versus the Foundation bridge for memory,
  durable DBLayer and worker-factory/lifecycle overhead. Raw native/Runwire
  process-supervision benchmarking remains Omnibus-owned, where the pool backend
  is implemented and explicitly selectable.
- [X] Exact-head PHP 8.4/8.5 lowest/stable PHPForge QA, analysis, clean install
  and release benchmarks are green on Foundation run #1473.

**Status:** [X] COMPLETE — released Omnibus 2.6 integration, lifecycle ownership,
durable compatibility, isolation coverage and direct-versus-Foundation benchmark
attribution are closed on an exact-head green PHPForge matrix.

---

## 26.9 TalkingBytes 2.1 utilization — complete

### Released baseline

TalkingBytes **2.1** is released from merged PR #13 at commit
`29fe13043225bfcf477adfa1f4dd1dc11fa4723f`. Foundation consumes released
`^2.1` only; no VCS/path compatibility alias is used.

TalkingBytes 2.1 adds host-oriented resolved protocol composition, explicit
persistent-runtime state/cancellation boundaries, a host-controlled inbound
gRPC exchange source, strengthened webhook replay semantics and native webhook
v2 delivery signatures binding timestamp + event + delivery ID + exact raw
body.

### Ownership

TalkingBytes owns HTTP client mechanics and resolved auth/cookie/retry/
resilience composition, inbound/outbound email transport/message chains,
webhook signing/verification/retry/replay contract, gRPC request/response/
stream mechanics and accepted-exchange protocol adaptation. Foundation owns
named profiles, capability selection, DI lifetimes, application path/secret
resolution, CacheLayer replay-store implementation, application handler lookup,
worker heartbeat/stop/release-generation policy and application observability
policy.

### Batch tracker

- [X] **Batch 1 — released floor + native resolved composition**
  - [X] raise Composer/module floor from `^2.0` to released `^2.1`;
  - [X] close 26.8 after exact-head green run #1473 and activate 26.9;
  - [X] replace Foundation HTTP auth/cookie/retry/rate-limit/circuit-breaker/
    idempotency assembly with TalkingBytes `HttpClient::fromResolvedConfig()`;
  - [X] replace Foundation gRPC retry/generated-stub assembly with
    `GrpcClientFactory`;
  - [X] replace Foundation webhook sender/verifier/receiver protocol assembly
    with TalkingBytes resolved-config APIs while retaining Foundation secret
    policy and CacheLayer replay-store selection;
  - [X] replace Foundation email sender transport/fallback/retry/rate-limit/DKIM
    assembly with `EmailSenderFactory::fromResolvedConfig()`; Foundation still
    resolves named transports, application paths and secrets.
- [X] **Batch 2 — lifetime/isolation + webhook acceptance**
  - [X] classify Foundation DI lifetimes against TalkingBytes 2.1 mutable-state
    semantics: immutable profile/factory/verifier graphs stay singleton while
    HTTP clients, webhook senders, emailers, spool receivers and inbound gRPC
    dispatchers keep execution-safe scoped/caller-owned state;
  - [X] prove sequential and Fiber-interleaved scope isolation for stateful HTTP
    and fake-email graphs; mutable clients are recreated across execution scopes;
  - [X] prove CacheLayer replay claims remain atomic/fail-closed through the
    existing contention/no-atomic coverage and add Foundation acceptance for
    TalkingBytes native v2 duplicate/tampered-delivery rejection;
  - [X] keep communication secrets out of runtime identity metadata, cache-key
    material and logs; the authenticated/restricted release `config.php` remains
    the intentional secret-bearing resolved configuration snapshot.
- [X] **Batch 3 — inbound gRPC worker lifecycle**
  - [X] consume TalkingBytes `GrpcInboundSource` / `serveOne()` through the
    existing Foundation `WorkerProvider` / `WorkerRuntime` heartbeat, stop and
    release-generation lifecycle;
  - [X] keep source/native transport ownership outside Foundation: applications
    bind a process-owned `GrpcInboundSource`; Foundation owns no gRPC socket or
    duplicate network/server loop;
  - [X] prove cancellation/stop responsiveness and fresh per-exchange handler
    scopes with the TalkingBytes fake accepted-exchange source.
- [X] **Batch 4 — email/native capability closure**
  - [X] prove native inbound/outbound email profiles remain TalkingBytes-owned;
    sender decorators/transports use `EmailSenderFactory::fromResolvedConfig()`,
    parser limits use `EmailLimits::fromArray()`, and mailbox/spool creation stays
    on native TalkingBytes factories;
  - [X] prove optional protocol graphs stay cold under explicit topology:
    `communication` activates HTTP/webhook/gRPC without email services while
    `notifications` activates email without HTTP/webhook/gRPC services;
  - [X] cover persistent ownership: IMAP/POP3 mailbox instances are freshly
    caller-owned and spool receivers are recreated across worker execution scopes.
- [X] **Batch 5 — benchmark + exact-head closure**
  - [X] benchmark direct TalkingBytes resolved composition versus Foundation
    HTTP/webhook/gRPC/email profile bridges; protocol-native transport/crypto/
    streaming/parser benchmarks remain TalkingBytes-owned;
  - [X] PHPForge run #1481 is green on implementation head
    `c4bb7f7486d965ec74e583f317c0e262e7a0b943` across PHP 8.4/8.5
    prefer-lowest/prefer-stable QA, PHPStan/Psalm analysis, clean install and
    both release benchmark jobs;
  - [X] close 26.9 after the full implementation-head matrix is green; this
    tracker reconciliation is documentation-only and changes no runtime code,
    dependency or generated topology.

### Security compatibility note

TalkingBytes 2.1 native webhook delivery uses bound `v2` signatures. Foundation
must not pair a native 2.0 sender with a 2.1 receiver or vice versa. The
Foundation integration uses the 2.1 native sender/receiver path together.

**Status:** [X] COMPLETE — released TalkingBytes 2.1 composition, persistent/
Fiber isolation, webhook v2/replay policy, inbound gRPC worker lifecycle,
email/capability ownership and direct-versus-Foundation attribution are closed
on green PHPForge run #1481.
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

# 27. Aggregate Foundation 3 release-readiness — COMPLETE

- [X] InfByte module-lifecycle handoff keeps `module:install/show/schema:*` execution-scoped when a connection may be used, while avoiding a synthetic `db` capability gate before the module/schema manager can determine whether database state is actually applicable.

- [X] InfByte lean-skeleton handoff additionally proved explicit capability-aware production validation: when `app.capabilities` is present, inactive auth/cache policy does not block release compilation; selecting `auth` retains the full hardened production checks. Omitting `app.capabilities` preserves legacy development auto-discovery semantics.

- [X] InfByte handoff audit caught and closed two core-consumer blockers after aggregate closure: core `app:install` no longer requires optional Epicrypt merely to create `AUTH_TOKEN_SECRET`, and module show/schema commands no longer force the database capability before they can report/install their own capability-owned schema state.

All lower-library passes are closed: 26.1 through 26.10 are complete where applicable, and 26.11 is closed/subsumed. Foundation-owned release readiness is closed independently before the separate InfByte skeleton handoff.

- [X] Composer normal install/release constraints pass on PHP 8.4/8.5, prefer-lowest and prefer-stable. Final PHPForge run #1489 is green on Foundation head `e76d08ed3492006b389b3b5972f3bb5f19b74937`.
- [X] PHPForge quality/static/security analysis is green: Composer audit, PHPStan, Psalm security analysis and SARIF generation/upload all returned success on PHP 8.4 and 8.5.
- [X] no unexpected skipped/deprecated tests remain under release policy. The reusable workflow ran with `fail_on_skipped_tests=true`; all four QA variants passed, and the final logs contain no runtime/test deprecation failure.
- [X] capability-absent graphs remain genuinely cold for optional lower libraries, covered by the optional-capability isolation suite plus the per-integration cold-path tests closed in 26.x.
- [X] Fiber/persistent-worker isolation passes across authentication/OAuth state, database/session state, filesystem/validation, Omnibus messaging and TalkingBytes communication boundaries. The persistent execution-state and 1,000-iteration runtime soak coverage remain green in the final QA matrix.
- [X] aggregate release generation/activation/replacement/source-isolation tests remain green, including one immutable Web/CLI/Worker/Scheduler generation, trusted manifest/config loading, generation-aware worker replacement and tamper rejection.
- [X] final `benchmark:release` is green on PHP 8.4 and 8.5 and attributes UID, CacheLayer, DBLayer, Epicrypt, Omnibus, OTP, Pathwise, ReqShield and TalkingBytes lower-layer work against Foundation bridge/policy overhead without duplicating specialist-native protocol benchmarks.
- [X] Foundation/InfByte ownership boundary is frozen for handoff: Foundation 3 owns the finalized lifecycle and migration contract; applying that contract to the InfByte skeleton is a separate post-Foundation task and is not a Foundation release-readiness blocker.
- [X] plan/tracker is reconciled. Lower-library evidence remains in 26.x as historical implementation detail; this Point 27 section is the condensed aggregate release record.

**Closure evidence:** Security & Standards run #1489 is green on final implementation head `e76d08ed3492006b389b3b5972f3bb5f19b74937`: PHP 8.4/8.5 prefer-lowest/prefer-stable QA, clean install, PHPStan, Psalm security analysis and both `benchmark:release` jobs all passed. The Security Report aggregation job was skipped by workflow conditions; all required producing jobs completed successfully.

---

## Immediate handoff

**Foundation 3 is release-ready.** Point 27 is closed on final green PHPForge run #1489 after all lower-library utilization passes, InfByte-driven consumer-boundary checks and aggregate runtime/release gates completed.

The next action is to merge/release Foundation 3. **InfByte work is intentionally deferred until after the Foundation 3 release**; its existing migration PR remains a separate consumer task and must not reopen Foundation runtime architecture.
