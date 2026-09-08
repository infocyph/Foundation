# Foundation 3 — Unified Runtime Development Plan

**Status:** Canonical implementation plan  
**Foundation target:** 3.x  
**Foundation source baseline:** `main`  
**InterMix baseline:** `^10.0.4`  
**Webrick baseline:** `^5.3`  
**Priority:** correctness → hot-path performance → persistent-runtime safety → scalability → ergonomics

> This is the single source of truth for Foundation 3 runtime development. Completed work is intentionally summarized so the document stays maintainable. Open lower-library passes remain actionable and detailed. If a lower layer already owns the correct generic mechanism, Foundation consumes it directly; generic missing primitives belong in the lower layer, not in a Foundation-only workaround.
>
> **Plan-maintenance rule:** completed lower-library passes may be condensed after their evidence is recorded. Any open or partially open pass must retain its ownership decision, current findings/known issues, implementation checklist, correctness/security acceptance, performance acceptance, and completion gate until that pass is actually closed.

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

## 26.4 OTP 6.1 + Passkey/WebAuthn Foundation integration — core implementation complete; acceptance/26.10 closure pending

### Baseline

- package: `infocyph/otp` `^6.1`;
- audited/released version: OTP 6.1;
- tag commit: `c7faf376b96611638e7bc0da6cd081496768d34f`;
- OTP 6.1 requires CacheLayer `^3.3` and keeps AOTP (`ext-sodium`) plus Passkey/WebAuthn (`web-auth/webauthn-lib ^5.3`) as optional capabilities;
- Foundation's CacheLayer floor is `^3.4`, so OTP's released authentication-state contract is consumed directly.

### Ownership decision

OTP 6.1 is the lower-layer authentication-mechanics package, not only a TOTP/HOTP/OCRA package. Foundation consumes it as the protocol/mechanics owner and retains application policy, persistence and composition.

OTP owns:

- TOTP, HOTP and OCRA algorithms, verification windows, periods/counters and result objects;
- `GenericOtp` state/attempt semantics;
- AOTP Ed25519 challenge-response mechanics, challenge issuance/consumption and verification;
- GridOTP enrollment/challenge/response mechanics and challenge-state handling;
- MobileOTP/mOTP compatibility semantics, including its legacy protocol calculation and verification window;
- provisioning URI/enrollment payload construction and parsing where the protocol supports it;
- recovery-code generation/verification and the recovery-code usage-store contract;
- secret-rotation planning/result primitives;
- OTP-owned replay/state-key formats and CacheLayer coordination;
- native atomic replay/monotonic transitions where CacheLayer exposes them and coordinated-lock fallback where required;
- Passkey/WebAuthn registration/authentication option construction, ceremony challenge issuance/persistence/one-time consumption, upstream WebAuthn validation, credential-record serialization/mutation and `PasskeyResult` mapping;
- WebAuthn origin/RP/challenge/user-presence/user-verification/signature-counter/backup-state protocol decisions through the upstream library;
- OTP/Passkey-specific encoding, parsing, validation, versioned state domains and safe diagnostic redaction.

Foundation owns:

- whether MFA/passkeys are enabled and which factor type/mode is permitted for an application/account;
- normalized auth configuration and release/build validation;
- the policy that MobileOTP is legacy-only/explicit opt-in rather than a new-deployment default;
- optional capability availability (`ext-sodium` for AOTP and `web-auth/webauthn-lib` for OTP Passkey) and graph activation;
- selection/validation of the CacheLayer `AuthenticationStateCacheInterface` backend supplied to OTP stateful modes;
- durable factor persistence and authoritative compare-and-swap semantics where persisted factor state is Foundation-owned;
- durable passkey credential-record repository/schema and atomic replacement of the credential record returned by OTP Passkey;
- Foundation principal/account ↔ passkey user-handle mapping, discoverable-credential account policy, passkey naming/management and authorization;
- protection/rotation lifecycle for persisted symmetric OTP/GridOTP/MobileOTP secrets;
- recovery-code key lifecycle and Foundation's durable adapter to OTP's recovery-store contract;
- operational logging/metrics and non-sensitive external error mapping;
- release/runtime capability activation so unused OTP/AOTP/GridOTP/MobileOTP/Passkey features remain cold.

Foundation must not reimplement OTP math, AOTP signatures, GridOTP challenge algorithms, MobileOTP wire compatibility, provisioning URI logic, recovery algorithms, OTP replay-key algorithms, WebAuthn option construction, WebAuthn ceremony validation, signature-counter rules, origin/RP validation or challenge-consumption mechanics now owned by OTP 6.1.

### Implemented Foundation contract

- [X] Foundation OTP floor raised from `^6.0` to `^6.1` and `ext-sodium` added to the development matrix for AOTP coverage.
- [X] OTP is the single Foundation lower-layer MFA/passkey mechanics boundary for selected OTP/Passkey modes.
- [X] Foundation passkey registration/authentication routes through `Infocyph\OTP\Passkey`.
- [X] Direct Foundation WebAuthn ceremony/options/validator/serializer/codec duplication removed; retained `WebAuthnRuntime` is an empty deprecated cold-path compatibility sentinel only.
- [X] Foundation WebAuthn configuration narrowed to OTP-consumed RP ID, exact trusted origin, ceremony TTL and optional subdomain policy.
- [X] AOTP and GridOTP are exposed deliberately; MobileOTP is available only through an explicit legacy-import workflow.
- [X] Existing TOTP/HOTP/OCRA application paths continue to delegate protocol/window/replay mechanics to OTP.
- [X] Selected stateful OTP modes and Passkey require CacheLayer `AuthenticationStateCacheInterface` and fail closed when unavailable.
- [X] Foundation does not add a second replay/challenge store for OTP-owned state.
- [X] HOTP/counter-OCRA durable counters remain authoritative and transition through revision compare-and-swap.
- [X] MFA factor creation/activation uses authoritative CAS rather than unconditional persistence.
- [X] Sensitive OTP secret/PIN/private-key-shaped fields are redacted from enrollment audit/result context.
- [X] AOTP stores only the enrolled public verification key; private-key generation/signing remains device-owned through OTP.
- [X] OTP's authoritative passkey `CredentialRecord` is persisted in a dedicated `credential_record` column.
- [X] Successful passkey authentication replaces the credential record only when the stored revision still equals the revision OTP actually verified.
- [X] Additive passkey record/revision migrations and readiness checks cover existing installs.
- [X] `benchmark:otp` provides initial direct-OTP-versus-Foundation attribution and participates in `benchmark:release`.
- [X] Focused AOTP/GridOTP/MobileOTP and selected/fail-closed OTP Passkey graph regression coverage is present.

### Remaining implementation/policy checklist

- [ ] Prove every production `MfaFactorCompareAndSwapStoreInterface` implementation remains authoritative under the final supported deployment/concurrency matrix.
- [ ] Re-audit OTP recovery-code adapter semantics for committed-count, replacement and atomic consumption against OTP 6.1's current contract.
- [ ] Replace any remaining broad OTP `catch (Throwable)` classification with an internal operational taxonomy that distinguishes credential mismatch/replay/configuration from CacheLayer/backend failure, factor-store CAS exhaustion and unexpected runtime faults while preserving non-sensitive external auth failures.
- [ ] Keep backend/coordination/persistence failures fail-closed and bounded across all selected modes.
- [ ] Protect durable symmetric OTP/GridOTP/MobileOTP secrets at rest through the Epicrypt policy finalized in 26.10; plaintext exists only inside the narrow enrollment/verification execution window.
- [X] Treat WebAuthn credential records as public-key credential state rather than symmetric MFA secrets while preserving access controls and diagnostic redaction.
- [ ] Decide the recovery-code HMAC key lifecycle explicitly with Epicrypt; do not silently couple it to unrelated token-secret rotation.
- [ ] Use OTP rotation planner/result types for algorithm-specific rotation while Foundation owns durable CAS/transactional activation and account-policy transitions.
- [ ] Prove concurrent secret/factor rotation cannot lose newer factor/counter/recovery state.
- [ ] Preserve OTP structured result/reason information internally rather than reducing useful state to booleans too early.
- [ ] Reconfirm OTP/Passkey service lifetimes are immutable/stateless or external-state-backed under persistent/Fiber execution.
- [ ] Reconfirm OTP-related capability graphs remain absent when no OTP/AOTP/GridOTP/MobileOTP/Passkey factor is selected.

### Passkey/WebAuthn Foundation handoff

Foundation no longer owns ceremony challenge generation/storage/consumption, creation/request option construction, direct attestation/assertion validator wiring, Base64URL protocol conversion or WebAuthn signature-counter rules. Those sit behind OTP `Passkey`.

Foundation still owns the durable application record returned by the ceremony. After successful registration/authentication it atomically persists the exact `credentialRecordJson` returned by OTP, binds it to the correct Foundation principal/user handle, rejects stale concurrent replacement and applies passkey/account policy. DBLayer 26.6 supplies the generic persistence/CAS primitive.

### Correctness and security acceptance

- [ ] Test TOTP valid/invalid verification, configured skew/window, replay rejection and concurrent replay through the final hardened auth-state cache topology.
- [ ] Test HOTP next-counter persistence and concurrent factor-store CAS; exactly one stale state advance may win.
- [ ] Test counter-OCRA durable CAS and non-counter/time/challenge OCRA through OTP replay state.
- [ ] Test recovery-code success, reuse rejection, replacement and concurrent consumption through the Foundation adapter.
- [X] Test AOTP enrollment/public-key persistence, issuance, valid/invalid signature and one-time challenge replay; prove no private key reaches Foundation persistence/logs/artifacts.
- [X] Test GridOTP enrollment, challenge issuance, valid/invalid response and challenge-state behavior through OTP-owned state.
- [X] Test MobileOTP legacy import/verification policy and ensure it is never presented as the preferred new-deployment mode.
- [ ] Test full Passkey registration and authentication through OTP `Passkey`, including observable RP ID/origin/challenge/user-presence/user-verification behavior without duplicating validator logic.
- [ ] Test OTP Passkey challenge expiry and concurrent duplicate ceremony consumption so at most one execution succeeds.
- [X] Persist the exact successful `credentialRecordJson` returned by OTP and prove stale credential-record writes are rejected rather than silently overwriting newer state.
- [ ] Test concurrent assertions against one stored passkey credential with realistic ceremony execution so a stale write cannot overwrite newer authenticator state.
- [ ] Test discoverable credential/user-handle mapping cannot bind a passkey to the wrong Foundation principal.
- [ ] Test cache/backend outage, factor-store CAS exhaustion, stale passkey persistence and unexpected OTP exceptions all fail closed while retaining the correct internal operational category.
- [X] Test production composition rejects an insecure/missing authentication-state capability for the selected Passkey path.
- [ ] Extend release/build rejection coverage to every selected stateful OTP mode that requires secure authentication-state capability.
- [ ] Test persisted secret protection for every production symmetric MFA factor store after 26.10.
- [ ] Complete sequential/interleaved Fiber verification and persistent-worker reuse across representative TOTP/AOTP/GridOTP/Passkey paths.
- [X] Test capability absence leaves unrelated auth graphs free of OTP/Passkey activation where not selected.
- [ ] Complete final PHP 8.4/8.5 stable/lowest QA and static-analysis matrix on the final PR head with deprecations resolved.

### Performance acceptance

Benchmark Foundation against direct OTP 6.1 for the same semantic operation:

1. graph/boot cost with all OTP capabilities absent versus enabled-but-unused;
2. direct TOTP/provisioning work versus the Foundation OTP wrapper, separating replay I/O from wrapper cost;
3. replay-protected TOTP through OTP's CacheLayer atomic path;
4. HOTP verification + Foundation factor-store CAS;
5. representative OCRA counter and challenge/time paths;
6. recovery-code verification/consumption;
7. AOTP issue + verify;
8. GridOTP challenge + verify;
9. MobileOTP verification as a compatibility path;
10. direct OTP Passkey registration/authentication versus Foundation policy + credential lookup/atomic persistence;
11. passkey stale-contention persistence path;
12. repeated representative verification under a persistent runtime with memory measurement.

`benchmark:otp` provides the initial direct native-provisioning versus Foundation provisioning-wrapper attribution. The remaining stateful factor/passkey/persistence measurements above stay open; CacheLayer/DBLayer costs must be attributed separately before optimizing wrapper code.

### Completion gate

26.4 can be checked only when Foundation consumes OTP 6.1 as the single OTP/AOTP/GridOTP/MobileOTP/Passkey mechanics boundary, direct WebAuthn ceremony duplication is removed, secure OTP auth-state topology is a release gate, all Foundation-owned factor/credential stores prove authoritative atomic persistence, persisted symmetric MFA secrets are protected, operational failure taxonomy is preserved, new-mode/rotation/concurrency tests pass, optional activation stays cold, final CI is green/deprecation-clean, and direct-OTP-versus-Foundation benchmarks record the final adapter/persistence overhead.

**Status:** core integration is implemented; final acceptance remains open and symmetric-secret-at-rest closure is intentionally owned by 26.10.

---

## 26.5 Pathwise 4 filesystem integration — lower layer release-ready; Foundation consumption pending 4.0 release

### Baseline

- current Foundation package floor remains `infocyph/pathwise ^3.1` until Pathwise 4.0 is tagged;
- previously audited/released Pathwise baseline: 3.1, commit `8226cf42747ae131486063cad39335d6dfc1c7f7`;
- Pathwise 4.0 is an intentional breaking major rather than the formerly expected additive 3.2 follow-up;
- Pathwise 4 release-candidate work is complete on PR #21 / `pathwise-3.2/storage-context`;
- release-candidate acceptance code head: `1a25a376844b3ef55ccb684a275f68e33bb2d64e`;
- after the 4.0 tag is published, Foundation must raise its floor to `infocyph/pathwise ^4.0` and consume the released contracts directly.

### Ownership decision

Pathwise/Flysystem own generic filesystem/storage/upload/download/archive/native-execution mechanics. Foundation owns application storage policy/configuration and runtime composition. Webrick owns HTTP request/response semantics and native response emission.

Pathwise 4 now owns:

- `StorageContext` as the instance-scoped named-filesystem/default/custom-driver/path-resolution boundary for persistent and multi-application runtimes;
- stateless filesystem creation through `StorageFactory`; the old process-global custom-driver/mount registry and facade mount gateways are no longer the preferred/public topology boundary;
- low-level stateless/direct-local helper mechanics where useful without requiring Foundation to create a global mount namespace;
- optional per-processor `StorageContext` routing for `UploadProcessor` and `DownloadProcessor`, including multiple contexts reusing the same logical disk name without cross-talk;
- generic file/directory copy/move/read/write/stream mechanics and truthful capability differences between local and adapter-backed storage;
- `UploadSource` path/stream/framework-mover input, Pathwise-owned private materialization, authoritative size and deterministic cleanup;
- upload validation, chunk handling/finalization, naming/deduplication and content inspection;
- `MalwareScanMode` (`OFF`, default `WHEN_CONFIGURED`, `REQUIRED`), typed `MalwareScannerInterface`, `MalwareScanRequest`, explicit `MalwareScanVerdict`, provider/status diagnostics and stable fail-closed errors;
- private local malware scan copies, actual-size enforcement, scan-copy mutation/replacement detection and scanning before MIME/signature/image parsing;
- assembled-file malware scanning for chunk uploads before deeper processing/publication;
- bounded clamd INSTREAM integration through `ClamAvDaemonScanner`; LMD + ClamAV is a deployment/signature configuration rather than a Foundation request-worker `maldet`/root execution path;
- download preparation, ranges, exact-byte positioning/iteration and resource closure through Pathwise range-aware streaming (`streamChunks()` / common stream core);
- typed transfer/range/chunk result objects;
- generic safe symlink create/status/remove behavior with explicit link/target roots, containment checks and broken-link/current-target handling;
- hardened archive creation/extraction, canonical/case/file-directory collision checks, traversal/symlink/special-entry rejection, expansion limits, exact streamed-byte enforcement and rollback/staging semantics;
- local-only truthful atomic replacement and staged non-atomic adapter writes;
- bounded native execution with timeout/stdout/stderr limits and typed unsupported/failure results;
- capability-based platform behavior: unsupported native guarantees are not emulated on Windows merely for parity; `AUTO` uses the portable PHP path and forced `NATIVE` fails explicitly;
- Pathwise-owned local queue/audit/transaction/index/watcher/retention mechanics where applications use them, without changing Omnibus ownership of Foundation messaging/worker queues;
- direct production `psr/log ^3.0.2` dependency while concrete logger implementation remains application-owned.

Foundation owns:

- `filesystem.disks`, `filesystem.default`, upload/download/offload/link/scanner application configuration;
- application base/public/storage path semantics through `PathManager`;
- deciding which configured filesystem capabilities are included in each runtime graph;
- resolving relative application disk roots against the Foundation base path before constructing `StorageContext`;
- upload/download policy values such as allowed roots/types/extensions/sizes, image/chunk limits, naming policy and malware scan mode;
- selecting/composing an optional application malware scanner and validating `REQUIRED` mode before traffic;
- Webrick `UploadedFile` → `UploadSource` adaptation without owning generic temp-file lifecycle;
- Webrick conditional-request behavior, `FileBody`/iterable stream response selection and native response emission;
- X-Sendfile/X-Accel application/server policy and eligibility;
- application storage-link configuration and the allowed Foundation public/storage roots passed to Pathwise safe-link mechanics;
- operational policy around Pathwise capability failures without rewriting lower-layer storage behavior.

Foundation must not retain a second generic named-storage registry, global-mount namespace workaround, upload materializer, malware-scan engine, range-stream implementation, safe-symlink engine, archive engine or native-process implementation once consuming Pathwise 4.

### Pathwise 4 lower-layer closure — complete

- [X] `psr/log ^3.0.2` is a direct production dependency.
- [X] `StorageContext` owns per-instance named filesystems, defaults, logical paths, local-path capability and custom driver factories.
- [X] Redundant process-global custom-driver/mount topology was removed/de-emphasized; `StorageFactory` is stateless and processor integration no longer requires Foundation global mounts.
- [X] `UploadProcessor` and `DownloadProcessor` accept/use per-instance storage context routing.
- [X] Two independent contexts may reuse the same logical disk name without cross-talk.
- [X] `UploadSource` owns typed source materialization/cleanup semantics for path, stream and framework-mover inputs.
- [X] Malware scanning is typed, mode-driven, fail-closed when configured/required and occurs before deeper content parsing.
- [X] `WHEN_CONFIGURED` with no scanner performs no malware scan; `REQUIRED` with no scanner fails closed.
- [X] ClamAV daemon scanning is bounded and shell/root-free for the PHP worker; LMD + ClamAV deployment guidance is documented.
- [X] Download range iteration/positioning/exact-byte/resource-closure mechanics are adapter-usable without making Pathwise the HTTP writer.
- [X] Generic safe symlink management is Pathwise-owned.
- [X] Archive/parser, queue durability, audit/retention/index/watcher and native-execution hardening are complete.
- [X] Windows handling is capability-based rather than maintained through parallel emulation engines.
- [X] Complete Pathwise 4 user documentation, migration/API/security/performance guidance and warning-as-error Sphinx gate are present.
- [X] PHP 8.4/8.5 stable+lowest QA, PHPStan/Psalm, Windows, optional adapter contracts, clean production install and release benchmarks pass on the release candidate; release stress is part of the Pathwise release gate.

### Foundation consumption checklist after Pathwise 4.0 release

- [ ] Raise the Foundation floor from `infocyph/pathwise ^3.1` to `^4.0` and remove obsolete 3.x compatibility paths.
- [ ] Refactor Foundation `StorageRegistry` into a thin application-config/default/base-path adapter over one Pathwise `StorageContext` per Foundation application/generation.
- [ ] Remove Foundation mount-scope XXH128 naming and `FlysystemHelper::replaceMount()` integration entirely.
- [ ] Ensure Foundation never mutates Pathwise process-global mount/default topology during normal application composition.
- [ ] Inject the same Foundation-owned `StorageContext` into transient `UploadProcessor` and `DownloadProcessor` instances.
- [ ] Reduce `FilesystemUploadRequestHandler` to Webrick upload/chunk metadata extraction plus direct `UploadSource` construction.
- [ ] Remove Foundation `foundation-upload-*`, `ensureDirectory()`, `materializeUpload()` and equivalent generic temp ownership.
- [ ] Map Foundation scanner configuration to `MalwareScanMode` rather than the former boolean `require_malware_scan` contract.
- [ ] Make `WHEN_CONFIGURED` the normal optional-scanner behavior unless application policy explicitly selects `OFF` or `REQUIRED`.
- [ ] If Foundation selects `REQUIRED`, fail build/boot before traffic when no `MalwareScannerInterface` service is configured; retain Pathwise runtime fail-closed behavior as the backstop.
- [ ] Support Pathwise `ClamAvDaemonScanner` directly as the standard ClamAV provider and allow custom/AMWScan/ICAP/cloud scanners through `MalwareScannerInterface` without vendor logic in Foundation.
- [ ] Document LMD + ClamAV as host scanner/signature integration; never require the PHP/Foundation worker to invoke `maldet` as root/sudo.
- [ ] Preserve stable/non-sensitive external scanner errors and route detailed previous/backend failures only to internal operational diagnostics.
- [ ] Replace Foundation range seek/discard/read duplication with Pathwise prepared download + `streamChunks()`/owned stream iteration.
- [ ] Keep Webrick request conditionals, response headers/statuses, `FileBody`/stream-body choice and native output in Foundation/Webrick.
- [ ] Replace generic `StorageLinkManager` internals with a thin Foundation config/`PathManager` adapter over Pathwise `SafeSymlinkManager`.
- [ ] Preserve Pathwise typed result objects directly rather than wrapping them in Foundation-only equivalents.
- [ ] Keep `PathManager` in Foundation because application base/public/storage/resource/runtime semantics are Foundation-owned.
- [ ] Keep Pathwise queue/native helper availability separate from Foundation messaging ownership: Omnibus remains the messaging/worker queue runtime.
- [ ] Preserve capability-based Windows behavior; do not add Foundation Windows-native emulation merely to bypass Pathwise `UNSUPPORTED` results.
- [ ] Keep filesystem capability cold when not selected and do not activate optional Flysystem adapters merely because packages are installed.

### Correctness and security acceptance

Pathwise lower-layer acceptance already established by its 4.0 release candidate:

- [X] isolated `StorageContext` instances/defaults/custom drivers and same-name logical disks do not cross-talk;
- [X] upload/download processor context routing works without global mounts;
- [X] typed upload sources clean Pathwise-owned materialization on success and failure;
- [X] malware modes, explicit verdicts, private staging, parser ordering, mutation detection, required-scanner failure and clamd protocol limits are covered;
- [X] chunk uploads scan the fully assembled file before deep parsing/publication;
- [X] prepared/range streaming enforces exact byte counts and closes resources on normal, exceptional and early-disposal paths;
- [X] symlink containment/current-target/broken-link semantics are covered;
- [X] archive traversal/collision/special-entry/bomb/write-time containment/rollback protections are covered;
- [X] bounded native execution and capability-based Windows fallback/forced-native failure are covered;
- [X] warning-free docs, stable/lowest PHP 8.4/8.5, analyzers, optional adapters, Windows, clean install and release benchmarks are release gates.

Foundation integration acceptance remains open:

- [ ] Two Foundation applications/generations in one process can reuse identical configured disk names without cross-talk or global Pathwise mutation.
- [ ] Default-disk resolution, logical disk paths and local-path capability match direct Pathwise `StorageContext` behavior.
- [ ] Unknown/malformed disk configuration fails before traffic.
- [ ] A Webrick upload is not copied by Foundation before Pathwise receives the source except where the Webrick source contract itself requires one framework move.
- [ ] Pathwise-owned upload materialization is always cleaned after validation/scanner/storage failure.
- [ ] `OFF`, `WHEN_CONFIGURED` without scanner, `WHEN_CONFIGURED` with scanner and `REQUIRED` modes all map correctly from Foundation configuration.
- [ ] `REQUIRED` mode without scanner is rejected during Foundation build/boot.
- [ ] Scanner infrastructure failure remains fail-closed and non-sensitive externally.
- [ ] ClamAV/LMD deployment does not require Foundation/PHP root privileges.
- [ ] Normal and chunked uploads preserve configured limits/naming/storage policy while generic mechanics remain Pathwise-owned.
- [ ] Full, single-range, suffix/open-ended, invalid and unsatisfiable downloads preserve correct Webrick status/header/body behavior while Pathwise owns byte positioning.
- [ ] Early-aborted Webrick streaming closes the underlying Pathwise resource deterministically.
- [ ] X-Sendfile/X-Accel remains available only when Foundation's application/server policy permits it and does not bypass Pathwise/Foundation security policy.
- [ ] Link create/status/remove uses Pathwise safe-link semantics while Foundation supplies only configured roots/mappings.
- [ ] Traversal/archive/symlink protections are not weakened by Foundation path normalization.
- [ ] Sequential/interleaved Fiber/persistent-worker filesystem use retains no prior request/job source, range, scanner or context state.
- [ ] Filesystem capability absence leaves unrelated runtime graphs free of Pathwise/Flysystem services.
- [ ] Foundation PHP 8.4/8.5 filesystem integration is deprecation-clean on the final Pathwise 4 floor.

### Performance acceptance

Benchmark the released Pathwise 4 lower-layer path and final Foundation bridge at minimum:

1. `StorageContext` construction with one and multiple disks;
2. warm `StorageContext::filesystem()` / `resolve()` / `localPath()` versus the Foundation `StorageRegistry` bridge;
3. custom-driver context construction/lookup;
4. direct `UploadSource::fromPath()` and `fromStream()` materialization versus Webrick→Foundation→Pathwise adaptation;
5. scan mode `OFF`, `WHEN_CONFIGURED` without a scanner, and `REQUIRED` with a deterministic in-process scanner, excluding external AV-engine time from Foundation wrapper attribution;
6. clamd round-trip/deployment performance separately from Foundation adapter overhead;
7. normal/chunk upload and finalize;
8. direct `DownloadProcessor::prepareDownload()` + `streamChunks()` versus Foundation Webrick response adaptation;
9. seekable versus non-seekable range streaming;
10. direct Pathwise safe-link create/status/remove versus Foundation link-policy adapter;
11. Foundation filesystem capability absent versus enabled-but-unused graph/boot cost;
12. repeated multi-context/persistent-runtime filesystem operations with memory/topology-isolation measurement.

Pathwise 4's own `benchmark:release` covers the major lower-layer boundaries, including context resolution, upload materialization/malware staging, range streaming, queue leases, local atomic/adapter-staged writes, archive extraction, bounded native execution, checksum iteration and deduplication. Foundation should benchmark only its remaining policy/request-response bridge rather than duplicate those lower-layer benchmarks.

### Completion gate

26.5 can be checked only after Pathwise 4.0 is tagged/released; Foundation raises its floor to `^4.0`; `StorageRegistry` is reduced to application configuration over `StorageContext`; the global mount namespace workaround, Foundation upload materializer, range-stream duplication and generic symlink mechanics are removed; scanner mode/provider composition is correct and `REQUIRED` fails before traffic when unavailable; Webrick remains the only HTTP response/output owner; optional/persistent/Fiber isolation is proven; final PHP 8.4/8.5 acceptance is green; and direct-Pathwise-versus-Foundation attribution records only the final application-policy/HTTP bridge overhead.

**Status:** Pathwise 4 lower-layer work is release-ready; Foundation consumption is blocked only on the 4.0 package release/tag and remains open afterward until the checklist above is complete.

---

## 26.6 DBLayer 5.1 Foundation integration — complete

**Released baseline:** DBLayer 5.1, commit `087f179ecac3e5555c346ce84cfc353050f8e3cb`.  
**Foundation floors:** `infocyph/dblayer ^5.1`, `infocyph/cachelayer ^3.4`.

Foundation uses explicit execution-owned DBLayer connections and `ConnectionRepository`, delegates generic writes/upserts and query-cache mechanics to DBLayer, retains only Foundation domain persistence/CAS policy, and supports DBLayer `ConnectionLease` pooling without sharing execution leases. Query caching remains explicit/default-off; transactional invalidation binds to the exact current execution connection; pooled rollback/reset/health/lifetime sanitation remains DBLayer-owned. Dedicated execution-owned connections remain the default, with pooling opt-in only after deployment-specific attribution.

Final DBLayer 5.1 lifecycle, query-cache, MFA/passkey CAS, schema/migration, Fiber/isolation, clean-install, static-analysis and stable/lowest PHP 8.4/8.5 acceptance passed on PR #13. `benchmark:dblayer` remains part of `benchmark:release` for deployment-specific lifecycle/pooling attribution.

**Status:** [X] complete.

---

## 26.7 ReqShield 3.1 utilization pass — open

### Baseline

- package: `infocyph/reqshield` `^3.1`;
- audited release: ReqShield 3.1;
- tag commit: `07e9e0a2465409e33c140b0cee920f821ca49c79`;
- ReqShield's production package does not require DBLayer; DBLayer is a development/integration dependency and database validation is exposed through ReqShield's small `DatabaseProvider` contract.

### Ownership decision

ReqShield owns validation, sanitization, schema/rule compilation and validation execution. Foundation owns named application schemas, application policy/configuration and framework integration around ReqShield.

ReqShield owns:

- validation rule parsing/compilation;
- built-in validation rules;
- `ValidationPlan` and rule execution;
- validation result/error/failure objects;
- sanitization;
- input casting;
- nested/wildcard validation mechanics;
- validation limits;
- field aliases/messages/locale behavior;
- strict/unknown-field behavior;
- DTO/result mapping where exposed by ReqShield;
- schema composition;
- JSON-schema export behavior;
- process-level rule/plan caching supplied by ReqShield;
- `CompiledValidator`;
- database-rule definitions;
- `DatabaseBatchRule`;
- `DatabaseProvider` contract;
- batching/grouping/execution of expensive database rules through `BatchExecutor`.

Foundation owns:

- named application validation schemas;
- built-in Foundation auth request schemas;
- application schema extension policy;
- `validation.defaults` / named overrides;
- choosing whether validation is enabled in a runtime graph;
- Webrick request/input adaptation;
- FormRequest/application convenience APIs;
- selection of an optional ReqShield database provider;
- selection of the DBLayer connection used by database rules;
- mapping validation failures to application/HTTP behavior;
- DI lifetime and build/runtime composition;
- deciding which configured custom callbacks/rules/sanitizers are acceptable dynamic inputs.

Foundation must not reimplement rule parsing, rule execution, sanitization, schema compilation, wildcard expansion, validation batching or JSON-schema generation above ReqShield.

### Current integration findings to preserve

1. `ValidationServiceProvider` only installs validation when the validation capability is selected.
2. Validation does not automatically create a database graph.
3. Foundation checks whether `DBLayerFactory` is already present and only then contributes `ReqShieldDatabaseProvider`.
4. `ValidatorFactory` accepts `?DatabaseProvider`; ordinary validation remains valid without DBLayer.
5. `ReqShieldDatabaseProvider` resolves its DBLayer connection only when a database rule is actually executed.
6. `ValidatorFactory::make()` and `makeRules()` produce a new ReqShield `Validator`, avoiding shared mutable validator state between executions.
7. `ValidationSchemaRegistry::extend()` delegates generic schema composition to `Validator::composeSchemas()` instead of maintaining another schema-composition algorithm.
8. Foundation's DB adapter batches values using DBLayer's safe parameter limits instead of issuing one query per field/value.

### Confirmed current issues / required decisions

1. ReqShield 3.1 deliberately keeps DBLayer out of its production dependencies. Foundation must preserve this modular boundary; validation-only applications must not gain DBLayer simply because ReqShield supports `exists` / `unique`.
2. The current Foundation graph provides a DB adapter to validators when database capability is already present. This is acceptable because connection resolution remains lazy, but do not add per-validation DB initialization merely for API uniformity.
3. ReqShield detects whether a validation plan actually contains database rules and only needs `DatabaseProvider` for that expensive batch. Preserve that behavior.
4. Database `exists` / `unique` checks are validation-time observations, not database constraints. They cannot guarantee uniqueness against a concurrent write. Foundation persistence code must still rely on real DB constraints/transactions and correctly map constraint violations.
5. `CompiledValidator` is readonly, but in ReqShield 3.1 it wraps a closure capturing a `Validator` and delegates repeated validation to that captured object. Foundation must not assume this automatically makes one compiled validator safe as a process-wide concurrent singleton.
6. ReqShield already maintains bounded process-level plan caching internally. Do not add a Foundation validation-plan cache until benchmarks prove a real missing layer.
7. If Foundation needs an immutable/exportable precompiled validation artifact for generated releases and ReqShield does not expose one, add that general capability to ReqShield rather than serializing Foundation's captured validator closure.
8. `ValidationSchemaRegistry` is mutable through `define()` / `extend()`. In generated production, configured/Foundation schemas should be finalized before traffic; request/job code should not mutate one process-wide schema registry.
9. Application-defined callable rules, conditions and sanitizers can be legitimate dynamic inputs. They must remain explicit dynamic islands rather than being silently serialized into generated artifacts.
10. Validation limits such as depth, field count, wildcard expansions and flattened paths are security/DoS controls. Foundation must not disable or inflate them simply to avoid validation failures.
11. ReqShield already batches expensive database rules by operation/table. Foundation must not regress to field-by-field `exists`/`unique` queries.
12. Foundation's DB adapter performs its own query construction because ReqShield intentionally exposes a framework-neutral contract. Keep that adapter narrow.
13. HTTP request construction/parsing remains Webrick-owned. Foundation should feed ReqShield normalized application input rather than introduce a second generic HTTP request parser through validation.
14. File/content validation and file storage remain different responsibilities: ReqShield validates input; Pathwise owns storage/upload processing. Foundation should not merge those runtimes.

### Audit and implementation checklist

- [ ] Rescan every Foundation `Infocyph\ReqShield` use against ReqShield 3.1 tagged APIs.
- [ ] Keep named schema/application policy in `ValidationSchemaRegistry`; keep rule execution in ReqShield.
- [ ] Keep Foundation schema extension based on `Validator::composeSchemas()` rather than generic array merging where ReqShield semantics differ.
- [ ] Finalize configured production validation schemas before traffic.
- [ ] Prevent normal production request/job code from mutating the shared schema registry.
- [ ] Retain development/tooling schema mutability only where genuinely useful.
- [ ] Keep `ValidatorFactory` as a thin configuration/profile mapper.
- [ ] Audit every `ValidatorFactory` setter/config option against ReqShield's native API and remove Foundation transformations that add no application semantics.
- [ ] Keep per-call Validator construction unless a lower-layer immutable/reentrant compiled form is proven safe and measurably faster.
- [ ] Do not cache `CompiledValidator` process-wide merely because its wrapper is readonly.
- [ ] Benchmark ReqShield's own bounded plan cache before adding any Foundation cache.
- [ ] If generated immutable validation plans would materially improve boot/hot-path cost, first add/release a generic exportable plan contract in ReqShield.
- [ ] Keep database validation optional.
- [ ] Do not activate DBLayer merely because validation is enabled.
- [ ] Keep DB connection acquisition lazy until a validation plan actually executes DB-backed rules.
- [ ] Preserve ReqShield's native `DatabaseProvider` contract as the only coupling from ReqShield into Foundation's database adapter.
- [ ] Preserve ReqShield `BatchExecutor` grouping/batching semantics.
- [ ] Keep DBLayer batch-size calculation in the Foundation adapter for actual backend parameter limits.
- [ ] Review `ReqShieldDatabaseProvider` query grouping for `exists`, `unique`, ignored IDs, nullable values and soft-delete policy.
- [ ] Treat database validation as advisory validation only; enforce authoritative uniqueness/integrity at database write time.
- [ ] Preserve validation depth/field/wildcard/path limits and fail safely when limits are exceeded.
- [ ] Keep custom callable rules/sanitizers/conditions as explicit dynamic configuration where needed.
- [ ] Avoid capturing request/principal/container state into long-lived validator instances.
- [ ] Feed ReqShield arrays/normalized values from the existing Webrick request boundary; do not add a second HTTP parsing layer.
- [ ] Preserve ReqShield's structured `ValidationResult`, failures and validated-input objects internally rather than reducing everything to booleans prematurely.
- [ ] Keep JSON-schema generation lower-layer-owned when Foundation exposes it.
- [ ] Ensure validation capability is absent from runtime graphs that do not select it.

### Correctness and security acceptance

- [ ] Test named Foundation schemas and application-defined schemas.
- [ ] Test schema extension/composition behavior against direct ReqShield.
- [ ] Test required/type/string/numeric/date/array/conditional representative rules through Foundation.
- [ ] Test sanitizers and casts.
- [ ] Test nested/wildcard validation.
- [ ] Test strict/strip/allow-unknown behavior.
- [ ] Test aliases/custom messages/locales.
- [ ] Test DTO/result behavior where Foundation exposes it.
- [ ] Test max depth, max fields, max wildcard expansions and max flattened paths.
- [ ] Test malformed or attacker-controlled deeply nested input fails within bounded resource usage.
- [ ] Test a validation-only application with no DBLayer validates successfully and contains no database definitions/connections.
- [ ] Test an application with database capability but a non-DB schema performs zero DB connection/query work during validation.
- [ ] Test schemas using `exists` / `unique` require a database provider.
- [ ] Test batched `exists` across repeated values/columns.
- [ ] Test batched `unique`, ignored IDs and soft-delete options.
- [ ] Test nullable database-rule values.
- [ ] Test database provider failure is surfaced as a validation infrastructure failure rather than silently converted to successful validation.
- [ ] Test DB uniqueness validation cannot replace an authoritative database unique constraint in persistence tests.
- [ ] Test repeated validation through a persistent worker does not retain prior validated data/errors.
- [ ] Test interleaved Fiber validations remain isolated.
- [ ] If compiled validators are ever shared, add explicit concurrency/reentrancy tests before adopting that lifetime.
- [ ] Test production schema registry topology remains unchanged across executions.
- [ ] Test custom callable rule/sanitizer dynamic islands do not leak execution state.
- [ ] Test disabled validation capability adds no ReqShield services to unrelated graphs.

### Performance acceptance

Benchmark at minimum:

1. validation capability absent versus enabled-but-unused graph/boot cost;
2. direct ReqShield Validator construction versus Foundation `ValidatorFactory::make()`;
3. first named-schema validation;
4. warm repeated named-schema validation using ReqShield's internal plan cache;
5. Foundation `compile()` / `CompiledValidator` path without unsafe singleton caching;
6. representative scalar schema;
7. nested/wildcard schema;
8. sanitization/casting-heavy schema;
9. strict/unknown-field handling;
10. direct ReqShield DB batch versus Foundation ReqShield→DBLayer adapter;
11. multiple `exists` checks showing batched behavior;
12. multiple `unique` checks showing batched behavior;
13. non-DB validation while database capability is present, proving zero DB I/O;
14. repeated validations under persistent runtime with memory measurement.

Do not introduce a Foundation validation cache simply because repeated schema construction appears in profiles. First attribute cost against ReqShield's own bounded compiled-plan cache.

Do not replace per-call mutable validators with shared compiled validators without concurrency proof.

### Completion gate

The ReqShield tracker can be checked only when validation/sanitization/schema mechanics remain ReqShield-owned; Foundation contains only application schema/profile/request-boundary policy; validation does not activate DBLayer on its own; non-DB schemas perform no database I/O even when DB capability exists; DB rules retain ReqShield-native batching; validation-time uniqueness is not mistaken for authoritative database integrity; production schema topology is finalized; mutable validators cannot leak state across executions; any compiled/shared validator optimization is explicitly proven reentrant; validation limits/security behavior remain intact; and direct-ReqShield versus Foundation attribution benchmarks record the final bridge overhead.

---

## 26.8 Omnibus 2.5 utilization pass — open

### Baseline

- package: `infocyph/omnibus` `^2.5`;
- audited release: Omnibus 2.5;
- tag commit: `7686de11b75ec4e02cbebd2080d6c470c1c314cf`;
- Omnibus 2.5 directly requires UID 5.0 and exposes optional CacheLayer/DBLayer integrations for coordination, durable queues, failure stores, workflows and after-commit behavior.

### Ownership decision

Omnibus owns messaging/event/queue/worker mechanics. Foundation owns application messaging topology, service resolution and integration of Omnibus workers with the Foundation release/execution lifecycle.

Omnibus owns:

- `Envelope` and stamps;
- `MessageIdStamp` message identity;
- `MessageBus`;
- message routing and `RouteMap`;
- transport contracts and `TransportRegistry`;
- synchronous and in-memory transports;
- DBLayer durable transport;
- Redis/Valkey transport;
- broker transport abstraction;
- AMQP/SQS integration boundaries;
- reservation/visibility/acknowledge/release/reject semantics;
- `Consumer`;
- retry strategy behavior;
- failed-message behavior and failure-store contracts;
- `Worker`, `WorkerOptions`, `WorkerLifecycle` and built-in `WorkerPool`;
- `HandlerMap`, `HandlerInvoker` and Omnibus handler middleware pipeline;
- event dispatch/listener maps and queued listeners;
- envelope/message/stamp serialization;
- uniqueness/overlap/rate-limit/circuit-breaker behavior supplied by Omnibus + CacheLayer;
- DBLayer-backed failure storage and workflow storage;
- DBLayer queue schema and transport mechanics;
- workflow coordination;
- after-commit messaging integration;
- Omnibus telemetry wrappers;
- scheduled-message dispatch primitives;
- message transport/consumer soak/runtime semantics.

Foundation owns:

- `messaging.handlers` application service IDs;
- listener service IDs;
- handler/job middleware service IDs;
- application message routes;
- selected transport profiles and queue names;
- application retry configuration;
- selected durable failure-store profile;
- selected Omnibus workflow/coordination capabilities;
- mapping configured application service IDs into the finalized InterMix runtime;
- Foundation worker graph inclusion and release-generation worker topology;
- graceful generation replacement policy;
- Foundation execution scope and cleanup around one delivered message;
- propagation of Omnibus message identity into Foundation logging/correlation state;
- selection of DBLayer/CacheLayer instances used by Omnibus integrations;
- application operational defaults and build-time validation.

Foundation must not create a competing event bus, queue runtime, retry engine, reservation protocol, failure queue, uniqueness system, overlap lock system, workflow engine or worker message loop above Omnibus.

### Current integration findings to preserve

1. `MessagingServiceProvider` already builds native Omnibus `HandlerMap`, `HandlerInvoker`, `ListenerMap`, `RouteMap`, `TransportRegistry`, `MessageBus`, `EventDispatcher`, `Consumer`, worker and scheduled-message services rather than wrapping Omnibus behind a second Foundation messaging abstraction.
2. `MessagingRuntimeResolver` is an explicit dynamic island for application-configured service IDs. The surrounding messaging graph remains generated.
3. Handler and listener service instances are resolved from the finalized container during actual execution rather than being captured when the process singleton messaging topology is built.
4. `ResolvingHandlerMiddleware` resolves application middleware inside the active execution scope.
5. Omnibus `HandlerInvoker` prebuilds the middleware pipeline structure once, so Foundation does not need another middleware pipeline runtime.
6. `InterMixExecutionScope` reuses `MessageIdStamp` as the Foundation execution correlation identity instead of generating an unrelated second execution ID.
7. `ConsumerFactory` delegates retry decisions to Omnibus `ExponentialRetryStrategy`.
8. `OmnibusWorkerFactory` maps application worker configuration into native `WorkerOptions` / `Worker`.
9. Scheduler-to-message behavior uses Omnibus `ScheduledMessageDispatcher`.

### Confirmed current issues / required expansion

1. Foundation's default `TransportRegistry` currently contains only `sync` and `memory`. Omnibus 2.5 exposes more lower-layer transport functionality, including DBLayer durable queues and native Redis/Broker boundaries. Foundation should expose selected Omnibus transports through configuration rather than leave users to rebuild integration outside Foundation.
2. The default `FailureStore` is `InMemoryFailureStore`. That is appropriate for local/testing/synchronous/in-memory use but is not a durable production failed-message store for an asynchronous durable queue.
3. A production durable worker should require an explicitly suitable failure-store policy where failed-message retention/retry operations are required.
4. DBLayer and CacheLayer are optional Omnibus integrations. Selecting `sync`/`memory` must not activate either dependency graph.
5. Selecting DBLayer transport/workflow/failure storage should consume the already-selected Foundation DBLayer capability and safe lifecycle from 26.6.
6. Selecting uniqueness/overlap/rate-limit/circuit policies should consume Omnibus's CacheLayer integration rather than Foundation-auth cache adapters or a new Foundation policy implementation.
7. Omnibus already provides its own bounded/legal CacheLayer policy-key encoder. Do not reuse Foundation authentication-state physical-key namespaces for messaging coordination.
8. Omnibus DBLayer `AfterCommitDispatcher` binds to an actual `Connection`. Under Foundation's execution-scoped DB model this integration must use the current execution connection and never become a process singleton that captures the first scoped database connection.
9. Omnibus `Consumer` already owns receive → execute → retry/release → failure/reject → acknowledge lifecycle. Foundation must not add a second retry/settlement decision outside it.
10. Failure/retry and transport settlement errors have different semantics. Foundation logging may classify them, but it must not acknowledge a message Omnibus decided should be released/rejected.
11. Handler/listener/middleware topology is known during Foundation composition. Service IDs should be validated/enriched into the generated graph before release, while actual scoped service resolution remains execution-time behavior.
12. `MessagingRuntimeResolver` accepts arbitrary callables in addition to service IDs. User-provided callables are legitimate dynamic islands, but Foundation-owned configured class/service handlers should prefer deterministic service IDs.
13. Normalize worker topology at build/process boot rather than making source configuration discovery part of steady worker lifecycle.
14. Foundation owns cross-generation worker replacement; Omnibus owns worker execution mechanics. Do not create two independent supervisors competing over restart/shutdown semantics.
15. Omnibus's `WorkerPool` is process orchestration for same-generation worker concurrency. Foundation must explicitly compose it beneath Foundation generation supervision instead of independently supervising the same workers twice.
16. Omnibus workflows, chains/batches and durable state already include atomic/claim semantics. If Foundation exposes them, use Omnibus contracts/stores directly rather than creating Foundation workflow records.
17. Omnibus 2.5 includes serializer registries/codecs. Foundation must require explicit message/stamp serialization topology for durable transports instead of serializing arbitrary service/runtime objects.
18. Message envelopes may contain sensitive application data. Foundation diagnostics must not dump complete serialized envelopes indiscriminately.
19. Messaging is optional. A runtime that does not select messaging must not pay for transports, consumers, DB queue tables, CacheLayer policies or worker topology.

### Audit and implementation checklist

- [ ] Rescan every Foundation `Infocyph\Omnibus` usage against Omnibus 2.5 tagged APIs.
- [ ] Keep `MessageBus`, route maps, handler maps, consumer, workers, retries, failure stores and transport settlement Omnibus-owned.
- [ ] Keep Omnibus `MessageIdStamp` as the authoritative Foundation execution correlation identity when present.
- [ ] Preserve one `foundation.worker` execution scope per delivered message.
- [ ] Continue seeding the Omnibus `Envelope` and message into the InterMix execution scope.
- [ ] Ensure scope cleanup completes before Omnibus acknowledges/releases/rejects according to the consumer result path.
- [ ] Preserve primary handler failure over Foundation cleanup failures while still allowing Omnibus to perform correct retry/failure settlement.
- [ ] Prevalidate configured handler/listener/middleware service IDs during graph/release build without eagerly instantiating execution-scoped services.
- [ ] Prefer service IDs for Foundation-owned messaging topology; retain raw callables only as documented dynamic islands.
- [ ] Keep execution-time resolution inside the active execution so scoped dependencies remain scoped.
- [ ] Verify singleton `HandlerInvoker` captures immutable topology/resolver wrappers only, never an execution-scoped handler instance.
- [ ] Expand Foundation transport configuration around Omnibus-native transports instead of implementing transport-specific queue code in Foundation.
- [ ] Keep `sync` and `memory` as zero-external-dependency transports.
- [ ] Add DBLayer durable transport composition only when explicitly configured.
- [ ] Add native Redis/Valkey transport composition only when explicitly configured and required client/extension is available.
- [ ] Add broker/AMQP/SQS integrations through Omnibus published boundaries when selected; do not embed vendor protocol logic into Foundation.
- [ ] Validate transport capabilities such as receive/delay/visibility at build/process boot where possible.
- [ ] Keep DBLayer/CacheLayer graphs absent unless selected Omnibus features require them.
- [ ] For DBLayer durable transport, consume 26.6 lifecycle rather than registering a separate process-global database connection.
- [ ] For DBLayer after-commit dispatch, bind the current execution connection safely; do not capture a scoped `Connection` in a process singleton.
- [ ] Use Omnibus DBLayer failure/workflow stores directly when selected.
- [ ] Keep Omnibus QueueSchema ownership for durable queue tables; Foundation chooses deployment/migration policy only.
- [ ] Keep `InMemoryFailureStore` for appropriate development/local/non-durable profiles.
- [ ] Require explicit durable failure-store choice for production durable queues where failed-message retention/retry is expected.
- [ ] Use Omnibus CacheLayer uniqueness, overlap, rate-limit and circuit-breaker integrations directly.
- [ ] Use Omnibus policy/storage-key semantics for messaging coordination; do not route through auth-state cache adapters.
- [ ] Keep Omnibus retry strategy authoritative; Foundation only maps retry configuration.
- [ ] Keep transport acknowledge/release/reject decisions inside Omnibus Consumer.
- [ ] Preserve Omnibus visibility/reservation semantics and cancellation/deadline behavior.
- [ ] Normalize worker topology into Foundation generation metadata/build output and avoid source configuration discovery during steady worker execution.
- [ ] Define exact ownership between Foundation generation supervision and Omnibus `Worker` / optional `WorkerPool`.
- [ ] Foundation owns generation A→B replacement; Omnibus owns same-generation execution/worker-loop semantics.
- [ ] Do not run a Foundation supervisor and Omnibus WorkerPool as independent owners of the same child processes.
- [ ] Keep scheduler runtime and worker runtime separate even when scheduler dispatches an Omnibus message.
- [ ] Use Omnibus workflow/chain/batch facilities directly if Foundation exposes them.
- [ ] Define durable serializer/message-codec/stamp-codec topology explicitly for each non-memory transport.
- [ ] Prohibit serialization of live container/Application/Connection/request/principal/service objects into durable envelopes.
- [ ] Keep message/failure diagnostics redaction-aware.
- [ ] Use Omnibus telemetry sinks/wrappers where semantics fit instead of wrapping every transport/consumer with another Foundation telemetry runtime.
- [ ] Keep messaging services entirely absent from runtime graphs that do not select messaging.

### Correctness and reliability acceptance

- [ ] Test synchronous dispatch and in-memory asynchronous transport.
- [ ] Test configured route/default route behavior.
- [ ] Test handler and listener/event resolution.
- [ ] Test handler/job middleware ordering.
- [ ] Test execution-scoped handler/middleware dependencies are fresh between messages.
- [ ] Test Omnibus `MessageIdStamp` maps to the same Foundation execution identity throughout handler/logging/history state.
- [ ] Test missing message IDs receive exactly one Foundation fallback execution identity.
- [ ] Test sequential messages do not retain previous envelope/message/principal/DB state.
- [ ] Test interleaved Fiber execution where supported.
- [ ] Test handler success acknowledges exactly once.
- [ ] Test retryable failure releases with Omnibus retry delay.
- [ ] Test terminal failure records failure and rejects according to Omnibus semantics.
- [ ] Test decode failure follows Omnibus undecodable-message failure semantics.
- [ ] Test Foundation cleanup failure cannot cause an already-successful/failed message to be settled incorrectly.
- [ ] Test cancellation, shutdown and visibility timeout behavior.
- [ ] Test worker `max_messages`, runtime and memory limits.
- [ ] Test graceful Foundation generation replacement while Omnibus worker execution is active.
- [ ] Test optional WorkerPool shutdown/restart ownership without double supervision.
- [ ] Test durable DBLayer transport enqueue/reserve/ack/release/reject and contention.
- [ ] Test DBLayer after-commit dispatch sends only after successful outer commit and does not send after rollback.
- [ ] Test durable failure-store retry claims/concurrent retry handling.
- [ ] Test workflow atomic claims/transitions if workflow support is enabled.
- [ ] Test Redis/Valkey transport when enabled and broker transport capability checks when enabled.
- [ ] Test unique-message, overlap, lost-lease, rate-limit and circuit-breaker behavior.
- [ ] Test `sync`/`memory` topology activates neither DBLayer nor CacheLayer solely because those packages are installed.
- [ ] Test DB-backed messaging and CacheLayer-backed policies activate only required graphs.
- [ ] Test durable serialization round trips for message + core stamps and rejects unknown/malformed types safely.
- [ ] Test durable envelopes cannot deserialize arbitrary Foundation runtime service objects.
- [ ] Test failed-message logs/telemetry avoid sensitive payload disclosure.
- [ ] Run long persistent-consumer soak tests proving bounded memory and no scoped state leakage.

### Performance acceptance

Benchmark Foundation against direct Omnibus for the same semantic workload:

1. messaging capability absent versus enabled-but-unused graph/boot cost;
2. direct synchronous Omnibus dispatch versus Foundation `foundation.messaging`;
3. handler-map resolution;
4. zero/one/multiple handler middleware;
5. service-ID resolution through the Foundation execution scope;
6. execution-scope/message-ID bridge overhead;
7. in-memory send/receive/ack cycle;
8. retryable failure/release path;
9. terminal failure-store path;
10. durable DBLayer enqueue/reserve/ack and contention;
11. Redis/Valkey send/receive when enabled;
12. uniqueness/overlap/rate-limit/circuit policy overhead separately;
13. durable serialization encode/decode;
14. worker construction/boot from generation-owned topology;
15. steady-state worker message throughput;
16. optional WorkerPool same-generation concurrency;
17. repeated persistent worker execution with memory measurement;
18. workflow/chain/batch paths only when Foundation actually exposes them.

Do not bypass Foundation execution scope merely to improve message throughput; scope is required for DB/auth/principal/temp-resource cleanup and state isolation. Do not create faster Foundation-specific queue paths that skip Omnibus retry/reservation/failure semantics.

### Completion gate

The Omnibus tracker can be checked only when Foundation remains a thin topology/service-resolution/lifecycle adapter around Omnibus; Omnibus owns routing, transport, retry, settlement, failure and workflow mechanics; message IDs are reused as Foundation execution identity; handler/middleware resolution remains execution-scoped without singleton capture; Foundation exposes required Omnibus-native durable transports rather than reimplementing them; DBLayer/CacheLayer integrations activate only when selected; durable workers have durable failure semantics; DB after-commit dispatch uses the correct current execution connection; Foundation generation supervision and Omnibus worker/WorkerPool ownership do not conflict; serialization boundaries contain data rather than live runtime services; persistent consumer isolation and cancellation/replacement tests pass; and direct-Omnibus versus Foundation attribution benchmarks record the final messaging bridge overhead.

---

## 26.9 TalkingBytes 2.0.0 utilization pass — open

### Baseline

- package: `infocyph/talkingbytes` `^2.0`;
- audited release: TalkingBytes 2.0.0;
- tag commit: `86d0e9dde8124ddeacea8ba7f81911af584b879b`.

### Ownership decision

TalkingBytes owns communication protocol execution. Foundation owns application profile selection/composition and execution-lifecycle policy around those protocol objects.

TalkingBytes owns:

- HTTP request/response execution and client configuration;
- retry, rate-limit, circuit-breaker, idempotency and cookie behavior supplied by TalkingBytes;
- inbound/outbound email protocol, parsing/serialization and transport behavior;
- webhook signing, verification, sender/receiver behavior and the `WebhookReplayStore` contract;
- gRPC client invocation, generated-stub/native invocation, streaming and inbound dispatch contracts;
- protocol-specific result/error objects and lower-layer transport semantics.

Foundation owns:

- named HTTP/email/webhook/gRPC application profiles;
- build-time capability/profile selection and validation;
- mapping configured gRPC/email/webhook handlers to application service IDs;
- InterMix lifetime/scope selection for profile objects;
- selecting the CacheLayer-backed webhook replay implementation;
- production secret/reference policy for credentials, API keys and signing secrets;
- deciding whether inbound gRPC/email processing participates in the existing worker execution boundary;
- observability/correlation policy without duplicating TalkingBytes protocol logic.

Foundation must not create another HTTP client, mail parser/transport, webhook signature runtime or gRPC protocol layer above TalkingBytes.

### Confirmed current findings

1. `CommunicationProfiles::http()` rejects production profiles that disable TLS peer or host verification. Preserve this fail-closed policy.
2. `HttpClient` and `WebhookSender` are execution-scoped, while `CommunicationProfiles` is process-safe configuration state. This is safer than a blanket singleton because cookie jars and some resilience components are mutable, but the pass must explicitly classify profile state: cookie/session state must never leak across executions, while rate-limit/circuit-breaker semantics intended to span calls must not be accidentally reset every execution.
3. If TalkingBytes needs a shareable resilience-state primitive, fix/consume that lower-layer primitive rather than introducing Foundation global mutable state.
4. `CacheLayerWebhookReplayStore` must use the canonical Foundation security-state key encoder from 26.3: explicit domain separation + SHA3-256, not SHA-256 or ad-hoc character replacement.
5. With CacheLayer 3.4 available, replay claim must use CacheLayer's true atomic `setIfAbsent()` capability; Foundation lock → `has()` → `set()` orchestration must remain absent.
6. Production `WebhookReceiver` requires replay protection by default. Do not weaken this merely to make webhook composition optional.
7. gRPC inbound dispatch is a narrow configured-service-ID boundary. Handler service IDs should be fixed during graph composition; actual handler resolution remains inside active execution.
8. An inbound gRPC server/process belongs to the existing worker runtime/lifecycle rather than creating a fifth Foundation runtime graph.
9. Foundation currently exposes HTTP/webhook/gRPC profile graph directly. Add TalkingBytes email profile integration where functionality requires inbound/outbound email; do not recreate MIME, mail-chain or transport behavior in Foundation.
10. Communication credentials/signing secrets are configuration secrets, not release identities. Do not log them, place them in cache/replay keys or bake raw secret material into generated runtime metadata. Coordinate the final secret-source/protection policy with 26.10.

### Audit and implementation checklist

- [ ] Rescan every Foundation `Infocyph\TalkingBytes` usage against the 2.0.0 tagged API and remove wrappers that add no application policy.
- [ ] Keep `CommunicationProfiles` as the thin profile mapper and normalize/validate profile topology before production runtime load.
- [ ] Classify every TalkingBytes-backed binding as immutable process state, execution-scoped state or intentionally shared concurrency-safe resilience state.
- [ ] Keep cookie-bearing/session-bearing HTTP clients execution isolated; prove no cookie/header/auth mutation leaks between requests/jobs/Fibers.
- [ ] Determine whether configured `RateLimiter`/`CircuitBreaker` state is intended to span executions. If yes, consume a lower-layer safe sharing mechanism; do not solve it with an unsafe Foundation singleton client.
- [ ] Keep webhook replay physical keys on the 26.3 domain-separated SHA3-256 security-state encoder.
- [ ] Require CacheLayer atomic `setIfAbsent()` for production webhook replay and keep Foundation replay-store lock dependency removed.
- [ ] Validate production replay topology during composition/release build, including authoritative security-state store and atomic capability.
- [ ] Preserve fail-closed replay claiming.
- [ ] Keep gRPC retry/streaming/native/generated-stub behavior lower-layer-owned.
- [ ] Run each inbound gRPC call/stream execution through the existing worker execution scope/correlation lifecycle when Foundation owns the server process.
- [ ] Add build-time validation for configured gRPC handler service IDs without eagerly instantiating handlers during graph compilation.
- [ ] Add named inbound/outbound email profiles through TalkingBytes native email APIs.
- [ ] Keep inbound/outbound email message-chain and protocol behavior in TalkingBytes.
- [ ] Keep outbound webhook HTTP-profile selection and retry/idempotency behavior delegated to TalkingBytes.
- [ ] Ensure communication profile secrets remain redaction-safe at Foundation logging and exception boundaries.
- [ ] Keep communication capability entirely absent from runtime graphs that do not select it.

### Correctness and security acceptance

- [ ] Test production TLS peer/host verification cannot be disabled through a named HTTP profile.
- [ ] Test HTTP profile auth modes, retry, idempotency, rate-limit/circuit behavior and cookie isolation through the Foundation bridge.
- [ ] Test sequential and interleaved Fiber HTTP executions do not leak cookie/auth/request state.
- [ ] Test webhook signature verification, age validation, replay acceptance once and concurrent duplicate rejection.
- [ ] Test long/attacker-controlled webhook delivery IDs map to legal deterministic CacheLayer physical keys.
- [ ] Test replay cache failures fail closed and cleanup does not mask the primary failure.
- [ ] Test production composition rejects replay-enabled webhook topology without required secure CacheLayer capability.
- [ ] Test gRPC unary/native/generated-stub and supported streaming paths without Foundation protocol duplication.
- [ ] Test inbound gRPC service resolution occurs inside the correct execution scope and does not retain previous call state in a persistent worker.
- [ ] Test inbound/outbound email profiles preserve TalkingBytes parsing/transport/message-chain behavior and execution isolation.
- [ ] Test secrets are absent from logs, exceptions, cache keys, generated route/container diagnostics and benchmark output.

### Performance acceptance

Benchmark at minimum:

1. communication capability absent versus enabled-but-unused graph/boot cost;
2. direct TalkingBytes HTTP request construction/execution versus Foundation profile bridge;
3. warm stateless profile lookup and scoped client creation;
4. cookie-enabled profile execution;
5. retry/rate-limit/circuit-enabled profile execution with state attribution;
6. direct webhook verification versus Foundation + CacheLayer replay claim;
7. gRPC unary dispatch;
8. representative gRPC streaming dispatch through Foundation scope boundary;
9. inbound email processing;
10. outbound email sending;
11. repeated communication operations in a persistent worker with memory/state-isolation measurement.

Do not cache or singletonize a mutable TalkingBytes client merely to improve a microbenchmark. Any lifetime optimization must preserve isolation and intended resilience-state semantics.

### Completion gate

The TalkingBytes tracker can be checked only when profile lifetimes are explicitly safe; webhook replay keys/claims conform to the hardened CacheLayer contract; production replay remains fail-closed; HTTP client state does not leak across executions; resilience state has an intentional lifetime; gRPC integrates through the existing worker lifecycle; inbound/outbound email and message-chain behavior are consumed from TalkingBytes rather than recreated; communication secrets are proven safe; and direct-TalkingBytes versus Foundation attribution benchmarks record the final bridge overhead.

---

## 26.10 Epicrypt 2.1 utilization pass — open

### Baseline

- package: `infocyph/epicrypt` `^2.1`;
- audited release: Epicrypt 2.1;
- tag commit: `f80092978328cccaef0d2233b08ce95b453dd90a`.

### Ownership decision

Epicrypt owns cryptographic primitives and high-level data-protection/key-derivation behavior. Foundation owns application key purpose, secret sourcing, persistence boundaries and auth-policy integration.

Epicrypt owns, where semantics match Foundation requirements:

- purpose-isolated key derivation through `Generate\KeyMaterial\KeyDeriver`/HKDF;
- key-material generation helpers;
- high-level string/file/envelope data protection;
- `StringProtector`, `FileProtector`, `EnvelopeProtector`;
- protection algorithms/options/results and protected-payload encoding;
- password-hashing primitives/policy helpers;
- MAC/signature/integrity primitives;
- token/JOSE/certificate/key-exchange primitives when Foundation actually needs those protocols;
- crypto-specific validation/error behavior and key/algorithm details.

Foundation owns:

- which application secrets exist and their semantic purposes;
- mapping secret references to externally supplied key material at process boot;
- purpose labels/domain separation between token signing, MFA-secret protection, recovery-code HMAC and other auth uses;
- storage schema/key-version metadata and rotation rollout policy;
- when protected values are decrypted/reprotected;
- authorization/account behavior after cryptographic verification;
- redaction/observability policy;
- keeping secrets out of generated release artifacts;
- build-time validation that configured keys/algorithms are usable without performing sensitive request-time work during graph compilation.

Foundation must not maintain parallel HKDF, encryption-envelope, MAC or password-hashing implementations when Epicrypt already provides the required contract.

### Confirmed current findings

1. OTP recovery-code authentication currently domain-separates an HMAC key from the auth token secret. Domain separation is good, but unrelated security functions should not silently share one root-secret lifecycle.
2. Foundation should use either a dedicated recovery key or derive purpose-specific subkeys from a deliberate master key through Epicrypt `KeyDeriver`.
3. MFA factors expose OTP secrets as strings at the application boundary. Every durable store must protect those secrets at rest.
4. Epicrypt's high-level data-protection layer is the canonical protection implementation; Foundation should not add a bespoke AES/Sodium wrapper.
5. Token-signing keys, recovery-code HMAC keys and MFA-encryption keys must have different purpose labels/derived material even when one externally managed master secret is intentionally used.
6. Release-generation config/artifacts are not a secret vault. Prefer stable secret references/identifiers in generated metadata and resolve key material from the deployment secret boundary at process boot.
7. Key rotation must support decrypt/verify with still-valid previous key material while new writes use the active key.
8. Durable key/version metadata must be sufficient for deterministic decryption/reprotection.
9. Do not rely on trial-decrypting arbitrary unrelated keys without a bounded explicit key ring.
10. Use Epicrypt high-level DataProtection services before assembling lower-level crypto primitives. Foundation policy should remain thin and purpose-oriented.

### Audit and implementation checklist

- [ ] Inventory every Foundation cryptographic operation: password hashing, token signing/verification, recovery-code HMAC, MFA-secret protection, configuration/domain-value protection, integrity checks and random key generation.
- [ ] Classify each operation as Epicrypt-owned primitive/high-level service or Foundation-owned policy.
- [ ] Remove duplicate lower-level implementations only when semantics are exactly equivalent.
- [ ] Introduce explicit purpose labels/versioning for every derived key.
- [ ] Keep separate purposes such as `foundation.auth.mfa-secret.v1`, `foundation.auth.recovery-hmac.v1` and token-signing purposes.
- [ ] Use Epicrypt `KeyDeriver` for deliberate subkey derivation instead of ad-hoc HMAC-based derivation scattered across auth classes.
- [ ] Decide whether recovery-code HMAC uses a dedicated externally supplied key or a derived subkey from one intentional auth master key.
- [ ] Document recovery-key rotation semantics.
- [ ] Protect OTP MFA secrets at rest with Epicrypt high-level data protection.
- [ ] Retain plaintext MFA secrets only for the narrow verification/provisioning execution window.
- [ ] Carry key/version/purpose metadata sufficient for deterministic decryption and rotation without exposing raw key material.
- [ ] Support active + bounded previous decryption/verification keys during rotation.
- [ ] Require new writes to use only the active key.
- [ ] Keep secret material out of InterMix generated artifacts, Foundation generation manifests, cache keys, logs, exception messages and metrics labels.
- [ ] Resolve external secret references once at process/runtime boot where practical; do not perform file/env/secret-provider discovery on request hot paths.
- [ ] Keep cryptographic service objects singleton only when immutable/concurrency-safe and free of mutable per-execution secret state.
- [ ] Prefer Epicrypt password APIs for password hashing/rehash policy where they match Foundation's public contract.
- [ ] Never use reversible encryption for passwords.
- [ ] Use Epicrypt token/JOSE/certificate primitives only for Foundation features that genuinely require those protocols.
- [ ] Do not increase dependency surface merely to maximize package usage.
- [ ] Preserve structured Epicrypt exceptions internally while mapping them to non-sensitive application/auth failures externally.
- [ ] Coordinate OTP secret/recovery decisions with 26.4.
- [ ] Coordinate persisted secret and passkey-adjacent application-key decisions with 26.4/26.11 where relevant.

### Correctness and security acceptance

- [ ] Test purpose-derived keys are deterministic for the same master/purpose and distinct across every Foundation security purpose.
- [ ] Test recovery-code verification remains stable across the intended rotation window and rejects use of a key outside that window.
- [ ] Test MFA secrets persist only in protected form for every production store.
- [ ] Test MFA secrets decrypt correctly for authorized verification/provisioning flows.
- [ ] Test active-key writes plus previous-key reads/reprotection during rotation.
- [ ] Test interrupted rotation and rollback behavior.
- [ ] Test tampered protected payloads fail closed with no plaintext disclosure.
- [ ] Test wrong purpose/key/version cannot decrypt or authenticate another Foundation domain's payload.
- [ ] Test password hash/verify/rehash behavior through Foundation matches selected Epicrypt policy.
- [ ] Test generated runtime/release artifacts contain no raw configured key or decrypted MFA secret.
- [ ] Test normal logs contain no raw configured key or decrypted MFA secret.
- [ ] Test sequential/Fiber/persistent-worker auth operations do not retain one execution's plaintext secret in reusable mutable state.
- [ ] Test missing/malformed/unsupported production key configuration fails before traffic where composition-time validation is possible.

### Performance acceptance

Benchmark at minimum:

1. crypto capability absent versus enabled-but-unused graph/boot cost;
2. direct Epicrypt key derivation versus Foundation purpose-key lookup/derivation;
3. direct `StringProtector` protect versus Foundation MFA-secret storage bridge;
4. direct `StringProtector` unprotect versus Foundation MFA-secret storage bridge;
5. active-key decrypt path;
6. previous-key decrypt/reprotect path;
7. password verify/rehash through direct Epicrypt versus Foundation auth adapter;
8. recovery-code HMAC derivation/verification after final key-lifecycle decision;
9. repeated auth operations under persistent runtime with memory measurement.

Cryptographic work is intentionally more expensive than ordinary application plumbing. Optimize Foundation wrapper/config lookup overhead, not away authentication, integrity, KDF or encryption guarantees.

### Completion gate

The Epicrypt tracker can be checked only when every Foundation crypto site is classified; duplicated key-derivation/data-protection code is removed or explicitly justified; MFA/recovery/token purposes have explicit independent derivation/lifecycle; durable MFA secrets are protected at rest; bounded rotation works; redaction/secret-boundary tests pass; persistent-runtime secret isolation is proven; and direct-Epicrypt versus Foundation attribution benchmarks record the final adapter overhead.

---

## 26.11 Standalone WebAuthn specialist pass — closed/subsumed

OTP 6.1 `Passkey` is now the sole Foundation-facing WebAuthn ceremony/state runtime over optional `web-auth/webauthn-lib`. Foundation's former ceremony store, options factory, credential mapper, serializer/validator runtime, attestation policy, direct passkey service, and duplicate Base64Url codec have been removed. The retained `WebAuthnRuntime` class is an empty deprecated compatibility/cold-path sentinel and owns no WebAuthn behavior.

DB persistence mechanics remain in 26.6; the integrated OTP/Passkey application work is tracked in 26.4; durable secret-protection policy remains in 26.10.

**Status:** [X] closed/subsumed.