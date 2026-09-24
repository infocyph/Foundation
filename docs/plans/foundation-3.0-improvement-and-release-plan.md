# Foundation 3.0 — improvement and release plan

**Target:** next release, **3.0**.  
**Status:** implementation started — Batch A (dependency/public contract) in progress.  
**Reviewed:** 2026-09-24.  
**Source baseline:** `dbaa92b3672c1c385ef0cc94854434d283857ff7`, plus the live Composer dependency edits described below.  
**Scope:** Foundation as Infbyte's reusable application hub, its first-party integrations, native application services, and supported execution environments.

This plan closes concrete gaps before 3.0 and records optional enrichments separately. It does not propose replacing the existing architecture. Foundation should continue to compose specialist packages, while Infbyte supplies application defaults and deployment entry points. Implementation proceeds in verified batches below; publication and deployment remain separate release steps.

## 1. Relationship to existing plans

- [Unified runtime plan](foundation-3-final-runtime-development-plan.md) remains the architectural and historical implementation record. Its aggregate closure cites CI run #1489 at `e76d08ed3492006b389b3b5972f3bb5f19b74937`.
- [Module hardening plan](foundation-3-module-system-hardening-plan.md) records the completed module work and its intended boundary.
- This document is the **3.0 follow-up review and release backlog**. Historical completed work stays completed; only the findings and verification gaps below become new work.
- Historical green CI does not validate the current source revision or subsequent dependency edits. Obtain final evidence for the actual 3.0 candidate before tagging.
- Foundation is the active implementation scope. Infbyte work is deferred at the user's request. Validate Foundation using isolated generic consumers; skeleton migration and publication are a later independent task and do not block Foundation batch completion.

### Migration baseline: Foundation 2.0

The upgrade scope covers all changes since tag `2.0`
(`a64715a9c1a0df8305cb9123ca8de8f2e13390ea`), including changes already made
through 2.1/2.1.1 and the current 3.0 candidate. Do not describe retained 2.0
features as newly introduced or repeat completed implementations. Maintain a
cumulative API/configuration/persisted-data inventory in the migration guide;
verify custom providers, auth credentials, sessions, schemas and queued payloads
against that starting point. Individual applications may start at later 2.x
versions but must still account for all applicable earlier migration steps.

## 2. Preserve the architecture

### 2.1 Ownership map

| Area | Mechanism owner | Foundation responsibility |
| --- | --- | --- |
| Containers, scope isolation, generated DI | InterMix | Provider composition, capability policy, execution boundaries |
| Routing, request/response, native HTTP adapters | Webrick | Application middleware, release/bootstrap integration |
| Runtime engine and FPM/FrankenPHP/RoadRunner/Swoole host adaptation | Runwire, through Webrick for HTTP and Omnibus for process pools | Propagate runtime context/capabilities and compose the application; no duplicate host drivers |
| Cache, atomic operations, locks | CacheLayer | Configuration, namespacing, readiness, scope use |
| Queries, connections, transactions, migrations | DBLayer | Application configuration, schema orchestration, release of owned resources |
| Messaging, queues, retry, worker pools | Omnibus | Handler wiring, auth-event forwarding, application worker policy |
| Cryptography and OAuth/OIDC/PAT protocol mechanics | Epicrypt | Accounts, principals, application persistence/policy and HTTP adapters |
| OTP and passkey mechanics | OTP, with WebAuthn specialist support | Enrollment, account integration, configuration and application policy |
| Filesystems, uploads, downloads | Pathwise | Application roots, capabilities and security policy |
| Validation and sanitization | ReqShield | Request/command/configuration integration |
| HTTP/email/webhook/gRPC transports | TalkingBytes | Application profiles, notification composition and scope cleanup |
| Identifiers | UID | Appropriate identifiers at execution/application boundaries |
| Array/config transformations | ArrayKit | Configuration composition and normalized snapshots |
| Auth, authorization, browser sessions | Foundation with specialist adapters | Reusable application semantics and persistence contracts |
| Scheduler, CLI, operations, notifications, resources | Foundation with specialist services | Application orchestration and public application APIs |
| Tooling | PHPForge | Project-specific gates and reproducible evidence |
| Application skeleton | Infbyte | Starter layout, application code, examples and deployment UX |

### 2.2 Invariants for all work

1. Retain four explicit application modes: `web`, `cli`, `worker`, `scheduler`. PHP-FPM, Runwire, FrankenPHP, RoadRunner and Swoole/OpenSwoole are host/transport choices, not additional application modes. When Runwire is selected, reuse its host adaptation rather than creating Foundation host drivers.
2. Webrick owns native request adaptation, request scope and response emission. Foundation must not add a competing server loop or unconditional outer request scope.
3. Package installation, feature selection, capability activation and readiness remain distinct. Optional integrations stay cold when disabled.
4. Preserve generated production containers and atomic release generations. Missing or invalid production artifacts must not trigger silent development bootstrap.
5. Preserve native specialist APIs. Add an adapter only for application policy, lifecycle or a real interoperability boundary.
6. Do not require Runwire, database, messaging or authentication merely to run a minimal Foundation application.
7. Keep mutable execution state scoped. Long-lived web servers require the same care as workers even though `RuntimeMode::isPersistent()` currently classifies only worker/scheduler modes.
8. Keep security, correctness and persisted compatibility ahead of optimization. Judge performance by sustained successful application RPM, with bounded memory, queue depth and connections.
9. Use the engineering principles in `vendor/infocyph/phpforge/resources/engineering-principles.md`; do not weaken tests, detectors, thresholds or skip policy to close a gate.

## 3. Review evidence and limits

### 3.1 Work inspected

The review used the existing graph as a navigation aid, then inspected current source, tests, configuration, documentation and CI. Graphify reported a skill/package version mismatch and truncated its broad result; its output is not treated as completeness evidence.

Primary source areas:

- `src/Application`, `src/Bootstrap`, `src/Container`, `src/Runtime`, `src/Release`, `src/Routing`;
- `src/Module`, `src/Diagnostics`, `src/Session`, representative auth/notification/testing boundaries;
- runtime, release, optional-capability, auth/OAuth, session and architecture tests;
- `composer.json`, the two checked-in workflows, and the active PHPForge configuration.

The review is not a complete security audit, a full production-load run, or certification of every database/HTTP backend.

### 3.2 Live dependency baseline

During review, the working tree changed these constraints; they were preserved:

| Package | Initial tracked constraint | Live constraint / installed version observed |
| --- | --- | --- |
| InterMix | `^10.0.4` | `^10.1.1` / `10.1.1` |
| Webrick | `^5.3` | `^5.4` / `5.4` |
| WebAuthn library | `^5.3.5` | `^5.3.9` / `5.3.9` |

Other reviewed runtime requirements: PHP `^8.4`, Composer runtime API `^2.0`, ArrayKit `^5.2`, CacheLayer `^3.4`, UID `^5.0`, PSR Log `^3.0.2`.

Reviewed optional integration floors in `require-dev`: DBLayer `^5.1`, Epicrypt `^3.1`, Omnibus `^2.6`, OTP `^6.1`, Pathwise `^4.1`, ReqShield `^3.2`, TalkingBytes `^2.1`. PHPForge is `dev-main@dev`; optional runtime packages must be installed explicitly by consuming applications because a library's development requirements do not propagate to consumers.

Freeze the selected floors and record installed source references at implementation start. Do not interpret this table as approval to update additional dependencies.

### 3.3 Commands actually run

| Check | Result |
| --- | --- |
| `composer ic:doctor` | Two warnings: host `pdo_mysql` and `pdo_pgsql` are missing |
| `composer ic:list-config` | All 11 listed configs resolve from PHPForge; no project-root `phpprobe.json` exists in this snapshot |
| `composer ic:active-config` | Active settings inspected; Deptrac has generic `Project`/`Vendor` layers |
| `composer ic:test:code` | **8 failed, 427 passed, 20,719 assertions**, 9.61 seconds, host PHP 8.5.4 |
| Direct session configuration probe | `INF` and `NAN` accepted for lock wait/lease settings |
| Direct session middleware failure probe | Lock-release exception becomes primary; original handler exception survives as `previous`; active session stack is cleared |
| Direct catalog inspection | 11 entries: seven specialist namespaces plus logging, operations, resources and session; cache absent |

Test failure classification:

- One CacheLayer atomic integration failure: Redis connection refused.
- Five session-lock contention cases: configured shared backends unavailable.
- `ConfigCacheIntegrationTest`: expected InterMix/Webrick constraints still use the previous floors.
- `ModuleStateResolverTest`: catalog WebAuthn floor `^5.3.5` differs from live `require-dev` `^5.3.9`.

These are the current baseline, not eight newly introduced code defects. Fix prerequisite provisioning and contract mismatches separately. Do not mark the full suite green on the strength of the passing subset. The raw local log was captured at `/tmp/foundation-plan-tests.log`; permanent release evidence must be saved separately.

The full PHPForge quality/release guard, real HTTP-server matrix, production soak and final-revision CI were **not** run during this planning review. No source-mutating formatter/refactor pipeline was run for this documentation task.

### 3.4 Infbyte source checked

The public [Infbyte Composer file](https://github.com/infocyph/Infbyte/blob/main/composer.json) currently requests Foundation `^2.1.1`. Its [front controller](https://github.com/infocyph/Infbyte/blob/main/public/index.php) uses `Request::fromGlobals()`, `Application::handle()` and `AutoEmitter`; its [bootstrap](https://github.com/infocyph/Infbyte/blob/main/bootstrap/app.php) calls `Foundation::web()`. Its [README](https://github.com/infocyph/Infbyte/blob/main/README.md) documents the older module surface.

These observations concern public `main` fetched on the review date. They do not establish the status of a migration branch or open PR. Inspect and reuse that work before implementing the handoff.

## 4. Priorities and completion rules

- **P0:** confirmed correctness/contract failures and release blockers. Close before 3.0.
- **P1:** required 3.0 validation and operating/documentation contracts. Close before advertising the affected capability as supported.
- **P2:** optional enrichments. Include only when driven by an actual application need and completed within the release budget; otherwise defer explicitly.
- **Confirmed** means observed in source or reproduced. **Verification gap** means the review did not establish the promised behavior. **Proposal** means a deliberate extension, not a defect.

Every work item must record its final commit, dependency identities, commands, environment, results and documentation changes. A suspected defect becomes a fix only after a minimal failing reproduction. An already-covered case should reference the existing test instead of adding a duplicate.

### 4.1 Implementation tracker

| Batch | Scope | Status | Current evidence / remaining gate |
| --- | --- | --- | --- |
| **A** | Dependency contract + public module/core contract | **PARTIAL** | All F30-01/F30-02 implementation items are complete. Workflow #1712 and #1714 prove PHP 8.4/8.5 stable+lowest disposable no-dev consumers, `vendor/bin/infbyte`, optional-package isolation and clean install. Final current-head all-green QA evidence is pending after formatter cleanup. |
| **B** | Session lifecycle correctness | **PARTIAL** | Primary-failure precedence, finite lock durations, stale-owner mutation protection and shared-lock regressions implemented. The current QA repair keeps the real exception reporter and release-failure semantics intact; maintenance/streaming contract closure and current green workflow evidence pending. |
| **C** | Runtime support + sustained evidence | **NOT STARTED** | Final 3.0 tested support statement and reproducible runtime fixture/evidence pending. |
| **D** | Release artifacts + rollback | **PARTIAL** | Read-only-source builds, failed-stage isolation, external trust, dependency identity and rollback documentation already exist. Runtime generation leases now enforce drain-safe pruning; current-head workflow evidence and remaining secret/rollback verification are pending. Infbyte handoff remains explicitly deferred. |
| **E** | Readiness, trust boundaries, architecture | **PARTIAL** | F30-10 readiness semantics and F30-12 architecture ownership are implemented and covered, including versioned `app:ready` output and explicit cold-capability agreement. F30-11 trust-boundary closure remains. |
| **F** | CI reproducibility + documentation | **PARTIAL** | PHP 8.4/8.5 stable+lowest, services, clean install and benchmarks are green in workflow #1711. Foundation now pins the reusable PHPForge workflow to `fdec64cf4460f13116eb0e2f3405acadd3e84377` and adds disposable production-consumer jobs; final current-head evidence and release evidence index remain pending. |

Tracker rule: mark a batch **DONE** only when its required acceptance criteria are backed by current-source tests, workflow evidence or an explicitly narrowed support statement. Historical green runs do not close a current batch.

## 5. Batch A — freeze dependencies and reconcile public contracts

**Priority:** P0. **Owner:** Foundation. **Depends on:** none.

### F30-01 — Align the 3.0 dependency contract

**Confirmed:** two current tests fail because the working Composer constraints, catalog and expected runtime dependency map differ.

Sources: `composer.json`, `src/Module/ModuleCatalog.php`, `tests/Feature/ConfigCacheIntegrationTest.php`, `tests/Feature/ModuleStateResolverTest.php`.

- [x] Confirm the intended 3.0 floors for InterMix 10.1.1, Webrick 5.4 and WebAuthn 5.3.9.
- [x] Review their relevant runtime/generated-artifact/API changes and test Foundation's affected integrations.
- [x] Align catalog constraints, documentation and test expectations with the selected contract. Preserve assertions protecting the minimal runtime package set.
- [x] Install/test stable and lowest permitted dependency combinations in disposable consumers, with and without optional packages.
- [x] Verify normal production `--no-dev` installation, autoload and `vendor/bin/infbyte` without PHPForge or development-only transitive dependencies.
- [x] Confirm public signatures that mention optional types remain safe when their capability is absent.

**Acceptance:** both constraint tests pass; every selected module's catalog floor is tested; minimal production consumers boot without optional-package resolution; selected dependency changes have current compatibility evidence.

### F30-02 — Resolve module/core terminology and executable examples

**Confirmed:** README still lists cache as a module; the catalog excludes cache. `docs/modules.md` says seven specialist namespaces, while the catalog also retains four built-in entries. Built-in session publication/schema commands still exist and must not be removed just to make a table simpler.

Sources: `README.md`, `docs/modules.md`, `docs/browser-sessions.md`, `resources/config/session.php`, `src/Module/ModuleCatalog.php`, `src/Module/ModuleManager.php`, `src/Module/ModuleSchemaManager.php`.

- [x] Document seven specialist namespaces separately from the four built-in catalog entries and core cache infrastructure.
- [x] Recommended 3.0 contract: preserve useful built-in commands; describe them as built-in capability configuration/schema operations, not installable specialist packages.
- [x] Remove stale cache-module instructions and replace them with the existing core cache command family.
- [x] Explain explicit capabilities versus development compatibility inference with minimal, auth/session and messaging examples.
- [x] Check every current user-facing `module:*` example against real CLI dispatch, including aliases, schema commands and `--json`.
- [x] If a built-in command is intentionally removed, provide its replacement and migration guidance first. Do not strand database-backed session provisioning.
- [x] Reconcile old plan closure wording with the final public contract without rewriting historical results as new evidence.

**Acceptance:** documentation and `module:list/show/plan/doctor` agree; cache remains outside module installation/removal; session config/schema examples work; disabled capabilities remain cold.

## 6. Batch B — fix session lifecycle edge cases

**Priority:** P0 for reproduced defects; P1 for remaining boundary verification. **Owner:** Foundation; CacheLayer retains lock mechanics. **Depends on:** A for final verification.

### F30-03 — Preserve the primary request failure through cleanup

**Confirmed reproduction:** a handler accesses a locked session and throws `primary application failed`; the lock provider throws `cleanup failed` during release. `SessionMiddleware` surfaces `cleanup failed` as the primary exception, with the handler failure chained beneath it. This differs from the explicit primary-failure policy in `ExecutionScope`.

Sources: `src/Session/Middleware/SessionMiddleware.php`, `src/Session/BrowserSession.php`, `src/Session/SessionExecutionState.php`, `src/Runtime/ExecutionScope.php`, `tests/Feature/BrowserSessionIntegrationTest.php`.

- [ ] Add the dual-failure regression and define precedence for handler, persistence, lock-release and context-leave errors.
- [ ] Preserve the original application/persistence failure when cleanup also fails; expose secondary failures through safe diagnostic reporting.
- [ ] Always attempt owned cleanup and clear context; make release behavior well-defined after a provider throws.
- [ ] Extend the review to other Foundation-owned cleanup boundaries, changing only demonstrated violations.

**Acceptance:** original handler/persistence exception remains primary; success followed by cleanup failure remains observable; no stale principal/session state survives; every owned cleanup action is attempted.

### F30-04 — Reject non-finite session timeouts

**Confirmed reproduction:** `SessionConfig::fromRepository()` accepts `INF` and `NAN` for both `session.lock.wait` and `session.lock.lease`. Numeric comparisons alone do not enforce usable finite bounds.

Source: `src/Session/SessionConfig.php`.

- [ ] Require finite numbers, retaining wait >= 0 and lease > 0.
- [ ] Cover zero, negative, fractional, non-numeric, `INF`, `-INF`, `NAN` and ordinary finite values.
- [ ] Check other Foundation-owned duration validators for the same pattern; scope any additional fix to a reproduced failure.

**Acceptance:** invalid settings fail before lock/backend work; valid fractional durations remain supported; error messages identify the configuration field.

### F30-05 — Verify session concurrency, rotation and bounded maintenance

**Verification gap:** existing tests cover contention and lost ownership at commit; they do not by themselves prove every deletion, rotation, pruning and streaming interleaving.

Sources: `src/Session/BrowserSession.php`, `src/Session/Store/FileSessionStore.php`, `tests/Feature/SessionLockContentionTest.php`, `tests/Feature/BrowserSessionIntegrationTest.php`.

- [ ] Exercise two independent clients against the same session on each supported shared lock backend.
- [ ] Verify expired lease before `regenerate()`/`invalidate()` cannot let a stale owner delete or overwrite newer state. Commit-time refresh alone is not evidence for earlier mutations.
- [ ] Test lost lease, process termination, write failure, read corruption and concurrent prune versus save. Specify the supported consistency contract when locking is disabled.
- [ ] Define session access during deferred/streamed responses: finalize before headers and reject late mutation, or use the existing Webrick lifetime contract deliberately.
- [ ] Measure pruning on large stores. `FileSessionStore::prune()` limits deletions but can still inspect the entire directory; add bounded scanning/checkpoints only if operational requirements demand them.
- [ ] Document single-host file sessions versus shared sessions, secure cookies, fixation prevention, idle expiry and lock lease sizing.

**Acceptance:** no stale-owner mutation in supported locking mode; consistent rotation/invalidation; no leaked locks after failures; bounded maintenance behavior documented and tested; no extra store I/O for untouched sessions.

## 7. Batch C — prove the runtime support contract

**Priority:** P1, required for advertised 3.0 runtime support. **Owners:** Foundation integration, Webrick/InterMix mechanics, Infbyte entry points; Runwire/Omnibus as applicable. **Depends on:** A and B.

### F30-06 — Build a real-server acceptance matrix

Foundation accepts a Webrick `RuntimeAdapterInterface` through
`FoundationReleaseBootstrap::web()`. For Runwire deployments, compose the existing
Webrick Runwire application/adapter bridge with the selected Runwire context.
Runwire already owns native portable/prefork and FPM, FrankenPHP, RoadRunner,
Swoole/OpenSwoole host adaptation. Foundation must propagate that support, not
implement another set of host adapters. Existing direct Webrick adapters remain
explicit alternatives for applications that do not select Runwire.

Source: [Runwire runtime modes and lifecycle](https://github.com/infocyph/Runwire#runtime-modes)
and [Webrick runtime bridge](https://github.com/infocyph/Webrick#persistent-runtimes),
checked 2026-09-24. These are upstream capabilities, not proof of Foundation's
complete native-server acceptance. The reviewed Foundation tests exercise SAPI,
a synthetic persistent adapter, Fibers and a 1,000-iteration CLI scope soak.

| Environment | 3.0 acceptance workload | Ownership boundary |
| --- | --- | --- |
| Embedded/ordinary PHP | Minimal bootstrap, application HTTP convenience path, CLI commands | Foundation application contract |
| PHP-FPM + OPcache | Generated release, cookies, file/stream bodies; direct SAPI and selected Runwire-hosted path | FPM/web server; Runwire host adapter when selected; Webrick HTTP semantics |
| Native Runwire portable/prefork | Reused compiled application, concurrent requests, disconnect and graceful drain | Runwire listener/process lifecycle; Webrick Runwire bridge |
| FrankenPHP via Runwire | Host-mode-aware application lifetime, request reset and shutdown | Runwire FrankenPHP host adapter; host-owned listeners/workers |
| RoadRunner via Runwire | Worker reuse, response writes and restart | Runwire RoadRunner host adapter; host-owned worker pool |
| Swoole/OpenSwoole via Runwire | Explicit driver, interleaved requests, scoped clients/principals, cancellation | Runwire host adapter; InterMix isolation and Webrick bridge |
| CLI/worker/scheduler | Stable scopes, failures, cleanup, leases and generation replacement | Foundation; Omnibus/Runwire for selected messaging pool mechanics |
| Direct Webrick host adapters / Workerman | Compatibility checks only if included in the 3.0 support statement | Existing Webrick adapters; no new Foundation or assumed Runwire Workerman driver |

- [ ] Compose Foundation's release through the Webrick Runwire bridge after Runwire selects its runtime context. Propagate persistence, concurrency, cancellation/deadlines and lifecycle policy instead of deriving them from `RuntimeMode` or extension presence.
- [ ] Use Runwire native `listen` only for Runwire-owned listeners and hosted `serveApplication` for host-owned execution. Explicitly select the Swoole driver; do not start competing listeners or pools under a host.
- [ ] Record supported PHP/server/extension versions and OS requirements; distinguish tested support from adapter availability.
- [ ] Build one small application fixture with plain JSON, auth, session/CSRF, DB transaction, upload/download, streaming and failure routes. Select features explicitly.
- [ ] Run it through the actual Foundation generated release and native transport in each required environment.
- [ ] Assert status, headers, multiple cookies, body, HEAD behavior and exactly one response emission.
- [ ] Test cancellation/disconnect, malformed and oversized input, concurrent users, rollback of unfinished transactions and next-request recovery.
- [ ] Test graceful reload with in-flight requests/jobs, restart and old-generation retirement.
- [ ] Keep HTTP Runwire host adaptation distinct from Omnibus Runwire worker-pool support. Reuse upstream protocol/backend tests; add only Foundation integration cases.
- [ ] Audit uses/documentation of `RuntimeMode::isPersistent()` so web-host persistence is not inferred incorrectly. No current internal callers were found; avoid an unnecessary API change if documentation suffices.

**Acceptance:** required rows have reproducible native-server results on the candidate; all scoped state is isolated; unsupported configurations fail early with a useful message. Unverified hosts must not be described as certified 3.0 support.

### F30-07 — Add representative sustained-runtime evidence

- [ ] Retain component attribution benchmarks, but add/complete end-to-end application workloads: minimal route, auth/session, DB-backed response and messaging ingress.
- [ ] Compare plain Webrick and equivalent Foundation routes under the same dependencies/environment to measure Foundation's added cost.
- [ ] Record cold boot, first use, warm RPM, p50/p95/p99, failures, response validation, RSS, connections, queue depth and cleanup behavior.
- [ ] Run several concurrency levels and repeated steady-state runs; choose a duration sufficient to expose leak/saturation behavior, with at least a 30-minute persistent-host soak for release qualification unless a justified alternative is recorded.
- [ ] Freeze acceptable regression and resource budgets before comparing candidates. Use stable comparable environments for enforcement; noisy CI results remain diagnostic.

**Acceptance:** correct responses, bounded resources and stable sustained throughput; benchmark evidence identifies PHP, server, extensions, dependencies, configuration and source commit. No application-RPM claim derived solely from microbenchmarks.

## 8. Batch D — release artifacts and Infbyte handoff

**Priority:** P1. **Owners:** Foundation release contracts; Infbyte application/bootstrap changes. **Depends on:** A–C for Foundation sign-off; Infbyte handoff deferred.

### F30-08 — Exercise deployment and rollback as operators use them

Existing atomic release publication, trust validation, source isolation and worker replacement are assets to preserve, not features to rebuild.

Sources: `src/Release`, `src/Routing/WebReleaseRuntime.php`, `src/Runtime/GeneratedRuntime.php`, `tests/Feature/FoundationReleaseEndToEndTest.php`, `tests/Feature/FoundationReleaseWorkerReplacementTest.php`, `tests/Feature/Phase10ReleaseSourceIsolationTest.php`.

- [ ] Verify read-only application images with separate writable storage and build/deploy permissions.
- [ ] Exercise concurrent builds, partial artifact writes, failed activation, missing/corrupt subordinate artifacts and recovery without changing the active valid generation.
- [ ] Verify dependency upgrades invalidate incompatible generated artifacts; a release records the exact dependency and configuration identity it was built against.
- [ ] Document how the trusted manifest digest reaches each FPM/worker/server process from deployment-controlled configuration.
- [ ] Exercise rollback while old workers still drain; prohibit pruning a generation still needed by a running process.
- [ ] Separate code rollback from schema/data rollback. Use expand/contract migrations where mixed generations may coexist.
- [ ] Verify secret/key rotation with old and new generations, without embedding plaintext secrets in generated files, logs or reports.

**Acceptance:** deployment rehearsal succeeds; failed builds leave the old generation active; incompatible artifacts fail closed; rollback limitations and recovery commands are documented.

### F30-09 — Deferred: Foundation 3.0 / Infbyte consumer handoff

**Status:** deferred by user instruction. Do not implement or change Infbyte in the current Foundation work. Preserve this handoff checklist for the later skeleton task; Foundation-only consumer checks belong to F30-01.

- [ ] Inspect the existing migration PR/branch and reuse completed work.
- [ ] Test a clean Infbyte consumer against the exact Foundation candidate in isolation; do not edit Foundation to depend on the skeleton.
- [ ] Adopt the release bootstrap/native runtime path for production instead of reusing the current development-style front controller unchanged.
- [ ] Keep a clear development bootstrap and an explicit production entry point; production must not silently fall back when trust/artifact inputs are absent.
- [ ] Update capability selection, providers, config templates, core cache instructions, module examples and writable-directory layout together.
- [ ] Test new installation, minimal application, auth/session application, a specialist module installation and upgrade of a representative 2.1 application.
- [ ] Rehearse `app:install`, `module:plan`, schema provisioning, `optimize`, `app:ready`, reload and rollback through the actual skeleton.
- [ ] Publish Infbyte's stable dependency constraint and docs only after Foundation 3.0 is available through normal Composer resolution.

**Acceptance:** both minimal and featureful Infbyte consumers pass against the candidate; stable create-project succeeds after publication; migration documentation covers configuration, generated artifacts and persisted-state changes. Foundation release closure and skeleton publication are tracked independently.

## 9. Batch E — module diagnostics, extension boundaries and security verification

**Priority:** P1 for accurate existing contracts; P2 for new extension APIs. **Owner:** Foundation with specialist fixes routed upstream. **Depends on:** A.

### F30-10 — Make readiness explain the actual application graph

Sources: `src/Diagnostics/ReadinessReport.php`, `src/Module/ModuleStateResolver.php`, `src/Module/Internal/ModulePackageStateResolver.php`, `src/Module/Internal/ModulePlatformResolver.php`.

**Observed limitation:** module constraint reasoning currently handles simple caret ranges, returning unknown for other expressions. `ReadinessReport` checks selected package presence separately from the richer module-state checks. End-to-end disagreement is a verification target, not yet a reproduced failure.

- [x] Compare `module:show`, `module:doctor`, `module:plan`, configuration validation, production compilation and `app:ready` for identical configurations.
- [x] Cover direct/transitive/missing packages, disabled capabilities, incompatible versions, missing extensions, unavailable schemas and selected feature dependencies.
- [x] Test exact pins, tilde/range/union constraints and aliases used by consumers. Keep unknown distinct from compatible.
- [x] Prefer Composer's established constraint semantics if broader analysis is necessary; any added runtime dependency must be explicit and justified. Do not build a general semver engine in Foundation.
- [x] Keep readiness output bounded and secret-free; separate configuration/build validity, liveness and dependency readiness with explicit I/O policy.
- [x] Preserve versioned machine-readable output and stable exit codes for automation.

**Acceptance:** no false-ready result for a required unsupported dependency/feature; inactive optional services are not contacted; diagnostics agree or clearly explain their differing scope.

### F30-11 — Close auth/session trust-boundary verification

Existing OAuth, MFA, passkey, rotation, revocation and authorization suites are substantial. Extend coverage at the Foundation boundary instead of recreating specialist protocol tests.

- [ ] Trace login, MFA completion, password reset/change, logout, role changes and revocation across persistent hosts and distributed stores.
- [ ] Verify browser-session rotation is explicit and correct when app authentication/privilege state changes; keep browser sessions distinct from auth refresh/device sessions.
- [ ] Exercise trusted proxy/host/origin configuration with CSRF, secure cookies, redirects, signed links and OAuth callback URLs.
- [ ] Verify public error responses and logs never expose credentials, cookies, tokens, MFA seeds or key locators.
- [ ] Test rate-limit/lockout/replay behavior across two processes and dependency outages, preserving the existing fail-closed policy where required.
- [ ] Review migration/rollback and retention behavior for existing auth data and active sessions.
- [ ] Route cryptographic/protocol issues to Epicrypt/OTP and atomic-store issues to CacheLayer/DBLayer, then add Foundation integration regressions.

**Acceptance:** realistic negative paths pass with no cross-user leakage, unintended authentication bypass, secret disclosure or silently weakened policy. A security-review checklist is evidence only when its cases are executed or tied to existing tests.

### F30-12 — Enforce the architecture that already exists

**Observed:** active Deptrac uses generic Project/Vendor layers. Targeted architecture tests prohibit several legacy paths but do not encode the entire ownership map.

- [x] Add a small project-specific architecture ruleset covering application composition, runtime boundaries, Foundation policy and specialist adapters.
- [x] Prevent runtime dependencies on Testing/tooling and prevent Foundation from depending on Infbyte.
- [x] Protect build-plane versus request/job execution boundaries and optional-capability isolation.
- [x] Capture the current dependency graph before enforcing changes; fix real violations without blanket exclusions or a speculative folder rewrite.
- [x] Document provider lifetime/reset requirements and the public extension seams for custom stores, notification channels, commands and application providers.

**Acceptance:** a deliberate boundary violation fails; current legitimate bridges pass; rules match actual public ownership and do not demand redundant wrapper layers.

## 10. Batch F — CI, reproducibility and documentation closure

**Priority:** P0 for baseline failures; P1 for release qualification. **Owner:** Foundation/PHPForge integration. **Depends on:** final implementation batches.

### F30-13 — Make the verification environment reproducible

- [ ] Record host PHP/extensions, installed dependency versions and test count before further implementation.
- [ ] Document/install the required host PDO extensions and provision the selected integration services with the existing PHPForge service catalog. Containers do not add extensions to host PHP.
- [ ] Supply the expected integration environment variables to the suite; do not hide unavailable shared-backend tests with skip directives.
- [ ] Re-run the unchanged baseline after prerequisites are available, then separate remaining product failures from environment failures.
- [ ] Preserve PHP 8.4/8.5 and stable/lowest CI, production clean installation and `fail_on_skipped_tests=true`.
- [ ] Add native-server jobs or a separately required release workflow for F30-06; document which matrix rows are covered upstream versus in Foundation.
- [x] Audit `phpforge@main` workflow and `dev-main@dev` tooling reproducibility. The reusable release workflow is pinned to PHPForge `fdec64cf4460f13116eb0e2f3405acadd3e84377`; the project development requirement deliberately remains `dev-main@dev`.
- [x] Retain every relevant release benchmark output. The pinned reusable workflow captures `benchmark:release` stdout into per-PHP benchmark artifacts while `build/cachelayer-33-utilization.json` remains the explicitly validated result file.
- [ ] Keep baseline comparison disabled on noisy runners. Configure a stable performance gate only after matching baseline/candidate environment metadata exists.

**Acceptance:** host results and prepared CI results are reported separately; all required jobs pass on the final candidate; artifacts make failures and performance claims reproducible.

### F30-14 — Publish a coherent 3.0 learning path

- [ ] Provide one entry page linking minimal usage, capabilities, provider composition, runtime hosting, auth/session recipes and production release flow.
- [ ] Add complete examples for standalone Foundation use and Infbyte use, with the same underlying contracts.
- [ ] Document native API ownership with short examples, avoiding Foundation forwarding facades.
- [ ] Consolidate cumulative 2.0 → 3.0 breaking changes in the migration guide: explicit modes/capabilities, builder/generated containers, module/core cache semantics, dependency floors and production bootstrap.
- [ ] Validate CLI snippets and important configuration examples against fresh consumers; keep historical plan examples clearly historical.
- [ ] State the tested support matrix and limitations without translating adapter availability into an unsupported compatibility promise.

**Acceptance:** a new consumer can install, enable one feature, test, build and deploy from current documentation; no conflicting module/runtime instructions remain.

## 11. Optional enrichment backlog

These are **P2 proposals**, not missing release requirements. Decide on actual Infbyte/application use cases before implementing. Each must stay disabled/cold when unused and use the specialist owner where one exists.

| ID | Enrichment | Existing seam / owner | Scope and acceptance |
| --- | --- | --- | --- |
| E01 | Application observability context | `ExecutionId`, PSR logging, Webrick/DBLayer/Omnibus hooks | Propagate correlation across HTTP/CLI/jobs; optional tracing exporter, bounded labels and no secrets; disabled-path overhead measured |
| E02 | Queued notifications | `NotificationDispatcher`, channels, Omnibus, TalkingBytes | Explicit opt-in delivery policy, per-channel outcome and idempotent retries; no new queue/transport engine; synchronous API remains predictable |
| E03 | Transactional outbox recipe | DBLayer transactions and Omnibus durable messaging | First verify native support; provide an application composition recipe for commit/send consistency and crash recovery, not a second persistence engine |
| E04 | Better application test ergonomics | `TestKit`, `HttpTestClient`, `FrozenClock` | Cookie-jar/session flow, generated-runtime parity helpers and deterministic expiry tests; ensure frozen time reaches the tested subsystem rather than assuming auth's clock controls direct `time()` calls |
| E05 | Explicit reusable application feature packages | Provider APIs and curated module catalog | Support a concrete consumer extension need via explicit registration/build-time metadata; deterministic conflicts and capability ownership; no request-time scanning or arbitrary package auto-execution |
| E06 | Tenant-aware application recipe | Execution scopes, DBLayer, CacheLayer, Pathwise, auth policy | Add only for a real tenant use case; scoped immutable tenant identity, explicit cache/storage/DB boundaries and isolation tests; no global mutable tenant resolver |
| E07 | Scheduler operating refinements | Existing scheduler, ProcessRunner and CacheLayer leases | Verify DST/time zones, missed-run policy, long-task heartbeats and ownership loss; add options only where existing semantics do not meet a demonstrated workload |
| E08 | Application idempotency recipe | CacheLayer/DBLayer atomic stores, Webrick, Omnibus | Scope keys by authenticated operation, define expiry/replay response and concurrent ownership; do not introduce a generic durable workflow engine |
| E09 | Optional session read-only/early-close mode | Existing BrowserSession and Webrick response lifecycle | Only after measurement demonstrates lock contention; define persistence/expiry/flash semantics and prohibit writes after closure |

Do not make 3.0 depend on implementing all proposals. Each accepted proposal needs a separate small design, compatibility decision, test cases and performance criteria.

## 12. Execution order and deliverables

| Milestone | Work | Required deliverable |
| --- | --- | --- |
| M0 — baseline | F30-01, F30-13 prerequisite setup | Frozen dependency/host manifest, classified baseline, final support targets |
| M1 — correctness | F30-03, F30-04, relevant failures from F30-05 | Narrow fixes with failing-before/passing-after regressions |
| M2 — public contract | F30-02, F30-10, F30-12, F30-14 | Consistent CLI/docs/readiness and enforced ownership rules |
| M3 — integration | F30-06, F30-08, F30-01 generic consumers | Native-server matrix, deployment rehearsal and Foundation consumer acceptance; Infbyte deferred |
| M4 — qualification | F30-07, F30-11, F30-13 final checks | Security/lifecycle/performance evidence on the candidate |
| M5 — publication | Final checklist below | Foundation 3.0 tag/package, then independently verified Infbyte publication |

Documentation and fixture work may proceed while runtime environments are prepared. Do not bundle unrelated enrichment or broad refactoring into blocker fixes. Effort should be estimated after M0: duration depends heavily on native-server/service provisioning and the final support matrix.

Recommended evidence location: `docs/evidence/foundation-3.0/` for a concise committed manifest/index, with large logs, traces and benchmark data stored as CI artifacts. Record source SHA, Composer package references, tooling/workflow identities, runtime versions, command, result, artifact location and reviewer for each gate. This directory is a future deliverable, not evidence generated by this plan.

## 13. Foundation 3.0 release acceptance checklist

### Required before tagging Foundation

- [ ] P0 fixes and the two dependency-contract failures are closed.
- [ ] Required runtime/support rows are implemented and verified, or the release's support statement is explicitly narrowed before publication.
- [ ] Plain minimal production installation and optional capability isolation pass without development packages.
- [ ] Config/catalog/CLI/docs agree, including built-in capabilities and core cache.
- [ ] Auth/session negative paths, cleanup precedence and persistent isolation pass.
- [ ] Schema migration and rolling-generation compatibility are verified for supported persistence backends.
- [ ] Release build, activation, trust validation, drain, rollback and artifact retention rehearsal pass.
- [ ] Native-server integration and representative performance/soak gates pass with interpretable evidence.
- [ ] PHPForge-required implementation processing/checks are run on code changes; detailed tests and the final release guard pass without weakening scope or thresholds.
- [ ] PHP 8.4/8.5 stable/lowest CI, analysis, audit and clean-install jobs validate the exact final implementation/dependency revision.
- [ ] Isolated Foundation consumers pass against that revision. Infbyte migration and consumer certification remain explicitly deferred to the separate skeleton task.
- [ ] Migration guide, release notes and support matrix identify breaking changes and known limitations.
- [ ] Every unfinished P2 item is explicitly deferred; no unfinished required gate is marked complete because historical plans were green.

### Deferred Infbyte work after Foundation package publication

- [ ] Install the actual Foundation 3.0 package in a fresh production consumer.
- [ ] Publish Infbyte's compatible stable dependency/config/bootstrap update.
- [ ] Verify normal `composer create-project`, module/config/schema operations, production build and a real HTTP request from that released skeleton.
- [ ] Link final package, source and CI identities in the release evidence record.

**Completion definition:** Foundation 3.0 is an independently usable, explicitly modular hub whose selected first-party services work together under its declared execution environments, with trustworthy deployment and migration behavior. Infbyte consumes that contract through a verified application skeleton.
