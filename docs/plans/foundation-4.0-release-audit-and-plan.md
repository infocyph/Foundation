# Foundation 4.0 audit and release plan

Audit date: 2026-10-11. Target: **Foundation 4.0**, upgrading directly from
published Foundation 2.1.1 and skipping publication of Foundation 3.

**Release status: blocked.** ArrayKit 5.3.1 resolves the audited numeric-path
issue and Foundation now requires that corrective version. Broader InterMix,
Webrick, CacheLayer and specialist-library upgrades, application/host runtime
redesign, security verification, and immutable release certification remain open.
Unsafe symlink traversal in the historical ArrayKit acceptance branch is not adopted.

This document records the audit and the work still required. It does not certify
the library as vulnerability-free. Production source, dependency requirements,
and branch integration have not been changed by this audit.

## Engineering contract

Follow [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md)
throughout implementation. Correctness, security, public contracts, data
integrity, and lifecycle ownership precede throughput. Foundation owns application
composition and policy; specialist libraries own their mechanisms.

**Foundation is to Infbyte what Illuminate is to Laravel.** Foundation provides
the reusable framework services, application lifecycle, built-in application
features, and extension contracts; Infbyte supplies the application skeleton,
bootstrap/configuration, and application-specific code. This relationship does
not require copying Illuminate's classes, facade system, or internal architecture.

**No specialist mechanism may be duplicated in Foundation.** Use its native owner
and retain only the plugin/integration needed for framework configuration, policy,
persistence mapping, lifecycle, and application ergonomics. This is a release
requirement, not optional cleanup. A differently named implementation, legacy
fallback, or inactive production class still counts as duplication. Where a
native owner lacks a required mechanism, fix or extend that owner and consume its
published API; do not maintain a second implementation in Foundation.

Prefer cohesive methods and private helpers over additional wrappers, interfaces,
or pass-through files. A framework service must add meaningful application policy,
workflow, or integration; a generic forwarding facade does not establish a new
ownership boundary. Do not edit vendor code, add quality suppressions, weaken test gates,
or lower complexity thresholds to make the release pass. Keep the current
function/class/dependency-tree budgets of 12/80/120.

The user explicitly authorizes a broad breaking redesign across Foundation's
public surface, namespaces, contracts, providers, built-in services, and internal
structure. Existing code and backward compatibility are not design constraints
for 4.0. Keep only responsibilities justified by the complete framework design;
remove or replace obsolete APIs and implementations rather than defaulting to
compatibility aliases. Preserve security/data-integrity requirements and identify
persisted-data consequences separately from source compatibility. Document the
new contracts and migration; avoid speculative layers even within this broader
scope. Separate required changes below from optional improvements.

### Library upgrade implementation standard

The following rules govern the actual implementation, not only this document's
wording. The objective is **100% compatibility with the updated libraries across
Foundation's complete framework and integration contracts**, with no missing
required behavior and no unnecessary additional surface. A small diff, an
almost-compatible adapter, or passing a subset of the old tests is not success.
Change size is not an acceptance criterion; redesign or replace whole integration
surfaces where required to adopt the native contracts correctly.

For every updated library, review its complete applicable public surface and
capabilities against every Foundation consumer, including built-in services,
plugins, providers, generated production artifacts, configuration, persistence
mappings, execution lifetimes, and Runwire composition. Use the latest published
native contracts directly. Updating Composer constraints, renaming removed
methods, or preserving legacy wrappers does not complete a library upgrade.

Build one compatibility record in this plan covering each package/version,
required capability, Foundation call path, native contract, failure/fallback
behavior, and verification evidence. Assess newly available capabilities as well
as current calls. Where a capability is outside Foundation's responsibility,
leave it with its native owner rather than adding a facade solely to mirror the
library API. Every applicable contract must be verified; an unresolved,
unsupported, or untested required contract blocks the compatibility claim.

Replace obsolete Foundation code and APIs outright where necessary. Do not add
legacy-method emulation, alternative implementations, generic wrappers,
speculative extension points, or extra class/method/function layers to keep old
code working. Every retained or added production symbol must implement an
explicitly required framework responsibility or plugin boundary. Remove
redundant symbols after consolidating their unique behavior. Tests must prove
the resulting native integration and framework behavior, not require the old
implementation shape to survive.

Verify the complete integration under every declared supported PHP version,
application mode, build/runtime path, module topology, backend and host contract,
including direct/intermediary Runwire propagation and ordinary capability
fallback. Use real backends where concurrency or persistence semantics require
them. Optional installation is not optional compatibility once a feature is
selected. Missing services, skipped cases, unchecked adapters, or one successful
example cannot stand in for full acceptance.

Record the precise published dependency set and immutable Foundation revision
for this evidence. Fix defects in the owning library and consume its published
corrective version; do not cover them with Foundation workarounds. Refresh the
matrix when a version changes. Claim 100% compatibility only after all required
entries and release gates pass; do not claim it from this audit or plan alone.

## Baseline and cumulative changes

| Reference | Verified revision | Meaning |
| --- | --- | --- |
| Published Foundation 2.1.1 | `f6749ebe0aa1f93c6b3586e8cb63ecc1f552d98b` | Required migration and comparison baseline |
| `main` / `origin/main` | `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` | All post-2.1.1 main changes are part of Foundation 4 |
| Current `feature/foundation-3` | `fc9b3258646ac744e61fd02e18d9b98e5afff2e8` | Also current remote main at audit time |
| `foundation-3/library-reuse-hardening` | `2f1e19e1f089e95d3a81fec62cda66a5cd1751d8` | 126 commits beyond current head; 65 changed files |
| `origin/arraykit-5.3/acceptance` | `8f1bc1097e0543f08209a226f1a76fe41459c82b` | Seven commits beyond current head; six changed files |

Both named branches fork from the current head. Their implementation evidence
must be revalidated after reconciliation with today's dependencies. Neither
branch has been imported during this audit.

The 2.1.1-to-current comparison spans 1,261 commits and 496 changed files
(42,104 insertions, 11,372 deletions). Production PHP source grew from 580 to
668 files; 378 current files belong to authentication. This is a cumulative
release, rather than a dependency bump. Review the complete tree diff alongside
public contracts, configuration, generated artifacts, persistence, and lifecycle
changes. File counts are scope evidence, not a reason to split or merge types.

Foundation's web, CLI, worker, and scheduler modes already exist in 2.1.1.
Runwire/FPM and other servers are host choices, rather than additional Foundation
application modes. The existing Foundation 3 migration, runtime, and evidence
documents are useful implementation history; their certification does not carry
over to Foundation 4.

Local 3.0 and 3.0.1 tags exist, but the remote has no 3.x tags and Packagist's
latest published Foundation version remains 2.1.1. Prepare one direct
2.1.1 → 4.0 migration. Do not publish an intermediate 3.x release as part of
this plan.

### Main changes since the last published tag

Foundation 4's audit and implementation starting point is the complete current
main tree, including every change after published 2.1.1. The two named branches
provide additional work to assess. Every change must be accounted for, but its
code/API may be retained, redesigned, delegated, or removed under the approved
breaking redesign. Do not accidentally discard a security invariant or recreate
an older defect when reconciling branches. Main and the working branch were
verified against the remote again on 2026-10-11 and have the same head.

The following inventory makes main's release scope explicit. It is based on
`git diff 2.1.1 main`, first-parent merges, current source, and tests; historical
Foundation 3 notes are supporting context, not the authoritative version list.
The inventory identifies responsibilities and security invariants to evaluate;
it does not freeze existing classes, signatures, or implementation structure.

| Main change group | Current source anchors | Foundation 4 responsibility and verification |
| --- | --- | --- |
| Builder-first provider and runtime composition | `Application/ServiceProviderInterface.php`, `Application/FoundationBuildContext.php`, `Container/FoundationGraph.php`, `Runtime/NonWebGraphFactory.php` | Preserve the move from `register(Application)` to graph `contribute(...)`, immutable boot semantics, and explicit mode isolation while adopting InterMix 11.1 |
| Unified production release generation | `Release/FoundationReleaseCompiler.php`, `Release/FoundationReleaseManifest.php`, `Release/FoundationReleaseRuntime.php`, `Release/FoundationReleaseBootstrap.php` | Preserve staged/serialized publication, deployment-controlled manifest trust, config/dependency identity checks, and generated web/non-web artifacts; rebuild for the new ABI |
| Generation lifetime and operations | `Release/ActiveGeneration.php`, `Operations/RuntimeControl.php`, `Worker/WorkerManager.php` | Preserve draining-generation leases, safe pruning/build coordination, worker replacement, readiness, activation, and rollback boundaries |
| Native config materialization and export validation | `Config/ArrayKitConfigCache.php`, `Config/ConfigLoader.php`, `Config/ConfigExportValidator.php` | Preserve native ArrayKit handling of delayed/lazy values and validated exports; reconcile 5.3 generations and the confirmed defects without reinstating a Foundation parser |
| Generation-owned HTTP matcher artifacts | `Routing/WebReleaseCompiler.php`, `Routing/WebReleaseConfiguration.php`, `Routing/WebReleaseRuntime.php` | Preserve generated/fused/sharded matcher publication and runtime loading through Webrick, contained cache paths, and native trust checks; verify missing/tampered/stale artifacts |
| Core cache and specialist module lifecycle | `Cache/CacheSchemaManager.php`, `Module/ModuleCatalog.php`, `Module/ModuleActivationManager.php`, `Module/ModuleStateResolver.php` | Preserve CacheLayer as core infrastructure, explicit installation versus activation, package/version/platform readiness, dependent-removal guards, and capability-aware schema execution |
| Authentication, OAuth/OIDC, MFA and passkey ownership | `Auth/Internal/`, `Auth/Adapter/Epicrypt/`, `Auth/Adapter/Otp/`, `Database/AuthSchema/` | Preserve native protocol/ceremony ownership, policy and persistence mappings, production key guards, secret protection, replay/rotation atomicity, and credential compare-and-swap; rehearse retained data |
| Browser session and execution state hardening | `Session/BrowserSession.php`, `Session/Middleware/SessionMiddleware.php`, `Runtime/CleanupGuard.php`, `Runtime/RuntimeExecutionState.php` | Preserve finite leases/waits, stale-owner mutation checks, scope isolation and cleanup; extend failure precedence to a throwing reporter |
| Notifications and optional communication | `Notifications/NotificationGraphFactory.php`, `Notifications/NotificationServiceProvider.php`, `Module/ModuleCatalog.php` | Preserve native notifications independently of optional TalkingBytes, statically compilable factories, and auth-delivery capability dependencies when adapting TalkingBytes 2.3 |
| Database, validation, messaging, filesystem and worker integration | `Database/`, `Validation/`, `Messaging/`, `Filesystem/`, `Scheduling/`, `Worker/` | Preserve native specialist bridges, durable/outbox and transaction boundaries, file/HTTP policies, and scheduler/worker execution lifetimes; apply latest APIs and passed Runwire contexts |

Main's first-parent history includes runtime-architecture integration, final
runtime readiness work, both close-26.6 merges, and PR #17. The latter main
integration also contains config materialization and generation-owned matcher
changes alongside notification compilation fixes. The range from local 3.0
commit `441ccd7` to current main alone changes 23 files (871 insertions,
125 deletions); it must not disappear when skipping the 3.x release name.

Existing release, generated-runtime, module, OAuth, session, and state-isolation
tests are evidence of required behavior for these main changes. Adapt meaningful
behavioral coverage to the new design and replace tests that enforce obsolete
APIs or duplicate implementations. Add missing boundary cases rather than
treating historical merged status or test names as current certification. Batch 1
reconciles branch intent with this inventory, and the final release gates cover
the combined tree.

## Complete framework ownership and public surface

Review all Foundation responsibilities before choosing the final 4.0 surface.
This is broader than upgrading integration calls. Built-in authentication,
authorization, sessions, notifications, console commands, scheduling, and
operations are first-class framework services where they provide unique
application behavior. Library reuse does not justify reducing Foundation to
bootstrap alone or moving reusable framework implementation into Infbyte.

For each production capability, record its native mechanism owner, the unique
Foundation responsibility, its proposed public API, configuration and persistence
contract, execution lifetime, and the disposition of existing code: retain,
redesign, delegate, or delete. Resolve the owner from actual published source and
contracts, not class names. Consolidate this record in this plan rather than
creating a second collection of overlapping architecture plans.

| Framework area | Foundation responsibility to design | Mechanisms delegated to native owners |
| --- | --- | --- |
| Application and extensions | Bootstrap, application topology, provider/plugin registration, module activation and application paths | InterMix graph construction, DI lifetimes, scope implementation and compiled container loading |
| Configuration and release operations | Application defaults/policy, trusted outer release identity, deployment activation/readiness and coordinated artifact publication | ArrayKit configuration parsing/materialization; each owner validates its own native artifacts |
| HTTP and presentation | Application route/middleware policy, auth/session placement, framework responses/resources and exception presentation | Webrick requests, routing/matching, middleware execution, HTTP semantics, response emission and native rate-limit bridges |
| Identity and authentication workflows | Accounts/devices, login/logout, lockout policy, password change/reset, verification, passwordless, remember-me, step-up and impersonation | Epicrypt passwords, purpose tokens, signing/encryption and supported token protocols; UID identifiers; CacheLayer atomic state; DBLayer persistence mechanics |
| Authorization | Principal resolution, roles/permissions, gates/policies, delegation and tenant/resource decisions | Native cache/database/identifier mechanisms; do not add a second policy engine if an installed owner already supplies the required behavior |
| Browser sessions | Browser state, flash data, cookie/session policy, route participation, regeneration/invalidation, commit and lifecycle coordination | CacheLayer locks/stores, DBLayer queries/transactions, Pathwise file operations where applicable, and one selected native CSRF token/verification implementation |
| Authentication sessions | Account/device login state, idle/absolute expiry, recent-auth/step-up state and revocation policy | Shared native persistence/atomicity mechanisms; unify framework identity and lifetime coordination without confusing it with browser key/value state |
| OAuth/OIDC, application tokens, MFA/passkeys | Account/client consent, application claims/policy, schema/store mapping, enrollment/recovery workflows and HTTP presentation | Epicrypt protocol/token/password mechanisms; OTP challenge/replay/grid/passkey ceremonies; native security-store contracts |
| Data, validation and filesystem | Application connection/store/profile selection, schema lifecycle, form/request policy, upload/download authorization and response integration | DBLayer SQL/transactions/schema execution; ReqShield validation/sanitization/database bridge; Pathwise storage/transfers/archive processing |
| Notifications and communication | Notification recipients/templates/channels, framework delivery policy and profile composition | TalkingBytes HTTP/email/webhook/gRPC transport, parsing, retries and bounded request pools |
| Events, jobs, schedules and console | Application handlers/commands, schedule definition and execution policy, execution context and framework observability | Omnibus dispatch/durable messaging/retry mechanics; CacheLayer leadership locks; Runwire host supervision and supported concurrency |
| Runtime and persistent lifecycle | Application/request/task context propagation and cleanup of Foundation-owned resources | Runwire workers/listeners/event loops/capabilities/cancellation; Webrick HTTP scope; InterMix DI scope and child-context implementation |

### Built-in auth and sessions: review one complete flow

Review `AuthServices`, `Auth/Authentication`, `Auth/Authorization`, `Auth/Principal`,
`Auth/Mfa`, `Auth/Passkey`, `Auth/OAuth`, `Session`, and their providers/stores as one
composition. The current `Auth/Authentication/Session/SessionManager` and
`Session/SessionManager` represent different concepts; their public names and
coordination may change. Do not merge semantics just because both are called
sessions, or retain parallel uncoordinated principal/login state.

Specify the web flow from request and browser-session entry through credential
verification, native security checks, account/device/auth-session creation,
principal binding, authorization, session rotation/commit and response output.
Specify equivalent bearer/API, CLI, queue/job, and concurrent-task flows. Define
one execution-local principal and explicit transitions for logout/revoke,
suspension, impersonation, password changes, recovery and step-up. Evaluate
tenant isolation, race behavior, retry/idempotency, failures and cancellation at
every state transition. Persisted browser state, authentication state and issued
credentials need an explicit compatibility or migration decision.

Review password/token fallbacks and legacy OAuth implementations against Epicrypt
3.2; OTP/WebAuthn/passkey ceremony code against OTP 7.0; browser CSRF generation
and validation against Webrick/Epicrypt; cache/counter/lock code against
CacheLayer/Webrick; and all storage/validation/transport helpers against their
native owners. These are mandatory review candidates, not a claim that every
class in those directories is duplicate. Choose one owner per mechanism and
adapt its contract once. Do not stack equivalent validators, parse the same
immutable input twice, or execute two replay/rotation implementations.

An optional dependency being unavailable must not activate a duplicate
Foundation security/transport engine. Leave unselected features cold; for a
selected feature, require its native owner and fail clearly when unavailable.
Runwire capability fallback uses the native library's ordinary supported path,
with the same framework policy and security guarantees.

## Published dependency target

Versions were checked against [Packagist's package metadata API](https://packagist.org/apidoc)
and the exact released source archives, rather than uncommitted sibling trees.
These are the latest published versions **at the audit date**; refresh them before
freezing the release. An ArrayKit corrective release is required below.

| Package | Published version | Released source reference |
| --- | --- | --- |
| `infocyph/arraykit` | **5.3.1** | `e77bf72a40c64b94f62e69f3b2662ce2dc47efec` |
| `infocyph/cachelayer` | 4.0 | `58ae96dfe14ee45a528247c00c6a3d727160f833` |
| `infocyph/dblayer` | 6.0 | `85b9a6373628692f1bf74ba20a31649fad767f2a` |
| `infocyph/epicrypt` | 3.2 | `8b1cdf370a8676e536db96b7bd0ae66347141512` |
| `infocyph/intermix` | 11.1 | `b5954a41d96c885cfa14aba241cb6c760a3a6237` |
| `infocyph/omnibus` | 3.0 | `053e5550522236e660fd85a94cf34c0a39830c29` |
| `infocyph/otp` | 7.0 | `2cf37029cbc667a63b620aee4b45d0ffdbfc76a0` |
| `infocyph/pathwise` | 4.2 | `f37e99ae41fbb9ab5c632beef266f146a4dc662c` |
| `infocyph/reqshield` | 3.3 | `427601b27ad270bbac401643c9197d3b13862669` |
| `infocyph/talkingbytes` | 2.3 | `1096b05c143db1167a69fb1ea78ab1ece99f4004` |
| `infocyph/uid` | 6.0 | `759b8cdf21c5b5d204210b3701eec508ffd5c70b` |
| `infocyph/webrick` | 6.0 | `f5eeaafe075c2016dbe29bcaa5a827cf246189c7` |
| `infocyph/runwire` | **2.1.1 exactly** | `745b1c2bd7caa56aa5abf6d742c1aa8318fec494` |

Raise runtime dependency floors to the accepted latest stable releases and
exercise both lowest and freshly resolved stable combinations. Keep optional
libraries optional in production: update their development coverage, module
installation constraints, capability errors, and documentation together. Add
Runwire `2.1.1` exactly to development/integration verification; the ordinary
production installation must continue to work without Runwire installed.
Composer `suggest` descriptions do not enforce module installation versions.

Also revalidate other direct dependencies. The fresh candidate resolves
`psr/log` 3.0.2, `opis/closure` 4.5.0, and `web-auth/webauthn-lib` 5.3.9.
Required PHP extensions and 64-bit requirements must match enabled modules.

PHPForge is a development toolchain, not a production capability. Its latest
stable tag is 1.02, but this repository already uses `dev-main`; downgrading to
the older stable tag would discard current quality policy. Retain that contract
and record/pin the verified toolchain and reusable workflow revision for release
evidence. The disposable fresh resolution used PHPForge
`ddcc701f340c59efbbdc20eddee40389b101f740`.

## Audit evidence and limitations

| Check | Result | Interpretation |
| --- | --- | --- |
| Current Composer validation and stable runtime constraints | Passed | Existing metadata is internally valid; it still selects old major versions |
| Current dependency security audit | No advisories; abandoned `doctrine/annotations` | Abandonment is a development-toolchain maintenance issue, not an advisory; audit command exits nonzero |
| Current full local quality run | Deterministic source checks passed; Pest 464 passed / six failed | Required database/cache services unavailable locally; full suite is not green |
| Unsandboxed Psalm retry | No errors | Initial socket failure was environmental; retry completed successfully |
| 2.1.1 production smoke | All four application modes booted | Disposable install with recorded InterMix 9.2/Webrick 4.0.2; not a full historical lock/suite reproduction |
| Fresh latest-library candidate | Dependency resolution/install succeeded | 143 packages; no advisories; does not imply source compatibility |
| Latest-library candidate Pest | 215 passed / 255 failed | Many failures cascade from obsolete InterMix APIs; not 255 independent defects |
| Latest-library candidate PHPStan | 327 diagnostics | Includes missing/changed APIs and 38 complexity findings under the refreshed toolchain |
| Fresh hosted current-head verification | Failed | Source and test failures remain even when CI provisions services |

The local runtime was PHP 8.5.4. PHP 8.4 was not installed locally. Disposable
candidate tests were restored explicitly because production `git archive`
exports omit tests. Candidate dependency changes occurred only in `/tmp`.

The fresh hosted run is [38105311540](https://github.com/infocyph/Foundation/actions/runs/38105311540)
on current head `fc9b325`. Production consumer and benchmark jobs passed, but
quality jobs failed Rector; stable Pest reported 462 passed / eight failed;
analysis on PHP 8.4 and 8.5 reported 38 complexity diagnostics. Release evidence
was skipped. Earlier green runs at the same source revision do not supersede
fresh failures with newer dependencies/tooling.

Historical branch runs
[36202903395](https://github.com/infocyph/Foundation/actions/runs/36202903395)
and [37278415963](https://github.com/infocyph/Foundation/actions/runs/37278415963)
passed at their respective heads. They predate this combined release candidate
and do not cover the newly reproduced boundary defects.

The audit covered cumulative source/dependency changes, branch patches, ownership
and native integration contracts, existing tests/CI, and focused adversarial
probes. Real service concurrency, all enabled module combinations, native host
performance, and migration rehearsal remain explicit release gates. A clean
dependency advisory scan and static analysis cannot establish absence of source
vulnerabilities.

## Required findings

### F4-01 — migrate container composition before enabling latest dependencies

**Owner:** Foundation using InterMix 11.1/Webrick 6.0. **Priority:** release blocker.

`Application`, `NonWebGraphFactory`, service providers, and registration helpers
call removed InterMix builder methods such as `development`, `singleton`,
`scoped`, `transient`, `definitions`, `options`, and `registration`. Some runtime
operations also rely on methods that are now protected or removed.

Do not mechanically replace `development()` with `build()`: the new `build()`
finalizes the graph. Complete configuration, recipes, module/provider
contributions, aliases, and input declarations before finalization. Resolve
runtime services only afterward. Use native `factory`/`autowire` definitions with
explicit lifetimes, `definitionGraph`, and the public `RuntimeContainerInterface`
scope APIs. Keep `ScopeContext` opaque. Avoid a Foundation clone of the old DI API.

Acceptance: all four modes; development and compiled production graphs; custom
providers; lazy optional modules; scope reset; nested/concurrent execution;
artifact rejection/rebuild; actual production-only consumers. Public provider
and parameter changes need migration examples.

### F4-02 — fix published ArrayKit numeric config lookup upstream

**Owner:** ArrayKit. **Priority:** release blocker.

ArrayKit 5.3 `BaseConfigTrait::isReadCacheSafePath()` passes a numeric-string
segment into `ReflectionReference::fromArrayElement()` after `array_key_exists`
has accepted it as an integer array key. The reflection operation throws.

Minimal reproduction against the published release:

```php
$config = new \Infocyph\ArrayKit\Config\Config();
$config->loadArray(['app' => ['servers' => ['first']]]);
$config->get('app.servers.0'); // ReflectionException: Array key not found
```

This affects Foundation configuration and is already visible in fresh hosted
configuration/OAuth integration tests. Fix PHP array-key semantics in the native
owner, add numeric/nested/reference/missing-key regressions, and publish a
corrective ArrayKit patch. Foundation then consumes that latest published
release. No vendor edits, shadow parser, or Foundation getter override.

### F4-03 — reconcile ArrayKit generation caches without following symlinks

**Owner:** Foundation `Config/ArrayKitConfigCache`. **Priority:** required
compatibility fix plus conditional high-impact filesystem defect in the branch.

Current `writeSharded()` checks namespace files at the cache root, although
ArrayKit 5.3 publishes them under the active generation. A normal write throws
`Unable to finalize ArrayKit namespace config cache .../app.php` after publishing
the generation pointer.

The acceptance branch understands `.arraykit-generation`, generation directories,
and `.arraykit-flat.php`. However, `removeDirectory()` recursively follows
`is_dir()` symlinks and generation discovery can include symlink directories.
A probe linking `cache/.arraykit-gen-deadbeef` to an outside directory and calling
`writeSingle()` deleted the outside sentinel before throwing on link removal.
This requires a writable/planted cache link; it is not evidence of an
unauthenticated remote exploit.

Reuse native generation APIs where available. Validate publication roots and
generation pointers; never recurse through root or nested symlinks; retain
restrictive permissions and delete only owned artifacts. Preserve the previous
valid cache if staging/publication fails. Test single/sharded transitions,
legacy layouts, malformed pointers, root/nested symlinks with outside sentinels,
concurrent readers, and injected failures. Correct this before importing the
branch cleanup into current source.

### F4-04 — carry forward canonical cache and lock ownership

**Owner:** Foundation `CacheManager`, `CacheLayerFactory`, `DBLayerFactory`, and
cache consumers. **Priority:** required correctness fix; reuse-branch F8/F9.

Reproductions on current source confirmed that `store(null)` differs from the
named configured default, and DBLayer query caching bypasses the named `db-query`
registry. Clearing that named store leaves the query-cache marker intact.

Import and adapt the branch's canonical store naming, explicit store/lock-store
resolution, replacement invalidation, and shared cache registry. Memoize reusable
lock providers by resource, never acquired lock handles. Include session, MFA,
passkey, webhook, migration, scheduler, and worker coordination consumers.
Infrastructure PDO ownership remains generation-scoped; transactions, leases,
and outbox operations remain execution-scoped with the exact transaction PDO.

CacheLayer 4 also requires cluster `transportIdentity` and changes node fail-open
configuration. Provide explicit validated identity and failure policy, preserving
durable history/generation fencing. Do not supply one universal reused identity
to satisfy a constructor. Test cluster identity rotation, history recovery,
store replacement, invalidation, and lock/provider isolation.

### F4-05 — preserve primary failures when cleanup reporting fails

**Owner:** Foundation `Session/Middleware/SessionMiddleware`. **Priority:** required
reliability fix.

Session release/context removal run through `CleanupGuard`, but reporting the
secondary failure afterward is unguarded. A throwing logger replaced an existing
handler exception in a probe; the session stack was still correctly cleared.
Guard secondary reporting so it cannot replace the primary throwable. Preserve
the defined cleanup-failure behavior when no primary exists. Extend the existing
session feature tests with a throwing logger, exact throwable identity,
exactly-once release/reset, and redacted diagnostics.

### F4-06 — reconcile specialist reuse without weakening security

**Owner:** Foundation integration policy. **Priority:** required branch integration.

Carry forward these cohesive changes from `library-reuse-hardening`, adapting
them to the latest published owner APIs:

- Native Webrick `AtomicCounterAdapter`; shared authentication counters must be
  atomic and fail closed when the required backend is unavailable. Current
  fallback performs read-modify-write. Production validation already requires
  appropriate Redis/Valkey configuration; preserve it and enforce shared-store
  semantics consistently in other environments. Process-local stores may use
  native in-memory counters.
- One Epicrypt OAuth protocol validation/result, preserving approved redirects,
  error presentation, audit hooks, and token/replay semantics. Legacy Foundation
  refresh/protocol machinery must be removed where it duplicates the native
  owner, including dormant compatibility implementations.
- Native Pathwise download preparation, preserving ranges, HEAD, headers,
  authorization, and deferred response completion. Prove invocation counts with
  runtime instrumentation rather than source-text assertions.
- TalkingBytes typed resolved HTTP configuration: parse once, retain TLS policy,
  and keep mutable request/client state inside its execution lifetime.
- Native bounded DBLayer transient-conflict retries for refresh transactions;
  no blanket retries of non-idempotent effects. Preserve atomic token rotation.
- A real Infbyte consumer of the redesigned framework, adapting its bootstrap
  and application-facing calls to 4.0. Verify intended application behavior; old
  call shapes need not survive. Do not silently use published Foundation 2.1.1.

Do not carry old API wrappers, version floors, or assertions into the new major
merely because they passed historical branch CI.

### F4-07 — restore current quality gates under the current toolchain

**Owner:** Foundation. **Priority:** release blocker.

Fix the 34 function and four class complexity findings through cohesive ownership
and private methods where sufficient. Review fresh Rector changes and changed
dependency signatures at their actual call boundaries. Run the required backend
tests with provisioned services. Investigate abandoned QA dependencies through
PHPForge upstream; do not mislabel abandonment as a vulnerability or suppress it.
No baseline additions, skipped backend cases, or relaxed thresholds.

### F4-08 — eliminate duplicate mechanisms and settle the complete framework surface

**Owner:** Foundation framework architecture and each native specialist owner.
**Priority:** release blocker under the user's clarified scope.

Complete the ownership/public-surface review above for the whole production tree,
including built-in auth, both session concepts, authorization, notification,
scheduling and console services. Remove duplicated algorithms, protocol engines,
parsers, stores/lock implementations, native lifecycle handling and generic
forwarding layers. Retain only meaningful framework services and plugin glue.
Existing code size, branch status, legacy names and backward compatibility are
not reasons to keep an implementation.

If a mechanism belongs to a sibling package but its published API lacks required
behavior, implement and release the native fix/extension there, then use that
published version. A temporary Foundation clone is not an accepted release
solution. Keep intentional Foundation policy/security checks distinct from the
specialist mechanism rather than deleting them indiscriminately.

Acceptance: one documented owner and implementation per mechanism, an explicit
disposition for each reviewed capability, revised public/configuration contracts,
and end-to-end application behavior through native owners. Prove removed paths
are unreachable because they are removed, not merely disabled. Verify the
Infbyte-facing framework surface without importing skeleton-specific business
logic into Foundation or framework engines into Infbyte.

## Runwire 2.1.1 composition contract

Foundation currently suggests Runwire but does not implement active
`RuntimeContext`/`RequestContext`/`CoroutineScope` propagation. The new release
must accept the host's instances and pass them through direct and intermediary
library calls. This is a required feature, not a dependency advisory.

Design the public application/execution boundary around native host and DI scope
ownership; existing `Application`/`ExecutionScope` APIs may be replaced. A scoped
public entry point such as `Application::withRunwire(runtime, callback, request, scope)`
is a proposed API, not an existing one; settle its typed signature after checking
the native Webrick/InterMix bridges and the new framework surface. Add an internal
binding only where it provides the required framework borrowing/restoration glue.

The contract is:

1. Borrow the exact active runtime, optional request/task context, and optional
   live coroutine scope. A CLI/worker task need not invent an HTTP request.
2. Validate runtime identity, PID/generation, request completion, cancellation,
   deadlines, and scope ancestry/liveness. Reject stale or mismatched bindings;
   missing capabilities do not permit bypassing these checks.
3. Restore prior bindings in `finally`, including nested calls and throwing
   callbacks. Use native Fiber/task-local propagation; mutable request/tenant
   state must not remain in singleton profiles or process-global slots.
4. Select supported native capabilities automatically. Cooperative operations
   require both the relevant capability and a live scope; otherwise execute the
   normal supported synchronous path. Do not promise asynchronous PDO merely
   because cancellation/deadlines are forwarded.
5. The host owns workers, listeners, event loops, requests, and borrowed scopes.
   Foundation must not create a runtime implicitly, drive or close its loop,
   complete its request, install competing signal handlers, or release its scope.
6. Preserve response-producer lifetime until the owning host finishes output.
   Define browser-session commit/release separately from deferred response
   production and verify both lifetimes under failure and cancellation. Foundation
   cleans up only resources/DI executions it created.
7. Keep the ordinary installation and execution path working with Runwire absent.

| Boundary | Native integration to reuse | Required forwarding/ownership |
| --- | --- | --- |
| Webrick HTTP → Foundation | `RunwireRuntimeBinding` / `RunwireInterMixScopeBridge` | Borrow the host DI scope and exact active contexts; avoid a second request scope |
| InterMix scope | `Integration/Runwire/RunwireIntegration` | Register native inputs before graph build; borrow opaque `ScopeContext`; restore nested bindings |
| CacheLayer | Native `bind` / `share` | Bootstrap binding only by its owner; share exact request/scope; never release host binding on request exit |
| DBLayer | `Connection::withRunwire` | Forward into query/transaction operations; preserve previous binding and transaction ownership |
| ReqShield → DBLayer | `validateWithRunwire` / native database bridge | Same instances through validation and batched database operations |
| Omnibus → DBLayer | `withRunwire`; managed worker lifecycle | Same contexts into durable operations; `runManaged` under host supervision |
| TalkingBytes | Context-bound native factories and request pool | Scope-bound clients; bounded concurrency; TLS/retries/deadlines retained |
| Pathwise | Native `RunwireExecutionContext` / scoped operation | Forward checkpoints; preserve deferred producers and host ownership |
| Epicrypt | Native `RunwireExecutionContext` on supported operations | Use only consuming APIs; retain cryptographic/protocol boundaries |
| OTP → CacheLayer | Native `CacheLayerRunwireExecutionContext` | Preserve lock lease, atomic replay/terminal-state, and cancellation semantics |
| UID | Native binding on waiting generators where applicable | Ordinary immediate UUID/ULID calls need no artificial runtime hop |
| ArrayKit | Ordinary config APIs | No unnecessary runtime integration for pure configuration operations |

CacheLayer's native bootstrap binding is process-wide. Never overwrite another
host's binding to simplify composition. Use its native request/Fiber-local sharing
and explicit binding ownership. Dynamic factories may be generation-owned, but
context-bound clients with cookies/auth/retry state must be execution-scoped.

Acceptance must exercise Foundation → library and Foundation → intermediary →
library chains, a host invoking Foundation as an intermediary, nested restoration,
two concurrent requests, child tasks, cancellation/deadline expiry, stale
generation, completed requests, missing capabilities, no coroutine scope, and
Runwire absent. Assert exact instance identity, no cross-tenant state, exactly-once
cleanup, and zero competing host lifecycle actions.

## Batch 1 — complete capability-level ownership and migration disposition

**Inventory baseline:** the reviewed current production tree contains **666 PHP source files** (including 377 under `Auth/`). These counts classify ownership and work size, not API-retention requirements. All listed areas are part of the 2.1.1 → 4.0 cumulative review; the exact per-symbol removal list and ABI changes are completed during Batch 2 rather than using the old signatures as compatibility requirements.

| Source area (PHP files) | Unique Foundation framework responsibility | Native owner / implementation disposition for 4.0 |
| --- | --- | --- |
| `Application/` (8), `Bootstrap/` (1), `Foundation.php` (1) | Four application modes, application bootstrap, provider contributions and application-facing entry points | **Redesign** graph assembly before InterMix finalization; keep meaningful framework facade, remove obsolete builder forwarding |
| `Container/` (2), `Runtime/` (13) | Execution-scoped context, Foundation resource cleanup, passed-instance host bridge | **Delegate** graph, scope, compile and execution primitives to InterMix 11.1 and Runwire 2.1.1; never create competing lifecycle/host loops |
| `Auth/` (377) | Account/device lifecycle, application login/consent/MFA/passkey policy, authorization, principal, OAuth HTTP presentation | **Retain framework policy; delegate** crypto/protocol/token internals to Epicrypt 3.2, OTP 7, UID 6 and native atomic stores; delete dormant duplicate refresh/counter engines |
| `Session/` (18) | Browser state, cookies, flash, rotation, CSRF policy and scoped HTTP session orchestration | **Retain distinct browser-session feature**, delegate locks/transactions/Webrick scope, coordinate but never conflate account/device authentication sessions |
| `Cache/` (9) | Capability activation, configured store identity and application-backed services | **Retain thin registry only**; delegate caching, counters, lock handles, clusters and invalidation to CacheLayer 4; remove local Webrick counter implementation |
| `Config/` (30) | Config selection, validation, deployment defaults, trust policy and cache publication | **Delegate** parsing, interpolation, cache generations and materialization to ArrayKit; keep outer manifest and trusted release path policy; no shadow parser |
| `Release/` (7), `Operations/` (6) | Staged application release, generation/fence/lease policy, readiness, activation and operational control | **Retain** outer atomic publication and operational policy; trust only owner-validated native InterMix/Webrick/ArrayKit artifacts |
| `Http/` (30), `Routing/` (18) | Middleware selection, authentication/authorization placement, application responses and route registration | **Delegate** request parsing, matcher execution, routing, rate-limits and emission to Webrick 6; no sharded/fused route clones |
| `Database/` (16) | Schema policy, named application connections, migration deployment and auth data mappings | **Delegate** transactions, leases, retry policy and query caching to DBLayer 6; preserve one transactional owner per request/job |
| `Filesystem/` (11) | Application upload/download permissions, disk/profile choices, HTTP response integration | **Delegate** storage, archive, ranges, integrity, streaming and file containment to Pathwise 4.2; avoid preparing one download twice |
| `Validation/` (5) | Form requests, application rule and error presentation | **Delegate** rule implementations/sanitization to ReqShield 3.3; no second native validator engine |
| `Communication/` (5), `Notifications/` (12) | Application profile selection, templates, recipients, notification channels and delivery policy | **Delegate** HTTP, email, webhook, gRPC transport and retry mechanics to TalkingBytes 2.3; retain framework notifications even without optional communications |
| `Messaging/` (14) | Job declarations, middleware and application outbox/dispatch policy | **Delegate** broker, delivery, retry and durable messaging to Omnibus 3; preserve transaction/outbox atomicity |
| `Scheduling/` (6), `Worker/` (5), `Process/` (8) | Application schedules, command/job execution, leadership policy, worker management and operational reporting | **Retain framework orchestration**, delegate timer/host supervision/concurrency to Runwire, leadership locks to CacheLayer; never borrow then close host contexts |
| `Command/` (31) | Command registration, built-in operational commands, application CLI parsing and output policy | **Retain** useful reusable console framework; do not duplicate native process execution or scheduler mechanics |
| `Module/` (11), `Generator/` (1) | Installation/activation/readiness, dependencies and developer scaffolding | **Retain** module/catalog policy and generation orchestration; align native published versions rather than accepting obsolete floors |
| `Security/` (4), `Logging/` (5), `Diagnostics/` (1) | Trusted environment/secret policy, safe application-level logging, failure precedence and readiness | **Retain policy**; use specialist security primitives and PSR logging, never replace root failures with secondary reporting |
| `Testing/` (5), `Exception/` (5), `Support/` (1) | Framework test fixtures, domain errors and genuinely shared policy utilities | **Retain only meaningful framework contracts**; remove generic duplicate wrappers and native error/normalizer clones when moving APIs |

**Auth subdomain disposition:** `Auth/Adapter/` (69) is a published-owner boundary, not a place for alternative crypto/DB/cache engines. `OAuth/` (60) keeps Foundation consent/client/audit/HTTP/persistence mapping and delegates protocol, DPoP and refresh-family mechanics to Epicrypt. `Authentication/` (55) retains account workflows including login, verification, recovery, impersonation, passwordless and device/session policy. `Authorization/` (25) retains application gates, tenancy and resource decisions. `Mfa/` (14), `Passkey/` (13), `Otp/` (4) retain enrollment/step-up/use policy but delegate native OTP and WebAuthn ceremonies. `Principal/` (6) keeps exactly one execution-local identity; `Internal/` (26) registers only needed services; `Support/` (42) and `Contract/` (22) require individual removal/replacement checks during Batch 2 rather than blanket retention. `Account/`, `Device/`, `Audit/`, `Http/`, `Notification/` and `Driver/` provide the remaining application contracts and policy.

### Exact authentication and browser-session composition contract (design accepted for implementation)

1. **Web request entry:** the host/Webrick owns the request and HTTP scope. `SessionMiddleware` resolves one browser-session container, obtains a native lock if required, and binds only browser state. `CsrfMiddleware` verifies browser-originated mutating operations according to application policy.
2. **Login:** `Auth/Http/AuthActions::login()` delegates to Foundation authentication/account workflows, which enforce native Epicrypt password/token decisions, deployment-visible native atomic lockout counters, account status, device verification and selected MFA/step-up. No account/device auth session is committed if validation or atomic conditions fail.
3. **Persistence and identity:** `Auth/Authentication/Session/SessionManager` creates/rotates/updates/revokes **account/device authentication sessions**, using DBLayer transaction ownership and native atomic security state. That record is distinct from `Session/SessionManager`'s browser key-value/cookie state. The browser can reference an auth-session ID but cannot itself become the authority for authenticated account state. Set exactly one `CurrentPrincipalContext` instance for that execution.
4. **Authorization:** `Auth/Authorization/Gate/` and application policies resolve account, tenant, role, delegation and resource decisions from the current execution's principal. Prevent stale principal/permissions from crossing persistent worker tasks, tenants or nested contexts.
5. **Response/cleanup:** rotate browser identifiers where required by login/logout; apply cookie, CSRF, expiry and persistence policy before Webrick output. On error preserve the primary throwable even if lock release, session reset or logging fails; release/reset exactly once. Deferred output and native body streaming belong to Webrick/Pathwise, not a second Foundation execution loop.
6. **Logout/revocation/recovery:** revoke selected auth-session or device/account records and derived credentials according to application policy, separately invalidate browser presentation and clear the execution principal. Account suspension/password change/refresh reuse triggers appropriate family/device invalidation. Reuse the same DBLayer transaction for atomic writes and selected CacheLayer invalidation/leases.
7. **Bearer/API and non-web:** bearer authentication resolves tokens through the chosen native protocol validator, maps them to one execution-local principal and authorizes without creating browser state; CLI/worker/scheduler start with an unauthenticated or explicitly delegated principal, no invented HTTP request or implicit Runwire host.
8. **Concurrency/migration:** native Runwire and InterMix scopes remain host-owned; nested/child tasks explicitly inherit only allowed identity and cancellation context, and restore the previous principal. Retained account password hashes, browser cookies/sessions, auth-session/device records, refresh families, OTP/recovery/passkey credentials and outbox messages require schema/data migration and failure/race tests in Batches 4–5, **not legacy compatibility shims**.

### Source-range and branch disposition

| Reviewed change group | Retain / redesign / delegate / delete | Behavior carried forward / evidence |
| --- | --- | --- |
| Post-published-2.1.1 main: four-mode builder/provider graph and generated runtimes | **Redesign** InterMix graph and ABI | Provider ordering, scope isolation, compiled/development parity; Batch 2 and migration |
| Main: release generations, maintenance, Webrick fused/sharded routes and native configuration | **Retain trust policy; delegate native mechanisms** | Atomic staging, manifest/fence, reader lifetimes, no symlink traversal, native route matching |
| Main: auth, MFA, passkey, OAuth, notification, messaging and application services | **Retain application policy; delegate protocols and storage** | Credential/replay/race security and real database state, tested in Batches 2–5 |
| Main: DB, cache, worker, HTTP, files, CLI, scheduler and configuration endpoints | **Retain framework services; delegate storage/host engines** | No process/request resource leaks, cold optional providers, native lock/transaction scope |
| `foundation-3/library-reuse-hardening`: canonical cache identities, lock registry, query cache, session/MFA/passkey/migration consumers | **Integrated on PR #20** | Current cache/DB/session/auth registrars plus named-store, named-lock and query-cache regressions |
| Hardening branch: Webrick native atomic adapter, shared auth counters | **Integrated**; redundant Foundation `WebrickAtomicCounter` and unsafe `CacheLayerCounterStore` **deleted** | Host/cluster auth counters require native atomic resources; process-only uses native in-memory counters |
| Hardening branch: Epicrypt validated OAuth protocol and DBLayer bounded refresh transaction retries | **Integrated current-version intent; redesign old refresh duplicates later** | One protocol evaluation per request, approved redirect/error/audit mappings and bounded transactions; tests imported |
| Hardening branch: Pathwise native HTTP range preparation, TalkingBytes resolved HTTP configuration | **Integrated** | Avoid duplicate download preparation and repeat immutable HTTP parsing; TLS remains production fail-closed |
| `arraykit-5.3/acceptance`: cache generation discovery, root/flat artifacts | **Selectively redesigned** | Native generation-aware validation, pinned readers and safe mode transitions, with no recursive symlink traversal |
| Acceptance branch: recursively deleting `.arraykit-gen-*` with `is_dir()` | **Rejected/deleted as unsafe** | Planted root and nested symlink sentinels must survive; never eagerly unlink pinned generations |
| ArrayKit published 5.3 native numeric read-cache lookup | **Fix/publish upstream, not in Foundation** | Correct PHP numeric-string offset handling before `ReflectionReference::fromArrayElement()`; still blocked by repository write permission |

### ArrayKit F4-02 — upstream correction published and consumed

ArrayKit **5.3.1**, published 2026-10-11 ([release](https://github.com/infocyph/ArrayKit/releases/tag/5.3.1), commit `e77bf72a40c64b94f62e69f3b2662ce2dc47efec`), includes the canonical numeric-string array-key fix from [ArrayKit PR #36](https://github.com/infocyph/ArrayKit/pull/36). Native `BaseConfigTrait::isReadCacheSafePath()` now normalizes integer-string array offsets before `ReflectionReference::fromArrayElement()` without altering literal string keys, reference detection or strict typing. ArrayKit's four PHP 8.4/8.5 Pest QA variants and both benchmarks passed on that PR. Its pre-existing complexity gate is independent.

Foundation `composer.json` now requires `infocyph/arraykit:^5.3.1`, excluding the defective 5.3.0 from both lowest and stable resolution. The existing numeric-path and OAuth environment integration regressions are retained. **F4-02 upstream cause fixed and published.** Foundation-side batch acceptance remains contingent on the final supported dependency matrix, unaffected security/ownership behavior and real CI proof.

## Active Batch 1 execution log

### Batch 1 — post-ArrayKit-5.3.1 checkpoint (2026-10-11)

- Verified official 5.3.1 published tag and corrected numeric-key source. Foundation core minimum version changed to `^5.3.1`; dedicated ArrayKit upstream PR #36 has native regression coverage.
- The first post-upgrade Foundation run [38126272273](https://github.com/infocyph/Foundation/actions/runs/38126272273) removed both historical `ReflectionException` failures, passing **492/493 tests** on PHP 8.4 stable dependencies. Its remaining test failure was a stale literal Composer baseline `^5.2`; this expectation is corrected. PHPForge Pint also flagged conflicting multiline empty-constructor formatting: the CacheLayer factory now uses an explicit readonly-closure property assignment.
- Lowest-version QA exposed `TalkingBytes 2.1` missing the native typed `fromResolvedConfig(..., baseConfig:)` API already used by Foundation. The dev requirement and communication module floor were updated to published `TalkingBytes ^2.3` with matching module/config tests. No old-version fallback was added. Production dependency sets stay narrowly scoped.
- Hardening-branch cache ownership is now unified for scheduler/worker leadership, webhook replay, browser session, MFA/passkeys, migration/query cache, native auth counters and config topology validation. Carried forward source-architecture, runtime identity, OAuth rotation and communication integration regressions.
- **Outstanding evidence:** new exact-head PHP 8.4/8.5 lowest/stable QA, benchmarks and scope regression inspection. Pre-existing 34 function / four class PHPStan cognitive-complexity findings remain tracked under Batch 2, with thresholds unchanged. Batch 1 remaining checkboxes are not marked complete until their own evidence is confirmed.
- InterMix 11.1 native builder/graph/finalization and all other specialist major upgrades remain Batch 2, not part of this isolated ArrayKit 5.3.1 acceptance increment.



- **2026-10-11 — Draft PR:** [Foundation #20](https://github.com/infocyph/Foundation/pull/20), `feature/foundation-3` → `main`. The PR remains draft; merge, tagging, and publication are excluded.
- **Cache reproductions and implementation:** `tests/Feature/Foundation4CacheIdentityRegressionTest.php` now covers default/named identity in both access orders, configured default changes, replacement, native lock provider identity, and distinct acquired lock handles. The cache manager/factory/DI graph and DBLayer query-cache integration now share one application-managed registry. The existing DBLayer query-cache feature suite was reconciled with the hardening branch and covers registry identity, cache replacement, and PDO infrastructure isolation. **F4-04 implementation is partial until latest-API migration and QA.**
- **Throwing diagnostics regression:** `SessionMiddleware` protects an original request throwable against secondary cleanup-logger failures; `BrowserSessionIntegrationTest` now checks exact exception identity, exactly two releases over two requests, one recorded warning, and no active session afterward. **F4-05 pending QA.**
- **ArrayKit publication safety:** `ArrayKitConfigCache` and `ConfigLoader` now reject symlinked publication roots before writing. `Foundation4ArrayKitPublicationSafetyTest.php` covers linked roots and both root-level and nested planted native-generation symlinks with an external sentinel. This preserves a regression against the unsafe acceptance-branch deletion behavior but does **not** resolve the generation-layout defect or certify full F4-03.
- **Upstream blocker — F4-02:** ArrayKit 5.3 `BaseConfigTrait::isReadCacheSafePath()` still passes canonical numeric-string segments to `ReflectionReference::fromArrayElement()`, which requires their normalized integer array keys. A corrective native patch and numeric/negative/literal/reference tests were prepared for a separate ArrayKit branch, but GitHub rejected branch creation with HTTP **403 Resource not accessible by integration**. **No upstream commit or PR was created.** Foundation must consume a published corrective ArrayKit version; do not add a local override.
- **QA:** PR-triggered [Security & Standards runs](https://github.com/infocyph/Foundation/actions?query=branch%3Afeature%2Ffoundation-3) execute on pushed commits; the current rollout is not certified. Current main's unrelated Rector/complexity failures and published ArrayKit 5.3 incompatibility remain blockers. Do not mark the grouped Batch 1 acceptance checkboxes complete on source edits alone.

### Batch 1 continuation — Foundation cache-generation fixes and QA (2026-10-11)

- **Generation-aware Foundation fix:** `ArrayKitConfigCache::writeSharded()` now validates the native `.arraykit-generation` target rather than looking for outdated root-level namespace files. The integration suites use native generation-aware artifact assertions. It validates native directory/link containment and rejects malformed/linked generation metadata without traversing it.
- **Pinned-reader safety:** switching to single cache mode retires the active generation pointer only after single-cache validation. Existing generations are deliberately retained for readers pinned to their old native generation; eager recursive deletion is forbidden. Added an in-process pinned-reader transition regression. Native/release-owned generation garbage collection and leases require further certification, so **F4-03 remains open**.
- **Rector:** applied the reported nullsafe/coalesce modernization in `Auth/OAuth/Authorization/AuthorizationRequestValidator.php` without changing its result contract.
- **Cold lock configuration:** an explicitly configured native lock driver no longer eagerly resolves the default cache backend. A new regression checks the PDO-backed cache remains cold while obtaining a native file lock.
- **CI distinction:** Previous finalized PR run [38118514862](https://github.com/infocyph/Foundation/actions/runs/38118514862) at SHA `197f68c` had **471 passing / 8 failing Pest tests**, quality and complexity failures; the eight clustered in ArrayKit cache artifacts and the native numeric-path issue. Subsequent source commits invalidate that run as certification. The current PR run should be evaluated **only after** the latest source/tracker SHA stabilizes.
- **Remaining blockers:** ArrayKit 5.3 numeric read-cache key normalization requires an upstream published fix; writing a branch in `infocyph/ArrayKit` returned `403 Resource not accessible by integration`, so there is no upstream PR. Full framework ownership and auth-flow disposition are not yet approved or completed. Latest native majors, Runwire propagation and release QA remain unimplemented. **No Batch 1 checkbox is closed and Batch 2 is not started.**

- **Follow-up validation:** `tests/Feature/Foundation4ArrayKitPublicationSafetyTest.php` now also asserts that linked, malformed, and missing native generation pointers fail safely without modifying external sentinel files. The previous security check exceeded the PHPForge per-function cognitive-complexity threshold; the pointer and directory checks were separated before the latest run. This is an **unverified fix**, not a passing QA result.
- **Current commit-specific gate:** evaluate the most recent PR run after the tracker is updated; passing consumer-install/benchmark jobs from superseded commits are informative only. No approval to progress past Batch 1 until upstream ArrayKit and required behavioral evidence are available.

### 2026-10-11 verification checkpoint — reconciled native branch changes

The hardening branch's MFA, passkey, browser-session and database-migration consumers now resolve shared cache state through `CacheManager` rather than bypassing the store identity with new native instances. `AuthCacheRegistrar` selects native atomic counters for host/cluster state. The redundant `CacheLayerCounterStore` and `WebrickAtomicCounter` are deleted and Webrick's native `AtomicCounterAdapter` is used. Imported tests cover cache registry replacements, concurrent refresh rotation/replay/audit, Pathwise prepare-once/ranges, Epicrypt validate-once/approved redirects and TalkingBytes typed configuration/TLS policy.

On [CI run 38120431178](https://github.com/infocyph/Foundation/actions/runs/38120431178), the PHP 8.5 stable test suite produced **491 passed, two failed**, both caused by the published ArrayKit `ReflectionReference` numeric-key defect. Four PHP 8.4/8.5 production consumer variants and clean install passed; Rector, reference checks and architectural boundaries passed. That run also detected Pint ordering/braces violations in newly edited `CacheLayerFactory` and `ArrayKitConfigCache`, and PHPCS unused test callback parameters; all three have since been corrected in subsequent commits. PHPStan still reports 34 function and four class complexity violations distributed across the broader 4.0 redesign, without new API/type diagnostics. None of these commits relaxes a quality gate.

**Batch 1 status:** first two architecture/branch-disposition inventory items complete at capability level. Runtime compatibility and per-symbol code removal are explicitly Batch 2 work, not hidden Batch 1 completion. Tests and native helper integration need passing final-commit QA; the upstream ArrayKit patch still cannot be pushed or published through this GitHub connection (branch and issue creation both fail HTTP 403), so remaining Batch 1 checkboxes stay open. Historical tests on previous commits do not certify the next source SHA.

### Provisional mechanism dispositions (Batch 1 ownership review, incomplete)

These are concrete starting decisions, not a claim that every production symbol has been audited or that APIs are frozen. The final ownership/public-surface record must include all built-in services and persisted-data effects.

| Source boundary | Native mechanism owner | Unique Foundation responsibility | Disposition for 4.0 |
| --- | --- | --- | --- |
| `Application/Application.php`, `Container/FoundationGraph.php`, `Runtime/NonWebGraphFactory.php` | InterMix graph, lifetimes and compiled scopes | Explicit four-mode application bootstrap, provider/capability policy, release binding | **Redesign** to complete inputs and providers before graph finalization; remove old builder calls, no compatibility facade |
| `Auth/AuthServices.php`, `Auth/Internal/` | InterMix direct resolution of registered services | Feature selection and coherent auth workflow registration | **Review/remove** generic forwarding surfaces; keep only meaningful policy and workflow entry points |
| `Auth/Authentication/Session/`, `Session/`, `Auth/Principal/` | Native CacheLayer/DBLayer state and Webrick HTTP lifecycle | Distinct account/device login-session and browser-session policies with one execution-local principal | **Redesign coordination**, never merge distinct persisted state semantics |
| `Auth/OAuth/`, `Auth/Adapter/Epicrypt/`, `Auth/Adapter/DBLayer/OAuth/` | Epicrypt OAuth/OIDC protocol, token and security mechanisms; DBLayer transactions | Application consent/claims, HTTP presentation, schema and policy mapping | **Delegate** duplicate protocol/refresh logic; preserve atomic rotation, replay and audit policy |
| `Cache/CacheManager.php`, `Cache/CacheLayerFactory.php` | CacheLayer stores, locks, counters, clusters and outbox | Configured store identity, capability activation and lifecycle ownership | **Retain integration**, canonical registry changes underway; migrate CacheLayer 4 cluster semantics next |
| `Database/DBLayerFactory.php` | DBLayer connection, transaction, pool, retry and query cache semantics | Configured application connection topology and execution/generation ownership | **Retain integration**, share cache registry and migrate DBLayer 6 contracts |
| `Config/ArrayKitConfigCache.php`, `Release/` | ArrayKit parsing/materialization and its native generation; native InterMix/Webrick artifacts | Trusted outer release manifest, staging/activation/readiness and ownership of deployed paths | **Redesign generation glue**; never follow symlinks or replace a valid generation on failed publication |
| `Routing/WebReleaseCompiler.php`, `Routing/WebReleaseRuntime.php` | Webrick routing and matcher compilation | Application route registration/policy and coordinated release publication | **Delegate matching**; preserve native fused/sharded Webrick paths and trust checks |
| `Notifications/`, `Messaging/`, `Scheduling/`, `Command/`, `Process/` | TalkingBytes transports, Omnibus messaging, Runwire process/host mechanisms where applicable | Framework notification templates, app jobs, schedules, CLI policy and observability | **Review individually** for native reuse; unresolved symbols and persistence contracts remain open |

### Auth/session composition acceptance sketch

- Webrick/host owns the HTTP request and request DI scope. Foundation coordinates browser-session cookie and CSRF policy, resolves one execution-local principal from an explicitly selected browser/auth-session or bearer path, and never silently shares mutable principal state between requests/tasks.
- Account login must validate native Epicrypt credentials, lockout/account policy and relevant MFA/step-up requirements before committing device/auth-session state. Use DBLayer transactions and CacheLayer native atomicity. Rotate browser identifiers as required, then bind the resulting principal for only that execution.
- Logout, account/device revocation, password recovery and impersonation must update the proper account/auth-session/credential records and invalidate browser bindings without confusing authentication state with browser key/value storage.
- Browser-session commit/release is distinct from deferred Webrick response emission. CLI/worker/scheduler operations need no invented browser session or HTTP request. Borrowed Runwire/InterMix contexts remain host-owned.
- Pending: trace every actual controller/provider/store path and migration of persisted browser sessions, auth-session records, OAuth families, OTP factors and passkeys; verify concurrency, race failure precedence, and exact host/context ownership before closing this design.

## Implementation batches and acceptance

Checkmarks indicate verified scope/design decisions, not final source certification.
Unchecked items remain release blockers until the specific acceptance evidence is present.
Keep only unresolved findings and required release evidence after release.

### Batch 1 — preserve reproductions and reconcile branch intent

- [x] Complete the framework ownership/public-surface review and built-in auth/
  session flow design before choosing replacement APIs; close F4-08 through the
  subsequent implementation batches.
- [x] Account for every post-2.1.1 main change group and both branches with an
  explicit retain/redesign/delegate/delete decision, migration notes, and
  meaningful behavioral coverage; retain security invariants when replacing code.
- [x] Convert the confirmed ArrayKit numeric-path, generation/symlink, cache
  identity, and throwing-logger probes into owner-appropriate regressions.
- [x] Resolve and publish the native ArrayKit patch; refresh the dependency table.
- [x] Reconcile both named branches against the intended 4.0 design, keeping
  applicable fixes and correcting unsafe generation cleanup before adoption.
- [ ] Reuse branch-owned cache/protocol/download changes and remove redundant
  implementations, aliases and compatibility glue with explicit replacement/
  removal and migration evidence.

### Batch 2 implementation log — start of native-major adoption (2026-10-11)

**Order and acceptance:** complete Foundation-owned regression/formatting QA from Batch 1; then migrate InterMix graph construction across **all four application modes** and development/compiled artifacts as one coherent native contract. Subsequent native-major integration changes must use the package's published contract directly and preserve production-only optionality. Never compile or resolve a partial graph in the middle of provider contribution.

**Confirmed InterMix 11.1 contract and affected Foundation call sites:**

| Native 11.1 interface | Foundation current call sites | Required change / no compatibility fallback |
| --- | --- | --- |
| `ContainerBuilder::create()`, `factory(id, Closure|FactoryDefinition, LifetimeEnum, tags)`, `autowire()`, `value()`, `alias()`, `input()` | `FoundationGraph`, `Application::create()`, `Bootstrapper`, built-in and custom service providers/registrars | Compose explicit recipes, lifetimes and scoped seed identities **before** `build()`; replace `singleton`, `scoped`, `bind`, and `bindFactory` with native definitions. No mutable-container emulation. |
| `ContainerBuilder::build()`, `definitionGraph()`, `compile()`, `production()` | `Application`, `NonWebGraphFactory`, non-web release compiler and Webrick release compiler | Provider contributions cannot follow `build()`; compiling finalizes the same exact graph. No `development()` or pre-finalization runtime resolutions. |
| `RuntimeContainerInterface`, `ScopeContext` | `ExecutionScope`, worker/CLI/scheduler runtime and generated container binding | Borrow native runtime instances and scope contexts, preserve exact identity, reject stale/foreign scopes, and don't assume mutable `Container`/generated `ProductionContainer` subtypes. |
| `RuntimeFactoryDefinition`, `FactoryDefinition::construct/staticFactory` | Foundation services requiring runtime-only Webrick objects, live error handlers, route registrars, generated runtime recipes | Keep compile-safe data/recipes in the exportable graph; register live closures only for explicitly unsupported development operations. Remove builder inspection and early `development()` calls from provider helpers. |
| No `ContainerBuilder::definitions()`, no mutable `options()` | Path/Auth/Communication/Messaging/Logging and 12+ other providers, `FoundationGraph::createBuilder()` | Replace implicit live-definition inspection with deterministic composition-time ownership/topology and native `enableDebugTracing()`. Don't finalize `definitionGraph()` while contributions remain. |

**Native UID 6.0 integration:** Foundation now requires `infocyph/uid:^6.0` rather than 5.x. The verified applicable call paths are `ExecutionId::generate()` (native `Id::ulid()`) and `UidAuthIdGenerator` (native `Id::ulid()` / `Id::uuid7()`); existing `UidIntegrationTest` and `UidRuntimeBoundaryTest` cover output validation, monotonic ordering, fork uniqueness, scheduler/worker IDs, and externally supplied correlation identity. UID 6.0's Sonyflake/Randflake/decimal encoding and optional GenerationContext are **native capabilities**, not new Foundation facade responsibilities. Production PHP 8.4 baseline already meets UID 6.0's PHP requirement. Real final-head QA is pending.

**PHPForge complexity remediation:** `RuntimeCoreValidator::notifications()` now delegates channel-map validation to one cohesive policy helper while preserving first-error semantics; `CacheTopologyValidator::validateClusters()` delegates configured-node-store, shared-transport and stable-node-ID predicates without duplicating storage mechanics. Baseline was **34 method + 4 class** findings; intermediate analysis confirmed one method issue removed and reduced cluster validation from complexity 15 to 13; a further extraction targets the strict 12 budget. Final-head analysis must confirm the net counts before claiming closure.

**Do not infer full Batch 2 completion:** InterMix 11.1/CacheLayer 4.0/Webrick 6.0 and other published majors (DBLayer 6, Epicrypt 3.2, Omnibus 3, OTP 7, Pathwise 4.2, ReqShield 3.3, TalkingBytes 2.3, UID 6, Runwire 2.1.1) still require a whole-surface native migration, provider/host lifecycle redesign, real-backend tests and compatibility evidence. `ModuleCatalog`, configured defaults, documentation and CI constraints must be updated together with each final native graph, without retaining old branches as compatibility aliases.

### Batch 2 — adopt latest container and specialist APIs

- [ ] Complete InterMix graph composition before finalization for every mode;
  migrate native lifetimes, inputs, scopes, compilation, and custom providers.
- [ ] Adopt Webrick 6, CacheLayer 4, and remaining latest module APIs, including
  cluster identity/history policy and transaction/outbox ownership.
- [ ] Complete the per-library compatibility record for the whole applicable
  native surface and all Foundation consumers; implement every required contract
  without retaining obsolete wrappers or limiting changes to a small diff.
- [ ] Redesign built-in framework services and remove duplicate native mechanisms
  according to the approved ownership map, including auth and session composition.
- [ ] Account for every retained/added production class, method and function by
  required framework responsibility or plugin boundary; remove unnecessary
  symbols and duplicated/legacy paths rather than adding compatibility layers.
- [ ] Align Composer, `ModuleCatalog`, dependency guards, publishable configuration,
  CI consumer constraints, examples, and test expectations with the same floors.
- [ ] Fix current Rector/complexity findings and all latest-candidate API failures.
- [ ] Provision MySQL, PostgreSQL, Redis, Valkey, and Memcached for required tests;
  verify current session contention scenarios rather than skipping them.

### Batch 3 — accept and forward host-owned Runwire contexts

- [ ] Implement complete scoped Foundation context propagation through native
  bridges, with only the required framework/plugin surface.
- [ ] Forward exact runtime/request/scope through relevant native module APIs,
  direct calls, intermediary chains, and child tasks.
- [ ] Verify capability fallback and absence of Runwire without implicit runtime
  creation; preserve generation/process/request boundaries and host ownership.
- [ ] Cover borrowed versus owned DI cleanup, throwing callbacks/reporters,
  cancellation, nested contexts, deferred output, and concurrent state isolation.

### Batch 4 — verify security and persistence composition

- [ ] Authentication/authorization: tenant/resource isolation, permission caches,
  session fixation/regeneration/expiry, CSRF, cookie policy, account suspension,
  rate limits, and atomic lock/counter behavior under concurrency.
- [ ] Full built-in flows: login/logout, browser/auth-session coordination,
  account/device revocation, idle/recent-auth/step-up semantics, remember-me,
  verification, passwordless/reset/change, recovery, impersonation, gates/policies
  and delegation; verify one principal and one owner per native mechanism.
- [ ] OAuth/tokens: approved redirects, PKCE, code consumption, refresh rotation
  and reuse detection, revocation, bounded retries, and auditing with real database
  races. Preserve Epicrypt ownership and Foundation policy/persistence mapping.
- [ ] MFA/passkeys: OTP terminal state/replay prevention, recovery-code consumption,
  encrypted secret handling, credential counters, origin/RP validation, and leases.
- [ ] Persistence/messaging: real-backend contention, transaction rollback/outbox
  atomicity, duplicate delivery, webhook replay, scheduler leadership, cancellation,
  reconnects, and generation reset without leaked transactions or locks.
- [ ] HTTP/filesystem/communications: trusted proxies, redirects, upload/path
  containment, executable configuration/artifact trust, ranges/stream failures,
  TLS/SSRF policy boundaries, retries, request body limits, and redacted diagnostics.
- [ ] Persistent lifecycle: per-request state reset across all modes, concurrent
  requests/tasks, failure cleanup, dependency replacement, and bounded resources.

Extend existing meaningful feature tests. Use real services where in-memory
tests cannot establish atomicity or isolation; keep pure definition/config checks
portable. Add tests for changed contracts and reproduced defects, not mirrored
implementation details.

### Batch 5 — direct migration and consumer rehearsal

- [ ] Write current Foundation 4 release/migration guidance and runnable examples;
  update documentation navigation and runtime claims together.
- [ ] Map every changed/removed 2.1.1 API and provider/configuration contract to its
  replacement. Explain dependency majors, compiled graph regeneration, and the
  passed-instance Runwire API. Rebuild generated artifacts for the accepted ABI.
- [ ] Rehearse retained password hashes, sessions/tokens, refresh families, MFA
  secrets/recovery codes, passkey credentials, queue payloads, and durable outbox
  data. Use additive migrations where possible; test backup restoration and
  explicit downgrade limits. Code rollback does not establish data rollback.
- [ ] Exercise production `--no-dev` consumers with both core-only and complete
  applicable module topologies, explicit Runwire hosts, and Infbyte against the
  candidate source and published dependencies. Adapt the skeleton to the approved breaking surface while
  preserving the Foundation-framework/Infbyte-application responsibility boundary.

### Batch 6 — certify one immutable release candidate

- [ ] Run PHPForge doctor/config diagnostics, then review necessary `ic:process`
  changes sequentially; run full `ic:tests` and required release checks without
  suppressions, missing-service exemptions, or skipped evidence.
- [ ] Verify supported PHP 8.4/8.5, lowest/latest dependencies, compiled/development
  graphs, production consumers, and required service matrices on the final SHA.
  Record toolchain/workflow references and the resolved dependency manifest.
- [ ] Close every required compatibility-record entry and verify no extra
  production surface or duplicate mechanism remains. Any unresolved integration
  contract blocks the 100% compatibility claim and the release.
- [ ] Establish comparable baseline/candidate sustained host measurements with
  valid response checks, repeated runs and medians, cold/warm routes, realistic
  authentication/database/cache workloads, concurrency 1/10/50, and one/four
  workers where the certified host supports them. Use stable production-equivalent
  infrastructure and keep the default maximum median successful-RPM regression
  at 2%; explain measured variance rather than silently changing the budget.
- [ ] Measure CPU, live process-tree RSS during load, p50/p95/p99 latency, errors,
  cache/query behavior, worker generation, and cleanup. Start measurement after
  readiness; do not substitute final-process RSS or a microbenchmark for host RPM.
- [ ] Certify the ordinary FPM path and a native Runwire 2.1.1 persistent host;
  include a bounded representative persistent-host soak with reset/cancellation/
  recycle checks. Claim other hosts only when their actual jobs pass. Existing
  execution-loop smoke tests alone do not certify a native server.
- [ ] Confirm all required jobs and evidence succeed on the exact final source
  and dependency set. Any source/dependency change invalidates affected evidence.
- [ ] Prepare reviewed 4.0 release notes/artifact and verify publication metadata.
  Keep implementation, CI readiness, merge, tag, and publication status separate.

## Completion boundary

Complete the required framework redesign, native-library adoption, removal of
duplication/unnecessary surface, and all compatibility/security/performance
gates. Do not add unrelated features, generic frameworks, wrappers, or speculative
classes/methods/functions beyond those responsibilities. Completeness is measured
by the accepted contracts and evidence, not patch size or feature count.
