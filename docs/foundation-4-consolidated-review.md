# Foundation 4 consolidated review

Date: 2026-11-07 (Asia/Dhaka). Reviewed source: `57d309a6dfc3e02078331a1118d563ed46f7c1d9` (Batch 119).

This is the current combined review of the original upgrade audit, the first independent A04 review, and the implementation through Batch 119. It replaces their historical status statements as the current findings list; their evidence remains in the [upgrade plan](foundation-4-library-upgrade-plan.md). No runtime code or dependency constraints were changed during this review.

**Disposition: seven confirmed source findings remain open, plus the two existing P6 certifications.** The original `PASSWORD`/`API_KEY` reproduction is fixed, but A04 is only partially remediated. The revised plan has **84/95 criteria accepted**: three reopened A04 criteria, the reopened P3 Pathwise streaming criterion, five new remediation criteria, and the two P6 criteria are open. The extra regression/streaming criteria do not represent additional independently counted defects. Prior 88/90 and 86/90 counts are historical checkpoints.

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
| A04: audit security | **Partially fixed; reopen all three criteria.** Original uppercase password/API-key cases pass, but R01 and R02 remain. |
| A05: process stdin supervision | Nonblocking bounded stdin/output pump and deadline/cancellation coverage retained; current process tests pass. R07 concerns a different owner, the command coordinator. |
| A06: native cancellation/admission expectations | Published native-owner exception/admission contracts retained; current Runwire integration tests pass. R05 is the uncovered lazy download path. |
| A07: ineffective architecture classification | Project Deptrac topology and forbidden-edge regressions retained; current architecture gate passes. |
| Runwire 3 / latest specialist integration | Current direct package versions are not outdated; ordinary PHP and supplied-context integration coverage exists. R05 remains a concrete integration gap. |
| Historical Foundation 3 lock provenance | Maintainer-approved policy exception retained: lock remains gitignored; reconstructed historical locks are not originals. No new lock policy change. |
| P4 retained-state recovery | Exact-current-head hosted cross-major job passed for its documented selected SQLite/Redis profile; application-specific production restore remains operator-owned. |

## Maintenance and policy decisions

These are tracked separately from the seven confirmed defects.

| ID | Observation | Required disposition |
| --- | --- | --- |
| M01 / existing I01 | Passwordless verification still accepts the same issued token twice before expiry. Public-manager probe returns `first_verified=true`, `second_verified=true`. | Decide/document reusable timed-token versus one-time redemption semantics. If magic links are one-time, add atomic redemption and expiry/concurrency regressions. Retain the original policy distinction rather than inventing an already promised single-use contract. |
| M02 | `doctrine/annotations` 2.0.2 is abandoned; PHPBench 1.7.0 requires it. Composer audit exits 1 for abandonment with **no advisory records**. | Track PHPForge/PHPBench upstream removal; do not edit vendor or add an unrelated runtime replacement. |
| M03 | Two development transitives have newer published versions: PHPUnit 13.4.6 versus 13.4.5; JsonMapper 6.0.0 versus 5.0.1. | Pest 5.3.5 explicitly conflicts with PHPUnit `>13.4.5`; Psalm 7.0.5 and `danog/advanced-json-rpc` require JsonMapper `^5`. Resolve through their owning toolchain/upstream releases. No incompatible override or old-library compatibility fallback was added. Do not claim every transitive package is at its absolute newest version. |
| M04 / existing I02 | Duplicate detector passes the unchanged configured budget. | Review cohesive semantic duplication when touched; do not merge unrelated native owners or relax detector budgets. The original 131-clone figure belongs to the original audit, not this run's summary. |

## Verification and release evidence

| Check | Current result |
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

## Consolidated completion order

1. Repair R03/R04 together at the OIDC authentication-evidence and approval boundaries; add real HTTP and ID-token regressions.
2. Repair R01/R02 together across every audit sink and supported credential family; retain trusted IDs and safe descriptive context.
3. Repair R05 with lazy-stream ownership/cancellation tests, and R06 with permission-at-first-write tests.
4. Repair R07 and inspect analogous cleanup sites; preserve primary exceptions and attempt every required cleanup.
5. Resolve/document M01–M04 through their named owners. Any exception to the user's latest-version requirement needs an explicit policy disposition, not an undocumented compatibility branch.
6. Run the complete configured quality/consumer/host matrix on the final repaired SHA, inspect the actual archive, then obtain both authentic P6 certifications under unchanged thresholds. Update this report and the canonical plan together after reviewing the complete result.

Temporary working evidence is under `/tmp/foundation-consolidated-audit-20261107/`; the earlier case probes remain under `/tmp/foundation-review-20261107/`. It includes `qa.log`, `qa-plain.log`, advisory/outdated JSON, `benchmarks.log`, `archive-inspection.json`, both archive consumers and named public-boundary probes. The scenarios, source owners, outcomes and required regressions above are the durable review record. Future implementation should turn the complete finding matrix into repository regressions, rather than closing an item after testing only its first reported spelling.
