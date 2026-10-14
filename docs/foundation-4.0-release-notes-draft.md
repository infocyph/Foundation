# Foundation 4.0 — Release candidate notes (draft)

> **Unpublished candidate:** PR #20 remains draft. This document is not an announcement, tag, compatibility promise, or certification. Final wording must be reviewed against the exact released source/dependency set.

## Core runtime and architecture

- Explicit web, CLI, worker and scheduler entrypoints with immutable native InterMix graph composition.
- One trusted, source-free compiled generation for web and non-web runtimes. Webrick retains ownership of native HTTP routing, request dispatch, route formats and response output.
- Native InterMix scoped input/borrowed scope handling, controlled cleanup, generation identity and concurrent execution isolation.
- Runwire is optional and explicitly selected. Foundation uses the host-owned RuntimeContext/RequestContext and native Webrick/InterMix integration; it does not add a competing server or coroutine scheduler.
- Optional specialist capabilities must be explicitly selected. Installation of an optional Composer package alone does not activate its framework integration.

## Selected native specialists

- ArrayKit, CacheLayer, InterMix, UID and Webrick form the required runtime foundation. CacheLayer is a required framework service, not a separately installable module.
- DBLayer owns database connections and migrations; Epicrypt owns cryptography and OAuth/token primitives; OTP/WebAuthn owns MFA/passkey ceremonies.
- Omnibus owns messaging, durable worker transports and scheduling primitives; Pathwise owns filesystem/storage; ReqShield owns validation; TalkingBytes owns communication.
- Foundation provides application composition, policy, capability selection and lifecycle boundaries without duplicating library-native internals.

## Security, state and lifecycle

- Authentication, browser session/CSRF, OAuth 2.1, passkey, one-time recovery, revocation and replay-isolation workflows are exercised through current selected native integrations.
- Built-in persistent lifecycle covers request/worker/fiber cleanup, cancellation, transaction rollback and no cross-request principal or runtime-scope leakage.
- Current Foundation 4 auth/OAuth persistence snapshot/restoration is tested independently of compatibility with previous major versions.

## Platform and integration

- PHP 8.4 or 8.5, Composer 2.
- Required: ArrayKit `^5.3.1`, CacheLayer `^4.0`, InterMix `^11.2`, UID `^6.0`, Webrick `^6.0.3`.
- Optional selected specialists: DBLayer `^6.1`, Epicrypt `^3.2`, Omnibus `^3.0`, OTP `^7.0`, Pathwise `^4.2`, ReqShield `^3.3`, TalkingBytes `^2.3`, Runwire `^2.1.1`; passkey feature uses compatible WebAuthn dependencies.
- Development and production `--no-dev` configurations build standalone Foundation releases; unrelated Infbyte application migration is not included.

## Major-version contract

Foundation 4.0 is a deliberate breaking major release. **There are no backwards-compatibility aliases or guarantees for previous Foundation or specialist major versions.** Consumers must use the current 4.0 public/provider/capability contracts and rebuild trusted release generations.

## Certification still required before publication

FPM and native Runwire have passed real HTTP smoke, cancellation, error recovery, worker replacement and bounded selected-native read/credential-cache soak on the candidate. These are shared-runner **smoke** results, not controlled sustained baseline-vs-candidate RPM or production-equivalent memory/latency certification. Production service backup/disaster recovery also requires deployment-specific validation. Release certification remains open. See the [release audit and plan](plans/foundation-4.0-release-audit-and-plan.md). Publish only after all applicable release gates pass on the final immutable source and dependency set.

Final release notes should contain exact tested SHA, dependency manifest, benchmark methodology and results, confirmed compatibility scope, and publication metadata.
