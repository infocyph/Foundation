# Foundation 4.0: setup and runtime contracts

**Status:** setup and production-runtime guide for the Foundation 4.0 candidate
on draft PR #20, not a publication announcement. Foundation 4.0 is a deliberate
breaking major release; prior-version API signatures, legacy configuration,
schema conversions and downgrade support are outside its release acceptance.

Read [architecture](architecture.md), [modules](modules.md),
[configuration](configuration.md), the
[current 4.0 public/provider/configuration contract](foundation-4-api-map.md),
and [4.0-native state integrity and recovery](foundation-4-retained-data-rehearsal.md)
before production deployment.

## Version and package requirements

Foundation 4 requires PHP 8.4+ and Composer 2. The candidate's direct production
dependencies are ArrayKit `^5.3.1`, CacheLayer `^4.0`, InterMix `^11.2`,
UID `^6.0`, and Webrick `^6.0.3`.

Select only required optional specialist modules and their current major floors:

| Purpose | Composer package constraint |
| --- | --- |
| Database/migrations | `infocyph/dblayer:^6.1` |
| Communication/HTTP/gRPC/email | `infocyph/talkingbytes:^2.3` |
| Messaging/queues/workers | `infocyph/omnibus:^3.0` |
| Filesystem/storage | `infocyph/pathwise:^4.2` |
| Validation | `infocyph/reqshield:^3.3` |
| Security/crypto | `infocyph/epicrypt:^3.2` |
| OTP-backed authentication | `infocyph/otp:^7.0` |
| Passkey ceremonies | `web-auth/webauthn-lib:^5.3.9` plus OTP |

The [module catalog](modules.md) owns optional feature and extension requirements.
Do not blindly install all optional modules. CacheLayer remains a Foundation
production dependency, not a separately installable module. Runwire 2.1.1 is
an optional host integration, not a Foundation production dependency.

**Before installing Foundation 4**, confirm all required
published versions are resolvable by Composer/Packagist. In particular,
Webrick 6.0.3 requires InterMix 11.2 and its compiled ABI 3; generate a fresh trusted InterMix/Webrick release from the current 4.0 source. Do not work
around missing package-index versions with transient dev aliases for release QA.

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

## Configuration and modules

Compiled artifact selection belongs to the immutable release generation,
not to mutable per-request configuration. Use current `resources/config/`
templates as the authoritative available keys.

Keep production `app.capabilities` explicit. Installed optional packages
alone must not activate production capabilities. Configuration templates
under `resources/config/` are canonical; module publication must preserve
existing application files unless an explicit force replacement is requested.

Review selected module status, configuration and readiness:

```bash
php infbyte module:list
php infbyte module:show database
php infbyte module:doctor messaging
php infbyte module:plan auth --feature=otp
php infbyte config:validate --production
php infbyte app:ready
```

Use `module:install` and `module:config:publish` only for chosen
capabilities. Review package ownership (direct versus transitive) before
removing modules. Preserve application-authored configuration.

## Rebuild trusted release artifacts

Build a single immutable Foundation 4 generation from normalized configuration and
the declared capability topology. The release must contain a matching
InterMix graph, Webrick 6 format-3 router manifest, non-web compiled
artifacts, worker provider topology and verified release identity.

Production may not fall back to project provider, route, config or source
discovery when an artifact is missing or invalid. Reject corrupt, tampered,
stale or mismatched manifests; never silently accept an unsupported Webrick release manifest.

InterMix 11.2 provides native source-free compiled scoped inputs (ABI 3).
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
applicable release gates are accepted. The detailed acceptance tracker is
[`foundation-4.0-release-audit-and-plan.md`](plans/foundation-4.0-release-audit-and-plan.md).


## Runnable candidate bootstrap examples

The checked-in [CLI smoke](examples/foundation-4-core.php) and optional
[Runwire worker input smoke](examples/foundation-4-runwire-worker.php)
are runnable in a checkout with compatible installed packages:

```bash
php docs/examples/foundation-4-core.php
# Only if optional Runwire was installed/selected:
php docs/examples/foundation-4-runwire-worker.php
```

These are *development* smoke checks; real hosted Runwire HTTP requires a
trusted compiled Webrick release and the host-supplied writer/context.

## Foundation-first release boundary

Foundation 4.0 is implemented and verified independently of the Infbyte application skeleton. Integrating an Infbyte application is a **separate follow-up after Foundation is ready**. The Foundation CI consumer matrix verifies core-only, database-only and published optional-package/Runwire deployments without cloning or rewriting Infbyte. The [API replacement map](foundation-4-api-map.md) remains the downstream migration reference. Operators must still validate their own selected production recovery and durable-state procedures; unit/synthetic fixtures alone do not certify external deployments.

## Production no-dev compiled-release smoke

In a production-like Composer consumer containing the selected published specialist packages and optional Runwire, run:

```bash
php /path/to/Foundation/docs/examples/foundation-4-compiled-release.php
```

The smoke builds a disposable trusted SHA-256 generation with native compiled Webrick web routes and CLI/worker/scheduler graphs; it dispatches HTTP and verifies an exact host-owned Runwire worker input. CI executes this through the four **full-optional --no-dev** matrices. It is not a replacement for a real application deployment or retained-data restoration.
