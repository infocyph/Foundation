# Foundation 4.0 — Release notes

Foundation 4 is the framework composition, policy and application-lifecycle
layer used by Infbyte. This is a breaking major release with current native
library contracts, required Core Runwire, explicit optional features and trusted
compiled generations.

**Unpublished release candidate.** Publication requires green final-source
security, static analysis, native runtime, integration, production-consumer,
migration and documentation checks. The maintainer discontinued the sustained
baseline/candidate throughput qualification for this release candidate.
It **did not pass** the former full-matrix acceptance criteria; no performance
equivalence, capacity or regression-free claim is made.
The release pull request and its CI artifacts identify the exact source,
dependency graph and verification results. These notes do not announce a
published tag or release date.

## Core runtime and architecture

- Explicit web, CLI, worker and scheduler entrypoints with immutable native InterMix graph composition.
- Trusted web loading now uses native source-free InterMix artifacts without reconstructing providers or attaching a fallback container. Compilation rejects source-only runtime hooks, and the compiled container also binds explicit capabilities; rebuild older candidate artifacts.
- One trusted, source-free compiled generation for web and non-web runtimes. Webrick retains ownership of native HTTP routing, request dispatch, route formats and response output.
- Native InterMix scoped input/borrowed scope handling, controlled cleanup, generation identity and concurrent execution isolation.
- Runwire is a required Core dependency with always-available native integration across supported environments. Foundation uses the host-owned RuntimeContext/RequestContext and native Webrick/InterMix integration; it does not add a competing server or coroutine scheduler.
- Published CacheLayer 4.0.2, DBLayer 6.1.4 and Omnibus 3.0.3 provide context sharing and native operation providers. Ordinary Foundation-created database and messaging services receive the active host context; redundant Foundation operation wrappers are removed.
- Optional specialist capabilities must be explicitly selected. Installation of an optional Composer package alone does not activate its framework integration.
- Webrick and HTTP-only counter, session, filesystem and OAuth adapters are confined to web graphs. Non-web commands, workers and scheduled tasks retain native application services without HTTP adapter registration or Webrick class loading.

## Serving and host ownership

- In Infbyte, `php infbyte serve` delegates to Runwire's native host selection;
  `php infbyte serve --runtime=swoole` explicitly selects the Swoole-family host.
  Driver detection, protocol policies, workers and event loops remain Runwire-owned.
- Ordinary PHP/FPM or externally managed servers can continue through the normal
  application `public/index.php` entrypoint without running `serve`. Shared,
  dedicated, Docker and local deployments use the same framework contracts.
- The active native runtime and request/task scope are forwarded through Core
  services and selected libraries, including intermediary callers. Capability
  checks retain normal synchronous paths where the host lacks a capability.

## Optional risk admission

Foundation owns optional risk assessment, telemetry, challenge verification and device trust; Webrick owns HTTP admission and response semantics. The feature remains disabled unless the application explicitly composes the protected login route. Shared CacheLayer authority and a dedicated authentication pool are required for authoritative distributed hash admission. Trust signals must come from a verified host boundary; a device identifier alone is not possession proof. Provider verification uses HTTPS over HTTP/1.1 with strict URI and peer checks. The release gate additionally requires the isolated real provider, worker lifecycle, edge HTTP/1.1–3 parity and scoped 30-minute mixed-route soak. This scoped acceptance is distinct from the retired Foundation-wide sustained comparison; it is not a production-wide capacity or equivalence claim. See the risk-admission documentation for deployment requirements.

## Foundation 4 module CLI and breaking selection contract

The Foundation 4 public module catalog contains **three groups and eight independently selectable features**:

- **data** — `data.database`, `data.filesystem`
- **security** — `security.crypto`, `security.validation`, `security.mfa`, `security.passkey`
- **execution** — `execution.messaging`, `execution.communication`

The former 11-entry public module catalog and its unqualified installable aliases are removed. Commands such as `module:install data.database`, `module:plan security --feature=mfa`, `module:doctor execution.messaging` and `module:schema:status execution.messaging` operate on explicit feature ownership. Machine-readable inventory, plans and lifecycle responses use **`schema_version: 2`**; previous machine parsers and scripts need migration. No implicit install-all or feature activation occurs for a bare group or for installed Composer packages. `security.mfa` with the OTP driver requires explicitly selected Epicrypt-backed `security.crypto`; standalone passkeys share OTP packaging without enabling MFA. Durable Omnibus requires explicit `data.database`, not Runwire activation. Core auth/cache/runtime/logging/operations/resources/sessions/notifications and PHPForge engineering QA are not installable optional features. See [the v2 module commands and upgrade guidance](modules.md) and [migration guide](foundation-4-migration.md).

## Selected native specialists

- ArrayKit, CacheLayer, InterMix, Runwire, UID and Webrick form the required runtime foundation. CacheLayer is a required framework service, not a separately installable module.
- DBLayer owns database connections and migrations; Epicrypt owns cryptography and OAuth/token primitives; OTP/WebAuthn owns MFA/passkey ceremonies.
- Omnibus owns messaging, durable worker transports and scheduling primitives; Pathwise owns filesystem/storage; ReqShield owns validation; TalkingBytes owns communication.
- Foundation provides application composition, policy, capability selection and lifecycle boundaries without duplicating library-native internals.

## Security, state and lifecycle

- Integrations remain uninitialized until their operation is needed. Anonymous authentication, untouched sessions, ordinary validation, custom notifications, rejected webhooks, synchronous messaging, and empty consumers do not initialize unrelated backend libraries. Query-cache configuration alone does not open a cache backend; writes still invalidate shared cached data. Published DBLayer 6.1.4 and Omnibus 3.0.3 supply these native deferred contracts.
- Authentication, browser session/CSRF, OAuth 2.1, passkey, one-time recovery, revocation and replay-isolation workflows are exercised through current selected native integrations.
- Passwordless links now use expiring, hashed issuance markers and atomic single-use redemption for both token drivers. Replays, missing state and directly issued stateless tokens cannot authorize a login. Shared persistent atomic auth state is required across processes; previously issued links must be reissued. Direct manager construction adds the auth TTL store and matching TTL.
- Built-in persistent lifecycle covers request/worker/fiber cleanup, cancellation, transaction rollback and no cross-request principal or runtime-scope leakage.
- Current Foundation 4 auth/OAuth persistence snapshot/restoration is tested independently of compatibility with previous major versions.
- Refresh/remember replacement insertion cannot overwrite an existing token identity; the native transaction rolls back both records on collision. Independent SQLite/MySQL/PostgreSQL processes exercise conditional auth transitions and account-update rollback.
- Fresh passkey schemas store the full opaque credential ID with a unique SHA3-256 identity index, supporting case-sensitive identity without exceeding MySQL index limits. Existing Foundation 4 candidate databases must be rebuilt or explicitly brought to the current schema before deployment; readiness rejects a missing index column.
- Feature lifecycle plans capture current configuration and Composer metadata, reject stale package-removal plans, and restore configuration after detected publication-time dependency drift. External Composer maintenance must participate in the documented project lock.
- Native TalkingBytes email sender, mailbox and receiver factories borrow the active passed Runwire context with synchronous fallback.
- Authentication-session rotation now requires an atomic conditional store transition, preventing a revoked, changed or expired source session from being replaced. Custom stores must adopt the new boolean rotation contract documented in the setup guide.

Direct account permissions now short-circuit authorization before querying role
permissions. Role and principal grants remain fallbacks, and each decision reads
current permissions so revocations and account isolation remain effective.

## Platform and integration

- 64-bit PHP 8.4 or 8.5, Composer 2.
- Required Core runtime: ArrayKit `^5.3.2`, CacheLayer `^4.0.3`, InterMix `^11.2.1`, Runwire `^3.1`, UID `^6.0.1`, Webrick `^6.1`, and PSR Log (`psr/log`) `^3.0.2`.
- Optional selected specialists: DBLayer `^6.1.4`, Epicrypt `^3.3`, Omnibus `^3.0.3`, OTP `^7.0.1`, Pathwise `^4.2.1`, ReqShield `^3.3.1`, TalkingBytes `^2.3.1`; passkey feature uses compatible WebAuthn dependencies.
- The WebAuthn passkey feature requires `web-auth/webauthn-lib` `^5.3.12`; `opis/closure` `^4.5` and PHPForge remain development/engineering dependencies, not required Core runtime packages.
- Optional transitive compatibility floors exclude PHP 8.4/8.5 implicit-nullability deprecations: Flysystem Local 3.25.1, MIME detection 1.16, PSR HTTP Factory 1.1, PKI Framework 1.6.2, Symfony Clock/PropertyAccess/PropertyInfo/Serializer/UID 6.4.3 (also excluding 7.0.0–7.0.2), and PHP 8.3 polyfill 1.30. Composer applies these conflicts when the package is present; optional feature installation remains explicit.
- Development and production `--no-dev` configurations build standalone Foundation releases. Infbyte's stable dependency cutover follows actual Foundation 4 publication; application publication is a separate release.

The [migration guide](foundation-4-migration.md#retained-application-and-data-contracts) distinguishes retired library-major APIs from retained key rotation, serialized message identity and application-owned configuration policy.

## Major-version contract

Foundation 4.0 is a deliberate breaking major release. **There are no backwards-compatibility aliases or guarantees for previous Foundation or specialist major versions.** Consumers must use the current 4.0 public/provider/capability contracts and rebuild trusted release generations.

## Package metadata

The release candidate declares Composer package `infocyph/foundation`, type `library`, license `MIT`, PHP `^8.4`, Composer runtime API `^2.0`, PSR-4 namespace `Infocyph\Foundation\`, and shipped CLI entry `bin/infbyte`. Optional specialist and passkey dependencies are declared as suggested packages and available in `require-dev` for development/test coverage; application owners install only explicitly selected package requirements. The candidate `composer.json` does not hardcode a release `version`: Composer release identity will come from the operator-published Git tag.

Release evidence must identify the **final published source SHA**, resolved
dependencies and lock digest, PHP/extensions, security results and CI runs.

## Performance qualification disposition

The maintainer elected to **stop further sustained-host performance testing**
for Foundation 4.0 and remove the release-specific comparison pipeline.
The earlier [complete 24-pair hosted-ARM attempt](https://github.com/infocyph/Foundation/actions/runs/43710493075)
finished its paired measurements, but **both comparison jobs failed** the
then-required 2% median successful-RPM and 2.5% coefficient-of-variation
acceptance gates. A later
[three-scenario self-hosted diagnostic](https://github.com/infocyph/Foundation/actions/runs/43731149599)
completed without functional errors but did not establish a complete release
performance verdict. Historical failures and raw artifacts remain available
in GitHub Actions and [tracking issue #21](https://github.com/infocyph/Foundation/issues/21).

This is an **explicit release-scope decision, not a repaired or passing
performance certification**. No production code, security assertion or runtime
constraint is changed to manufacture a performance pass. Deployments requiring
measured throughput SLOs must validate them on their own production-equivalent
hardware before adopting this release.

Final publication still requires the normal PHP 8.4/8.5 QA, native runtime
serving checks, production consumers, retained-data migration/rollback,
security audit, documentation and explicit maintainer approval. Production
backup/disaster-recovery requirements also need deployment-specific validation.
Until a tag is actually published, these notes remain unpublished.

## Security and reliability corrections

- Audit metadata redacts credential keys consistently across casing and separators; canonical session/device identities come from verified principal/session evidence.
- OIDC authentication freshness uses genuine authentication evidence, including the zero-age case; consent approval enforces the same interaction policy as its initial request.
- Borrowed Runwire cancellation remains active for every lazy download read and for abandoned or truncated streams.
- Environment-secret staging is private before the first write. Primary command failures remain the reported failure when cleanup also fails.
- Release config staging starts with private `0600` files and rejects temporary-directory fallback. Config caches use `0640`; complete generations build inside `0700` staging and publish as `0750`, protecting scalar credentials embedded in native compiled containers. New standalone compiler output directories use `0750`; application owners must also keep existing output directories private. Rebuild and retire older caches/generations and align deployment/PHP-worker ownership; see the [release privacy guidance](deployment/releases.rst).
- Passwordless redemption consumes shared expiring markers atomically; replay, missing state and store failures fail closed.

These implemented corrections do not replace final source, package, consumer and sustained-host verification.
