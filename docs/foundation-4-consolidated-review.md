# Foundation 4 consolidated review

Date: 2026-11-07 (Asia/Dhaka). Current uncommitted repairs are based on `bc05ac4ce535d898acb5a77a85556bfa18977511` (Batch 134). Original review source: `57d309a6dfc3e02078331a1118d563ed46f7c1d9` (Batch 119).

This combines the upgrade audit, independent reviews and implementation checks. The implementation record below owns current working-tree status; earlier rechecks and qualification statements are historical evidence. The [upgrade plan](foundation-4-library-upgrade-plan.md) owns formal acceptance.

## Current maintenance and tracker synchronization

The Foundation source findings R01–R07 are repaired locally. The additional passwordless hardening M01/I01 is now implemented, and the duplication review M04/I02 is complete. The canonical plan records **93/95 implementation items complete, P0 25/25**; only its two P6 certifications are unchecked. This count records completed implementation, not final hosted certification. Release verification is intentionally excluded from the current maintenance task, including provisioning and qualifying the missing service matrix, final archive/consumer qualification and sustained hosts.

- **Passwordless:** both simple-purpose and Epicrypt signed-payload drivers use expiring hashed issuance markers and native atomic TTL consumption. Issuance persists before notification, cryptographic rejection cannot consume valid state, missing/consumed state cannot authorize a login, and storage failures propagate. Public manager/HTTP action composition and four independent SQLite-backed processes prove one winning redemption. Native-clock expiry, token isolation and unregistered-token rejection are repository regressions. Shared state and constructor changes, reissuing old links and cache-loss behavior are documented.
- **Duplication:** current configured PHPForge gate passes without budget changes. Source-only review snapshot before final canonical-key reuse: 115 groups / 3,600 duplicated lines / 4.9% across 669 files. Review of the key-ring/file-reader and validator groups found distinct algorithm defaults, key purposes, diagnostics, audit and domain-validation owners; the existing abstract timed-token services already share representation-specific mechanics. M04 closes as an explicit bounded-duplication decision, not as a blanket removal or an unresolved defect.
- **Dependencies:** live Composer dry-run reports nothing to modify; no advisory records. M02/M03 remain upstream-blocked development-only maintenance, with exact constraints and owner links in the [dependency disposition](foundation-4-dependency-qualification.md#current-maintenance-disposition). They are not silently marked fixed, latest or waived.
- **Git and verification:** this working tree includes the previous R01/R04 repairs and new passwordless changes; no commit, push, merge, tag or publication. Previous archive/consumer and hosted evidence below predates the passwordless change and does not certify this final tree. Local maintenance validation is recorded below; it does not substitute for release verification.

### Local maintenance validation

| Check | Final result |
| --- | --- |
| Focused authentication, architecture, PIN audit, OIDC route and generated-secret regressions | **44 passed / 5,575 assertions**, including eight passwordless cases for the two token drivers. Each four-process redemption race has one winner. |
| Required PHPForge process flow | Exit 0; no rule, budget, skip or suppression changes. |
| Final `composer ic:tests` | Exit 2: **990 passed, 25 failed / 36,937 assertions**. The 25 failed test identities exactly match the previous unavailable MySQL/PostgreSQL, Redis/Valkey/Memcached and shared-lock prerequisites. No new functional failure remains. |
| Static, architecture and quality gates | Skip scanner, Composer normalization, syntax, references, duplicate/comment policy, Pint, PHPCS, Deptrac, PHPStan, Psalm and Rector all pass. Four newly propagated exception annotations were corrected before this final run. |
| Composer and documentation | `composer validate --strict` passes; live dry-run changes no packages; no advisory records; abandoned dependency remains reported. Local documentation targets/anchors and `git diff --check` pass. |
| Release verification | Excluded by the maintainer: no new hosted CI, final ZIP/consumer qualification, missing-service provisioning or P6 certification. Older results remain historical. |

Evidence: `/tmp/foundation-maintenance/` plus `/tmp/foundation-maintenance-outdated.json` and `/tmp/foundation-maintenance-audit.json`. Earlier review sections preserve historical reproductions and counts; use this section and the canonical plan for current dispositions.

## Residual fixes and local verification

At the earlier R01/R04 repair checkpoint, both residual defects were repaired in the working tree based on `bc05ac4c`; the failing inputs became repository regressions. The checks in this section predate the passwordless maintenance change above. No vendor, dependency constraint or lockfile-policy changes were needed.

- **R01:** Folded PIN-family matching no longer uses a suffix exception list. It follows the same conservative credential-family policy as OTP and the longer secret terms, while retaining the explicit safe identifier exceptions. The shared in-memory/SQLite fixture now covers **297 sensitive keys**, including five PIN prefixes, six suffixes and lower/upper/kebab/CamelCase/PascalCase/arbitrary-case variants. Independent `PIN_INPUT`/`PIN_DATA`/`PIN_ENTRY`/`ACCOUNT_PIN_CONFIRMATION`/mixed-case probes retain no sentinels; the SQLite probe reports `persisted_sentinel=false`. The original independent 388-key matrix also passes.
- **R04:** Foundation's authentication-evidence policy refuses reusable session timestamps for `max_age=0`, even within the current clock second. Epicrypt receives unauthenticated interaction evidence and retains ownership of the native reauthentication/error decision. The direct issuance guard rejects the same zero-age reuse. GET, valid-CSRF POST, silent/interactive consent, existing consent, same-second timestamps, no code-state writes and unchanged prior consent are covered; ordinary positive-age consent still succeeds. The built-in controller and direct issuance API do not accept interaction-specific reauthentication proof, so they fail closed for zero-age requests instead of fabricating it. Host applications own their interactive authentication flow. Standalone Epicrypt's elapsed-age comparison is unchanged; this repairs the Foundation boundary with its current supported API, not an old-version fallback or a patched vendor copy.

The expanded tests failed before the runtime changes (three failing test cases) and pass afterward: **22 tests / 5,353 assertions** across the two audit feature files and the real Webrick OIDC consent-route file. The independent OIDC probe now returns `login_required`, no GET/POST authorization codes, and blocked direct issuance for the same-second/prior-consent case.

| Check at the earlier R01/R04 checkpoint | Result |
| --- | --- |
| PHPForge process flow | Pass with the loopback access required by Rector. Initial sandbox-only loopback refusal was retried; no tool configuration was relaxed. |
| `composer ic:tests:details` and final `composer ic:tests` | Each exits 2 solely for the same **25 unavailable service prerequisites**; **982 tests pass, 36,863 assertions**. Required MySQL/PostgreSQL, Redis/Valkey/Memcached and shared-lock settings/backends are unavailable here. |
| Quality gates | Skip scanner, Composer normalization, syntax, references, duplicate/comment policy, Pint, PHPCS, Deptrac, PHPStan, Psalm and Rector all pass. `composer validate --strict` passes. |
| Actual ZIP | 716 files; all 710 tracked runtime/resource/CLI files present and byte-identical to that repair checkpoint; zero untracked or excluded development/generated/environment payloads. SHA-256 `aa6f76ec0898580c563aabd3c6af6b3b0d5c586ac658e90ea9243f0ad8c694b0`. |
| ZIP-installed production consumers | Core: 12 packages; full optional: 57 packages; zero development packages/tooling. Platform checks, CLI lists, core/12 selected-feature matrices and the full compiled web/CLI/worker/scheduler example pass. The packed PIN probe also retains no credential values. |

**Historical qualification at this checkpoint:** source changes were uncommitted and had no new exact-revision hosted CI result. Formal acceptance was held at **90/95** pending hosted qualification. The synchronized tracker above now distinguishes completed implementation from still-open release verification; neither P6 certification is accepted. There is no currently reproduced residual R01/R04 source failure in the checks above. No merge, tag, publication or threshold waiver was performed.

Working evidence is under `/tmp/foundation-fix-residuals/`: before/after regression logs, process logs, detailed/final QA logs, the independently verified OIDC probe, inspected ZIP and isolated consumers. The native-library and no-context execution contracts remain unchanged outside the repaired authentication policy and credential classification.

## Historical independent recheck of Batch 134 — two residual findings

**Disposition at that recheck: 90/95 accepted.** R01 and R04 required further repair; R02, R03, R05, R06 and R07 were independently confirmed repaired. Both P6 certifications remained open. The A04 credential-classification/regression and A08 approval-interaction criteria were reopened. Their subsequent implementation and local verification are recorded above.

All nine focused feature files pass locally: **55 tests, 2,751 assertions**. The original independent **388-key credential matrix now passes completely**. Original probes now show no forged canonical audit IDs, zero streamed bytes after host cancellation, mode `0600` at the first environment-secret write, and the original command exception propagated despite release failure. The timestamp-provenance/session-rotation and valid-CSRF route regressions also pass. These repairs are retained.

| Residual finding | Independent current reproduction | Required correction |
| --- | --- | --- |
| **R01 / P2: compound PIN casing still bypasses redaction** | Lowercase `pin_input`, `pin_data`, `pin_entry` and `account_pin_confirmation` redact. `PIN_INPUT`, `PIN_DATA`, `PIN_ENTRY`, `ACCOUNT_PIN_CONFIRMATION` and `mFaPiNInPuT` retain supplied sentinel values. The actual DBLayer/SQLite path persists the uppercase sentinels. `AuthEventRecorder` still mangles uppercase segmented keys, and its folded PIN branch recognizes only a finite suffix list. | Preserve case-insensitive treatment of PIN words inside supported compound keys, rather than adding more suffix exceptions. Extend both in-memory and persisted matrices across the same key's lower/upper/CamelCase/arbitrary-case forms and multiple prefix/suffix combinations, retaining safe identifier/noncredential controls. The fixed original matrix is necessary evidence but not complete policy coverage. |
| **R04 / P1: zero-age OIDC request silently reuses same-second authentication** | Give a principal authoritative `auth_time` equal to the fixture clock, and grant prior consent. With `prompt=none&max_age=0`, native interaction returns `READY`; GET and POST issue codes. The existing real-Webrick/valid-CSRF test also fails when this case is added with prior consent: its callback contains `code=`, not an error. The committed zero-age case uses a timestamp 30 seconds old and no prior consent, so it misses the boundary. | Handle zero age as forced reauthentication, including the prior-consent and equal-clock-second case. Qualify native interaction, real GET/valid-CSRF POST, consent nonmutation and applicable direct issuance. The installed Epicrypt `OpenIdInteractionPolicy` uses elapsed age `> maximumAuthenticationAge` with no zero special case; correct the native owner and integrate its supported fix/version without editing vendor or adding an older-library fallback. Foundation's issuance guard also needs consistent zero-age policy/proof handling. |

For R04, [OpenID Connect Core §3.1.2.1](https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest) explicitly makes `max_age=0` equivalent to `prompt=login`. This reproduction concerns reuse of pre-existing authentication/consent; it does not dispute R03's repaired timestamp provenance or claim an unauthenticated code exchange.

Exact reviewed-source hosted workflows are green: [Security & Standards 42369045695](https://github.com/infocyph/Foundation/actions/runs/42369045695) and [native hosts 42369045437](https://github.com/infocyph/Foundation/actions/runs/42369045437). Their inspected jobs include all four stable/lowest PHP 8.4/8.5 QA and production-consumer lanes, both strict analyses, native services, retained-state cutover and supported host smoke. Paired prepare/run/compare jobs are skipped, so P6 remains unqualified. The new failing probes were run independently after those successful workflows; green CI does not cover them yet.

Current working evidence: `/tmp/foundation-check-bc05ac4c/regressions.log`, `pin-field-probe.php`, `persisted-pin-field-probe.php`, `oidc-zero-age-probe.php`, `OidcZeroAgeBoundaryTest.php` and `zero-age-route.log`. The PIN probe returns only retained key names/booleans; the OIDC probe reports decisions and code-presence booleans. Only documentation is changed by this recheck; no source fix, merge, tag or publication is performed.

## Historical post-remediation qualification — Batch 134, superseded by independent recheck

The table and adversarial reproductions below are the **historical Batch 119 review snapshot**, not current unresolved product behavior. All seven confirmed defects R01–R07 and their nine formally tracked security/runtime acceptance requirements have been remediated and verified in the actual source with full official QA on Batches 131a–133. R04 is exercised through the real Webrick route dispatcher, native OIDC controller and CSRF middleware (with the production route mapping validated separately); R05 is exercised through real lazy stream iteration, cancellation/Fiber/abandoned ownership and a mid-stream filesystem read error. A04 includes persisted DBLayer credential/identity evidence, and R06/R07 retain their permission-at-first-write and cleanup-failure tests.

**Batch 134 disposition before independent recheck: 93/95 accepted, two P6 sustained-host performance criteria still open.** Host/quality evidence: [Batch 133 Security & Standards](https://github.com/infocyph/Foundation/actions/runs/42368558762) and [native host smoke](https://github.com/infocyph/Foundation/actions/runs/42368558572). The required two P6 paired-host primary/confirmation matrices were **not executed** by these standard runs. `composer.lock` remains gitignored; PR #20 remains draft and unmerged.

## Historical Batch 119 review snapshot

**At Batch 119, seven confirmed source findings remained open, plus the two existing P6 certifications.** The original `PASSWORD`/`API_KEY` reproduction was fixed, but A04 was only partially remediated. The revised plan had **84/95 criteria accepted**: three reopened A04 criteria, the reopened P3 Pathwise streaming criterion, five new remediation criteria, and the two P6 criteria were open. The extra regression/streaming criteria did not represent additional independently counted defects. Earlier 88/90 and 86/90 counts are historical checkpoints.

## Review coverage

The current inventory contains 669 source PHP files across 31 top-level source areas and 254 test/fixture PHP files. Whole-repository PHPForge checks were combined with manual review and adversarial probes at the public boundaries below.

| Surface | Coverage and evidence |
| --- | --- |
| Dependencies and security advisories | Live direct/transitive Composer metadata, advisory and abandonment results, platform checks, constraint-blocker inspection, installed and production-consumer graphs. |
| Authentication and authorization | Notification recipients, issuance responses, password reset/change and email verification, refresh/remember rotation, audit sinks and identity provenance, passwordless redemption, MFA/OTP/passkey state, permissions and delegation. |
| OAuth/OIDC | Input parsing, client credentials, PKCE, redirects, consent, authorization/refresh/revocation persistence, principal resolution, interaction policy, authentication age and ID-token claims. |
| HTTP and sessions | Principal middleware, CSRF/origin checks, cookie policy, browser-session persistence/locking, exception responses, route presets and compiled routes. |
| Runtime and native integrations | Ordinary PHP and supplied Runwire contexts, admission/cancellation, scope restoration, database connections/leases, cache atomics/topology, messaging, workers, scheduling, process supervision and cleanup. |
| Filesystem, secrets and logging | Public-root policy, uploads/scanning, download preparation and lazy streaming, environment installation/rotation/protection, audit and JSON logging. |
| Build and deployment | Config and executable-artifact trust, module Composer/config lifecycle, generated web/CLI/worker/scheduler releases, retained-state evidence, archive contents, isolated production consumers, workflow gates and documentation claims. |

## Confirmed findings

Historical reproductions from the original audit follow. R01–R07 are repaired; see the current status above rather than treating these preserved examples as open defects.

P1 means a security boundary needs correction before release. P2 findings are required security/correctness repairs under the stated conditions. These are repository findings, not assigned CVEs.

| ID | Priority | Finding | Plan owner |
| --- | --- | --- | --- |
| R03 | P1 | OIDC fabricates a fresh authentication time when the principal has none. | A08: authentication evidence |
| R04 | P1 | OIDC consent POST bypasses the interaction checks enforced on GET. | A08: approval policy |
| R01 | P2 | Compound OTP/PIN credential names still bypass audit redaction. | A04: credential classification |
| R02 | P2 | Three audit paths still promote free-form metadata into canonical identity fields. | A04: identity provenance |
| R05 | P2 | Non-local streamed downloads lose Pathwise's borrowed Runwire context after preparation. | A09: lazy I/O lifecycle |
| R06 | P2 | Environment-secret writes contain plaintext before their temporary file becomes private. | A10: private staging |
| R07 | P2 | Command lock cleanup can replace the primary exception. | A11: failure ordering |

### R01 — complete audit credential classification

**Source:** [AuthEventRecorder.php](../src/Auth/Support/AuthEventRecorder.php#L68), particularly the segmented matcher, folded matcher and exact-only OTP/PIN/code-verifier branch.

The Batch 118 fix correctly redacts the originally reported `PASSWORD`, `pAsSwOrD`, `TOKEN`, `API_KEY`, `AUTHORIZATION` and `PRIVATE_KEY` values. It still splits uppercase characters before segmented matching, while the folded substring matcher omits `otp` and `pin`. Those terms are accepted only as whole folded keys.

**Reproduction:** Record nested synthetic metadata through public `AuthEventRecorder::record()`. Lowercase `mfa_otp` and `account_pin` become `[REDACTED]`; `MFA_OTP`, `ACCOUNT_PIN`, `mFaOtP` and `aCcOuNtPiN` retain their values. The same uppercase values survive native DBLayer/SQLite audit persistence. Safe request/token identifiers remain preserved.

A 388-key matrix across 24 credential families, compound names, casing and separators found retained OTP/PIN variants and compound PKCE aliases such as `mfa_code_verifier` and `codeVerifierValue`. These belong in the same credential-classification correction, rather than a sequence of narrowly patched examples. The matrix retained 20 values: eight OTP/PIN spellings and twelve compound code-verifier spellings.

**Required repair and regressions:** Define and implement the sensitive-key/alias policy consistently, with explicit safe-identifier exceptions. Extend both `Foundation4SecurityBoundariesTest` and `Foundation4AuthAuditPersistenceTest` with a table-driven matrix covering all supported credential families, casing/separator/compound forms, nesting and safe identifiers. Assert absence of the original sentinel bytes in persisted JSON for login, reset, MFA and passkey events. Avoid a fix that simply creates another exact spelling list for the first two examples.

### R02 — finish separating audit identities from metadata

**Source:** [AuditingAuthorizer.php](../src/Auth/Authorization/Gate/AuditingAuthorizer.php#L59), [DelegationManager.php](../src/Auth/Authorization/Grant/DelegationManager.php#L80), and the failed-login branch of [Authenticator.php](../src/Auth/Authentication/Login/Authenticator.php#L128).

`AuditingAuthorizer` and `DelegationManager` explicitly pass free-form `context`/`metadata` session and device IDs into the recorder's canonical fields. Failed login similarly promotes `context.device_id`. This contradicts the current authentication documentation and the accepted A04 identity criterion.

**Reproduction:** Give the authorizer a principal carrying verified session/device identities and pass different caller-chosen IDs as descriptive context. The authorization-denied audit event uses the caller's IDs. A delegation grant does the same. Public `AuthActions::login()` for a nonexistent account also emits the caller-chosen canonical device ID. The probes exercise actual public manager/action paths; this is audit attribution corruption, not a demonstrated authorization bypass.

**Required repair and regressions:** Obtain canonical identities from explicit trusted inputs, an authoritative principal/record, or leave them null. Preserve descriptive metadata separately. Inventory every `AuthEventRecorder::record()` call rather than stopping at the previously listed eight managers. Add positive trusted-identity and negative injected-context assertions for denied authorization, delegation grant/revoke and failed login, including persistence. Preserve the already fixed token-family/account and record-owned device behavior.

### R03 — preserve actual OIDC authentication time

**Source:** [OAuthManager.php](../src/Auth/OAuth/OAuthManager.php#L390), [AuthorizationCodeManager.php](../src/Auth/OAuth/Authorization/AuthorizationCodeManager.php#L92), and [SessionPrincipalResolver.php](../src/Http/Resolver/SessionPrincipalResolver.php#L63).

`OAuthManager::openIdInteractionState()` substitutes the current clock when `auth_time` is absent/invalid. Authorization-code issuance makes the same substitution. The built-in session principal resolver supplies session/device IDs but omits the stored authentication time, so this affects the normal resolver path as well as custom principals.

**Reproduction:** With the native OIDC fixture, an account principal with no authentication-time evidence, prior consent, `prompt=none` and `max_age=60` receives `READY`. A principal explicitly authenticated 7,200 seconds ago correctly receives `login_required`. Exchanging a code issued for the unknown-time principal produces an ID token whose `auth_time` equals issuance time, despite no authentication occurring in the probe.

**Impact:** An RP's authentication-age requirement can be satisfied by invented evidence. OIDC defines `max_age` against actual active authentication and defines `auth_time` as the time authentication occurred. See [OpenID Connect Core, authentication request](https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest) and [ID-token claims](https://openid.net/specs/openid-connect-core-1_0.html#IDToken).

**Required repair and regressions:** Propagate authoritative authentication/reauthentication timestamps from the host/session through principal projection and code/ID-token issuance. Unknown or invalid time must require authentication when freshness must be established; do not manufacture it from request/issuance time. Cover missing, invalid, future, stale and fresh evidence, native session resolution, `max_age=0`, positive `max_age`, and preservation across session rotation and token flows. Use stored authentication evidence rather than arbitrary request metadata or a new session's creation time.

### R04 — enforce OIDC interaction policy on consent approval

**Source:** [OAuthAuthorizationController.php](../src/Auth/OAuth/Http/OAuthAuthorizationController.php#L42) and [OAuthHttpHandler.php](../src/Auth/OAuth/Http/OAuthHttpHandler.php#L73).

GET invokes `openIdInteraction()`, but POST `decision=approve` directly grants consent and issues a code for the current principal. It does not establish that required reauthentication or account selection happened. This is independent of R03: it reproduces even with an accurate stale `auth_time`.

**Reproduction:** Use the same registered OIDC client, principal and authorization parameters (`prompt=none`, `max_age=60`) with an authentication time 7,200 seconds old. Controller GET redirects with `error=login_required`; controller POST with `decision=approve` redirects with an authorization code. The probe invokes the controller directly; the finding is the missing approval policy, not a claim that CSRF middleware is absent. A valid CSRF token establishes request origin, not fresh authentication.

**Required repair and regressions:** Gate the approval path on authoritative satisfaction of the requested OIDC interaction requirements before granting consent or issuing a code. Treat consent, reauthentication and account selection as distinct evidence. Test the real HTTP route with valid CSRF: stale/missing authentication, `prompt=login`, unsatisfied `acr_values`, account selection, silent requests, and a successful genuinely reauthenticated consent submission. Retain ordinary OAuth consent behavior and native Epicrypt protocol ownership.

### R05 — retain Runwire cancellation during streamed download I/O

**Source:** [FilesystemResponseFactory.php](../src/Filesystem/FilesystemResponseFactory.php#L314) and its [stream producer](../src/Filesystem/FilesystemResponseFactory.php#L431).

The Pathwise binding exists only inside `prepareDownloadWithRunwire()`. Pathwise restores that binding when preparation returns. `respond()` subsequently returns a lazy `streamChunks()` generator for a non-local disk, which performs reads without the binding. Pathwise's per-chunk checkpoints therefore have no host context to inspect.

**Reproduction:** Configure a custom non-local driver backed by a real Flysystem operator, enter the exact supplied Runwire request, prepare a response, cancel the host request, and consume its producer while that same request remains shared. The probe reads all **608 bytes after cancellation**, with no `CancelledException`. No network service is needed. Existing tests check cancellation before preparation, so they pass without covering this case.

**Required repair and regressions:** Carry the original borrowed ownership into actual iterator advancement, validate it on every resume, and restore bindings safely on yield, completion, failure and early disposal. Wrapping generator creation alone is insufficient. Test cancellation after preparation and between chunks, completed/stale owners, early abandonment, read failure and concurrent Fibers. Preserve ordinary no-context streaming, local `FileBody`, HEAD/range/conditional/offload behavior, and host ownership of request completion and event loops.

### R06 — make environment staging private before writing secrets

**Source:** [EnvironmentSecretManager.php](../src/Security/EnvironmentSecretManager.php#L122).

`write()` creates a sibling temporary file using `fopen(..., 'x')`, writes the entire environment, and only then calls `chmod(..., 0600)`. Under umask `0022`, its mode while containing plaintext is `0644`. This temporarily exposes existing environment secrets and the newly generated token secret to another local user who can traverse the application directory and open the stage. Final `.env` mode `0600` does not remove that window.

**Reproduction:** Call public `generate()` with a synthetic private `.env`, a traversable temporary application directory and umask `0022`. A temporary, process-local namespaced `fwrite` observer delegates to native `fwrite` and inspects `fstat` before control returns to `write()`: secret bytes have been written with mode `0644`; final mode is `0600`. This deterministic observation proves the ordering. A separate timed fork observer did not catch the short window and is not represented as a successful cross-user exploit.

**Required repair and regressions:** Establish and check restrictive permissions before the first secret byte is written, retaining exclusive creation, atomic activation, full-write/flush checks and cleanup. Cover permissive umasks, privacy at the first write, permission-setting failure before any payload write, existing private files and final mode. Reuse the repository's established private-staging approach where appropriate; do not change the process-wide umask as a hidden service side effect.

### R07 — preserve command primary failures during cleanup

**Source:** [CommandExecutionCoordinator.php](../src/Command/CommandExecutionCoordinator.php#L98).

The supervised path unconditionally releases its lock in `finally`; failed-history recording is also unguarded. A release exception becomes the propagated exception instead of the command's primary failure, unlike the existing `CleanupGuard` contract used by workers and scheduling.

**Reproduction:** Exercise public `run()` with a successful test lock acquisition, an invalid isolated executable (primary `LogicException`), and a lock provider that throws `RuntimeException` on release. The propagated exception is the release `RuntimeException`. PHP retains the original `LogicException` in `getPrevious()`, so the cause is not completely lost, but callers observe the wrong primary exception/classification.

**Required repair and regressions:** Preserve the primary failure while still attempting history and lock cleanup, using the existing cohesive cleanup owner. Surface cleanup failures when there was no primary failure. Cover successful execution plus failed release, primary plus failed release, failed-history recording, overlap rejection, and exactly-once release. Audit the analogous unguarded release in `RuntimeControl::mutate()` as part of the same failure-ordering work; only the command path was fault-injected in this review.

## Earlier findings and implementation reviews

| Earlier item | Current disposition |
| --- | --- |
| A01: notification recipient and HTTP token disclosure | Existing repairs and regressions retained. The current full suite includes recipient/purpose, issuance-response and stored-address race coverage; no new reproduction found during this review. Application routes still own authentication, account ownership and rate limits. |
| A02: executable artifact verification/use mismatch | Captured-byte/private-copy evaluation retained in current trusted owners. Current artifact tamper/replacement and source-free release tests pass. |
| A03: unknown route preset drops middleware | Unknown/malformed presets fail closed; explicit empty custom groups remain intentional. Current development/compiled-route tests pass. |
| A04: audit security | R01/R02 repairs and the compound/casing persisted regressions are complete locally; final release qualification remains separate. |
| A05: process stdin supervision | Nonblocking bounded stdin/output pump and deadline/cancellation coverage retained; current process tests pass. R07 repaired the separate command-coordinator cleanup owner. |
| A06: native cancellation/admission expectations | Published native-owner exception/admission contracts retained; current Runwire integration tests pass. R05 subsequently repaired lazy download ownership/cancellation. |
| A07: ineffective architecture classification | Project Deptrac topology and forbidden-edge regressions retained; current architecture gate passes. |
| Runwire 3 / latest specialist integration | Current direct package versions are not outdated; ordinary PHP and supplied-context integration coverage exists. R05 integration repair is retained and locally covered. |
| Historical Foundation 3 lock provenance | Maintainer-approved policy exception retained: lock remains gitignored; reconstructed historical locks are not originals. No new lock policy change. |
| P4 retained-state recovery | Previously qualified hosted cross-major job passed for its documented selected SQLite/Redis profile; application-specific production restore remains operator-owned. |

## Maintenance and policy decisions

These are tracked separately from the seven confirmed defects.

| ID | Observation | Required disposition |
| --- | --- | --- |
| M01 / existing I01 | **Implemented:** passwordless links now require a manager-issued, expiring hashed marker and atomic one-time redemption for both native token representations. | Regression coverage includes replay, isolation, native expiry, unregistered state, storage failure, separate applications and four independent competing processes. Constructor, shared-state and reissue requirements are documented. Final release qualification is separate. |
| M02 | **Upstream blocked:** latest PHPBench 1.7.0 still requires abandoned `doctrine/annotations` 2.0.2. Live audit has no advisory records and exits 1 for abandonment. | PHPForge/PHPBench own removal; no supported compatible Foundation update exists. Retain the visible development-only finding and exact dependency chain in the dependency record; no vendor edit or fake package replacement. |
| M03 | **Upstream blocked:** live resolution cannot install latest PHPUnit 13.4.6 or JsonMapper 6.0.0 under the current upstream tool contracts. | Pest 5.3.5 conflicts with PHPUnit `>13.4.5`; Psalm 7.0.5 and RPC 3.2.3 require JsonMapper `^5`. Composer dry-run changes nothing. No incompatible override or claim that every transitive is absolutely latest. |
| M04 / existing I02 | **Review complete:** configured duplicate detector passes unchanged; source-only review snapshot before final canonical-key reuse reports 115 groups / 3,600 lines / 4.9%. | Retain bounded native-purpose/representation-specific key/validation duplication and existing abstract timed-token sharing. Review identified no additional correctness defect requiring extraction; no budget or suppression change. |


## Historical original-review verification and release evidence

| Check | Result at the original review |
| --- | --- |
| PHPForge doctor/config resolution | Healthy; intended bundled tools plus project Deptrac, PHP 8.4/8.5 CI matrix, current QA workflow pin. |
| `composer validate --strict` | Pass. |
| `composer outdated --direct --format=json` | Empty `installed` list; direct packages current. Full transitive result and blockers are recorded under M02/M03. |
| `composer audit --locked --format=json` | No security advisory records; exit 1 due to M02 abandonment. |
| Local `composer ic:tests` with socket access | Exit 2: **969 passed, 25 failed; 31,850 assertions**. All 25 failures require unavailable MySQL/PostgreSQL, Redis/Valkey/Memcached or shared-lock test prerequisites. No socket restriction failures remain in this run. |
| Repository-wide quality gates | Skip-directive scanner, Composer normalization, syntax, references, duplicate/comment detectors, Pint, PHPCS, Deptrac, PHPStan, Psalm and Rector all pass. |
| Two focused audit test files | **18 passed, 434 assertions**. Their green result does not cover the newly demonstrated inputs above. |
| Adversarial probes | R01–R07 reproduced as documented. Failures are deliberate findings, not passing regressions. The older password/API-key probe now passes. |
| `composer benchmark:release` | All nine configured specialist-utilization scripts complete successfully. These diagnostics do not certify sustained paired host RPM. |
| Actual Composer ZIP | 716 files; all 710 tracked runtime/resource/CLI files present; zero untracked or excluded development/generated/environment payloads. SHA-256 `e5fd4eb7bd307df8cb83a96c91f4cc2436f11bb25f5ff0b1c7089e68299a92a6`. |
| Archive-based core `--no-dev` consumer | 12 production packages, no development packages/tooling. Platform checks, CLI list and core feature fixture pass. Foundation is installed from the ZIP, not a source symlink. |
| Archive-based full-optional `--no-dev` consumer | 57 production packages, no development packages/tooling. Platform checks, CLI list, 12 selected-feature configurations and compiled web/CLI/worker/scheduler example pass. |
| Exact reviewed-head hosted CI | [Security & Standards 42352368456](https://github.com/infocyph/Foundation/actions/runs/42352368456) and [native HTTP hosts 42352367856](https://github.com/infocyph/Foundation/actions/runs/42352367856) completed successfully on `57d309a6dfc3e02078331a1118d563ed46f7c1d9`. Inspected jobs include all four PHP 8.4/8.5 stable/lowest QA/consumer lanes, strict analyses, native services, retained-state cutover and supported host smoke. |
| P6 sustained comparisons | **Both open.** Paired prepare/run/compare jobs are skipped in the current host-smoke run. The workflow is still absent from default `main` (live fetch returns 404). Follow the existing [execution handoff](foundation-4-release-certification-handoff.md); smoke, microbenchmarks and green QA do not close these gates. |
| Git/publication | Source revision reviewed as committed and initially clean; this review changes documentation only. PR #20 is draft/open/unmerged. No merge, tag, publication, threshold change or default-branch write. |

The existing P6 contract remains unchanged: separate shared-lock source and independent-lock dependency comparisons; primary/confirmation FPM/Runwire × one/four-worker × concurrency 1/10/50 batches; five >=120-second trials after warm-up; required soak/recovery; <=2% median successful-RPM regression and <=2.5% CV.

## Historical remediation order

Source repairs and local maintenance dispositions are complete as recorded above. This preserved sequence is the original audit work order; release verification and the explicitly blocked upstream toolchain items remain separate.

1. Repair R03/R04 together at the OIDC authentication-evidence and approval boundaries; add real HTTP and ID-token regressions.
2. Repair R01/R02 together across every audit sink and supported credential family; retain trusted IDs and safe descriptive context.
3. Repair R05 with lazy-stream ownership/cancellation tests, and R06 with permission-at-first-write tests.
4. Repair R07 and inspect analogous cleanup sites; preserve primary exceptions and attempt every required cleanup.
5. Resolve/document M01–M04 through their named owners. Any exception to the user's latest-version requirement needs an explicit policy disposition, not an undocumented compatibility branch.
6. Run the complete configured quality/consumer/host matrix on the final repaired SHA, inspect the actual archive, then obtain both authentic P6 certifications under unchanged thresholds. Update this report and the canonical plan together after reviewing the complete result.

Temporary working evidence is under `/tmp/foundation-consolidated-audit-20261107/`; the earlier case probes remain under `/tmp/foundation-review-20261107/`. It includes `qa.log`, `qa-plain.log`, advisory/outdated JSON, `benchmarks.log`, `archive-inspection.json`, both archive consumers and named public-boundary probes. The scenarios, source owners, outcomes and required regressions above are the durable review record. Future implementation should turn the complete finding matrix into repository regressions, rather than closing an item after testing only its first reported spelling.
