# Foundation 4.0 — Release candidate notes (draft)

> **Unpublished candidate:** PR #20 remains draft. This document is not an announcement, tag, compatibility promise, or certification. Final wording must be reviewed against the exact released source/dependency set.

## Core runtime and architecture

- Explicit web, CLI, worker and scheduler entrypoints with immutable native InterMix graph composition.
- One trusted, source-free compiled generation for web and non-web runtimes. Webrick retains ownership of native HTTP routing, request dispatch, route formats and response output.
- Native InterMix scoped input/borrowed scope handling, controlled cleanup, generation identity and concurrent execution isolation.
- Runwire is optional and explicitly selected. Foundation uses the host-owned RuntimeContext/RequestContext and native Webrick/InterMix integration; it does not add a competing server or coroutine scheduler.
- Published CacheLayer 4.0.1, DBLayer 6.1.1 and Omnibus 3.0.1 provide context sharing and native operation providers. Ordinary Foundation-created database and messaging services receive the active host context; redundant Foundation operation wrappers are removed.
- Optional specialist capabilities must be explicitly selected. Installation of an optional Composer package alone does not activate its framework integration.

## Foundation 4 module CLI and breaking selection contract

The Foundation 4 public module catalog contains **three groups and nine independently selectable features**:

- **data** — `data.database`, `data.filesystem`
- **security** — `security.crypto`, `security.validation`, `security.mfa`, `security.passkey`
- **execution** — `execution.runtime`, `execution.messaging`, `execution.communication`

The former 11-entry public module catalog and its unqualified installable aliases are removed. Commands such as `module:install data.database`, `module:plan security --feature=mfa`, `module:doctor execution.messaging` and `module:schema:status execution.messaging` operate on explicit feature ownership. Machine-readable inventory, plans and lifecycle responses use **`schema_version: 2`**; previous machine parsers and scripts need migration. No implicit install-all or feature activation occurs for a bare group or for installed Composer packages. `security.mfa` with the OTP driver requires explicitly selected Epicrypt-backed `security.crypto`; standalone passkeys share OTP packaging without enabling MFA. Durable Omnibus requires explicit `data.database`, not Runwire activation. Core auth/cache/logging/operations/resources/sessions/notifications and PHPForge engineering QA are not installable optional features. See [the v2 module commands and upgrade guidance](modules.md) and [migration guide](foundation-4-migration.md).

## Selected native specialists

- ArrayKit, CacheLayer, InterMix, UID and Webrick form the required runtime foundation. CacheLayer is a required framework service, not a separately installable module.
- DBLayer owns database connections and migrations; Epicrypt owns cryptography and OAuth/token primitives; OTP/WebAuthn owns MFA/passkey ceremonies.
- Omnibus owns messaging, durable worker transports and scheduling primitives; Pathwise owns filesystem/storage; ReqShield owns validation; TalkingBytes owns communication.
- Foundation provides application composition, policy, capability selection and lifecycle boundaries without duplicating library-native internals.

## Security, state and lifecycle

- Authentication, browser session/CSRF, OAuth 2.1, passkey, one-time recovery, revocation and replay-isolation workflows are exercised through current selected native integrations.
- Built-in persistent lifecycle covers request/worker/fiber cleanup, cancellation, transaction rollback and no cross-request principal or runtime-scope leakage.
- Current Foundation 4 auth/OAuth persistence snapshot/restoration is tested independently of compatibility with previous major versions.
- Refresh/remember replacement insertion cannot overwrite an existing token identity; the native transaction rolls back both records on collision. Independent SQLite/MySQL/PostgreSQL processes exercise conditional auth transitions and account-update rollback.
- Fresh passkey schemas store the full opaque credential ID with a unique SHA3-256 identity index, supporting case-sensitive identity without exceeding MySQL index limits. Existing Foundation 4 candidate databases must be rebuilt or explicitly brought to the current schema before deployment; readiness rejects a missing index column.
- Feature lifecycle plans capture current configuration and Composer metadata, reject stale package-removal plans, and restore configuration after detected publication-time dependency drift. External Composer maintenance must participate in the documented project lock.
- Native TalkingBytes email sender, mailbox and receiver factories borrow the active passed Runwire context with synchronous fallback.
- Authentication-session rotation now requires an atomic conditional store transition, preventing a revoked, changed or expired source session from being replaced. Custom stores must adopt the new boolean rotation contract documented in the setup guide.

## Platform and integration

- PHP 8.4 or 8.5, Composer 2.
- Required Core runtime: ArrayKit `^5.3.1`, CacheLayer `^4.0.1`, InterMix `^11.2`, UID `^6.0`, Webrick `^6.0.3`, and PSR Log (`psr/log`) `^3.0.2`.
- Optional selected specialists: DBLayer `^6.1.1`, Epicrypt `^3.2`, Omnibus `^3.0.1`, OTP `^7.0`, Pathwise `^4.2`, ReqShield `^3.3`, TalkingBytes `^2.3`, Runwire `^2.1.1`; passkey feature uses compatible WebAuthn dependencies.
- The WebAuthn passkey feature requires `web-auth/webauthn-lib` `^5.3.9`; `opis/closure` `^4.5` and PHPForge remain development/engineering dependencies, not required Core runtime packages.
- Development and production `--no-dev` configurations build standalone Foundation releases; unrelated Infbyte application migration is not included.

## Major-version contract

Foundation 4.0 is a deliberate breaking major release. **There are no backwards-compatibility aliases or guarantees for previous Foundation or specialist major versions.** Consumers must use the current 4.0 public/provider/capability contracts and rebuild trusted release generations.

## Publication metadata audit (reviewed; approval withheld)

The release candidate declares Composer package `infocyph/foundation`, type `library`, license `MIT`, PHP `^8.4`, Composer runtime API `^2.0`, PSR-4 root `Infocyph\\Foundation\\`, and shipped CLI entry `bin/infbyte`. The nine optional specialists are declared as suggested packages and available in `require-dev` for development/test coverage; application owners install only explicitly selected package requirements. The candidate `composer.json` does not hardcode a release `version`: Composer release identity will come from the operator-published Git tag.

**Documentation and dependency roles have been reviewed, but publication approval has not been granted.** The release evidence must identify the **final published source SHA**, complete resolved dependency manifest, Composer lock digest, PHP/extensions and CI runs. No tag, release asset or release date should be presented as already published. The remaining paired same-runner baseline/candidate tests and the dependent final go/no-go decision are recorded in [Batch 7 evidence](plans/foundation-4-module-batch7-evidence.md). CI smoke and synthetic benchmark results are not substitutes.

## Certification still required before publication

FPM and native Runwire have passed real HTTP smoke, cancellation, error recovery, worker replacement and bounded selected-native read/credential-cache soak on the candidate. These are shared-runner **smoke** results, not controlled sustained baseline-vs-candidate RPM or production-equivalent memory/latency certification. Production service backup/disaster recovery also requires deployment-specific validation. Release certification remains open. See the [release audit and plan](plans/foundation-4.0-release-audit-and-plan.md). Publish only after all applicable release gates pass on the final immutable source and dependency set.

Final release notes must contain the exact published source SHA, resolved dependency manifest and Composer lock hash, confirmed selected feature support, controlled baseline/candidate comparison artifacts (five sustained ≥30-second correct-response repetitions per FPM/Runwire case, stable variance and no greater than 2% successful-RPM regression), representative authenticated/database/cache workload metrics, and reviewed publication metadata. Until those external controlled-host measurements and the final acceptance decision are available, this document remains an **unpublished draft**; passing GitHub-runner smoke must not be described as release certification.

### Pre-merge measurement environment (2026-10-16)

The user selected GitHub Actions for the matched host comparison and Docker for
local verification. Each GitHub scenario runs the frozen baseline and candidate
sequentially on the same runner with one dependency lock, identical benchmark
tools and isolated equivalent PostgreSQL datasets plus Redis. The matrix covers
FPM/Runwire, one/four workers and concurrency 1/10/50, with five runs of at least
30 seconds, a 2.5% maximum coefficient of variation and the unchanged 2% median
successful-RPM regression budget. Cold compile/first-ready-request and CLI
measurements are reported separately. This approval selects the pre-merge test
environment; it does not establish capacity for an unmeasured production deployment.
