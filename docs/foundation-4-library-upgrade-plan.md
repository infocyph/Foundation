# Foundation 4: latest-library integration and full-library audit plan

Date: 2026-11-03 (Asia/Dhaka). Status: **implementation in progress; release gates open**.

## Implementation checkpoint — 2026-11-03

Work is underway on PR #20 / `feature/foundation-3`; no merge, tag, publication, or Infbyte source changes have been made. The pre-implementation audit and version matrix below remain historical baseline evidence, not assertions about the current working branch.

- **P0 partial:** hardened account-authoritative password-reset delivery, recursively sanitized authentication-audit metadata, made route presets fail closed, bound trusted config/worker artifacts to captured verified bytes, and supervised process stdin through the timeout/output pump. Added focused regression tests. Follow-up coverage is still needed for end-to-end notification delivery, adversarial artifact swapping, independent audit sinks, and process cancellation. A07 now has project-specific Deptrac classification and a negative app-dependency regression, verified in four lanes; broader graph review remains.
- **P1 partial:** raised `composer.json` Core/optional release floors, added optional-package exclusion bounds, synchronized both module catalogs, production-consumer installation manifests, and selected fixture expectations. Runwire 3 replaces Runwire 2 without dual-version support. Published dependency resolution and consumer checks are validated through hosted CI, but all other migration gates require final-source verification.
- **P2–P3 in progress:** preserved ordinary PHP and host-borrowed Runwire paths; OTP 7.0.1 challenge/verification/passkey operations now borrow the current context. Epicrypt file protection and Pathwise HTTP upload/chunk/finalization/download preparation pass operation-local context. Added cancellation and state-preservation regressions. Broader specialist/live-service qualification remains open.
- **P4–P5 partial:** synchronized version labels in the migration/release/OTP/authentication/filesystem/operations documentation and native API test descriptions; documented operation-local Runwire forwarding, ordinary PHP fallback and response-stream ownership. Exhaustive documentation and compatibility review remain open.
- **P6 open:** at `694aa480`, all four production consumers, both static-analysis lanes, both benchmark scripts, full Pest and Deptrac passed; four QA lanes failed only Pint formatting, corrected at `4fecb4a3`. Native-host run `41639644975` was green. Strict paired-host throughput, end-to-end dependency-upgrade comparison, retained-state rollback and an all-green exact-final-head run remain release blockers.
- **Additional security improvement:** removed untrusted notification payload from TalkingBytes email message metadata, preventing reset tokens and caller context from leaking into transport-level metadata while preserving rendered message content. The privileged PHPForge release workflow is pinned to an immutable revision.
- **Latest rolling implementation (verification pending):** added Foundation ReqShield upload/MIME/path and `FormRequest` cancellation regressions (`5e6e5ba7`); bound passwordless delivery to the actual identifier's stored account and MFA/security notifications to trusted account addresses, while keeping purpose-specific email-verification destinations (`da1aa9b7`, `2828e444`); corrected the host throughput comparator to reject trials shorter than 120 seconds (`2790750c`). These do not close the full real-backend or performance-upgrade gates.

The 90 implementation checkboxes below are acceptance criteria. Mark only entries with implementation and focused test/CI evidence; unmarked entries remain release requirements, even when partially implemented.

### Rolling batch tracker

| Batch | Implemented source | Latest verified evidence | Remaining |
| --- | --- | --- | --- |
| Contract / dependency reconciliation | `1003e003`–`2118c7b2` | Previously failing 14 assertions resolved; current hosted four-lane Pest pass | Final exact-head CI |
| Native OTP, Epicrypt, Pathwise operations | `556e5ea0`–`d8af04d1` | Borrowed context and cancellation regressions passed Pest on PHP 8.4/8.5, lowest/stable | Complete specialist/live-backend, soak, generation cases |
| Architecture boundary | `694aa480` | Project Deptrac check and negative Foundation-to-Infbyte dependency fixture passed four QA lanes | Broader coverage review and architectural audit |
| QA formatting and document parity | `4fecb4a3`, `79977e28` | Single remaining Pint blank-line failure corrected; old version labels and native-context docs synchronized | Verify newest commit and finish detailed documentation |
| Hosting and packaging | `694aa480` | Host smoke run `41639644975` passed; four production consumers and both static-analysis lanes passed run `41639645706` | Strict paired-host and retained-state certification |
| ReqShield file and cancellation boundary | `5e6e5ba7` | New regression fixtures; current four-lane QA was green for prior tested source | Exact-commit QA and MIME/host cancellation results |
| Auth notification recipient hardening | `da1aa9b7`–`2828e444` | Purpose-specific recipient identity and negative override regressions implemented; resolved PHPStan complexity finding | Exact-commit security/QA result and wider auth flow review |
| Sustained performance contract | `2790750c` | Comparator and fixture enforce 120-second trials rather than 60 | Full matched-host execution and separate dependency-upgrade evidence |



## Objective and agreed boundaries

Upgrade Foundation 4 to the latest published library releases and integrate their current APIs throughout the framework. Audit the complete library for vulnerabilities, correctness defects, integration gaps and worthwhile improvements, and track required remediation here. Foundation is to Infbyte what Illuminate is to Laravel: Foundation supplies reusable framework components, composition and public integration contracts; Infbyte supplies the application experience built on them. Foundation must be independently installable, testable and usable by other applications.

The requirements for this work are:

- Latest published library versions form the new minimum supported baseline. Remove compatibility paths for older library versions/APIs; do not offer dual Runwire 2/3 support or silently select an older dependency to pass a check.
- Every applicable integration works with or without an active Runwire host. Ordinary PHP execution and execution with a borrowed Runwire context are supported modes. Synchronous operation without Runwire context is intentional behavior, not a legacy-version fallback.
- Support shared hosting, VPS, dedicated/in-house servers, containers and other environments meeting the declared PHP/platform requirements. Ordinary web hosting must not require a Foundation-managed listener, process supervisor, PCNTL, POSIX, Swoole, QUIC or a persistent worker. Feature-specific prerequisites remain explicit.
- Runwire remains a required Core Composer dependency in this Foundation 4 design. Installing its package does not activate a runtime. Optional Runwire integration in the specialist packages must continue working when those packages are used independently without Runwire installed.
- A supplied stale, mismatched, cancelled or expired context must not be treated as an absent context and rerun synchronously. Unsupported explicitly selected drivers/features must fail clearly.
- Foundation owns application composition, capability policy, release generations and application execution coordination. Webrick owns HTTP adaptation/request scope, InterMix owns DI scopes, and the selected host/Runwire owns its listeners, process model, event loop and native runtime lifecycle. Specialist libraries retain their mechanisms.
- Keep optional specialists optional. Package installation does not activate a feature. Maintain the three module groups, eight selectable features and current `schema_version: 2` module contract.
- Work in this branch as Foundation 4 regardless of its branch name. Infbyte source changes and application publication are separate downstream work.

Follow the installed PHPForge [engineering principles](../vendor/infocyph/phpforge/resources/engineering-principles.md) and [agent workflow](../vendor/infocyph/phpforge/resources/AGENTS.md). Preserve correctness, security, lifecycle ownership and the existing performance budgets. Do not edit `vendor/`.

## Evidence collected for this plan

The inspected Foundation source is `cbaaa59ff9b60d0cf9cb13ba4a784fe859178409`; the worktree was clean before planning. Existing Foundation 4 architecture, generated-runtime implementation, module catalogs, integration tests, migration documentation and host workflows were inspected. The existing Graphify graph was used for navigation; source is authoritative.

Live Composer/Packagist queries on the date above established the version matrix below. `composer show --direct --latest --format=json` reports the local dependency set at the latest available direct releases except Runwire. `composer outdated --direct --format=json` reports Runwire `2.1.2 -> 3.0`. Installed versions do not establish that Foundation's declared lower bounds or integrations are correct.

The local `composer.lock` is ignored by Git. Its SHA-256 at inspection was `8fdf346ad7ab148e1c0a5af74b8cec15d5ab0cbd6a6482e779a5fad82ef59d7e`; the manifest SHA-256 was `5d01d8ac1f5d8442835cba3e860e7a44aebf883c859658dd7bf9ad7f860970da`. Preserve library lockfile policy; retain resolved locks as validation artifacts. Infbyte/application deployments own their committed application lockfiles.

The proposed ranges and optional-package conflicts were resolved in a temporary directory using a copy of the manifest/lock:

```sh
composer update --with-all-dependencies --dry-run --no-install \
    --no-scripts --no-plugins --no-interaction --prefer-stable
```

Result: zero installs/removals, one update (`infocyph/runwire 2.1.2 -> 3.0`), and no security vulnerability advisories reported. Composer reported the abandoned development transitive dependency `doctrine/annotations`. This is resolution evidence only; it does not certify API compatibility, runtime behavior or production consumers. Foundation's manifest, lock and installed dependencies were not changed.

`composer ic:doctor`, `composer ic:list-config` and `composer ic:active-config` completed; doctor reported a healthy setup. The subsequent baseline audit, test failures and production-consumer results are recorded below. The planned Runwire 3 dependency change has not been applied or certified.

## Target dependency matrix

"Latest" here means latest published stable release advertised by Composer at inspection, with the existing development-only PHPForge `dev-main` exception. Re-query immediately before implementation and update this matrix if publication changes. Local sibling working branches are reference material, not substitutes for published packages.

Caret ranges below deliberately admit future compatible releases; their lower bounds exclude every older release than this new baseline. An unresolved latest-version incompatibility is a blocker to fix, not permission to downgrade.

| Package | Current declaration | Locally resolved | Verified latest | Planned declaration |
| --- | --- | --- | --- | --- |
| **Core `require`** | | | | |
| `infocyph/arraykit` | `^5.3.1` | 5.3.2 | 5.3.2 | `^5.3.2` |
| `infocyph/cachelayer` | `^4.0.1` | 4.0.2 | 4.0.2 | `^4.0.2` |
| `infocyph/intermix` | `^11.2` | 11.2.1 | 11.2.1 | `^11.2.1` |
| `infocyph/runwire` | `^2.1.2` | 2.1.2 | 3.0 | `^3.0` |
| `infocyph/uid` | `^6.0` | 6.0.1 | 6.0.1 | `^6.0.1` |
| `infocyph/webrick` | `^6.0.3` | 6.0.4 | 6.0.4 | `^6.0.4` |
| `psr/log` | `^3.0.2` | 3.0.2 | 3.0.2 | retain `^3.0.2` |
| **Optional specialists tested through `require-dev`** | | | | |
| `infocyph/dblayer` | `^6.1.2` | 6.1.4 | 6.1.4 | `^6.1.4` |
| `infocyph/epicrypt` | `^3.2` | 3.3 | 3.3 | `^3.3` |
| `infocyph/omnibus` | `^3.0.2` | 3.0.3 | 3.0.3 | `^3.0.3` |
| `infocyph/otp` | `^7.0` | 7.0.1 | 7.0.1 | `^7.0.1` |
| `infocyph/pathwise` | `^4.2` | 4.2.1 | 4.2.1 | `^4.2.1` |
| `infocyph/reqshield` | `^3.3` | 3.3.1 | 3.3.1 | `^3.3.1` |
| `infocyph/talkingbytes` | `^2.3` | 2.3.1 | 2.3.1 | `^2.3.1` |
| `web-auth/webauthn-lib` | `^5.3.9` | 5.3.12 | 5.3.12 | `^5.3.12` |
| **Development tooling** | | | | |
| `opis/closure` | `^4.5` | 4.5.0 | 4.5.0 | retain `^4.5` |
| `infocyph/phpforge` | `dev-main@dev` | `7214b16` | Composer reports `7214b16` | refresh `dev-main@dev`; record exact resolved SHA |

Keep PHP `^8.4`, Composer runtime API `^2.0` and development `ext-sodium` unless actual platform evidence requires a change. Runwire requires 64-bit PHP. Extension availability controls supported selected features; it must not impose a managed-server requirement on ordinary PHP hosting.

Inspect transitive updates with a complete `composer update --with-all-dependencies`, audit and platform check. Preserve existing PHP/deprecation compatibility conflicts unless verified evidence permits their removal. Epicrypt 3.3 requires phpseclib 4.0.3 and PSR HTTP Factory 1.1; Pathwise requires the existing Flysystem Local/MIME compatibility floors. Update selected optional adapter/SDK dependencies to their latest compatible published releases when exercising those features; do not install every suggested Flysystem, cloud, broker or gRPC package by default.

## Required work packages

### P0. Full-library audit and confirmed remediation

The 2026-11-03 audit covered the repository's 668 source files and 229 test/fixture files through repository-wide PHPForge checks, dependency/advisory inspection and targeted source review. Manual review concentrated on authentication/authorization, OAuth, session/CSRF, executable release artifacts, HTTP middleware, filesystem policy, database/cache state, messaging/worker/scheduler ownership, process execution, module operations, configuration/secrets, logging, packaging and CI. Automated checks and sampled source review do not prove the absence of further vulnerabilities.

All findings below are **open**. No runtime fix has been applied. Severity describes the observed impact under the stated preconditions; these are local audit findings, not assigned CVEs.

| ID | Priority / category | Confirmed finding | Required owner / work |
| --- | --- | --- | --- |
| A01 | High / authentication security | Request context can override the destination of an account's password-reset notification. A locally captured token sent to the overridden destination successfully resets the victim account. | `AuthActions`, `PasswordResetManager`, `TalkingBytesAuthNotifier`; establish a trusted recipient boundary and reject untrusted delivery overrides. |
| A02 | High / artifact trust | Config and worker-topology loaders hash a path and later require it again. A deterministic changing-stream probe executes replacement PHP after the original bytes passed verification. | `FoundationReleaseConfig`, `WorkerTopology`, related trusted loaders; verify and evaluate the same captured bytes. |
| A03 | Medium / fail-open routing configuration | An unknown route preset returns an empty middleware stack. A typo in `web-auth` silently removes session, CSRF and authentication middleware from the group. | `RoutePresetRegistrar`; reject unknown presets and malformed configured stacks during registration/build. |
| A04 | Medium / sensitive data retention | A nested auth `context` bypasses top-level credential stripping and reaches audit metadata unchanged. A successful login retained a `password` sentinel in its audit event. | `AuthActions::context()`, `AuthEventRecorder`, audit adapters; use explicit safe context and recursive credential redaction at the audit boundary. |
| A05 | Medium / process availability and timing | Blocking stdin is written before the process timer, cancellation checks and stdout/stderr pump start. A 50 ms timeout returned success after about 640 ms and reported only about 15 ms. | `ProcessCapture`, `ProcessRunner`; include stdin preparation in bounded nonblocking supervision and elapsed-time accounting. |
| A06 | Medium / dependency-contract regression | 33 tests still fail outside socket restrictions with the currently installed sibling versions. Stale DBLayer exception expectations and CacheLayer admission timing account for the failures. | Native Runwire provider/operation and web runtime tests; align with current native cancellation/admission contracts while retaining no-side-effect and cleanup assertions. |
| A07 | Medium / verification coverage | Deptrac exits successfully with 0 violations but reports 2,708 uncovered dependencies and 0 allowed edges. Its generic resolved layer configuration does not classify the actual integration graph sufficiently. | Project architecture configuration and boundary tests; classify the intended first-party/native-package edges and prove prohibited edges fail. |

#### A01. Bind security notifications to an authoritative destination

Evidence: `src/Auth/Http/AuthActions.php:153` passes the nested context through `context()` at line 315; `PasswordResetManager::issue()` appends that context to its notification. `src/Auth/Adapter/TalkingBytes/TalkingBytesAuthNotifier.php:62` prefers payload `email`, `identifier`, `account_email` and `recipient_email` before the account's stored address.

Reproduction: call `AuthActions::requestPasswordReset()` with `identifier=victim@example.test` and `context.email=attacker@example.test`; pass the resulting notification through the actual TalkingBytes notifier with an in-memory fake transport. The notification belongs to the victim, the selected recipient is the attacker, and its token changes the victim password. No external email was sent. Exposure requires an application to pass attacker-controlled fields/context into these helpers and use this notifier; Foundation does not automatically publish a reset route.

- [x] Separate HTTP-supplied context from trusted delivery policy. Resolve password-reset recipients from the target account; do not accept delivery-field overrides from request metadata.
- [ ] Preserve explicit email-verification destination semantics through a validated, purpose-specific contract rather than a generic metadata override. Apply the same review to passwordless/MFA/security notifications.
- [ ] Add end-to-end regressions for nested and top-level override fields, account lookup by identifier/ID, missing/invalid recipients and each security-notification purpose. Assert both the intended recipient and the account/purpose of the issued token.
- [ ] Review public HTTP response mapping so issuance results containing tokens are never blindly exposed as an unauthenticated response. Keep low-level domain results useful without making request handlers disclose their secrets.

#### A02. Bind executable artifact verification to the evaluated bytes

Evidence: `src/Release/FoundationReleaseConfig.php:22` hashes then requires at line 27; `src/Worker/WorkerTopology.php:62` hashes then requires at line 67. `WorkerTopology::loadGeneration()` repeats the pattern for the Foundation manifest at lines 89–98. The existing `FoundationReleaseManifest::loadTrusted()` already demonstrates a captured-bytes/private-copy approach, but the other paths do not use equivalent protection.

Reproduction: a local deterministic stream fixture returns valid format-1 config/topology bytes on the hash read, then equivalent bytes with a harmless execution marker on the require read. Both loaders return successfully and execute the unverified marker (`opens=2`, `replacement_executed=true`). This models replacement between verification and use; filesystem exploitation requires concurrent write/replacement access to an artifact path. It is not evidence of an unauthenticated remote entrypoint.

- [x] Capture artifact bytes once, verify those bytes and evaluate only that authenticated private copy. Reuse a cohesive existing owner; preserve each payload's schema and dependency/generation validation.
- [ ] Apply the review to worker-generation manifest reloads, Webrick release manifests and JSON metadata verification/read pairs. Do not infer safety from a separate earlier hash check.
- [ ] Add deterministic replacement regressions for config, topology and generation manifest loading; retain tamper rejection, valid source-free loading and cleanup/error-ordering coverage.

#### A03. Reject missing security preset names

Evidence: `RoutePresetRegistrar::stack()` selects configured/built-in names and otherwise uses `[]`. A real application probe returned `['session','csrf','resolve-auth','auth']` for `web-auth` and `[]` for `web-aut`. `configuredGroups()` / `normalizeStack()` also silently discard malformed entries.

- [x] Throw an actionable configuration exception for unknown presets. Reject malformed names/stacks/middleware entries rather than filtering away intended protections.
- [ ] Preserve deliberately declared custom groups, including an explicitly empty custom stack where the public contract permits it.
- [ ] Prove a typo cannot publish an unprotected route in development or a compiled release. Verify existing valid presets/custom overrides still produce their exact intended stacks.

#### A04. Keep credentials out of audit metadata

Evidence: `AuthActions::context()` returns a nonempty nested context verbatim; `src/Auth/Support/AuthEventRecorder.php:41` records metadata verbatim, and `DBLayerAuditEventStore` JSON-encodes it without redaction. The existing JSON logger's redaction does not cover this independent audit store.

Reproduction: a login with a valid ordinary password and nested `context.password=audit-secret-sentinel` records that sentinel unchanged in the final audit event. The probe prints only a boolean. This confirms retention of supplied credential-shaped data, not extraction of an unrelated server secret.

- [x] Establish reserved security/context fields and redact credential/token/authorization/private-key values recursively before recording audit events, including calls that bypass `AuthActions`.
- [ ] Keep trusted actor/session/device/correlation identities distinct from request-provided display metadata. Preserve useful event type, outcome, account correlation and nonsecret token/request identifiers while redacting bearer credentials.
- [ ] Add in-memory and DBLayer audit regressions with nested arrays and mixed-case sensitive keys; assert that login/reset/MFA/passkey secrets do not persist.

#### A05. Supervise process stdin without blocking deadlines

Evidence: `src/Process/Internal/ProcessCapture.php:25` calls `preparePipes()` before initializing the clock at line 30. `preparePipes()` synchronously calls `writeInput()`, whose blocking `fwrite()` loop cannot poll cancellation, heartbeat, output or deadlines.

Reproduction: run a local PHP child that waits 600 ms before reading stdin; provide 1 MiB input and `timeoutSeconds=0.05`. Observed wall time was approximately 0.64 s, reported duration approximately 0.015 s, `timedOut=false`, reason `exited`. A child that writes enough output before reading input can also deadlock the opposing pipes; that case needs a bounded regression fixture.

- [x] Start elapsed/deadline accounting before pipe preparation; pump bounded stdin writes together with stdout/stderr reads and termination checks.
- [ ] Preserve output limits, EOF handling, child-tree cleanup, primary exception ordering, cancellation/heartbeat and signal restoration.
- [ ] Add bounded regressions for a non-reading child, output-before-input, partial writes, early child exit and cancellation during input. Verify resource cleanup on success and every termination path.

#### A06–A07. Repair contract tests and architecture evidence

- [ ] Replace DBLayer `ConnectionException` expectations only where published native behavior now propagates `Runwire\\Exception\\CancelledException`. Do not wrap cancellation merely to make stale tests pass. Recheck the same behavior with Runwire 3 after P1.
- [x] Move completed/cancelled-request assertions to the actual CacheLayer admission boundary; assert that user code and writes were never admitted, and bindings remain correctly restored.
- [x] Retain structured-child, Fiber isolation, primary-failure, no-second-dispatch and web/non-web operation assertions. The initial 33 failures include cascading assertions; do not count each one as an independent product bug.
- [x] Add meaningful Deptrac classification for the actual specialist/runtime graph while preserving the existing dedicated architecture tests. Demonstrate that a representative forbidden runtime-to-build or framework-to-application edge is rejected. Do not hide uncovered edges by excluding them or disabling the gate.

#### Additional improvements, separate from confirmed blockers

| ID | Observation | Proposed follow-up |
| --- | --- | --- |
| I01 | `PasswordlessManager::verify()` accepts the same issued token twice; both simple and Epicrypt adapters perform timed-token verification without a manager-owned consumption record. | Define the public redemption policy. If these are one-time login links, add atomic single-use redemption with explicit expiry/replay tests. Treat this as security hardening pending that policy, not a claimed violation of an already documented one-time contract. |
| I02 | PHPProbe reports 131 clone groups / 4,952 duplicated lines (6.64%); the configured 10% error budget passes. Some validation/key-resolution logic is repeated across native adapters. | Review actual semantic clones and centralize cohesive duplicate logic when touched, preserving distinct native owners/contracts. Do not infer that all token-similar groups should be merged or alter the detector budget. |

#### Audit validation record and remaining gates

| Check | Current baseline result |
| --- | --- |
| `composer audit --locked --format=json` | No advisory records; exit 1 because `doctrine/annotations` is abandoned. Development-only maintenance item; inspect PHPForge/PHPBench ownership rather than adding an unnecessary runtime replacement. |
| `composer ic:tests:details` | Exit 2. Pest: **807 passed, 54 failed, 5 warnings; 29,280 assertions**. This is not a green suite. |
| PHPForge source/quality checks | Syntax: 951 PHP files, zero errors. References: 939 files, 828 symbols, 17,371 references, zero errors. Duplicate/comment checks, Pint, PHPCS, PHPStan, Psalm and Rector checks passed. Deptrac returned zero violations with the coverage limitation in A07. |
| Focused rerun with local socket access | Six affected test files: **74 passed, 34 failed; 957 assertions**. 33 failures are the contract/assertion issues in A06. One Redis contention test reaches a password-protected local Redis and fails `NOAUTH`; credentials were not guessed. Hosting/socket tests that could not bind in the sandbox pass with normal loopback access. |
| Missing service prerequisites | Initial full-suite failures include absent MySQL/PostgreSQL DSNs, Valkey configuration and shared lock backends. Service inspection shows a healthy local Redis container; this does not establish availability of the other engines/topologies. Supply the configured test credentials/services and rerun; do not weaken the tests. |
| Composer archive | **714 files**, all **709 tracked runtime/resource/CLI files** present, zero untracked entries and zero excluded development/generated/environment payloads. SHA-256 `7115aa4e0d3c57896ef462c9027d11034dcf0925c81f1120a35e5ca9647cbae6`. |
| Packed core `--no-dev` consumer | PHP 8.5.4: install, optimized autoload, platform checks, CLI list and core feature fixture passed. 12 production packages; no development tooling or optional specialists. |
| Packed full-optional `--no-dev` consumer | PHP 8.5.4: install/platform checks, **12 selected-feature configurations**, trusted generated web/CLI/worker/scheduler smoke and host-input identity checks passed. 57 production packages; no development tooling. |
| Consumer dependency identity | Both consumer checks use the current Foundation manifest, including Runwire **2.1.2**. They do not certify the proposed Runwire 3 upgrade. |
| `composer benchmark:release` | Exit 0 for the nine configured specialist-utilization scripts: UID, CacheLayer, DBLayer, Epicrypt, Omnibus, OTP, Pathwise, ReqShield and TalkingBytes. Local diagnostic output is retained in `benchmarks.log`; it is not paired host-RPM certification. Update stale package-generation attribution strings during P5. |
| Performance / portability / CI | Local benchmark diagnostics are separate from sustained paired host qualification. Full PHP 8.4/8.5 latest/lowest, prepared-container/service matrices, supported native hosts, soaks and exact-final-source hosted gates remain required in P6. No new hosted run was dispatched during this audit. |

Temporary evidence is under `/tmp/foundation-full-audit/`: `auth-probe.php`, `trust-probe.php`, `process-probe.php`, their boolean/marker-only JSONL results, `qa-clean.log`, `host-recheck-clean.log`, inspected archive and isolated consumers. The detailed PHPForge raw log is `/tmp/foundation-full-audit-qa.log`. These temporary paths are working evidence, not shipped artifacts or permanent release records. The findings, input shapes, observed outcomes and acceptance requirements above are the durable record; convert each confirmed defect into a repository regression during implementation.

P0 acceptance: fix A01–A05 with adversarial regressions, reconcile A06, close A07's meaningful architecture coverage gap and rerun all affected gates. Resolve additional confirmed findings discovered while doing that work. Optional improvements remain separately identified.

### P1. Freeze the baseline and synchronize dependency policy

- [ ] Refresh live published metadata for all direct and selected transitive packages. Record exact source refs, platform requirements and advisories. Preserve the pre-upgrade source, dependency manifest and lock for comparisons.
- [x] Apply the matrix to `composer.json`. Raise the existing DBLayer/Omnibus conflicts to `<6.1.4` / `<3.0.3`. Add lower-bound conflicts for the other optional specialists and WebAuthn matching the matrix. `require-dev` alone cannot enforce a production consumer's optional-package floor.
- [x] Synchronize package ranges in `src/Module/ModuleCatalog.php` and `src/Module/ModuleArchitectureCatalog.php`, including both OTP-backed MFA and passkey features. Update module installation/planning/doctor expectations and package-version fixtures.
- [x] Synchronize the embedded database-only/full-optional manifests in `.github/workflows/security-standards.yml`, documentation install commands and production-consumer fixtures. Keep optional package ownership explicit in application manifests.
- [ ] Inspect dependency/API compatibility branches in touched integrations. Call the supported native API directly; remove old-version probes, aliases and alternate implementations whose only purpose is supporting excluded library versions. Retain explicit optional-feature availability diagnostics and security checks.
- [ ] Resolve the complete graph with latest dependencies and run strict Composer validation, platform checks and audit. Preserve the exact new lock as evidence without changing the repository's lockfile tracking policy.

Acceptance: every entry point advertises the same baseline; consumers cannot install an older optional specialist alongside Foundation; latest versions resolve together without aliases, path-repository substitutions or weakened platform requirements.

### P2. Integrate Runwire 3 across runtime and hosting boundaries

Owners: `src/Runtime/ExecutionScope.php`, `NativeRunwireOperation.php`, `src/Container/FoundationGraph.php`, `src/Routing/WebReleaseRuntime.php`, `src/Release/FoundationReleaseBootstrap.php`, `src/Command/CommandCatalog.php`, `src/Command/System/ApplicationSystemCommand.php` and the generated web/non-web graph owners.

- [ ] Compare the actual Runwire APIs Foundation consumes against published 3.0, including named constructor arguments, enums, callbacks and failure behavior. The inspected runtime/request/options/server entry points remain available; do not invent renames because the major changed.
- [ ] Requalify `Runtime::create()->listen()->run()`, application factories and host-owned `serveApplication()` through the current Webrick bridge. Keep `serve` an explicit command; normal SAPI/FPM dispatch must not start it.
- [ ] Preserve one exact host-owned `RuntimeContext` and the applicable `RequestContext`/`CoroutineScope` through web, CLI, jobs, schedules and intermediary calls. Scope/request objects are operation-specific; never cache them in singleton factories.
- [ ] Recheck CacheLayer 4.0.2 / InterMix 11.2.1 admission and binding restoration. They now reject completed/cancelled requests earlier and preserve borrowed binding ownership. Only release a binding acquired by the current owner.
- [ ] Cover Runwire 3 coroutine shutdown ordering: scope resources close while the scheduler still owns its loop. Foundation cleanup must run once, preserve the primary failure and leave borrowed host scopes/resources to their owner.
- [ ] Preserve native capability checks and synchronous paths when no coroutine capability/context exists. Explicit invalid/cancelled contexts fail before side effects; they must not trigger a second ordinary execution.
- [ ] Verify AUTO driver selection and explicit Swoole selection, portable/native serving, worker replacement, graceful drain and fork/generation checks. Keep the existing explicit `FIXED` protocol-policy default.
- [ ] Keep ordinary PHP hosting usable without optional process/host extensions. Required platform checks must distinguish package installation from driver/feature availability.

Acceptance: current native hosting and ordinary PHP execution both work; no Runwire 2 compatibility branch, second event loop/listener, fabricated host context or leaked borrowed scope exists.

### P3. Requalify each specialist through real Foundation operations

#### ArrayKit 5.3.2 and InterMix 11.2.1

Owners: `src/Config/`, `src/Container/`, generated runtime/compiler owners and provider composition.

- [ ] Exercise ArrayKit snapshot isolation, reference-safe dot-path changes, lazy numeric namespaces and config publication through Foundation's config loader/cache. Preserve immutable release configuration and trusted publication boundaries.
- [ ] Exercise InterMix definition-cache isolation by environment/value type, explicit scoped inputs, borrowed child scopes, cancelled-request admission, artifact validation and source-free generated invocation.
- [ ] Ensure generated production graphs use the same current native contracts as development graphs. Add no source-container or older-artifact compatibility path.

#### CacheLayer 4.0.2

Owners: `src/Cache/`, `src/Auth/Adapter/CacheLayer/`, session stores and webhook replay stores.

- [ ] Requalify shared-context forwarding/restoration and cancellation in ordinary and Runwire-bound operations, including nested/Fiber requests.
- [ ] Retain canonical named cache/lock identity and lazy backend initialization. Exercise selected local, Redis/Valkey, Memcached and database-backed configurations against their real prerequisites.
- [ ] Validate atomic auth counters, authentication-state cache, integrity protection, object-payload prohibition, session locks and webhook `setIfAbsent`. Never emulate a required atomic operation with read-then-write or silently replace a configured backend.
- [ ] Exercise the changed Redis Cluster/node adapter boundaries when those topologies are selected.

#### DBLayer 6.1.4

Owners: `src/Database/DBLayerFactory.php`, `src/Runtime/RuntimeExecutionState.php`, `src/Database/`, auth persistence and database-backed cache/messaging services.

- [ ] Preserve operation-time `setRunwireContextProvider()` resolution, including native iteration/retry paths. Test with and without active Runwire context.
- [ ] Verify failed-statement cleanup before rollback/retry. DBLayer 6.1.4 closes failed cursors even when exceptions retain statement objects; exercise contention/retry with retained exception traces, not just a successful query.
- [ ] Retain execution-owned connections/exclusive pool leases, cleanup rollback/release and separately owned infrastructure connections. Verify lazy query-cache composition does not recursively construct a cache/database graph.
- [ ] Re-run auth conditional transitions, retained-data restoration and durable messaging transactions against SQLite and production database engines selected by CI.

#### Omnibus 3.0.3

Owners: `src/Messaging/`, `src/Worker/`, `src/Scheduling/`.

- [ ] Preserve native operation-time `RunwireBinding` providers for bus, consumer and worker graphs. Check retries, cancellation/deadlines, nested handler dispatch, deferred work and borrowed InterMix scopes.
- [ ] Requalify durable queue/outbox/failure/schedule integration with DBLayer >=6.1.4, CacheLayer >=4.0.2 and UID >=6.0.1. Verify rollback, retry, lease release, acknowledgment and duplicate prevention.
- [ ] Keep application worker coordination and native process supervision at their existing explicit boundaries. An installed/borrowed Runwire context must not automatically select another worker pool.

#### OTP 7.0.1 and WebAuthn 5.3.12

Owners: `src/Auth/Adapter/Otp/`, `src/Auth/Internal/AuthMfaGraphFactory.php`, `AuthPasskeyGraphFactory.php` and `src/Auth/Otp/`.

**Confirmed integration gap:** Foundation's current AOTP/Grid/TOTP/OCRA/passkey calls omit the native optional Runwire context. An ambient CacheLayer binding alone does not supply OTP's explicit `runwire` argument.

- [ ] Resolve the current borrowed context at each stateful operation and pass it to the applicable OTP issue, verify, replay-cache and passkey begin/finish APIs. Preserve ordinary operation with a null context and native cooperative lock waits with a valid scope.
- [ ] Keep context out of long-lived OTP/Passkey instances. Recheck Foundation-owned HOTP/OCRA counter CAS and recovery-code store operations at the mutation boundary; use native context arguments where supported and Foundation checkpoints where Foundation owns the mutation.
- [x] Audit `OtpMfaVerifier::verify()` / `verifyEnrollment()` broad `Throwable` catches so native cancellation cannot become an invalid-configuration result. Preserve legitimate invalid-code result contracts.
- [ ] Preserve atomic single-use state, exhaustion/tombstones, generation-specific factor binding, replay rejection and committed-mutation semantics. A successful committed consumption must not be reported as uncommitted merely because cancellation arrives afterward.
- [ ] Re-run strict canonical Base32 acceptance/rejection, supported MFA factors, recovery-code races and full passkey ceremonies with the new WebAuthn baseline. Preserve RP/origin checks, credential revision CAS and opaque credential identity.
- [ ] Document exact selected extensions, including sodium for AOTP and XMLWriter only for SVG QR output. Passkeys must not acquire an unnecessary sodium prerequisite.

#### Epicrypt 3.3

Owners: `src/Security/EnvironmentFileProtector.php`, `src/Auth/Adapter/Epicrypt/`, MFA protection and OAuth/OIDC graph composition.

- [x] Integrate operation-time native Epicrypt execution contexts into Foundation-invoked file/stream operations that support them. The environment file protector currently invokes `FileProtector` without its optional context; pass the current host context when present.
- [ ] Requalify ES256/ES384/ES512 keys against the new exact EC-curve checks. Fix mismatched application configuration/fixtures rather than relaxing the library's security checks.
- [ ] Verify remote JOSE resolver cancellation remains cancellation wherever a selected/custom resolver is used. Do not turn it into stale-key success or an ordinary resolution retry. Do not introduce remote discovery into the existing local-key configuration just to exercise a new API.
- [ ] Preserve existing token formats, key purposes/AAD, password policy, OAuth/OIDC state and protected MFA secrets. Existing key rotation is a current security contract; older-library compatibility code is removed independently of key lifecycle policy.

#### Pathwise 4.2.1

Owners: `src/Filesystem/FilesystemTransferFactory.php`, `FilesystemUploadRequestHandler.php`, `FilesystemResponseFactory.php`, storage services and relevant commands.

**Confirmed integration gap:** Foundation's transfer construction/operation paths do not currently apply Pathwise's callback-scoped `withRunwire(RunwireExecutionContext, operation)` binding.

- [x] Bind the native Pathwise context around operations Foundation actually invokes: upload/chunk/finalization and applicable download preparation/file workflows. Restore the binding on completion/failure; use ordinary operations when no context exists.
- [ ] Keep binding lifetime around the actual operation, not merely processor construction. A processor returned to application code remains a native Pathwise object; document its native explicit binding contract rather than retaining a request inside a shared service.
- [ ] Keep actual response streaming and host cancellation with Webrick's response owner. A prepared response must not retain a completed Pathwise/Runwire scope.
- [ ] Verify path containment, untrusted upload profiles, malware policy, atomic publication, chunk ownership, temporary-file cleanup and cancellation through the Foundation HTTP bridge. No replacement filesystem abstraction or Runwire async filesystem is required.

#### ReqShield 3.3.1

Owners: `src/Validation/`, `FormRequest`, DBLayer validation-provider composition and input/JSON error boundaries.

- [ ] Preserve `FormRequest`'s existing explicit `validateWithRunwire()` path and ordinary `validate()` path. Verify request cancellation reaches native validation and DB-backed rules without changing SQL-native comparison semantics.
- [ ] Test upload/MIME validation through Foundation with stream-wrapper paths, empty paths and NUL-containing values. ReqShield 3.3.1 rejects these before file access; Foundation must not normalize them into an executable wrapper path or bypass the native rule.
- [ ] Requalify rules, sanitizers, DTO casts, validation profiles and database existence/uniqueness composition using current APIs.

#### TalkingBytes 2.3.1

Owners: `src/Communication/CommunicationProfiles.php`, communication graph/provider, `src/Notifications/EmailProfiles.php`, mail/auth notification adapters and gRPC workers.

- [ ] Preserve existing per-operation `withRunwire(runtime, request, scope)` forwarding for HTTP, gRPC and sender/mailbox/receiver factories. Verify later profile/configuration changes retain host cancellation and the earliest deadline.
- [ ] Exercise retries, HTTP pools, multipart upload lifetime, redirects, webhook replay, SMTP/Mailpit, mailbox/spool behavior and native gRPC when selected. Check error mapping through Foundation and resource cleanup on cancellation.
- [ ] Verify unbound operation in ordinary PHP, and bound operation through both direct Foundation calls and intermediary libraries. No second transport attempt may occur after terminal cancellation/deadline rejection.
- [ ] Retain TalkingBytes as the communication mechanism owner. Runwire 3 outbound clients do not automatically replace configured TalkingBytes transports.

#### UID 6.0.1

Owners: `src/Runtime/ExecutionId.php`, `src/Auth/Adapter/Uid/UidAuthIdGenerator.php`, ID config and native callers.

- [ ] Preserve existing UUID7/ULID defaults and ID formats. Requalify generation in ordinary, concurrent, persistent and Runwire-bound executions.
- [ ] Use native `GenerationContext` / `RunwireBinding` where coordinated/waiting native generators are actually exposed. Current `Id::uuid7()` / `Id::ulid()` calls do not accept those contexts; do not invent parameters or retain context globally.
- [ ] UID 6.0.1 already publishes UUID v2. Keep native access available without automatically changing auth/execution defaults or adding forwarding facades for every UID feature.

#### Webrick 6.0.4

Owners: `src/Routing/`, `src/Http/`, `src/Session/Middleware/`, trusted web release generation and serving commands.

- [ ] Requalify native routing, response emission, session finalization, exception mapping, signed URLs and compiled artifacts against the current dependency combination.
- [ ] Exercise the Webrick/InterMix/Runwire bridge with exact context identity, cancellation, nested scopes and host shutdown. Keep web scope ownership in Webrick and non-web graphs free of web-only service registration/loading.
- [ ] Verify ordinary SAPI/FPM, native Runwire and selected persistent host adapters against the same public Foundation components. Host-specific support claims require actual host evidence.

### P4. Remove obsolete compatibility and rebuild generated releases

- [ ] Inventory old-library compatibility code, test descriptions, fixtures and docs. For example, `CurrentInfocyphApiCompatibilityTest.php` still describes DBLayer 5 / Omnibus 2.6, while current dependencies are DBLayer 6 / Omnibus 3. Update misleading labels and assertions to the supported baseline; preserve meaningful behavioral coverage.
- [ ] Distinguish current public options and security policies from old-version shims before removal. No broad deletion based only on a name containing `legacy` or `fallback`.
- [ ] Keep required-package and selected-feature failures explicit. Missing optional packages should produce actionable feature diagnostics, not select an older implementation or silently disable an explicitly selected feature.
- [ ] Rebuild all web/CLI/worker/scheduler artifacts after dependency changes. `FoundationReleaseManifest::dependencyFingerprint()` and release-runtime validation already reject Composer dependency drift; preserve that rejection and test it with the actual upgrade.
- [ ] Re-run source-free loading, provider ordering, generation leases, atomic activation, fork safety and worker replacement. Older generated candidates must be rebuilt, not accepted through a compatibility loader.
- [ ] Rehearse retained database/cache/auth/session/queue state. Use an explicit migration for any demonstrated persisted-format change; do not invent schema changes for a dependency bump. Rollback restores a coherent source/dependency/artifact set and compatible data.

### P5. Update documentation and downstream adoption instructions

- [ ] Update dependency versions and current integration behavior in `docs/foundation-4-migration.md`, `docs/foundation-4.0-release-notes.md`, `docs/foundation-4-api-map.md`, `docs/modules.md`, `docs/runtime-hosting.md`, specialist guides, examples, benchmark attribution labels and README as applicable.
- [ ] Explain Foundation/Infbyte's component/application relationship and Foundation's independent consumer contract. Keep Infbyte migration guidance separate from implementing or publishing Infbyte changes.
- [ ] Document both ordinary-PHP and Runwire-bound execution for selected specialists, including how application-owned native objects receive context. State exact supported PHP/64-bit and selected-feature prerequisites.
- [ ] Provide an Infbyte adoption checklist: update its application dependencies, remove excluded-version API usage, install selected optional features directly, rebuild trusted releases/autoloaders, validate its actual hosting mode and rehearse retained-state upgrade/rollback.
- [ ] Replace old-version fallback recommendations with current API/configuration examples. Preserve explicit ordinary PHP/FPM setup and current security/key-rotation guidance.

### P6. Validation and release evidence

Run focused behavior tests while implementing, then the full PHPForge processing/detailed QA/release-guard flow. Fix causes; do not suppress findings, lower thresholds or remove relevant tests. Refresh and review the pinned PHPForge reusable QA workflow against the selected tooling revision; its current pin is `fdec64cf4460f13116eb0e2f3405acadd3e84377`, independently of the installed plugin revision.

| Gate | Required evidence |
| --- | --- |
| Dependency policy | Strict manifest validation, stable runtime constraints, complete updated graph, audit and platform checks; negative resolution for older optional versions |
| PHP/platform | PHP 8.4/8.5 latest and lowest **within the new supported ranges**, including deprecations and optional-feature prerequisites |
| Core consumer | Packed `--no-dev` installation; no optional specialists/PHPForge; normal web/CLI/worker/scheduler operation without an active Runwire runtime |
| Selected consumers | Database-only, each independently selectable feature and full optional production consumers; correct direct ownership and no accidental feature activation |
| Runtime composition | Direct and intermediary calls, absent context, runtime-only context, request/scope context, nested/borrowed scopes, Fibers, cancellation/deadlines, stale/forked/generation-mismatched contexts and cleanup failure ordering |
| Real operations | OTP/passkeys, file transfers, validation, HTTP/mail/gRPC, database retry/transactions, cache/auth state, durable messages and schedules through Foundation services |
| Hosting | Ordinary SAPI/FPM with restricted process capabilities; actual native Runwire; PHP 8.4/8.5 Swoole/OpenSwoole lanes; selected FrankenPHP/RoadRunner adapters where provisioned |
| Packaging | Inspect actual Composer archive against tracked runtime files; retain CLI/resources; exclude vendor, tests, docs, benchmarks, generated graphs/builds and local environment data |
| Generated release | Rebuilt web/CLI/worker/scheduler artifacts, source-free loading, dependency drift rejection, trusted manifests, activation/reload and no cross-execution state leakage |
| Performance/soak | Complete matched evidence, valid responses, CPU/RSS and tail latency, cancellation/recovery/recycle and bounded resource growth under the unchanged budgets |
| Hosted final revision | Exact final source SHA, resolved dependency manifests/lock digests, PHP/extensions/INI, workflow attempts, artifact hashes and results for all required gates |

Existing suites to extend include `RunwireSelectedNativeBoundaryTest`, `Foundation4NativeRunwireOperationTest`, `RunwireCoroutineScopeIntegrationTest`, `RunwireNonWebScopeTest`, `ExecutionScopeIsolationTest`, `WebrickInterMixIntegrationTest`, `WebReleaseRuntimeTest`, `ServeCommandIntegrationTest`, `CurrentInfocyphApiCompatibilityTest`, module/catalog parity tests and specialist integration suites. API/reflection smoke alone is insufficient: prove that normal Foundation operations receive cancellation/context at their native side-effect boundary.

#### Performance comparison must account for the dependency migration

The current `.github/workflows/foundation4-host-certification.yml` copies one candidate lock into both revisions. `benchmarks/Support/Foundation4HostComparison.php` requires matching `composer_lock_sha256`. The current baseline requires Runwire 2; the candidate will require Runwire 3. Copying the new lock into the old manifest is not a valid end-to-end upgrade comparison.

- [ ] Retain the existing shared-lock comparison for source/integration attribution using a valid Foundation 4 baseline that accepts the new dependency baseline. Validate both manifests and runtime behavior before measuring it.
- [ ] Add an explicit dependency-upgrade comparison contract for the pre-upgrade stack versus the final stack. Record both immutable locks, package/source-reference manifests and a reviewed dependency-delta allowlist. Reject unexplained package changes and mismatches in host/tooling/workload/data/INI identity. Keep the ordinary comparator's same-lock rejection intact.
- [ ] Update the workflow, report/comparison owners, comparator tests and certification guide together. Preserve distinct source-only and whole-upgrade verdicts; neither can substitute for the other or relabel the old dependency set.
- [ ] Preserve the current full FPM/Runwire, one/four worker, concurrency 1/10/50 matrix; primary/confirmation batches; five >=120-second trials after warm-up; required soak/recovery windows; <=2% median successful-RPM regression; and <=2.5% CV. Keep failed/inconclusive evidence and rerun unchanged revisions when investigating noise.

Native shared-runner smoke is functional evidence, not complete throughput certification. Missing hosts/services are reported as unavailable gates rather than silently replaced with a different backend.

## Implementation order and completion criteria

1. Freeze the audited source/dependency evidence, add regressions and address the P0 security/correctness blockers. Reconcile current dependency-contract expectations without restoring older-library behavior.
2. Apply P1 across manifest, catalogs and consumers; establish the Runwire 3 runtime/hosting contract in P2 and prove ordinary PHP still works.
3. Complete the P3 real-operation integrations, prioritizing OTP context/cancellation, Pathwise operation binding, Epicrypt file context and DBLayer retry lifetime. Repeat P0 regressions on the new dependency set.
4. Remove excluded-version compatibility, rebuild/rehearse generated releases and update documentation through P4/P5.
5. Complete P6 production consumers, hosting, full QA, upgrade/source performance comparisons and exact-final-revision evidence. Review optional improvements separately.

The work is complete when P0's confirmed blockers are resolved, every supported component uses the latest published baseline, no older-library compatibility path remains, both ordinary and applicable Runwire-bound operation pass, packages/artifacts are verified, and all required release gates pass on the exact final revision/dependency set. Merge, tagging, publication and Infbyte deployment remain maintainer actions. This plan does not declare Foundation 4 release-ready.

## Source references and release identities

Published version lookup uses [Composer's package metadata](https://repo.packagist.org/packages.json) and per-package `https://repo.packagist.org/p2/<vendor>/<package>.json` endpoints. Runwire migration sources are the published [3.0 migration guide](https://github.com/infocyph/Runwire/blob/054737bc6a8d4025a8d05fabb8ad390f66ee47ae/docs/migration-3.0.rst) and [compatibility audit](https://github.com/infocyph/Runwire/blob/054737bc6a8d4025a8d05fabb8ad390f66ee47ae/docs/compatibility-audit-3.0.rst). Epicrypt changes are documented in its [3.3 release notes](https://github.com/infocyph/Epicrypt/blob/65cf0e619d22ece5a2e52cfaa3988120cf170ff6/docs/release-notes-3.3.0.rst).

The following source refs identify the inspected package snapshots. Except for Runwire 3, they are already present in the local lock. The stable Infocyph package refs also match the corresponding local sibling release tags; Runwire 3's ref was queried directly from published metadata and matches its local tag. PHPForge is a development revision. Reconfirm all refs against refreshed published metadata at implementation time.

| Package release | Source reference |
| --- | --- |
| ArrayKit 5.3.2 | `f338c70b2ac86b9fa7244fdfb21e5a4b3e38df79` |
| CacheLayer 4.0.2 | `a5c314b6fb9b1d20fdf5cfde044b6f5c235c2e22` |
| InterMix 11.2.1 | `4ddb59961a0076f182378412fbc4ff5031e1f5af` |
| Runwire 3.0 | `054737bc6a8d4025a8d05fabb8ad390f66ee47ae` |
| UID 6.0.1 | `01bedaea0347ca815d0c47c97f4f8915efc1f2d4` |
| Webrick 6.0.4 | `981d095251dc3bf9c65c434f0d84aecb38025f88` |
| DBLayer 6.1.4 | `c530bf861d2921bc7c59b4ea9ffe4f9dc0620877` |
| Epicrypt 3.3 | `65cf0e619d22ece5a2e52cfaa3988120cf170ff6` |
| Omnibus 3.0.3 | `ae60cb2701d97103ba8452c9f51e11b7aebe515a` |
| OTP 7.0.1 | `f40d42e283528cdc25a0c3473e9e2650372e7cfb` |
| Pathwise 4.2.1 | `5e1c18f2f95307d0456e749c374c1dd045a0ecd9` |
| ReqShield 3.3.1 | `733b54e98d76b43b9b651dae0b859e5bf8152bb8` |
| TalkingBytes 2.3.1 | `be6ca68deb31f730054122a421fe0a400a99a227` |
| WebAuthn 5.3.12 | `c7aeeb1d35e39b7d32ab5146cce4b668f271a68e` |
| PSR Log 3.0.2 | `f16e1d5863e37f8d8c2a01719f5b34baa2b714d3` |
| Opis Closure 4.5.0 | `b97e42b95bb72d87507f5e2d137ceb239aea8d6b` |
| PHPForge dev-main (reported) | `7214b16487277181c177343d4e4163a9b5843f24` |
