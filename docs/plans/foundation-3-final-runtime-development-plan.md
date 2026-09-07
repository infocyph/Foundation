# Foundation 3 — Unified Runtime Development Plan

**Status:** Canonical implementation plan
**Foundation target:** 3.x
**Foundation source baseline:** `main`
**InterMix baseline:** `^10.0.4`
**Webrick baseline:** `^5.3`
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single source of truth for Foundation 3 runtime development. Completed work is intentionally summarized so the document stays maintainable; open lower-library passes remain actionable and detailed. If a lower layer already owns the correct generic mechanism, Foundation consumes it directly. If a genuinely general primitive is missing, fix the lower layer once instead of building a Foundation-only workaround.

---

## 1. Architectural invariants

Foundation has four independent runtime paths:

1. `web`;
2. `cli`;
3. `worker`;
4. `scheduler`.

There is one Foundation graph/composition source, but each runtime uses a fresh InterMix `ContainerBuilder` and its own generated artifact. Webrick owns only the web HTTP path.

### InterMix owns

- DI graph composition and validation;
- singleton/scoped/transient lifetimes;
- aliases, values, contextual bindings and tags;
- compilation-safe constructor/static-factory recipes;
- generated production containers;
- scope seeds and execution isolation;
- lifecycle/scope-leave behavior;
- compile reports and dynamic-island visibility.

### Webrick owns for web

- route registration/build and execution plans;
- matcher compilation/runtime matching;
- lazy Request materialization;
- middleware dispatch;
- HTTP request-scope decisions;
- routing-control responses;
- runtime adapters/capabilities;
- native response writing and streaming;
- frozen production URL/runtime registries;
- coordinated web release metadata.

### Foundation owns

- normalized application configuration;
- runtime/capability selection;
- graph/provider composition policy;
- application-facing integrations;
- CLI/worker/scheduler orchestration;
- Foundation-specific auth/session/database/filesystem policy;
- cross-runtime immutable release generation;
- deployment activation/trust policy;
- diagnostics, migration guidance and attribution benchmarks.

Foundation must not add a second DI runtime, HTTP runtime, connection pool, query builder, validation engine, queue runtime, filesystem engine or crypto implementation above a lower library that already owns that responsibility.

---

## 2. Production/runtime contract

### InterMix

Production uses generated `ProductionContainer` instances. Normal production graph mutation is prohibited. Every independently active runtime uses a fresh builder. Unexpected skipped definitions fail release generation. `productionPrevalidated()` is allowed only when the trusted digest comes from immutable deployment metadata outside writable artifact storage.

Compilation-safe recipes remain:

```text
FactoryDefinition::construct(...)
FactoryDefinition::staticFactory(...)
ServiceReference(...)
```

A closure/`DirectFactory` may be reflection-free but is still a dynamic production island.

### Webrick

Development and production are intentionally different:

```text
development:
Foundation graph -> ContainerBuilder::development() -> live development RouterKernel

production:
Foundation graph + route topology -> coordinated Webrick release compile
 -> generated InterMix web container
 -> compiled Webrick router
 -> RuntimeAdapter selected once
 -> RuntimeServer
```

The minimal compiled route must remain Request-free and scope-free when its execution plan allows it. Webrick is the sole native response writer.

### Stable execution scopes

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

Execution/request/job/message IDs are scope seeds/correlation values, never synthesized scope names.

---

## 3. Immutable release generation

All four runtime artifacts belong to one immutable Foundation generation:

```text
release/<generation>/
    foundation.php
    config.php                  optional
    web/
        Webrick release manifest
        InterMix web artifact
        Webrick router artifact
    cli/
        InterMix artifact + metadata
    worker/
        InterMix artifact + metadata
    scheduler/
        InterMix artifact + metadata
```

Rules:

- build and verify a complete generation before publication;
- fail on unexpected InterMix dynamic islands/skipped definitions;
- atomically switch one active-generation pointer only after all runtime artifacts validate;
- incomplete/failed builds leave the previous generation active;
- persistent workers replace gracefully onto the new generation;
- old-generation cleanup stays outside request/job hot paths;
- verified loading is the default; trusted prevalidated loading requires an external immutable trust source.

---

## 4. Foundation hashing policy

Foundation-owned hashing is selected by semantic requirement:

- **SHA3-256** for security-sensitive derivation where collision resistance/security aliasing matters;
- **XXH128** for non-security deterministic fingerprints, freshness identities and key compaction;
- no new Foundation-owned SHA-256;
- lower-layer/protocol/persisted digest formats remain owned by their defining contracts;
- SHA3-256/XXH128 never replace MACs, signatures, encryption or KDFs.

For Foundation-owned security CacheLayer keys, domain-separate the logical identity, preserve the complete SHA3-256 digest and encode it in a CacheLayer-legal form such as unpadded Base64URL.

---

## 5. Completed runtime implementation summary

Phases 0–9 are complete. The following architecture is already established and must not regress:

- builder-first composition with fresh web/CLI/worker/scheduler builders;
- InterMix `^10.0.4` generated-runtime model;
- Webrick `^5.3` compiled production runtime;
- builder-first provider contract and compilation-safe recipes/aliases;
- broad production `onMissing()`/late provider activation removed;
- principal/session/database execution bookkeeping is scoped/execution-local;
- stable semantic scopes and Fiber-safe isolation;
- route-first Webrick graph enrichment before InterMix compile;
- web InterMix artifact compiled exactly once through Webrick's coordinated release path;
- compiled/frozen routing and URL registries;
- Request/scope/middleware creation driven by Webrick execution-plan capabilities;
- routing-control errors separated from application exception rendering;
- maintenance removed from a universal Foundation HTTP wrapper;
- portable file/stream responses with Webrick-only native emission;
- generated CLI/worker/scheduler runtimes reused safely across executions;
- immutable unified release generation with atomic activation/rollback;
- long-running worker/scheduler state-isolation and bounded-memory acceptance;
- complete Phase 9 correctness/static-analysis/performance acceptance.

Key final benchmark evidence retained from the completed runtime pass:

- Foundation generated-container resolution is effectively at direct InterMix cost;
- `Application::make()` overhead is only tens of nanoseconds over generated resolution;
- the Foundation non-web execution boundary adds only a few microseconds over bare InterMix scope entry/leave;
- compiled Foundation HTTP hot-path tax measured approximately 1% over standalone compiled Webrick;
- real PHP-FPM + OPcache acceptance completed with zero request failures in the recorded Nginx/Apache runs.

Phase 10 remains open only for aggregate release gates and the unfinished lower-library utilization passes below.

---

## 6. Phase 10 release-readiness gates

Already completed:

- old dynamic-container construction/mutation rescan;
- old `compileTo`/resolver-map activation rescan;
- closure-alias/deterministic-closure-factory rescan;
- generated-service Application/container capture rescan;
- dynamic-island and singleton-lifetime review;
- scope/Fiber cleanup review;
- live production Registrar/Collection and route-cache duplication removal;
- production source-discovery audit;
- unnecessary Request/scope/global middleware audit;
- direct native-output audit;
- hot-path hashing/manifest parsing audit;
- Foundation-owned SHA-256 migration/guard;
- hidden DB/cache capability audit;
- cleanup primary-exception preservation audit;
- stale InterMix/Webrick runtime configuration/docs cleanup.

Still open:

- [ ] validate all hard implementation gates after lower-library passes;
- [ ] validate final definition of done after lower-library passes;
- [ ] complete InfByte consumption/handoff against the final Foundation 3 lifecycle.

---

# 26. Subsequent lower-library utilization passes

Each pass must:

1. audit the current released lower-layer API;
2. separate generic lower-layer mechanics from Foundation policy;
3. fix missing generic primitives in the lower library rather than adding Foundation workarounds;
4. prove correctness under persistent/concurrent execution where relevant;
5. benchmark Foundation overhead against the direct lower-layer operation;
6. update this canonical plan and its tracker.

---

## 26.1 ArrayKit 5.2.0 — complete

**Released baseline:** ArrayKit 5.2.0, commit `053440b61071a17332b18879b12026f54a0ad144`.

Foundation now delegates generic env/config parsing, raw environment access, layered lazy file config, dot notation and merge mechanics to ArrayKit while retaining only application source-order, host-protection, hydration, release-artifact and validation policy. Production generated config remains source-discovery-free. Correctness, corruption fallback and attribution benchmarks passed under PHPForge.

**Status:** [X] complete.

---

## 26.2 UID 5.0 — complete

**Released baseline:** UID 5.0, commit `4a95eb8058e73c72e74e44fedd25755198899eae`.

Foundation uses UID monotonic ULID as the generated non-web execution correlation fallback while preserving authoritative supplied IDs byte-for-byte. Scope labels stay semantic/deterministic. Release hashes, security randomness and pure temporary entropy remain with their owning primitives. Fiber/persistent/fork behavior and direct-UID attribution were accepted.

**Status:** [X] complete.

---

## 26.3 CacheLayer 3.3 atomic capability — complete

**Released baseline:** CacheLayer 3.3, commit `581194b184da929f7f098672ccf91869b1da984c`.

CacheLayer owns optional atomic `setIfAbsent()`, `getAndDelete()` and `compareAndSet()` capability plus atomic counters, locks, cache semantics and backend correctness. Foundation consumes those capabilities directly, uses domain-separated SHA3-256 security physical keys and XXH128 non-security fingerprints, and no longer emulates replay/consume atomicity through Foundation locks.

**Status:** [X] complete.

> Current DBLayer CI resolves CacheLayer 3.4 under its existing compatible constraint. Section 26.6 must explicitly decide whether DBLayer's own minimum should now move to `^3.4`; do not raise it merely because Composer selected the latest compatible release.

---

## 26.4 OTP 6.1 + Passkey/WebAuthn Foundation integration — open

**Released lower-layer baseline:** OTP 6.1, commit `c7faf376b96611638e7bc0da6cd081496768d34f`.

OTP now owns TOTP/HOTP/OCRA/GenericOtp, AOTP, GridOTP, MobileOTP compatibility, recovery/rotation mechanics, replay/challenge coordination and Passkey/WebAuthn ceremony behavior through optional `web-auth/webauthn-lib`.

Foundation still must:

- [ ] raise the OTP floor to `^6.1` and verify PHP 8.4/8.5 stable/lowest;
- [ ] route passkey ceremony handling exclusively through OTP `Passkey`;
- [ ] remove direct Foundation WebAuthn ceremony/options/validator/codec duplication;
- [ ] expose AOTP/GridOTP deliberately and MobileOTP only as explicit legacy compatibility;
- [ ] require secure CacheLayer auth-state capability for selected stateful OTP/Passkey modes;
- [ ] preserve HOTP/counter-OCRA durable counters in the authoritative factor store;
- [ ] preserve authoritative MFA compare-and-swap;
- [ ] atomically persist the exact passkey credential record returned by OTP;
- [ ] reject stale concurrent passkey replacement;
- [ ] keep AOTP private-key material client/device-owned;
- [ ] protect persisted symmetric MFA secrets through Epicrypt policy from 26.10;
- [ ] keep operational backend/CAS failures fail-closed and distinguish them internally from credential mismatch/replay;
- [ ] prove Fiber/persistent-runtime isolation and optional-capability cold paths;
- [ ] record direct-OTP-vs-Foundation attribution benchmarks.

DBLayer section 26.6 owns the generic persistence primitive required for authoritative stale-write rejection.

---

## 26.5 Pathwise 3.1 — open

**Released baseline:** Pathwise 3.1, commit `8226cf42747ae131486063cad39335d6dfc1c7f7`.

Open work:

- [ ] deterministically clean Foundation-materialized upload temp files on success/failure;
- [ ] preserve primary upload exception over cleanup failures;
- [ ] add a real build-time malware-scanner composition path when scanning is required;
- [ ] keep scanner cost absent when disabled;
- [ ] treat Pathwise static mounts/custom drivers as generation/process boot state, never request/job mutable state;
- [ ] preserve Pathwise typed transfer results directly;
- [ ] keep local/non-local capability detection correct for Webrick `FileBody` vs portable streaming;
- [ ] keep X-Sendfile/X-Accel explicit and policy-driven;
- [ ] preserve traversal/archive/symlink/bomb protections;
- [ ] prove stream ownership/closure and persistent-runtime state isolation;
- [ ] record Foundation-vs-direct-Pathwise attribution benchmarks.

---

## 26.6 DBLayer 5.x utilization and lower-layer hardening — active

### Baseline

- package baseline consumed by Foundation: `infocyph/dblayer` `^5.0`;
- audited DBLayer 5.0 tag/main baseline: `0a599814b09f9d922d017a9c2ef80d99726061a2`;
- current lower-layer work branch: `feature/foundation-26.6-hardening` created from that `main` baseline;
- current DBLayer PR: **#31 — `Harden instance query caching and pooled connection ownership`**;
- ArrayKit current compatible runtime selected by CI: 5.2.0;
- CacheLayer current compatible runtime selected by CI: 3.4;
- DBLayer release number after this additive hardening is to be chosen by DBLayer release policy; Foundation must consume an actual released tag, never the feature branch.

### Ownership decision

DBLayer owns database mechanics and database-specific runtime behavior:

- `ConnectionConfig` and connection/security primitives;
- PDO/write/read-replica lifecycle;
- transactions/savepoints/`afterCommit()`;
- query execution and `QueryBuilder`;
- prepared-statement caching;
- driver capability detection;
- retry/deadline/cancellation mechanics;
- pool checkout/release/sanitation;
- query-result caching and invalidation;
- repository/result/pagination primitives;
- schema/migration/seeding mechanics;
- lower-layer database security, telemetry and batch optimization.

Foundation owns:

- application connection topology/default selection;
- relative SQLite path policy;
- runtime capability composition;
- InterMix lifetime and execution ownership;
- selecting pooling/query caching/migration locking;
- selecting the CacheLayer backend supplied to DBLayer when query caching is enabled;
- application/auth schema and migration topology;
- application-facing repository adapter composition;
- scope cleanup integration;
- auth/account concurrency policy built on generic DBLayer primitives.

Foundation must not use DBLayer's process-static `DB` façade as its normal execution boundary. Static façade support may remain a DBLayer convenience API, but explicit `Connection` instances are the Foundation runtime contract.

### Confirmed lower-layer gaps from the Foundation audit

1. **Instance query-cache ownership:** DBLayer 5.0 result-cache reads/invalidation relied on process-static `DB` cache state even when callers owned a direct `Connection`. Foundation must not register execution connections globally merely to make invalidation work.
2. **Exact-connection post-commit invalidation:** the write that mutates a cached table must schedule invalidation through the same `Connection::afterCommit()` transaction lifecycle that performed the write.
3. **Cache identity isolation:** query keys/table tags must distinguish different physical/logical connection identities sufficiently to prevent accidental cross-host/schema/tenant collision when a shared CacheLayer backend is intentionally reused.
4. **Pool ownership generation:** a bare reused `Connection` reference cannot prove that a stale caller still owns the current checkout generation. Persistent/concurrent users need a lower-layer lease/token contract.
5. **Double/stale release rejection:** releasing a connection that is not currently owned, releasing twice, using the wrong pool name or releasing a tokenized checkout through a bare API must fail instead of returning an actively owned connection to idle state.
6. **Pool sanitation:** transaction/sticky/query-context/deadline/cancellation/read-replica/statement state must be clean before an object crosses execution boundaries.
7. **Instance-oriented repository construction:** normal repository helpers must be usable from an explicit `Connection` without a static `DB` lookup or static global result-processor dependency.
8. **Generic conditional persistence:** Foundation passkey/MFA stores need authoritative stale-write rejection, but DBLayer must expose only generic database primitives—not OTP/passkey-specific policy.
9. **CacheLayer floor review:** DBLayer currently allows older CacheLayer 3.x releases while CI selects 3.4. Audit whether the lower-layer APIs DBLayer now relies on justify raising the declared floor; keep the minimum unchanged if no 3.4-only contract is required.
10. **Benchmark before Foundation pooling:** even after lower-layer correctness is proven, Foundation enables pooling only if persistent-runtime attribution shows a meaningful lifecycle benefit.

### Lower-layer implementation work in DBLayer

#### A. Instance-owned query caching

- [~] Add a `Connection`-owned query-cache binding (`setQueryCache()` / instance resolver).
- [~] Make `QueryBuilder` cached reads use the owning `Connection` cache instead of static `DB::cache()`.
- [~] Make write invalidation schedule through the exact owning `Connection::afterCommit()`.
- [ ] Preserve the static `DB` façade as compatibility sugar by injecting the façade-selected cache into façade-created/managed connections; do not make explicit connections fall back to global mutable state.
- [ ] Decide whether an implicit per-connection memory cache remains desirable when `cacheFor()` is called without explicit cache configuration; if kept, prove it is truly instance-local and document its non-shared semantics.
- [ ] Harden result-cache key and automatic table-tag identity so shared cache backends cannot collide merely because two connections share the same logical name/database string.
- [ ] Test cache hit/miss, commit invalidation, rollback no-invalidation, nested transaction behavior and same-name/multi-connection isolation.
- [ ] Test locking/transaction/sticky-write/raw/complex dependency bypass behavior remains unchanged.
- [ ] Test explicit shared CacheLayer backends invalidate only the intended connection/table namespace.

#### B. Pool lease ownership

- [~] Add tokenized `ConnectionLease` checkout at `PoolManager`.
- [~] Make callback-scoped `PoolManager::using()` use the lease path.
- [~] Reject double release, stale token release, wrong-owner release and bare release of an active tokenized checkout.
- [ ] Keep legacy `get()/release()` only as a documented compatibility path for strictly scoped callers; Foundation persistent runtimes should use leases.
- [ ] Prove checkout tokens cannot overflow into ambiguous ownership during realistic process lifetime; wrap/reset safely if the implementation uses a monotonically increasing integer.
- [ ] Ensure a failed release/sanitation does not mark a lease successfully released before the pool has accepted/discarded the connection.
- [ ] Ensure pool manager bookkeeping cannot retain dead connection objects indefinitely.
- [ ] Add Fiber-interleaving/stale-reference tests proving one physical connection is never concurrently owned by two live leases.
- [ ] Add unhealthy/expired/reconnect/active-transaction release tests through the lease API.
- [ ] Add long checkout/release soak tests with bounded pool-manager memory.

#### C. Connection reuse sanitation

- [ ] Audit `Connection::resetRuntimeStateForReuse()` field-by-field against all mutable runtime state.
- [ ] Explicitly cover active transaction rollback/unrecoverable transaction rejection.
- [ ] Reset sticky-write state.
- [ ] Reset query comment/context.
- [ ] Reset query deadline/timeout/cancellation callbacks.
- [ ] Reset read-replica selector/session state as required.
- [ ] Define whether prepared statements survive reuse; retain only when tied to the still-valid PDO and proven safe.
- [ ] Define whether explicitly bound query-cache configuration survives reuse (normally yes as connection configuration, not execution state).
- [ ] Ensure deferred `afterCommit()` callbacks cannot leak into the next checkout.
- [ ] Ensure telemetry/listener/profiler execution-local state does not leak through pooled connection objects.

#### D. Instance-oriented repository API

- [ ] Add a direct explicit-connection repository construction path in DBLayer.
- [ ] Prefer `Connection::repository($table)` or another equally narrow instance API over forcing callers through static `DB::repository()`.
- [ ] Keep `ResultProcessor` immutable/shareable where safe; do not require global static state for repository creation.
- [ ] Preserve existing repository/query-builder semantics and table normalization.
- [ ] Add tests proving repository use works with direct, pooled and façade-owned connections without cross-instance state.

#### E. Generic atomic/conditional persistence primitives

- [ ] Inventory existing `QueryBuilder` conditional update/transaction/row-lock capabilities before adding new API.
- [ ] If existing `where(...)->update(...)` already expresses efficient optimistic CAS cleanly, keep it and document the canonical pattern instead of adding redundant abstraction.
- [ ] If repeated application adapters need a generic helper, add only a DB-neutral conditional-update/CAS primitive with affected-row semantics; no passkey/OTP naming in DBLayer.
- [ ] Ensure the primitive is single-statement atomic where supported and never emulates CAS as read-then-write.
- [ ] Preserve transaction/row-lock alternatives for domains that require pessimistic ownership.
- [ ] Add stale-value/contention tests across supported SQL drivers where CI services permit.

### Foundation integration after the DBLayer release

- [ ] raise Foundation's DBLayer floor to the released hardening version;
- [ ] rescan every Foundation `Infocyph\DBLayer` usage against that tag;
- [ ] keep normalized `ConnectionConfig` construction outside execution hot paths;
- [ ] keep one owned connection per execution/name unless it is a checked-out DBLayer lease;
- [ ] benchmark create/use/disconnect vs lease checkout/use/release;
- [ ] enable one process/generation-level pool only where the benchmark wins materially and the runtime concurrency model is proven safe;
- [ ] make `RuntimeExecutionState` own each checkout lease until cleanup;
- [ ] never expose one checked-out connection to two active executions;
- [ ] keep `freshConnection()` dedicated/non-pooled unless an explicit caller contract requests otherwise;
- [ ] release through DBLayer's sanitation path, never a duplicate Foundation reset routine;
- [ ] keep query caching opt-in and bind the selected CacheLayer store explicitly to the exact connection/pool configuration;
- [ ] keep application CacheLayer activation independent from database capability unless query caching/migration locking/another selected DB feature actually needs it;
- [ ] use DBLayer-native transactions/savepoints/retry/deadline/cancellation/`afterCommit()` directly;
- [ ] preserve DBLayer sticky-read and prepared-statement behavior as lower-layer concerns;
- [ ] preserve `DBLayerMfaFactorStore::compareAndSwap()` as the authoritative MFA state transition;
- [ ] replace plain passkey usage-state updates with authoritative stale-write-rejecting persistence using the released generic DBLayer primitive/pattern;
- [ ] stale passkey persistence must fail authentication rather than silently overwrite or ignore newer authenticator state;
- [ ] keep DBLayer Schema/MigrationRunner/SeedRunner lower-layer-owned;
- [ ] keep migration discovery/locking administrative and off request/job hot paths;
- [ ] do not enable DBLayer's global static query log merely because Foundation logging exists;
- [ ] keep database capability absent from runtime graphs that do not select it.

### Correctness/security acceptance

- [ ] named/default connection resolution and SQLite relative-path policy;
- [ ] no PDO open merely because the graph was compiled;
- [ ] commit/rollback/nested savepoints/`afterCommit()`;
- [ ] scope cleanup rolls back remaining transactions;
- [ ] cleanup failure does not mask the primary execution failure;
- [ ] sequential executions do not inherit transaction/sticky/comment/deadline/cancellation/replica state;
- [ ] interleaved Fibers never receive the same active leased connection;
- [ ] lease checkout/release, double/stale/wrong release rejection;
- [ ] unhealthy/expired/reconnect/incomplete-transaction handling;
- [ ] hundreds/thousands of persistent executions with bounded pool memory;
- [ ] replica sticky behavior does not leak across executions;
- [ ] instance-owned cached SELECT hit/miss behavior;
- [ ] INSERT/UPDATE/DELETE invalidate only after successful outer commit;
- [ ] rollback does not publish committed invalidation;
- [ ] nested commit/rollback invalidation semantics;
- [ ] lock/transaction/sticky/raw/complex-query cache bypass remains correct;
- [ ] cache namespace isolation across same-name connections pointing at different hosts/schemas/logical tenants;
- [ ] MFA compare-and-swap contention allows exactly one stale-state transition winner;
- [ ] passkey credential-state contention cannot overwrite newer counter/backup-state state;
- [ ] migration locking/failure/concurrent attempt behavior;
- [ ] production security/TLS/raw-query policy across supported drivers;
- [ ] database-disabled graphs remain free of connection/pool/query-cache work.

### Performance acceptance

Benchmark at minimum:

1. database capability absent vs enabled-but-unused graph/boot;
2. normalized `ConnectionConfig` lookup;
3. first connection construction/open;
4. execution create/use/disconnect lifecycle;
5. DBLayer lease checkout/use/release lifecycle;
6. warm pooled connection vs new connection;
7. prepared-statement reuse with and without pooling;
8. direct DBLayer query vs Foundation scoped connection/query;
9. transaction begin/commit/rollback;
10. direct DBLayer query cache vs Foundation-selected DBLayer cache;
11. result-cache hit/miss/invalidation;
12. cache-key/tag namespace derivation cost;
13. MFA compare-and-swap;
14. passkey conditional persistence;
15. migration/seeder administrative boot;
16. repeated persistent web/worker executions with memory, connection count and checkout contention.

Do not enable pooling merely because it exists. Do not add a Foundation query-cache layer. Do not add an auth-specific persistence API to DBLayer.

### Current implementation evidence / live status

- [X] branch created from DBLayer `main`: `feature/foundation-26.6-hardening`;
- [X] draft PR opened: `infocyph/DBLayer#31`;
- [~] direct `Connection` query-cache ownership introduced;
- [~] QueryBuilder reads moved to the owning connection cache;
- [~] query invalidation moved to owning `Connection::afterCommit()`;
- [~] tokenized `ConnectionLease` / `PoolManager::checkout()` introduced;
- [~] lease/double/stale release unit tests added;
- [~] instance-cache commit/rollback/same-name isolation tests added;
- [X] clean-install CI passed on the first PR run;
- [~] first analyzer failure identified as a `void` callback mismatch in `afterCommit()` and corrected on the branch;
- [ ] wait for/review the new CI run after the analyzer correction;
- [ ] complete façade compatibility, repository instance API, cache-identity hardening, sanitation audit, concurrency/soak tests and benchmark work before requesting merge.

**Completion gate:** Section 26.6 closes only after the DBLayer lower-layer release is green, Foundation consumes that released tag, connection lifecycle/pooling is explicitly benchmark-chosen, query caching is fully instance-correct, pooled ownership is concurrency-safe, MFA/passkey persistence is authoritative, and direct-DBLayer-vs-Foundation attribution records the final bridge overhead.

---

## 26.7 ReqShield 3.1 — open

**Released baseline:** ReqShield 3.1, commit `07e9e0a2465409e33c140b0cee920f821ca49c79`.

Open work:

- [ ] keep rule parsing/execution/sanitization/schema mechanics ReqShield-owned;
- [ ] finalize production schema topology before traffic;
- [ ] keep mutable Validator instances per-call unless a lower-layer reentrant compiled form is proven safe;
- [ ] do not add a Foundation validation-plan cache before measuring ReqShield's own bounded plan cache;
- [ ] keep DBLayer optional and acquire a DB connection only for actual DB-backed validation rules;
- [ ] preserve ReqShield `DatabaseProvider` and batching semantics;
- [ ] treat `exists`/`unique` as advisory validation, never a substitute for DB constraints;
- [ ] preserve depth/field/wildcard/path limits;
- [ ] keep custom callable rules/sanitizers explicit dynamic islands;
- [ ] prove persistent/Fiber state isolation and DB-free validation cold paths;
- [ ] record direct-ReqShield-vs-Foundation attribution.

---

## 26.8 Omnibus 2.5 — open

**Released baseline:** Omnibus 2.5, commit `7686de11b75ec4e02cbebd2080d6c470c1c314cf`.

Open work:

- [ ] keep routing/transport/retry/settlement/failure/workflow mechanics Omnibus-owned;
- [ ] preserve Omnibus message ID as Foundation execution correlation identity;
- [ ] keep handler/middleware resolution execution-scoped without singleton capture;
- [ ] expose selected Omnibus-native durable transports rather than reimplementing them;
- [ ] activate DBLayer/CacheLayer only for selected Omnibus capabilities;
- [ ] require suitable durable failure storage for production durable queues;
- [ ] bind DBLayer after-commit dispatch to the current execution connection safely;
- [ ] define Foundation generation supervision vs Omnibus Worker/WorkerPool ownership without double supervision;
- [ ] keep durable serialization data-only, never live runtime/service objects;
- [ ] prove retry/ack/release/reject correctness, persistent isolation and graceful generation replacement;
- [ ] record direct-Omnibus-vs-Foundation attribution.

---

## 26.9 TalkingBytes 2.0.0 — open

**Released baseline:** TalkingBytes 2.0.0, commit `86d0e9dde8124ddeacea8ba7f81911af584b879b`.

Open work:

- [ ] keep HTTP/email/webhook/gRPC protocol execution TalkingBytes-owned;
- [ ] classify profile/client lifetime as immutable process state, execution state or intentionally shared safe resilience state;
- [ ] keep cookie/auth/request mutation isolated between executions;
- [ ] keep webhook replay on CacheLayer atomic `setIfAbsent()` and Foundation security-key derivation;
- [ ] preserve production replay fail-closed topology;
- [ ] integrate inbound gRPC through the existing worker lifecycle rather than a fifth runtime graph;
- [ ] add TalkingBytes-native inbound/outbound email profiles where required;
- [ ] keep communication secrets out of logs/cache keys/generated artifacts;
- [ ] prove Fiber/persistent isolation and optional-capability cold paths;
- [ ] record direct-TalkingBytes-vs-Foundation attribution.

---

## 26.10 Epicrypt 2.1 — open

**Released baseline:** Epicrypt 2.1, commit `f80092978328cccaef0d2233b08ce95b453dd90a`.

Open work:

- [ ] inventory/classify every Foundation cryptographic operation;
- [ ] use Epicrypt high-level protection/key-derivation/password primitives where semantics match;
- [ ] define explicit purpose labels/versioning for derived keys;
- [ ] separate token-signing, MFA-secret and recovery-HMAC purposes/lifecycles;
- [ ] protect durable symmetric MFA secrets at rest;
- [ ] support active + bounded previous keys for rotation;
- [ ] keep secret material out of generated artifacts/logs/cache keys/metrics;
- [ ] resolve external secret references at boot rather than request hot paths;
- [ ] preserve fail-closed/tamper behavior and purpose isolation;
- [ ] prove persistent-runtime plaintext-secret isolation;
- [ ] record direct-Epicrypt-vs-Foundation attribution.

---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is now the Foundation-facing WebAuthn ceremony/state boundary over optional `web-auth/webauthn-lib`. Foundation no longer needs a separate WebAuthn protocol integration architecture.

Remaining work is intentionally tracked in:

- section 26.4 for passkey policy/OTP integration;
- section 26.6 for authoritative atomic credential persistence;
- section 26.10 for adjacent application key/protection policy.

**Status:** [X] standalone specialist pass closed/subsumed.

---

## 27. Working principles

For every runtime/lower-library change ask:

1. does a lower layer already own this behavior?
2. is this generic mechanism missing in the lower layer or merely Foundation policy?
3. can work happen at build time instead of runtime?
4. can runtime work happen once per process/generation instead of once per execution?
5. is lifetime safe under persistent and interleaved execution?
6. can explicit ownership replace process-global mutable state?
7. are retries/atomicity/locking provided by the correct owner?
8. are dynamic islands truly necessary and visible?
9. is cleanup deterministic and does it preserve the primary exception?
10. is Foundation overhead measured against the direct lower-layer operation?
11. if Foundation owns a hash, is the requirement security-sensitive (SHA3-256) or non-security identity (XXH128)?

---

## 28. Lower-library progress tracker

- [X] ArrayKit 5.2.0 utilization pass.
- [X] UID 5.0 utilization pass.
- [X] CacheLayer 3.3 atomic-capability + Foundation integration pass.
- [ ] OTP 6.1 + Passkey Foundation integration pass.
- [ ] Pathwise 3.1 utilization pass.
- [ ] **DBLayer 5.x utilization/hardening pass — active on `feature/foundation-26.6-hardening`, PR #31.**
- [ ] ReqShield 3.1 utilization pass.
- [ ] Omnibus 2.5 utilization pass.
- [ ] TalkingBytes 2.0.0 utilization pass.
- [ ] Epicrypt 2.1 utilization pass.
- [X] standalone WebAuthn specialist pass retired/subsumed by OTP 6.1 Passkey.

---

## 29. Final definition of done

Foundation 3 is ready only when:

- all four runtime artifacts build/load as one immutable generation;
- production uses generated InterMix containers and compiled Webrick correctly;
- no process-global mutable execution state leaks between scopes;
- lower-library mechanics remain lower-library-owned;
- DB/cache/auth/filesystem/messaging/validation/communication/crypto optional capabilities stay cold when unused;
- all security-sensitive state transitions are authoritative and fail closed;
- pooled/shared resources prove explicit ownership and concurrency safety;
- every dynamic island is intentional and reported;
- no production hot path performs source discovery or duplicate lower-layer orchestration;
- attribution benchmarks show the final Foundation overhead for every active lower-library bridge;
- Phase 10 aggregate gates pass;
- InfByte consumes the final Foundation 3 runtime/release lifecycle directly instead of recreating another framework runtime.
