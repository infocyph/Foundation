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

### Foundation ownership

Foundation owns normalized application configuration, capability selection, provider composition policy, application-facing integrations, CLI/worker/scheduler orchestration, Foundation auth/session/database/filesystem policy, immutable cross-runtime release generation, deployment activation/trust, diagnostics, migration guidance, and attribution benchmarks.

Foundation must not add a second DI runtime, HTTP runtime, database connection pool, query builder, validation engine, queue runtime, filesystem engine, or cryptographic implementation above a lower library that already owns it.

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

Each pass must audit the current released API, keep generic mechanics in the lower layer, prove persistent/concurrent correctness where relevant, benchmark Foundation against the direct lower-layer operation, and update this plan/tracker.

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

CacheLayer owns optional atomic `setIfAbsent()`, `getAndDelete()`, `compareAndSet()`, counters, locks, cache semantics, backend correctness, and the newer 3.4 runtime used by DBLayer 5.1. Foundation consumes those primitives directly and does not emulate cache atomicity through Foundation locks.

- [X] 3.3 atomic capability integration completed previously.
- [~] Foundation dependency floor raised to `infocyph/cachelayer ^3.4` during 26.6; final QA still required with DBLayer 5.1.

---

## 26.4 OTP 6.1 + Passkey/WebAuthn Foundation integration — open

**Released baseline:** OTP 6.1, commit `c7faf376b96611638e7bc0da6cd081496768d34f`.

Foundation still must:

- [ ] raise OTP floor to `^6.1` and verify PHP 8.4/8.5 stable/lowest;
- [ ] route passkey ceremony behavior exclusively through OTP `Passkey`;
- [ ] remove direct Foundation WebAuthn ceremony/options/validator/codec duplication;
- [ ] expose AOTP/GridOTP deliberately and MobileOTP only as legacy compatibility;
- [ ] require secure CacheLayer auth-state capability for selected stateful OTP/Passkey modes;
- [ ] preserve HOTP/counter-OCRA durable counters and authoritative MFA CAS;
- [ ] atomically persist the exact passkey credential record returned by OTP and reject stale replacement;
- [ ] keep AOTP private-key material device-owned;
- [ ] protect persisted symmetric MFA secrets through Epicrypt policy from 26.10;
- [ ] prove Fiber/persistent isolation, fail-closed backend behavior, optional-capability cold paths, and direct-OTP attribution.

DBLayer 26.6 owns the generic database primitive/pattern for authoritative stale-write rejection.

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

## 26.6 DBLayer 5.1 Foundation integration — active

### Released baseline

- DBLayer tag: **5.1**;
- tag commit: `087f179ecac3e5555c346ce84cfc353050f8e3cb`;
- DBLayer 5.1 requires PHP `^8.4`, ArrayKit `^5.2`, CacheLayer `^3.4`, and PSR Log `^3.0.2`;
- Foundation floors now target `infocyph/dblayer ^5.1` and `infocyph/cachelayer ^3.4`.

DBLayer owns connection mechanics, Pool/PoolManager/ConnectionLease, reuse sanitation, QueryBuilder/result cache semantics, optimistic writes, repository/result primitives, schema/migrations/seeding, security, and telemetry. Foundation owns application topology, capability selection, execution lease ownership, cache-store selection, auth policy/schema, and scope cleanup.

Normal Foundation runtime uses explicit DBLayer `Connection` objects. The only intentional production read of the process-static `DB` façade is the worker pre-fork compatibility guard that detects externally opened legacy façade connections; it is diagnostic state, not Foundation execution state.

### DBLayer 5.1 lower-layer evidence

- [X] instance-owned query cache and exact-connection `afterCommit()` invalidation;
- [X] direct connections do not require static façade cache state;
- [X] tokenized `ConnectionLease` / `PoolManager::checkout()` with stale/double/wrong release protection;
- [X] reuse sanitation and lower-layer Fiber/interleaving regression coverage;
- [X] `ConnectionRepository` instance-first repository path;
- [X] native upsert and optimistic conditional write primitives;
- [X] DBLayer lifecycle/pooling/prepared-statement benchmark subjects;
- [X] DBLayer CacheLayer floor `^3.4`, ArrayKit floor `^5.2`.

### Foundation integration tracker

#### Batch 1 — released baseline and instance-owned primitives

- [~] CacheLayer `^3.4` and DBLayer `^5.1` floors — **implemented; QA pending**;
- [~] `DatabaseRepository` migrated to DBLayer 5.1 `ConnectionRepository` — **implemented; QA pending**;
- [~] generic Foundation auth INSERT/UPDATE/DELETE/upsert moved to DBLayer QueryBuilder/native upsert — **implemented; QA pending**;
- [~] explicit `database.query_cache.enabled/store` with lazy exact-Connection cache binding — **implemented; QA pending**.

#### Batch 2 — authoritative auth persistence

- [~] full Foundation DBLayer usage rescan/classification completed; normal runtime is instance-owned and the remaining static façade production read is only the worker pre-fork legacy diagnostic — **implemented; QA pending**;
- [~] MFA CAS keeps Foundation domain policy but performs one native DBLayer conditional revision update — **implemented; QA pending**;
- [~] unconditional MFA `save()` uses native DBLayer upsert — **implemented; QA pending**;
- [~] passkey persistence now has an independent revision, DB/in-memory compare-and-swap stores, stale-write rejection, and additive auth-schema migration — **implemented; QA pending; OTP authoritative record wiring remains 26.4**.

#### Batch 3 — runtime lifecycle and pooling decision

- [~] normalized `ConnectionConfig` objects remain cached in `DBLayerFactory` outside execution hot paths — **implemented; QA pending**;
- [~] Foundation dedicated create/use/disconnect vs DBLayer lease checkout/use/release attribution benchmark added — **benchmark execution pending**;
- [~] warm/new and prepared-statement reuse benchmark subjects added — **benchmark execution pending**;
- [~] opt-in DBLayer lease pooling path implemented with `DB_POOL_ENABLED=false` by default; singleton `DBLayerFactory` owns the process/generation pool and each `RuntimeExecutionState` owns one lease/name until cleanup — **acceptance decision pending benchmark/CI**.

`freshConnection()` remains dedicated/non-pooled. Foundation release cleanup calls only `ConnectionLease::release()` for pooled connections; DBLayer owns rollback/reset/health/lifetime sanitation. Fiber tests assert two live Foundation executions cannot own the same pooled Connection.

A separate ownership fix was required before pooling: singleton CacheLayer PDO stores/invalidation transports may retain raw PDO objects, so they now use a generation-owned `DBLayerFactory::infrastructureConnection()` instead of borrowing an execution connection/lease. Transactional cache invalidation still uses the current execution connection because it intentionally participates in that transaction.

#### Batch 4 — query-cache, migration, and bridge acceptance

- [~] query-cache cold path, exact-Connection binding, rollback no-invalidation, and successful outer-commit invalidation tests added — **QA pending**;
- [~] shared query caching now requires either an explicit selected-store namespace or an application-specific `cache.prefix`; the generic shipped namespace is rejected for DB query caching — **QA/backend matrix pending**;
- [~] Schema/MigrationRunner/SeedRunner remain DBLayer-owned; migration definitions are explicit and migration lock resolution occurs only when administrative runner/seed paths are invoked — **audit complete; QA pending**;
- [~] DBLayer 5.1 lifecycle/bridge benchmark registered in `benchmark:release`; direct-vs-Foundation lifecycle attribution exists — **transaction/cache/MFA/passkey benchmark result collection still pending**.

### Correctness/security acceptance

Implementation evidence exists, but these remain unchecked until the PR QA/CI pass records results:

- [ ] default/named connection resolution and relative SQLite path policy;
- [ ] no PDO open merely because the graph was compiled;
- [ ] commit/rollback/nested savepoints/`afterCommit()` and cleanup rollback;
- [ ] cleanup failure never masks the primary execution failure;
- [ ] sequential executions do not inherit transaction/sticky/comment/deadline/cancellation/replica state;
- [ ] if pooling is selected: Fiber lease isolation, stale/double/wrong release rejection, unhealthy/expired/reconnect/incomplete-transaction handling, bounded soak memory;
- [ ] instance-owned query-cache hit/miss behavior and commit/rollback invalidation;
- [ ] locking/transaction/sticky/raw/complex-query cache bypass remains DBLayer-correct;
- [ ] selected shared cache namespace/topology isolation;
- [ ] MFA CAS contention yields exactly one stale-state transition winner;
- [ ] passkey contention cannot overwrite newer authenticator credential state;
- [ ] migration lock/failure/concurrent-attempt behavior;
- [ ] production DB security/TLS/raw-query policy;
- [ ] database-disabled and query-cache-disabled graphs remain free of unnecessary DB/cache work.

### Performance acceptance

Benchmark/report at minimum:

1. database capability absent vs enabled-but-unused graph/boot;
2. normalized ConnectionConfig lookup;
3. first connection construction/open;
4. create/use/disconnect lifecycle;
5. DBLayer lease checkout/use/release;
6. warm pooled vs new connection;
7. prepared-statement reuse with/without pooling;
8. direct DBLayer query vs Foundation scoped query;
9. transaction begin/commit/rollback;
10. direct DBLayer query cache vs Foundation-selected DBLayer cache;
11. cache hit/miss/invalidation and namespace derivation cost;
12. MFA compare-and-swap;
13. passkey conditional persistence;
14. migration/seeder administrative boot;
15. repeated persistent web/worker execution memory, connection count, and checkout contention.

Pooling must not become the default merely because it exists. The current default remains dedicated execution-owned connections until representative persistent-runtime benchmarks show a material benefit and correctness acceptance is green. No Foundation query-cache layer or auth-specific DBLayer API is permitted.

**Completion gate:** 26.6 closes only after PR QA passes against released DBLayer 5.1/CacheLayer 3.4, the pooling recommendation is explicitly chosen from benchmark evidence, optional query-cache isolation/cold paths are proven, authoritative MFA/passkey persistence is accepted, and direct-DBLayer attribution is recorded.

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

OTP 6.1 `Passkey` is the Foundation-facing WebAuthn ceremony/state boundary over optional `web-auth/webauthn-lib`. Remaining work is tracked in 26.4 (OTP/passkey policy), 26.6 (authoritative persistence), and 26.10 (adjacent key/protection policy).

**Status:** [X] closed/subsumed.
