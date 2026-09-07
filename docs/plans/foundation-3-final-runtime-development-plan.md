# Foundation 3 — Unified Runtime Development Plan

**Status:** Canonical implementation plan  
**Foundation target:** 3.x  
**Foundation source baseline:** `main`  
**InterMix baseline:** `^10.0.4`  
**Webrick baseline:** `^5.3`  
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single source of truth for Foundation 3 runtime development. Completed work is intentionally summarized so the document stays maintainable. Open lower-library passes remain actionable and detailed. If a lower layer already owns the correct generic mechanism, Foundation consumes it directly; generic missing primitives belong in the lower layer, not in a Foundation-only workaround.

---

## 1. Architectural invariants

Foundation has four independent runtime paths: `web`, `cli`, `worker`, and `scheduler`. There is one Foundation graph/composition source, but each runtime uses a fresh InterMix `ContainerBuilder` and its own generated artifact. Webrick owns only the web HTTP path.

### Lower-layer ownership

**InterMix** owns DI graph composition/validation, lifetimes, aliases/values/contextual bindings/tags, generated production containers, scope seeds, execution isolation, lifecycle/scope leave, and compile reports.

**Webrick** owns web route registration/build, matcher compilation, Request materialization, middleware dispatch, HTTP request scope, routing-control responses, runtime adapters, native response writing/streaming, frozen URL/runtime registries, and coordinated web release metadata.

**DBLayer** owns connection mechanics, pooling/leases, reuse sanitation, QueryBuilder/result-cache semantics, optimistic writes, repository/result primitives, schema/migrations/seeding, database security, and database telemetry.

**CacheLayer** owns cache semantics, backends, locks, atomic primitives, namespaces, cache metrics, and backend-specific coordination guarantees.

### Foundation ownership

Foundation owns normalized application configuration, capability selection, provider composition policy, application-facing integrations, CLI/worker/scheduler orchestration, Foundation auth/session/database/filesystem policy, immutable cross-runtime release generation, deployment activation/trust, diagnostics, migration guidance, and attribution benchmarks.

Foundation must not add a second DI runtime, HTTP runtime, database pool, query builder, validation engine, queue runtime, filesystem engine, or cryptographic implementation above a lower library that already owns it.

### Stable execution scopes

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

Execution/request/job/message IDs are scope seeds/correlation values, never synthesized scope names.

---

## 2. Production/runtime contract

Production uses generated InterMix `ProductionContainer` instances. Every independently active runtime uses a fresh builder; unexpected skipped definitions fail release generation. Compilation-safe recipes remain `FactoryDefinition::construct(...)`, `FactoryDefinition::staticFactory(...)`, and `ServiceReference(...)`. Closure/direct factories remain explicit dynamic islands.

Web production remains:

```text
Foundation graph + route topology
 -> coordinated Webrick release compile
 -> generated InterMix web container
 -> compiled Webrick router
 -> RuntimeAdapter selected once
 -> RuntimeServer
```

The minimal compiled route must remain Request-free and scope-free when its execution plan permits it. Webrick is the sole native response writer.

---

## 3. Immutable release generation

All four runtime artifacts belong to one immutable Foundation generation:

```text
release/<generation>/
    foundation.php
    config.php                  optional
    web/                        Webrick + InterMix artifacts
    cli/                        InterMix artifact + metadata
    worker/                     InterMix artifact + metadata
    scheduler/                  InterMix artifact + metadata
```

Build and verify the complete generation before publication; atomically switch one active-generation pointer only after every runtime artifact validates; failed builds keep the previous generation active; persistent workers replace gracefully; old-generation cleanup stays outside request/job hot paths.

---

## 4. Foundation hashing policy

- **SHA3-256**: Foundation-owned security-sensitive derivation where collision resistance/security aliasing matters.
- **XXH128**: non-security deterministic fingerprints, freshness identities, and key compaction.
- No new Foundation-owned SHA-256.
- Protocol/persisted/lower-layer digest formats remain owned by their defining contracts.
- Hashes never replace MACs, signatures, encryption, or KDFs.

Foundation-owned security CacheLayer keys are domain-separated and retain the full SHA3-256 digest in a CacheLayer-legal encoding.

---

## 5. Completed runtime implementation summary

Phases 0–9 are complete. Do not regress:

- builder-first web/CLI/worker/scheduler composition;
- InterMix `^10.0.4` generated-runtime model;
- Webrick `^5.3` compiled production runtime;
- scoped/execution-local principal/session/database bookkeeping;
- stable semantic scopes and Fiber-safe isolation;
- route-first graph enrichment and one coordinated web compile;
- frozen routing/URL registries and execution-plan-driven Request/scope creation;
- Webrick-only native response output;
- generated non-web runtimes safely reused across executions;
- immutable unified release generation with atomic activation/rollback;
- bounded persistent worker/scheduler memory and state-isolation acceptance;
- Phase 9 correctness/static-analysis/performance acceptance.

Retained attribution evidence: generated-container resolution is effectively direct InterMix cost; `Application::make()` adds only tens of nanoseconds; non-web execution boundary adds only a few microseconds over bare InterMix scope entry/leave; compiled Foundation HTTP hot-path tax measured about 1% over standalone compiled Webrick; recorded PHP-FPM + OPcache Nginx/Apache runs completed without request failures.

---

## 6. Phase 10 release-readiness gates

Completed audits include dynamic-container mutation, old compile/resolver activation, closure aliases, Application/container capture, dynamic islands/singletons, Fiber cleanup, production source discovery, unnecessary Request/scope/global middleware, native output, hashing/manifest parsing, hidden DB/cache activation, cleanup exception preservation, and stale InterMix/Webrick configuration/docs.

Still open:

- [ ] validate aggregate hard gates after lower-library passes;
- [ ] validate final definition of done after lower-library passes;
- [ ] complete InfByte consumption/handoff against the final Foundation 3 lifecycle.

---

# 26. Subsequent lower-library utilization passes

Each pass must audit the current released API, keep generic mechanics in the lower layer, prove persistent/concurrent correctness where relevant, benchmark Foundation against the direct lower-layer operation where that evidence changes an architectural decision, and update this plan/tracker.

---

## 26.1 ArrayKit 5.2.0 — complete

**Released baseline:** ArrayKit 5.2.0, commit `053440b61071a17332b18879b12026f54a0ad144`.

Foundation delegates generic env/config parsing, raw environment access, layered lazy file config, dot notation, and merge mechanics to ArrayKit while retaining application source-order, host-protection, hydration, release-artifact, and validation policy. Production generated config remains source-discovery-free.

**Status:** [X] complete.

---

## 26.2 UID 5.0 — complete

**Released baseline:** UID 5.0, commit `4a95eb8058e73c72e74e44fedd25755198899eae`.

Foundation uses UID monotonic ULID as generated non-web correlation fallback while preserving supplied authoritative IDs byte-for-byte. Scope labels remain semantic/deterministic; release hashes, security randomness, and temporary entropy remain with their owning primitives.

**Status:** [X] complete.

---

## 26.3 CacheLayer atomic capability — complete; current floor 3.4

**Atomic capability baseline:** CacheLayer 3.3, commit `581194b184da929f7f098672ccf91869b1da984c`.  
**Current Foundation integration floor:** CacheLayer 3.4, tag commit `b064b8196ddc4672ce37be252bc7a4cadb78527e`.

CacheLayer owns optional atomic `setIfAbsent()`, `getAndDelete()`, `compareAndSet()`, counters, locks, cache semantics, backend correctness, and the 3.4 runtime used by DBLayer 5.1. Foundation consumes those primitives directly and does not emulate cache atomicity through Foundation locks.

- [X] 3.3 atomic capability integration completed.
- [X] Foundation floor raised to `infocyph/cachelayer ^3.4` and validated with DBLayer 5.1 during 26.6.

**Status:** [X] complete.

---

## 26.4 OTP 6.1 + Passkey/WebAuthn Foundation integration — implementation complete; closure pending

**Released baseline:** OTP 6.1, commit `c7faf376b96611638e7bc0da6cd081496768d34f`.

### Integrated contract

- [X] Foundation development floor raised to `infocyph/otp ^6.1` and `ext-sodium` added to the development matrix for AOTP coverage;
- [X] selected passkey ceremony behavior routes exclusively through OTP 6.1 `Passkey`;
- [X] direct Foundation WebAuthn ceremony/options/validator/serializer/codec duplication removed; the retained `WebAuthnRuntime` symbol is an empty deprecated cold-path compatibility sentinel only;
- [X] Foundation WebAuthn configuration narrowed to OTP-consumed RP ID, exact trusted origin, ceremony TTL, and optional subdomain policy;
- [X] AOTP and GridOTP are deliberately exposed; MobileOTP is available only through an explicit legacy-import workflow;
- [X] AOTP stores only the public key server-side; private-key generation/signing remains device-owned through OTP;
- [X] selected stateful OTP modes and Passkey require a CacheLayer `AuthenticationStateCacheInterface` and fail closed when unavailable;
- [X] HOTP/counter-OCRA durable counters remain authoritative and transition through revision compare-and-swap;
- [X] MFA factor creation/activation now also use authoritative CAS, and sensitive OTP secret/PIN fields are redacted from enrollment audit/result context;
- [X] OTP's exact authoritative passkey `CredentialRecord` is persisted in a dedicated `credential_record` column and replaced only when the stored revision still matches the revision OTP verified;
- [X] additive passkey record/revision schema migrations and readiness checks cover existing installs;
- [X] direct OTP attribution is available as `benchmark:otp` and included in `benchmark:release`;
- [X] focused AOTP/GridOTP/MobileOTP and selected/fail-closed OTP Passkey graph regression coverage is present;
- [ ] protect persisted symmetric MFA secrets through Epicrypt policy from 26.10;
- [ ] complete final PHP 8.4/8.5 stable/lowest CI plus persistent/Fiber/cold-path regression confirmation on the final PR head.

DBLayer 26.6 supplies the generic persistence revision/CAS mechanism. OTP owns algorithms, native challenge/replay behavior, and all WebAuthn ceremony/validator/serializer behavior. Foundation retains only application account/factor policy, audit/notification/lockout/satisfaction orchestration, and durable persistence policy.

**Status:** implementation complete; final closure waits on 26.10 symmetric-secret protection and final CI evidence.

---

## 26.5 Pathwise 3.1 — open

**Released baseline:** Pathwise 3.1, commit `8226cf42747ae131486063cad39335d6dfc1c7f7`.

- [ ] deterministic Foundation temp-upload cleanup on success/failure without masking the primary exception;
- [ ] real build-time malware-scanner composition when required, zero scanner cost when disabled;
- [ ] process/generation ownership for static mounts/custom drivers;
- [ ] preserve typed transfer results and correct local/non-local response handling;
- [ ] keep X-Sendfile/X-Accel explicit/policy-driven;
- [ ] preserve traversal/archive/symlink/bomb protections and stream ownership;
- [ ] prove persistent isolation and direct-Pathwise attribution.

---

## 26.6 DBLayer 5.1 Foundation integration — complete

**Released baseline:** DBLayer 5.1, commit `087f179ecac3e5555c346ce84cfc353050f8e3cb`.  
**Foundation floors:** `infocyph/dblayer ^5.1`, `infocyph/cachelayer ^3.4`.

### Final integrated contract

- [X] normal Foundation runtime uses explicit execution-owned DBLayer `Connection` objects rather than static `DB` façade execution state;
- [X] the only intentional production `DB` façade read is the worker pre-fork compatibility diagnostic for externally opened legacy façade connections;
- [X] `DatabaseRepository` uses DBLayer 5.1 `ConnectionRepository`;
- [X] generic auth INSERT/UPDATE/DELETE/upsert delegates to DBLayer QueryBuilder/native upsert;
- [X] MFA CAS retains Foundation domain policy but uses native conditional revision writes;
- [X] passkey persistence has an independent revision, DB/in-memory CAS behavior, stale-write rejection, and additive schema migration;
- [X] query caching is explicit/default-off via `database.query_cache.enabled/store` and binds the selected CacheLayer backend to the exact DBLayer Connection;
- [X] query-cache-disabled database paths remain CacheLayer-cold;
- [X] shared DB query caching requires an explicit selected-store namespace or an application-specific cache prefix;
- [X] DBLayer owns query-cache bypass/invalidation mechanics; successful outer commit invalidates, rollback does not;
- [X] CacheLayer PDO stores/invalidation infrastructure use generation-owned database connections and never retain a PDO borrowed from an execution lease;
- [X] transactional cache invalidation uses an execution-bound CacheLayer factory so its outbox shares the exact PDO participating in the transaction;
- [X] DBLayer `ConnectionLease` pooling is integrated as an optional path with one lease/name owned by each `RuntimeExecutionState`;
- [X] `freshConnection()` remains dedicated/non-pooled;
- [X] DBLayer remains the sole owner of pooled rollback/reset/health/lifetime sanitation;
- [X] lease/Fiber/sanitation, query-cache, MFA/passkey CAS, schema-upgrade, and optional-capability regression coverage is present;
- [X] DBLayer 5.1 lifecycle attribution benchmark is available as `benchmark:dblayer` and is included in `benchmark:release`.

### Final lifecycle decision

Foundation **keeps dedicated execution-owned connections as the default**. `DB_POOL_ENABLED` remains `false` by default.

Pooling is supported for persistent runtimes but is an opt-in operational optimization. A deployment should enable it only after representative datastore/runtime benchmarks demonstrate a material benefit. Generic CI/SQLite timing is not treated as a proxy for network-database production workloads. This conservative choice closes the architectural decision without inventing a performance claim the standard CI benchmark did not establish.

### Validation evidence

- [X] PR #13 merged into `main` at merge commit `195b8187fc2198d63c15d73616d51ae9dab94e2b`;
- [X] merged PR head includes final migration-order fix commit `dff282b3aca99599f8d829c6b40cee070929280e`;
- [X] Security & Standards run **#1157** on the final PR head completed successfully;
- [X] PHP 8.4 and PHP 8.5 analyzers passed;
- [X] clean install passed;
- [X] stable/lowest QA matrix passed on the final PR head;
- [X] existing benchmark jobs remained green;
- [X] DBLayer-specific lifecycle benchmark remains available for deployment-specific pooling attribution.

**Status:** [X] complete.

---

## 26.7 ReqShield 3.1 — open

**Released baseline:** ReqShield 3.1, commit `07e9e0a2465409e33c140b0cee920f821ca49c79`.

- [ ] keep parsing/execution/sanitization/schema mechanics ReqShield-owned;
- [ ] finalize production schema topology before traffic;
- [ ] keep mutable Validator instances per-call unless a reentrant compiled form is proven safe;
- [ ] do not add a Foundation validation-plan cache before measuring ReqShield's bounded plan cache;
- [ ] acquire DBLayer only for actual DB-backed validation rules;
- [ ] preserve `DatabaseProvider`, batching, DB constraints, limits, explicit callable dynamic islands, Fiber isolation, DB-free cold paths, and direct attribution.

---

## 26.8 Omnibus 2.5 — open

**Released baseline:** Omnibus 2.5, commit `7686de11b75ec4e02cbebd2080d6c470c1c314cf`.

- [ ] keep routing/transport/retry/settlement/failure/workflow mechanics Omnibus-owned;
- [ ] preserve Omnibus message ID as execution correlation identity;
- [ ] keep handler/middleware resolution execution-scoped;
- [ ] expose selected Omnibus-native durable transports and activate DBLayer/CacheLayer only when required;
- [ ] bind DBLayer after-commit dispatch to the current execution connection safely;
- [ ] define Foundation supervision vs Omnibus worker ownership without double supervision;
- [ ] keep durable serialization data-only and prove retry/ack/release/reject correctness, persistent isolation, graceful replacement, and direct attribution.

---

## 26.9 TalkingBytes 2.0.0 — open

**Released baseline:** TalkingBytes 2.0.0, commit `86d0e9dde8124ddeacea8ba7f81911af584b879b`.

- [ ] keep HTTP/email/webhook/gRPC protocol execution TalkingBytes-owned;
- [ ] classify profile/client lifetime and isolate cookie/auth/request mutation;
- [ ] keep webhook replay on CacheLayer atomic `setIfAbsent()` and Foundation security-key derivation;
- [ ] preserve production replay fail-closed topology;
- [ ] integrate inbound gRPC through the existing worker lifecycle;
- [ ] add native inbound/outbound email profiles where required;
- [ ] keep secrets out of logs/cache keys/artifacts and prove optional-capability cold paths/direct attribution.

---

## 26.10 Epicrypt 2.1 — open

**Released baseline:** Epicrypt 2.1, commit `f80092978328cccaef0d2233b08ce95b453dd90a`.

- [ ] inventory every Foundation cryptographic operation;
- [ ] use Epicrypt high-level protection/KDF/password primitives where semantics match;
- [ ] define purpose labels/versioning and separate token/MFA/recovery key purposes;
- [ ] protect durable symmetric MFA secrets at rest and support bounded previous keys for rotation;
- [ ] keep secrets out of artifacts/logs/cache keys/metrics and resolve external secret refs at boot;
- [ ] prove tamper/fail-closed behavior, purpose isolation, persistent plaintext-secret isolation, and direct attribution.

---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is now the sole Foundation-facing WebAuthn ceremony/state runtime over optional `web-auth/webauthn-lib`. Foundation's former ceremony store, options factory, credential mapper, serializer/validator runtime, attestation policy, direct passkey service, and duplicate Base64Url codec have been removed. The retained `WebAuthnRuntime` class is an empty deprecated compatibility/cold-path sentinel and owns no WebAuthn behavior.

DB persistence mechanics remain in 26.6; the integrated OTP/Passkey application work is tracked in 26.4; durable secret-protection policy remains in 26.10.

**Status:** [X] closed/subsumed.
