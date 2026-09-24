# Foundation 2.0 through 2.x → 3.0 migration

Foundation 3 replaces the mutable/fallback runtime architecture with one
builder-first composition model and immutable generated production releases.
This guide covers the cumulative changes an Infbyte/Foundation host must adopt
from 2.0 through the 2.x series, not only the difference from 2.1.1. The 2.0
baseline is tag `2.0`, commit `a64715a9c1a0df8305cb9123ca8de8f2e13390ea`.
A direct upgrade does not require deploying each intermediate version, but all
applicable API, configuration and persisted-data changes must be rehearsed.

## Cumulative upgrade inventory

The four explicit `Foundation::web/cli/worker/scheduler()` entry points already
existed in 2.0 and remain. Authentication, browser sessions, the CLI and the four
built-in module entries also already existed; 3.0 changes their integration and
lifecycle contracts rather than introducing them for the first time.

| Area | 2.0 contract / usage to inspect | 3.0 action |
| --- | --- | --- |
| Providers | `ServiceProviderInterface::register(Application)` and `$app->register()` | Implement `contribute(ContainerBuilder, FoundationBuildContext)` and list providers in the runtime topology before graph composition; `boot()` cannot add definitions |
| Provider helpers | Protected `bindFactory`, `bindRecipe`, `hasExplicitBinding` on `ServiceProvider` | Contribute definitions with the native InterMix builder API; review factory lifetimes and generated-container compatibility |
| Container access | Mutable `$app->container()` used during application execution | Keep mutation in development/build composition; resolve runtime services through `make()` or `runtime()` |
| Production caches | `ContainerCacheManager`, `RouteCacheManager`, `RouteCachePath` and independent cache switches | Rebuild one immutable Foundation release generation; do not load old generated files |
| Cache installation | Optional CacheLayer and `cache`/`cachelayer` module operations | CacheLayer becomes a direct runtime dependency; keep activation explicit and use core cache schema commands |
| Module features | Installing `auth` brings OTP and WebAuthn; notifications alias communication | Select auth features explicitly; retain native notification config independently of communication |
| Validation adapters | `Foundation\Validation\ReqShieldDatabaseProvider`, `ValidationSchemaRegistry` | Use Foundation's configured validation services and ReqShield-owned DBLayer/schema contracts; remove imports of deleted Foundation adapters |
| Passkeys | Foundation-owned WebAuthn ceremony classes | Use Foundation's auth/passkey services backed by OTP; review custom drivers and migrate stored credential metadata through the auth schema lifecycle |
| Tokens and cryptography | Epicrypt 2.x, Foundation `HmacTokenCodec`, application-issued credentials | Adopt configured Epicrypt 3.1 services; explicitly decide whether each existing token/session/key format is retained, migrated or revoked; do not assume wire compatibility |
| Filesystem | Pathwise 3.x integrations | Review custom adapters, paths, uploads and download policy against Pathwise 4.1; preserve application storage data |
| Queues/workflows | Omnibus 2.5 durable readers/writers | Follow the documented 2.6 cutover before allowing new-format writes; code rollback alone cannot restore old readers |
| Runtime state | `RuntimeContextTracker` or app-held mutable user/job state | Use scoped services and stable execution boundaries; verify cleanup under concurrency |

This inventory identifies migration boundaries; it is not a claim that every
2.0 application or persisted format is automatically compatible. Search your
application and extension packages for these old APIs, and test the replacement
against both development and generated production runtimes.

## Persisted data and rollout order

1. Record the application's current Foundation and specialist versions,
   configured drivers, auth/session tables, key identifiers and queue codecs.
   Back up state and verify restoration before schema or format changes.
2. Migrate custom providers and configuration in a staging copy. Preserve
   application config; do not bulk-overwrite published files with defaults.
3. Inspect and provision applicable auth, browser-session, messaging and core
   cache schemas. Use the existing schema commands and additive migrations;
   do not drop tables or silently reset active accounts/sessions.
4. Rehearse existing password verification, refresh/revocation, MFA/passkey
   login and queued payload consumption. Record explicit invalidation and user
   reauthentication requirements for formats that cannot be retained.
5. Follow the [Omnibus 2.5 → 2.6 durable cutover](messaging.md#omnibus-25--26-durable-cutover).
   Do not mix 2.5 readers with new wrapped payload writes. Retain legacy codecs
   for old rows as documented; assess data rollback separately from code rollback.
6. Build fresh release artifacts and switch the trusted generation through the
   deployment entry point. Drain/restart old HTTP, worker and scheduler
   processes so incompatible generations do not keep sharing mutable state.
7. Verify the rollback rehearsal, including schema/key/payload restrictions,
   before production activation. Keep a documented recovery path when an old
   binary can no longer read newly written data.

See [authentication](authentication.md), [OTP schema upgrades](otp.md),
[OAuth/OIDC](oauth-2.1.md), [browser sessions](browser-sessions.md), and
[filesystem integration](filesystem.md) for the current domain contracts.

## Dependency baseline

Foundation 3 currently requires:

- PHP `^8.4`;
- InterMix `^10.1.1`;
- Webrick `^5.4`;
- ArrayKit `^5.2`, CacheLayer `^3.4`, UID `^5.0`, PSR Log `^3.0.2`;
- Composer runtime API `^2.0`.

Optional integration floors are DBLayer `^5.1`, Epicrypt `^3.1`, Omnibus `^2.6`,
OTP `^6.1`, Pathwise `^4.1`, ReqShield `^3.2`, TalkingBytes `^2.1`, and WebAuthn
`^5.3.9` when passkeys are selected. A consuming application must require the
optional packages it uses; Foundation's `require-dev` does not install them for
consumers.

Compared with 2.0, this includes major upgrades from InterMix 9.x, Webrick 4.x,
Epicrypt 2.x and Pathwise 3.x. Review custom use of those native APIs alongside
the Foundation changes.

`infocyph/phpforge` remains the development QA source at `dev-main@dev`.

## 1. Select the runtime explicitly

Choose exactly one Foundation runtime at bootstrap:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web($config);
$cli = Foundation::cli($config);
$worker = Foundation::worker($config);
$scheduler = Foundation::scheduler($config);
```

Foundation never infers application runtime policy from `PHP_SAPI`.

## 2. Move provider binding work to graph contribution

Providers contribute deterministic definitions to InterMix `ContainerBuilder`
before production compilation. Do not depend on `boot()` mutating the DI graph or
on late production provider activation.

The concrete mutable InterMix development `Container` is a composition-time
identity only. Production code must resolve from the generated
`ProductionContainer` and must not require mutable-container access.

Prefer InterMix recipes, service references, aliases, and explicit descriptors
over closure factories. Any remaining dynamic boundary must be intentional and
visible in compile/skipped reporting.

## 3. Use the Webrick production path for HTTP

Production web routing is compiled once through Webrick's coordinated release
compiler. Route registration, `Registrar`, mutable route collections, and route
source files are build/development concerns only.

Webrick owns:

- compiled route matching and execution plans;
- Request creation;
- `webrick.request` scope entry/leave;
- routing-control errors and application exception dispatch;
- runtime adapter selection;
- native response emission.

Do not add a Foundation outer HTTP scope or a second response emitter. A minimal
compiled route is intentionally allowed to remain Request-free and scope-free.

## 4. Use generated InterMix runtimes for non-web work

CLI, worker, and scheduler containers compile directly through InterMix and are
reused for the process/runtime lifetime.

Do not move these generated containers into CacheLayer. InterMix's generated PHP
artifact is the production cache: PHP/OPcache executes it directly, while
InterMix validates its own native `.meta.json` sidecar. CacheLayer remains core
Foundation infrastructure for application caching, coordination, counters,
node/cluster caches, and any explicitly selected PSR-6 definition cache on a
dynamic graph. Foundation does not enable InterMix definition caching
automatically for generated production containers.

One execution unit enters the corresponding stable semantic scope only when it
executes scoped work:

- `foundation.cli`;
- `foundation.worker`;
- `foundation.scheduler`.

Execution IDs and job/message/schedule context are scope seeds rather than scope
names. Persistent worker/scheduler processes must not retain principal, session,
DB transaction, message, or other execution-local state between units.

## 5. Deploy one immutable Foundation generation

A production release generation now owns all four runtime identities together.
The generation includes:

```text
foundation.php
config.php
web/
  container.php
  router.php
  release.json
cli/
  container.php
  container.php.foundation.json
worker/
  container.php
  container.php.foundation.json
  providers.php
scheduler/
  container.php
  container.php.foundation.json
```

Exact subordinate metadata files are compiler-owned; applications should not
construct artifact identities themselves.

Foundation stages and verifies the complete generation before atomically
switching the active pointer. If any compile/verification step fails, the
previous generation stays active.

Production release loading consumes the generation-owned `config.php` snapshot
and generated topology/artifacts. It does not rediscover project config,
providers, or routes and does not silently fall back to the mutable/source
runtime.

## 6. Remove obsolete configuration

Delete these old keys from application configuration:

```text
app.container.alias
app.container.compiled
app.container.compiled_activation
router.cache
```

They no longer select runtime behavior. Production artifact paths and trust
identities belong to the Foundation/Webrick release manifests.

The remaining InterMix composition controls are development/build concerns:

```text
app.container.environment
app.container.lazy_loading
app.container.debug_tracing.enabled
app.container.debug_tracing.level
```

Development/build configuration caching remains separate from production release
artifacts. Foundation 3 delegates both supported layouts to ArrayKit:

- `app.config_cache.type=sharded` uses ArrayKit's native lazy namespace cache
  and `__flat.php` exact-leaf index;
- `app.config_cache.type=single` uses ArrayKit's native whole-config
  `exportCache()/loadCache()` artifact at `bootstrap/cache/config/config.php`.

Do not recreate a host-level config serializer or introduce a routing-style
`fused` config mode; ArrayKit's sharded cache already carries the fused leaf
index. Foundation's `__manifest.php` is policy/identity metadata, not a second
copy of the complete config payload.

## 7. Make production capabilities explicit

Production compilation/loading uses an explicit capability topology. An omitted
capability set is minimal; it no longer means “activate every optional package
that Composer happens to have installed.”

This keeps Foundation's core CacheLayer-backed cache capability plus optional
DBLayer, messaging, communication, security, filesystem, validation, OTP, and
WebAuthn infrastructure cold when not selected.

Development composition may still discover installed packages when no explicit
topology is supplied.

## 8. Preserve primary failures during cleanup

Application/job/schedule failures take precedence over failures from cleanup.
Foundation attempts owned cleanup (scope state, DB rollback/disconnect, locks,
process registry, signal restoration, temp resources) without allowing a later
cleanup exception to replace the original work exception.

Application integrations should follow the same rule.

## 9. Do not recreate Foundation runtime lifecycle in InfByte

InfByte should provide host/application policy—configuration, providers, routes,
commands, worker definitions, writable paths, and deployment UX—then consume
Foundation's runtime/release APIs directly.

Do not add another container compiler, route cache, universal HTTP scope,
provider activation layer, or response emitter in the application skeleton.

## 10. Migrate to the hardened specialist-module lifecycle

Foundation 3's package-backed module vocabulary is limited to `auth`,
`communication`, `database`, `filesystem`, `messaging`, `security`, and
`validation`.

Important migration changes:

- CacheLayer is core Foundation infrastructure; remove any `cache` or
  `cachelayer` module install/remove assumptions.
- `notifications` is Foundation native and is no longer an alias for
  `communication`. Communication owns only `communication.php`; existing
  `notifications.php` remains application/native-notification config.
- Bare `module:install auth` no longer broad-installs OTP plus WebAuthn.
  Request `--feature=otp` or `--feature=passkey` explicitly.
- Package presence is not direct module ownership. Automation must inspect
  direct/transitive/ownership-unknown state rather than inferring from
  `InstalledVersions` alone.
- Installation no longer writes activation policy. Use `module:enable` and
  `module:disable`; module-system overrides live in `config/modules.php`.
- Production still requires a complete explicit `app.capabilities` topology.
  Partial module overrides do not replace it.
- Aggregate `module:schema:sync` follows active capabilities only. Use
  targeted `module:schema:install <module>` for an explicit inactive-module
  schema mutation.
- Composer mutation no longer forces `--update-no-dev`; normal application dev
  dependency state is preserved.
- Removal is fail-closed and dependency-aware. Disable first; config and data
  are never removed automatically.
- Use `module:plan` before mutation, `module:doctor` for readiness, and
  `module:repair` to resume partial installations.

Machine-readable module/list/show/plan payloads now expose schema-versioned
state. Consume named fields and tolerate additive fields rather than parsing
human table output.

## Historical runtime-redesign benchmark evidence

These historical measurements do not qualify the new 3.0 dependency floors or
replace current candidate verification. Phase 9 measured Foundation against the
lower layers after the runtime redesign.
The values below are acceptance evidence from the canonical Foundation 3 runtime
plan; compare future results only under matching environments.

### Generated DI — PHP 8.4

- direct InterMix resolution: **221.8 ns median**;
- Foundation generated-container resolution: **218.67 ns median**;
- `Application::make()`: **240.67 ns median**;
- bare InterMix `withinScope()`: **1,404.21 ns**;
- Foundation execution boundary: **5,113.29 ns**;
- additional Foundation execution-boundary cost: about **3.709 µs**.

The façade/container cost is tens of nanoseconds; no additional DI optimization
was justified.

### Compiled HTTP — PHP 8.5

- standalone compiled Webrick: **4,570.43 ns/request**;
- Foundation compiled web: **4,614.67 ns/request**;
- attributable warm-request delta: **44.24 ns / 0.97%**;
- both processes: **8 MiB final / 10 MiB peak** memory in the attribution run.

The measured Foundation hot-path tax was approximately one percent, so the
accepted decision was to keep the simpler lifecycle/scope semantics rather than
introduce a deoptimization-prone shortcut.

### Real PHP-FPM + OPcache — PHP 8.4.25

20,000 requests at concurrency 32 completed with zero failures:

| Server | Throughput | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: |
| Nginx 1.24 | 2,089.04 req/s | 15 ms | 22 ms | 26 ms |
| Apache 2.4.58 | 1,782.89 req/s | 17 ms | 25 ms | 29 ms |

Cold-first/warm-sequential latency and aggregate PHP-FPM pool RSS are retained in
the Phase 9 benchmark artifacts. RSS in that evidence is aggregate per-process
RSS and must not be interpreted as unique physical memory.

The corrected Phase 9 acceptance workflow was run `33946071733` on commit
`7ce759d36e71e469314fdfa0ca54f75f24e78d9c` and covered PHP 8.4/8.5 stable and
prefer-lowest QA, analyzers, clean install, representative/maintenance/DI/HTTP
benchmarks, and the real PHP-FPM server gate.

## Migration checklist

Before shipping an InfByte/Foundation 3 host:

- choose web/CLI/worker/scheduler explicitly;
- migrate provider binding work to builder contribution;
- remove mutable-container assumptions from production services;
- remove the four obsolete config keys above;
- compile one immutable Foundation release generation;
- pass explicit production capability topology;
- boot HTTP through the Webrick release runtime;
- boot CLI/worker/scheduler from generated InterMix artifacts;
- ensure long-running execution state is scoped and cleanup preserves primary
  failures;
- verify no host-layer route cache, container compiler, universal web scope, or
  native emitter duplicates Foundation/Webrick/InterMix ownership;
- run the full release guard and representative benchmarks in the target
deployment environment.
