# Foundation 4.0: setup and runtime contracts

This guide describes Foundation 4.0 setup and production runtime contracts. Foundation 4.0 is a deliberate
breaking major release; prior-version API signatures, legacy configuration,
schema conversions and downgrade support are outside its release acceptance.

Read [architecture](architecture.md), [modules](modules.md),
[configuration](configuration.md), the
[current 4.0 public/provider/configuration contract](foundation-4-api-map.md),
and [4.0-native state integrity and recovery](foundation-4-retained-data-rehearsal.md)
before production deployment.

The demand-driven candidate also changes framework adapter constructors: HTTP
principal resolvers and database-backed auth stores receive the host's PSR
container. They resolve native collaborators only when credentials or a store
operation requires them. Custom direct construction must pass that container;
no legacy constructor overload is retained. Keep execution-scoped services in
their active scope. Unused integrations remain cold through scope cleanup.

Published DBLayer 6.1.4 and Omnibus 3.0.3 provide the native deferred cache,
transport and failure-store contracts. Their Composer floors, feature catalogs,
worker diagnostics and package tables are aligned. Foundation requires these
patches when the corresponding optional feature is selected.

Runtime selection also limits integration registration. Webrick routing, HTTP
counter adapters, browser-session/CSRF middleware, filesystem HTTP handlers and
OAuth HTTP controllers belong to the web graph. CLI, worker and scheduler
graphs retain their native cache, storage, auth and OAuth application services
without registering or loading those HTTP adapters. Each operation initializes
only the backends it actually uses.

## Version and package requirements

Foundation 4 requires 64-bit PHP 8.4+ and Composer 2. The candidate's direct production
dependencies are ArrayKit `^5.3.2`, CacheLayer `^4.0.2`, InterMix `^11.2.1`,
Runwire `^3.0`, UID `^6.0.1`, Webrick `^6.0.4`, and PSR Log `^3.0.2`.

Select only the optional **feature IDs** needed by this application, grouped into the three public modules `data`, `security`, and `execution`. The following packages are installed only for selected features:

| Public feature | Composer package constraint |
| --- | --- |
| `data.database` | `infocyph/dblayer:^6.1.4` |
| `data.filesystem` | `infocyph/pathwise:^4.2.1` |
| `security.crypto` | `infocyph/epicrypt:^3.3` |
| `security.validation` | `infocyph/reqshield:^3.3.1` |
| `security.mfa` | `infocyph/otp:^7.0.1`; OTP-driver MFA also requires explicitly selected `security.crypto` |
| `security.passkey` | `infocyph/otp:^7.0.1` and `web-auth/webauthn-lib:^5.3.12`; selecting passkeys does not activate MFA |
| `execution.messaging` | `infocyph/omnibus:^3.0.3`; durable transport requires `data.database` |
| `execution.communication` | `infocyph/talkingbytes:^2.3.1` |

The [module catalog](modules.md) owns optional feature and extension requirements.
Do not blindly install all optional modules. CacheLayer remains a Foundation
production dependency, not a separately installable module. Runwire 3.0 is also Core: its host/context integration is always available,
with native capability checks and normal synchronous execution when no active context is passed.

**Before installing Foundation 4**, confirm all required
published versions are resolvable by Composer/Packagist. In particular,
Webrick 6.0.4 requires InterMix 11.2.1 and its compiled ABI 3; generate a fresh trusted InterMix/Webrick release from the current 4.0 source. Do not work
around missing package-index versions with transient dev aliases for release QA.

See [the audited current dependency graph](foundation-4-dependency-qualification.md)
for exact published commit references, selected platform requirements and
locked CI evidence; the [compatibility audit](foundation-4-compatibility-audit.md)
distinguishes removed library-major shims from current application/security policy.

## Explicit runtime and provider ownership

Choose the explicit entrypoint
appropriate to the process:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => __DIR__]);
$cli = Foundation::cli(['base_path' => __DIR__]);
$worker = Foundation::worker(['base_path' => __DIR__]);
$scheduler = Foundation::scheduler(['base_path' => __DIR__]);
```

Assign providers explicitly by runtime in `bootstrap/providers.php`:

```php
return [
    'common' => [],
    'web' => [],
    'cli' => [],
    'worker' => [],
    'scheduler' => [],
];
```

Application providers now contribute native InterMix 11 graph definitions
before `build()`. Use native InterMix `factory()` lifetimes, `input()`, declarative providers,
and the immutable graph. Do not mutate finalized service definitions. Do not use protected
InterMix repository introspection to make application policy decisions.

Webrick owns web request scopes. Foundation owns CLI, worker and scheduled
execution scopes. Request/job-scoped services must not be resolved from the
root container. Parent worker processes must fork before opening process-bound
resources. Authentication, session and database state must reset between
successive requests/jobs.

## Passed Runwire context and native operations

Core integration accepts the host's exact `RuntimeContext`,
optional `RequestContext` and optional `CoroutineScope` as execution inputs.
The compiled web host accepts the passed runtime through
`WebReleaseRuntime::runwireApplication()`. Foundation forwards the active native
context to ordinary DBLayer connections and Omnibus bus/consumer/worker services,
including captured Foundation execution scopes attached with
`ExecutionScope::withinBorrowedScope()`.

Use native connection queries and `MessageBus::dispatch()` directly. The earlier
Foundation `DBLayerFactory::withConnection()` and
`MessagingRuntimeResolver::dispatch()` entrypoints are removed. CacheLayer owns
context sharing/restoration, while DBLayer and Omnibus own their cancellation
checks. Hosts retain request completion, child-task lifetime, workers and event
loops. Without an active context, native synchronous behavior applies.

## Custom authentication-session stores

Implementations of `Auth\Contract\Storage\SessionStoreInterface` must implement
`rotate(AuthSession $current, AuthSession $replacement, int $now): bool`.
Atomically replace only the unchanged, unexpired source session. Return `false`
after revocation, expiry or a competing update; an insertion failure must preserve
the original session. `SessionManager` rejects a failed transition. This contract
concerns authentication sessions; browser-session storage has its own contract.

## Configuration and modules

Compiled artifact selection belongs to the immutable release generation,
not to mutable per-request configuration. Use current `resources/config/`
templates as the authoritative available keys.

Keep production `app.capabilities` explicit. Installed optional packages
alone must not activate production capabilities. Configuration templates
under `resources/config/` are canonical; module publication must preserve
existing application files unless an explicit force replacement is requested.

Inspect public module groups and plan **explicit qualified features**, using schema-v2 machine output where needed:

```bash
php infbyte module:list
php infbyte module:show data
php infbyte module:doctor execution.messaging
php infbyte module:plan security --feature=mfa
php infbyte module:plan data.database
php infbyte module:install data.database --dry-run
php infbyte module:install data --feature=database
php infbyte module:enable data.database
php infbyte module:disable data.database
php infbyte module:repair data.database
php infbyte module:config:publish execution.messaging
php infbyte module:schema:status execution.messaging
php infbyte config:validate --production
php infbyte app:ready
```

The former public 11-entry module names, including `module:install database` and `module:plan auth --feature=otp`, **are removed**, not compatibility aliases. `module:list`, show, plan and lifecycle JSON use `schema_version: 2`. A bare group can be inspected but cannot install, enable or remove all of its features implicitly. Composer package installation never enables a feature; `module:enable` is explicit. `modules.features` provides the selected feature flags, and required conditional prerequisites must also be selected. Review direct versus transitive package ownership and preserve existing application configuration and durable state before removing packages. `security.mfa` with `auth.drivers.mfa=otp` requires explicit `security.crypto`; `execution.messaging` with durable transport requires `data.database`. See [the canonical v2 CLI catalog and lifecycle examples](modules.md).

## Rebuild trusted release artifacts

When booting a compiled generation, supply
`INFOCYPH_FOUNDATION_RELEASE_ROOT` and
`INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256` as process environment values.
`FoundationReleaseBootstrap::fromEnvironment()` reads only `getenv()`:
assignments to `$_ENV` or `$_SERVER` are not deployment configuration and
cannot override the release identity. Configure them at the service/process
manager boundary before PHP begins handling requests.

Build a single immutable Foundation 4 generation from normalized configuration and
the declared capability topology. The release must contain a matching
InterMix graph, Webrick 6 format-3 router manifest, non-web compiled
artifacts, worker provider topology and verified release identity.

Production may not fall back to project provider, route, config or source
discovery when an artifact is missing or invalid. Reject corrupt, tampered,
stale or mismatched manifests; never silently accept an unsupported Webrick release manifest.

InterMix 11.2.1 provides native source-free compiled scoped inputs (ABI 3).
Foundation's generated web, CLI, worker and scheduler release identity, scoped
seed isolation and prevalidated loaders must still pass exact-release QA
before production deployment is certified.

## Preserve data and verify

Using current Foundation 4 schemas and selected native owners, verify state
integrity and recovery for auth/session/token records, OTP/passkeys, durable
queues, outbox and cache replay state. Test isolated backup/restore and
recovery with the configured service topology; transformation of earlier
major-version deployment data is outside Foundation 4 release acceptance.

At minimum, verify real MySQL/PostgreSQL and Redis/Valkey/Memcached where
selected; FPM and a supported persistent host; fork/cancellation/reset
behavior; and generated/fused/sharded routing modes with preserved trust
and signed URL behavior. Do not infer production safety from passing unit
tests or a microbenchmark alone.

Run `composer ic:release:guard` and the full Security & Standards matrix
(PHP 8.4/8.5, lowest/stable dependencies, clean installs, consumer checks
and service-backed integration). Track exact source SHA, dependency versions
and CI links. Do not merge, tag or publish Foundation 4 until all
applicable release gates are accepted. See the
[host certification procedure](foundation-4-host-benchmark-certification.md) and
[QA workflow](../.github/workflows/security-standards.yml) for required evidence.


## Runnable candidate bootstrap examples

The checked-in [CLI smoke](examples/foundation-4-core.php) and
[Runwire worker input smoke](examples/foundation-4-runwire-worker.php)
are runnable in a checkout with compatible installed packages:

```bash
php docs/examples/foundation-4-core.php
# Core Runwire context forwarding:
php docs/examples/foundation-4-runwire-worker.php
```

These are *development* smoke checks; real hosted Runwire HTTP requires a
trusted compiled Webrick release and the host-supplied writer/context.

## Infbyte application adoption checklist (after Foundation 4 publication)

This section is for the **downstream Infbyte application**, not Foundation's own
release process. Do not change Infbyte's dependency graph or deployment artifacts
against an unpublished Foundation candidate. Foundation and its independent
production consumers must first pass the release gates and publish Foundation 4.

1. **Inventory and preserve the deployed application.** Record the application
   commit, `composer.lock` SHA-256, PHP/extensions/INI, hosting driver,
   selected optional features, enabled capability topology, generated release
   identity, and current data/schema versions. Back up database tables,
   cache/session state, auth tokens and MFA/passkeys, durable queue/outbox/failure
   data and any filesystem-backed storage. Verify backups can be restored in an
   isolated deployment before modifying dependencies.
2. **Update the application-owned Composer graph.** Require the published
   Foundation 4 stable release and each *selected* optional package directly,
   using the versions in [the dependency matrix](foundation-4-library-upgrade-plan.md).
   Resolve the application's full graph and commit its new lockfile. Do not
   install DBLayer, Omnibus, OTP, Pathwise, ReqShield, Epicrypt or TalkingBytes
   just because a package appears in Foundation's `require-dev`. PHPForge
   remains a development dependency, not a production dependency.
3. **Remove excluded API and configuration usage.** Replace Runwire 2 context,
   old InterMix/DBLayer/Omnibus service calls, old module aliases and JSON-v1
   feature identifiers with the current contracts in
   [the API replacement map](foundation-4-api-map.md). Treat missing explicitly
   selected packages as actionable errors. Do not add compatibility shims,
   automatically enable installed features, or silently substitute synchronous
   behavior after a stale/cancelled native context.
4. **Select and validate exactly the application's features.** Inspect
   `php infbyte module:list`, `module:show` and `module:doctor`; plan and
   enable qualified IDs such as `data.database` or `execution.messaging`
   individually. Review conditional requirements (for example, durable
   messaging needs database ownership; OTP-backed MFA needs its declared
   crypto feature). Run `php infbyte config:validate --production` and
   `php infbyte app:ready` using the target deployment configuration.
5. **Rebuild Composer autoloaders and all generated releases.** Run
   `composer dump-autoload --optimize` in the application deployment and
   rebuild trusted web, CLI, worker and scheduler generations using the
   published Foundation 4 compiler contract. Never reuse a Foundation 3
   generation or override dependency-fingerprint rejection. Verify that the
   immutable manifest and the resolved dependency lock describe the same
   deployment and that source-free loading works before activating the new
   generation. See [compiled release example](examples/foundation-4-compiled-release.php).
6. **Qualify the actual host, including the no-Runwire path.** Exercise ordinary
   PHP/FPM/index.php without a native loop or supervisor and, **if the
   application uses one**, its selected Runwire-backed persistent host.
   Confirm host-owned context identity, timeouts, cancellation, nested scopes,
   request/job state reset and graceful worker replacement. Test every
   selected integration through real application requests and jobs, not merely
   by checking that its class autoloads.
7. **Rehearse retained-state migration and rollback.** Restore a production-like
   snapshot, run the documented application migrations, and verify session,
   account, OTP/passkey, key-rotation, cache, durable queue/outbox and filesystem
   read/write semantics. Roll back a *coherent* application source, Composer
   lock, compiled generation, configuration and data snapshot, rather than
   switching only PHP source while leaving incompatible state behind. Record
   recovery evidence using the
   [retained-data rehearsal guide](foundation-4-retained-data-rehearsal.md).
8. **Stage, measure and approve separately.** Check application startup,
   response correctness, tail latency, successful RPM, resource growth and
   worker recycle/soak for the actual host and selected backends. Keep the
   final Infbyte application deployment decision independent of Foundation's
   already-published library release. Preserve results and the rollback
   decision as application-owned artifacts.

The checklist is a downstream *adoption procedure*, not a declaration that
any Infbyte migration or retained-data rehearsal has been executed.

## Foundation-first release boundary

Foundation 4.0 is implemented and verified independently of the Infbyte application skeleton. Integrating an Infbyte application is a **separate follow-up after Foundation is ready**. The Foundation CI consumer matrix verifies core-only, database-only and published optional-package/Runwire deployments without cloning or rewriting Infbyte. The [API replacement map](foundation-4-api-map.md) remains the downstream migration reference. Operators must still validate their own selected production recovery and durable-state procedures; unit/synthetic fixtures alone do not certify external deployments.

## Production no-dev compiled-release smoke

In a production-like Composer consumer containing the selected published specialist packages and Core Runwire, run:

```bash
php /path/to/Foundation/docs/examples/foundation-4-compiled-release.php
```

The smoke builds a disposable trusted SHA-256 generation with native compiled Webrick web routes and CLI/worker/scheduler graphs; it dispatches HTTP and verifies an exact host-owned Runwire worker input. CI executes this through the four **full-optional --no-dev** matrices. It is not a replacement for a real application deployment or retained-data restoration.
