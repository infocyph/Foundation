## Published Runwire worker fix adopted — 2026-10-17

Runwire **2.1.2** is published and Composer-resolvable at
`7e9bf20ad0737a187dfed77b723791b141b36378`; its tag includes the validated
worker patch `23011696724bbd86b2d412a07bc34348d8d169e1`. Foundation now
requires **`infocyph/runwire:^2.1.2`**. No Foundation-side worker/fork/context
workaround is introduced. Composer installs the published distribution, with no
sibling path override; all direct dependencies are current.

Real Foundation CLI acceptance now covers explicit Swoole one/two-worker
serving, concurrent bursts of sixteen isolated requests, actual worker PID/context/slot
identity, handler-failure recovery, native AUTO/portable serving and the normal
index entrypoint. A dedicated PHP 8.4/8.5 × Swoole/OpenSwoole hosted matrix
requires the selected extension before running these tests; missing extensions
cannot silently omit the worker cases in that matrix. Local detailed quality
passes all checks (784 tests / 29,807 assertions); the real Docker Swoole
serving/release subset passes 17 tests, including repeated fresh two-worker
starts. All 13 final `composer ic:tests` checks, processors and actionlint
pass. Current-source [Security & Standards 39007156463](https://github.com/infocyph/Foundation/actions/runs/39007156463)
and [native/hosted serving 39007156290](https://github.com/infocyph/Foundation/actions/runs/39007156290)
are green, including all four PHP 8.4/8.5 Swoole/OpenSwoole serving lanes and
stable/lowest production consumers. The complete [CPU-controlled comparison 39010682913](https://github.com/infocyph/Foundation/actions/runs/39010682913)
on unchanged candidate `3f295de` ends with eight passing and four inconclusive
cases, zero stable regressions, and zero failed responses across 120 measured
trials/eight soak windows. The unchanged 2.5% CV ceiling blocks acceptance.
See [current provenance, per-case variance and Core integration evidence](foundation-4-module-batch7-evidence.md#completed-controlled-comparison--2026-10-17).
The frozen baseline and 2% RPM / 2.5% CV limits remain unchanged. Keep PR #20
draft; merging, tagging, publication and Infbyte changes remain separate.

> Earlier dated checkpoints below retain their original dependency/source evidence.

## Core Runwire correction — approved 2026-10-16

### Serve and direct-host entrypoints — user clarification, 2026-10-16

`php infbyte serve` must start serving through native Runwire. No runtime flag
means native `RuntimeDriver::AUTO`; explicit selections such as
`--runtime=swoole` use the installed enum and native selector. Supported runtime
values and help derive from `RuntimeDriver::cases()`, never a Foundation copy.
Normal PHP/FPM deployment pointing at Infbyte's `public/index.php` remains
supported without the command or a framework-owned listener, worker pool or
event loop. Externally managed Swoole/OpenSwoole and other persistent hosts use
Webrick's native adapters on the same application path. Both entry paths must
work for shared hosting, dedicated hosts, containers and local development
where the selected host's capabilities are available.

The prior command launched `PHP_BINARY -S`; that reproduced gap is not covered
by lower-level native-host benchmarks. The integration below replaces it in
Foundation; Infbyte skeleton migration remains a separate follow-up.

**2026-10-17 local implementation checkpoint:** `serve` now
delegates to native Runwire; the existing trusted bootstrap implements its
application-factory contract. The supervisor remains unbooted, enum-based
selection and FIXED/default policy preflight pass, and the focused suite passes
24 tests / 328 assertions. Real HTTP probes pass default native, portable native,
ordinary raw-PHP/index.php and Docker nginx/FPM paths, including failure recovery.
Direct two-worker Swoole hosting through Webrick also passes without Runwire.
Runwire/Swoole 6.2.3 passes at one worker but **fails process ownership at two**:
worker PIDs 7/8 receive runtime PID 1 because Runwire 2.1.1 creates the
application/context before host forks. This native-owner defect requires a
Runwire fix and regression coverage; no Foundation workaround or readiness claim.
All 13 final `composer ic:ci` checks pass, including the expanded worker tests.
Stable/lowest production consumers pass Core, database inspection, twelve full
selected-feature configurations and trusted web/CLI/worker/scheduler generation.
Hosted QA and performance for the new serving candidate remain pending.

- [x] Replace the built-in PHP server path with native `Runtime`, `Server` and
  host-owned `serveApplication()` selection, using Runwire's environment probe
  and selector. Validate configuration before compiling/publishing a generation;
  dry-run must not open listeners or publish artifacts.
- [x] Make the existing trusted `FoundationReleaseBootstrap` implement native
  `RuntimeApplicationFactoryInterface`. Its required `create(RuntimeContext)`
  loads the generation inside the actual worker/host and forwards that exact
  context to the existing Webrick bridge. Do not inherit a live compiled DI
  container across prefork or add another factory/runtime engine.
- [x] Expose common CLI runtime/host/port/worker and protocol-policy selections
  through native options. Runtime AUTO and protocol policy AUTO are distinct.
  Protocol defaults remain FIXED; explicit FIXED/LATENCY/THROUGHPUT/AUTO use
  `AdaptivePolicyMode::cases()`, preserve native protocol-specific tuning, and
  do not raise resource/security limits. Reject options unsupported by the
  selected host rather than pretending that its wire policy changed.
- [x] Retain full advanced native configuration through the same bootstrap
  factory passed directly to Runwire's `RuntimeOptions`/`Server` APIs, including
  HTTP/3's independent inbound/outbound policies and capability requirements;
  do not mirror the library's option schema or implement adaptive scheduling.
- [x] Verify actual CLI serving, native/portable worker ownership, exact context
  forwarding, clean shutdown and trusted releases, plus normal FPM/index.php
  request handling without `serve`. Cover invalid selections and unavailable
  explicit runtimes, and renew affected quality/host evidence after the change.
  Current executable `3f295de`: hosted QA and all four Swoole/OpenSwoole lanes
  pass; local direct FPM/index.php and externally managed Swoole each pass 40
  concurrent requests plus failure recovery. This closes serving correctness,
  not the separate sustained performance gate.

**Hosted checkpoint, executable `3d35605`:** [Security & Standards 38917716824](https://github.com/infocyph/Foundation/actions/runs/38917716824)
passed every required quality/production lane, and [native host smoke 38917716261](https://github.com/infocyph/Foundation/actions/runs/38917716261)
passed. The separate manual [paired comparison 38917773579](https://github.com/infocyph/Foundation/actions/runs/38917773579)
completed twice: 8/12 pass plus four inconclusive, then 10/12 pass plus two
inconclusive on unchanged source. All response and two-minute soak checks pass;
CV above the unchanged 2.5% limit prevents performance acceptance. The PR event
intentionally skips paired jobs; it does not represent the manual matrix's
result. Current source/lock provenance, per-attempt results and the supporting
local +0.614% FPM diagnostic are in [B7 evidence](foundation-4-module-batch7-evidence.md).
PR #20 stays draft; throughput/stability and final sign-off remain open.

**Intermediary boundary follow-up:** An additional probe on `faa4ba7` reproduced
missing Core DI inputs when an upstream library shared the runtime without an
explicit Foundation seed map. Eight adversarial cases failed before the fix:
six compiled mode/pool combinations and completed/cancelled shared requests.
Core now validates the shared context using the existing explicit-input checks
and seeds exact native objects into DI. Focused validation passes 31 tests /
1,112 assertions, including development/compiled direct/intermediary execution,
borrowed children, coroutine identity/cancellation and runtime-only null scopes.
The `faa4ba7` hosted QA succeeded, but the sustained comparison was cancelled;
that revision does not certify this follow-up. Renewed full detailed quality
passes with 769 tests / 29,638 assertions and all configured static/security/
formatting/reference checks. Hosted quality passes on `3d35605`; sustained host
acceptance remains open as recorded above. No native mechanism or public wrapper was added.

The user requires Runwire in `require` and approved making its integration Core.
This supersedes the earlier optional runtime decision D12 and all historical
three-module/nine-feature claims. The current public contract is **three modules,
eight optional features**; `execution.runtime` is removed and rejected rather
than retained as an alias. Eight `core:*` diagnostics include `core:runwire`.

Runwire `^2.1.2` is installed in every production graph. Core declares native
runtime/request/task inputs in web, CLI, worker and scheduler graphs without
requiring an optional feature or capability flag. Exact caller-owned contexts
continue through native CacheLayer/DBLayer/Omnibus/ReqShield/TalkingBytes/UID
boundaries. An absent active context uses the native synchronous path; Foundation
does not create substitute contexts, listeners, event loops or workers.

Runwire owns environment detection and capability checks: portable CLI works
without prefork facilities; FPM/FrankenPHP/RoadRunner use host-owned serving;
Swoole/OpenSwoole requires explicit native host selection. Optional extensions
remain capability requirements, not Foundation installation requirements.

Implementation and validation cover Composer roles, protected Core package
ownership, all eight feature lifecycle plans, CLI schema-v2 output, compiled
Core inputs, borrowed/cancelled contexts, synchronous fallback and isolated
production `--no-dev` installs. Detailed quality passes with 769 tests / 29,638
assertions, including intermediary forwarding and CLI inventory provenance. Final
`composer ic:ci` passes all 13 configured quality checks, including that
additional regression. Stable/lowest no-dev consumers pass twelve selected-feature
configurations per mode and trusted web/CLI/worker/scheduler generation. Native
portable selection succeeds with fork functions disabled; local Docker passes
12 authenticated scenarios, 24 trials and four 30-second soak windows with no
invalid responses, plus cancellation/recovery and worker replacement. Composer
validation, stable constraints, configured audit and actionlint pass.
Prior hosted SHA/counts below are historical and cannot certify this Core
contract. Current hosted quality passes on `3d35605`; stable host throughput
evidence is still required before merge/release acceptance.
The authenticated HTTP baseline remains frozen at `6274939`; its response/query/cache workload is unchanged. CLI diagnostics record each source's actual feature count and label the nine-to-eight catalog change explicitly; they do not claim equivalent-inventory throughput. Keep the HTTP 2% median RPM and 2.5% CV budgets unchanged; do not reset the baseline to hide the Core integration cost.

> **Historical acceptance before the Core correction, 2026-10-16:** Executable `60a7951` has [fully green PHP 8.4/8.5 stable/lowest QA, analysis, consumers and release evidence](https://github.com/infocyph/Foundation/actions/runs/38860725174). The release ledger is **32/37**, with reopened native integration, security and state criteria revalidated. B7 remains **4/7 engineering gates, 0/7 whole-batch accepted**; module totals remain **46/49 engineering, 42/49 batch accepted** until the strict [paired host comparison](https://github.com/infocyph/Foundation/actions/runs/38860730929) and final decision pass. The approved environment is baseline/candidate paired on the same GitHub runner, with 30-second warm-up, five >=60-second measurements, CV <=2.5% and median RPM regression <=2%. [Current B7 evidence](foundation-4-module-batch7-evidence.md). Earlier dated status paragraphs are historical. PR #20 stays draft; no merge, tag, publication or Infbyte changes.

> **2026-10-16 live follow-up:** Paired workflow `38837335243` ran twice on unchanged candidate `95eccf9`; stable scenarios passed on the repeat, but four cases exceeded CV 2.5%, so host acceptance remains inconclusive. Stable QA passes on `b00ff5d`; lowest QA exposed third-party implicit-nullability warnings. Verified optional transitive floors and a separate 30-second warm-up plus five >=60-second trials are prepared; <=2% RPM and <=2.5% variance budgets remain unchanged. Both host soak summaries are retained and required by `6cd6c98`. Final exact-source QA and a stable full host matrix remain required. See the [current release ledger](foundation-4.0-release-audit-and-plan.md).

> **2026-10-16 pre-merge update:** Native provider composition, independent SQLite/MySQL/PostgreSQL auth contention, signed native WebAuthn/HOTP step-up, child-only cursor cancellation and real Composer publication/removal races are now implemented with passing focused regressions. Final Docker authenticated smoke passed all 12 scenarios and four soak windows; stable/lowest production consumers pass. The user selected GitHub Actions for the sustained comparison: each scenario pairs baseline `6274939c395f75e5cf777aad5d7439f10792758c` and final candidate on the same runner, one lock/tool revision, five >=30-second samples, CV <=2.5% and <=2% RPM regression. Exact-source hosted QA and comparison remain pending; the strict ledger is 19/37 until final acceptance. Earlier unavailable-host statements are historical. See the [current release ledger](foundation-4.0-release-audit-and-plan.md).

> **2026-10-15 QA checkpoint:** `fd34d0d2` corrected two feature tests missing the selected Runwire input, one stale TalkingBytes architecture source assertion, and an activation Rector style violation. Native R6 operations and Composer-lock R8 metadata enforcement are implemented but renewed acceptance remains **19/37 NO-GO** until complete exact-head QA, native direct-call contracts and B7 dedicated matched hardware verification. [Release audit](foundation-4.0-release-audit-and-plan.md).

> **2026-10-15 R6/R8 implementation checkpoint:** Foundation now has native operation-scoped DBLayer, ReqShield, TalkingBytes and Omnibus forwarding for CacheLayer/Runwire-owned fiber contexts and a shared Composer lock plus fresh metadata fingerprint on the v2 module enable/disable path. Implemented tests cover mid-query cancellation, nested restoration and mutated installed metadata. Raw direct native calls without an operation boundary and physically controlled B7 throughput remain unverified, so **19/37 release acceptance and historical 46/49 module engineering checks do not change**. See [current release ledger](foundation-4.0-release-audit-and-plan.md); latest source QA pending.

> **2026-10-15 exact-source recheck:** Executable SHA [`aea1ab2`](https://github.com/infocyph/Foundation/commit/aea1ab2ffedae30ed4f1d4eabe65f7cbe9f47198) has [full Security & Standards **SUCCESS**](https://github.com/infocyph/Foundation/actions/runs/38749779083) after retrying GitHub codeload PHPStan HTTP 429 failures: PHPForge QA **4/4**, PHPStan **2/2**, production `--no-dev` consumers **4/4**, benchmarks **2/2**, clean installation, and release evidence. [Native HTTP host smoke **SUCCESS**](https://github.com/infocyph/Foundation/actions/runs/38749778570). The legacy mutation removals and Epicrypt remember-token ownership cutover passed this exact-source suite. R6 automatic Runwire forwarding and B7 matched production-equivalent sustained baseline/candidate throughput are **not** certified by these jobs. The Foundation release re-audit stays **NO-GO, 19/37**, and B7 whole-batch acceptance is still blocked; keep the historical 46/49 module engineering count separate. PR draft; no merge, tag, publish or Infbyte changes.

> **2026-10-15 — module cutover final remediation (source stage, QA pending):** Removed legacy `ModuleManager::install/remove` and `ModuleActivationManager::enable/disable` mutations, kept Core publication/read-only inventory and v2 locked feature lifecycle, and replaced stale legacy concurrency coverage with competing feature-plan tests. Also moved remember-token signatures/subject/device verification to Epicrypt 3.2. Previous module implementation totals are *historical*, not new acceptance. R6 automatic native Runwire operation forwarding and B7 physically matched host performance/final signoff remain blocked; same-SHA recertification and independent adversarial review required. Current Foundation release acceptance is **19/37, NO-GO**. See [live release audit](foundation-4.0-release-audit-and-plan.md).

# Foundation 4 — Module Architecture & Lifecycle Rework Plan

> **2026-10-15 feedback remediation checkpoint:** The Foundation 4 release re-audit remains **NO-GO, 19/37 renewed acceptance**. R5 installed Composer version normalization, R8 locked full-feature-snapshot and post-mutation dependency guards, and retirement of the redundant legacy `ModuleManager::install/remove` implementation (R9 partial) are source-implemented and awaiting exact-source verification. R9 obsolete activation methods and native remember-token ownership remain open; R6 real Runwire operation forwarding remains open. Previous B0–B6 / 46-of-49 counts are historical proof, **not revalidated end-to-end acceptance for reopened criteria**. Do not check B7 performance/sign-off without physically matched repeated host comparison and final same-SHA matrices. Main live ledger: [Foundation 4 release re-audit](foundation-4.0-release-audit-and-plan.md).


> **Re-audit note (2026-10-15):** Historical B0–B6 acceptance numbers below are not current certification for reopened Composer, concurrency, native integration or lifecycle concerns. The Foundation 4 release re-audit currently records **19/37** accepted. Short Composer installed-version handling is source-addressed but awaiting full QA. R8 stale module feature snapshots and R9 redundant mutation paths remain open. Do not raise architecture counts or accept B7 without exact-source and production-equivalent evidence.


> **Live status (2026-10-15, branch `feature/foundation-3`, exact audited source [`b8dad2c`](https://github.com/infocyph/Foundation/commit/b8dad2c4d619f917c660a85e68480e7fdcb29c2a)):** Batches 0–6 **42/42 QA accepted**. B7 **4/7 individual engineering gates checked; 0/7 full-batch accepted**. Overall **46/49 engineering QA-checked (93.9%)**, **42/49 batch-accepted (85.7%)**, **7/8 batches accepted**. The canonical 3-module/9-feature v2 API, unpublished release notes, Composer metadata and publication provenance were reviewed and regression-guarded. [Security & Standards #38722750952](https://github.com/infocyph/Foundation/actions/runs/38722750952) **SUCCESS** (QA PHP 8.4/8.5 × lowest/stable 4/4, PHPStan 2/2, production `--no-dev` consumers 4/4, benchmarks 2/2, clean install and release evidence) and [native HTTP host #38722750628](https://github.com/infocyph/Foundation/actions/runs/38722750628) **SUCCESS** on immutable source [`b8dad2c`](https://github.com/infocyph/Foundation/commit/b8dad2c4d619f917c660a85e68480e7fdcb29c2a); native host is functional smoke **not** controlled host RPM certification. All 52 PR inline review threads resolved. **Three B7 gates remain OPEN:** actual dedicated matched-host baseline/candidate comparison (3), stable ≤2% median validated successful-RPM budget with representative auth/database/cache workload (4), and contingent final release-note/go-no-go signoff (7). Foundation 4 separate release audit remains **32/37**, PR #20 stays draft; no Infbyte, merge, tag, publication.
> **Approved target:** prebuilt Foundation Core + preconfigured development QA + **3 optional modules / 9 independently selectable features**.
>
> **Working branch:** `feature/foundation-3` · **PR:** [#20](https://github.com/infocyph/Foundation/pull/20) (keep draft)  
> **Plan created:** 2026-10-14 · **Scope:** Foundation repository exclusively  
> **Companion release tracker:** [Foundation 4 release audit](foundation-4.0-release-audit-and-plan.md)  
> **Implementation cadence:** one complete batch → focused QA → full QA → evidence → tracker sync → next batch.

## 1. Intent and non-negotiable boundaries

Design a coherent, performance-first Foundation **module architecture** for substantial rework, with exactly **three public optional modules** (`data`, `security`, `execution`) and **nine optional features**. Foundation Core and PHPForge are not installable application modules. Start from the actual Foundation 4 implementation, not published 2.x contracts or an imagined greenfield framework. The work may intentionally reshape the current Foundation 4 module API and CLI output **once the design is approved**, but must not reintroduce previous-major compatibility shims.

The new design must clearly separate:

1. **Ecosystem domain/category:** for documentation, navigation, discoverability, grouping and optional policy.
2. **Composer package:** direct vs transitive installation ownership and supported package constraints.
3. **Application module:** deployable application purpose and its install/config/schema/diagnostics surface.
4. **Runtime capability:** explicit opt-in to an actual native integration/provider.
5. **Feature:** an independently selectable subset of a module, with its own package and platform needs.
6. **Native-library owner:** the upstream package responsible for its own mechanisms.
7. **Engineering tooling:** tools for building/testing Foundation; never silently made production modules.

The earlier five functional ecosystem categories are superseded as **module architecture** by **Core + 3 modules + separate QA tooling**. Do **not** make all ecosystem libraries “modules” just to match a grouping diagram. Do **not** create Foundation wrappers around native InterMix/Webrick/Runwire/DBLayer/CacheLayer/Omnibus/Epicrypt/OTP/Pathwise/ReqShield/TalkingBytes mechanisms already owned upstream.

**Out of scope:** Infbyte application code, Infbyte integration/migration, changes to unrelated Infocyph packages, merges, tagging, and publication. Do not implement speculative cross-repository changes. Source rework in Foundation reopens relevant release QA/performance gates; green historical workflow runs do not certify new source.

## 2. Verified current architecture (baseline, not necessarily final design)

### 2.1 Current module vocabulary

The documented [module system](../modules.md) has **11 catalog entries**:

| Type | Current entries | Notes |
| --- | --- | --- |
| Seven specialist-purpose namespaces | `auth`, `communication`, `database`, `filesystem`, `messaging`, `security`, `validation` | These express application purposes; they are not automatic Composer package discovery. |
| Four Foundation-built-in catalog entries | `logging`, `operations`, `resources`, `session` | Built-in config/schema features, with no specialist Composer installation/removal lifecycle. |
| Core-backed special case | `auth` (among the seven above) | Core authentication is Foundation-owned; OTP/passkey are optional selected features. |
| Core capability outside module catalog | `cache` / CacheLayer | Required Foundation dependency. Separate `cache:schema:*`; never `module:install cache`. |
| Native integration outside dedicated module install alias | `notifications` | Foundation-owned notification graph; TalkingBytes email is optional integration. |
| Core runtime integration | Runwire | Required production dependency; native capability checks and exact host-owned context forwarding. |

Current behavior worth preserving **unless a decision record explicitly supersedes it**:
- Composer **available/direct/transitive/unknown** are different states. Transitive availability is not direct module ownership.
- `install` selects required/feature packages; `enable` selects runtime policy; neither should imply the other.
- Developer package discovery and production explicit `app.capabilities` topology are distinct. `config/modules.php` overrides are partial and do not replace production's full explicit declaration.
- Auth OTP and passkey have different package and configuration implications. Passkey requires OTP support + WebAuthn, without activating unrelated OTP MFA.
- Module config publication respects project ownership; force replacement uses staging/backup with symlink protection.
- Targeted schema install belongs to the selected owner; aggregate sync follows only active capabilities. No schema/data deletion on module removal.
- Composer, config, cache invalidation and migrations are **not** one atomic transaction. Repair must be deterministic and idempotent; never promise an unsafe all-or-nothing Composer rollback.
- Current read/plan/doctor/list JSON is `schema_version: 1`; CLI machine consumers need versioned contracts rather than silently changing meaning.
- A production compiled generation must be deterministic, immutable, native-library owned and free from incidental Composer scans or dynamic module discovery.

### 2.2 Existing source and tests to inventory before redesign

| Responsibility | Existing implementation |
| --- | --- |
| Catalog, package/feature declarations, alias resolution | `src/Module/ModuleCatalog.php`, `src/Module/Internal/ModuleCatalogValidator.php` |
| State, direct/transitive ownership, readiness | `src/Module/ModuleStateResolver.php`, `src/Module/Internal/ModulePackageStateResolver.php`, `src/Module/Internal/ModulePlatformResolver.php` |
| Conditional dependencies/capabilities | `src/Module/Internal/ModuleDependencyResolver.php`, `src/Application/FoundationBuildContext.php` |
| Composer install/remove | `src/Module/ModuleManager.php` |
| Activation policy | `src/Module/ModuleActivationManager.php`, `config/modules.php` in consuming applications |
| Config publishing | `src/Module/Internal/ModuleConfigPublisher.php` |
| Schema management | `src/Module/ModuleSchemaManager.php`, `src/Module/FreshModuleSchemaRunner.php` |
| CLI orchestration/output | `src/Command/System/ModuleSystemCommand.php`, command registry/dispatcher |
| Core tests | `tests/Feature/ModuleCatalogModelTest.php`, `ModuleStateResolverTest.php`, `ModuleLifecycleIntegrationTest.php`, `ModuleConfigPublicationTest.php`, `NativeCapabilityPolicyIntegrationTest.php`, `RuntimeCapabilityConfigTest.php`, `DatabaseMigrationCapabilityCompositionTest.php` |

**Audit candidates, not pre-established defects:** large central catalog and CLI/state classes; possible mixed concerns (metadata, resolver, lifecycle orchestration, presentation); multiple representations of feature selection/activation; synchronization of catalog floors, Composer, tests and documentation; missing deterministic integration-contract inventory. Confirm each hypothesis with traces/tests before changing production code.

## 3. Approved architecture — prebuilt Core + exactly three optional modules

**Architecture choice: APPROVED (2026-10-14).** This is the target for the rework, **not the current implementation**. Foundation's existing 11-entry catalog remains the baseline until Batch 1–6 changes have been implemented and tested.

### 3.1 Prebuilt Foundation Core and development tooling (never installable modules)

| Layer | Ownership and packages | Installation and lifecycle rule |
| --- | --- | --- |
| **Foundation Core** | ArrayKit `^5.3.1`, CacheLayer `^4.0`, InterMix `^11.2`, UID `^6.0`, Webrick `^6.0.3`, external `psr/log ^3.0.2` | Existing **required Foundation Composer/runtime dependencies**. No module install/enable/disable/remove commands for these libraries. |
| **Core application capabilities** | Built-in auth policy, sessions, logging, operations, resources, notifications and CacheLayer-backed caching | Available as Foundation-owned built-in functionality. Capability-specific configuration/policy can still be explicit; don't turn built-ins into installable packages. Preserve their native schema/config owners. |
| **Engineering & Quality Assurance** | **PHPForge** `dev-main@dev` | Preconfigured for Foundation's **development, CI, diagnostics, benchmarks and release QA**. Development-only: never shipped as a production module, auto-enabled runtime provider or package installed by user module commands. |

**“Prebuilt” describes the Foundation baseline and tooling, not a promise that every dependency's source is physically bundled in an archive.** Required Composer libraries stay required, and PHPForge remains a development dependency.

### 3.2 Three public optional modules / eight optional features

| Public module | Feature ID | Primary Infocyph owner | Optional/external dependency and intended scope |
| --- | --- | --- | --- |
| **`data` — Data & Storage** | `database` | **DBLayer** `^6.1` | Connections, migrations, schema/read-write persistence. Core CacheLayer is reused, not installed. |
| `data` | `filesystem` | **Pathwise** `^4.2` | Filesystem/storage features; no requirement to enable database. |
| **`security` — Security & Identity** | `crypto` | **Epicrypt** `^3.2` | Cryptography, secrets, tokens and applicable OAuth primitives; use actual native APIs. |
| `security` | `validation` | **ReqShield** `^3.3` | Validation/sanitization/request security; independently selectable. |
| `security` | `mfa` | **OTP** `^7.0` | OTP/MFA factors and their applicable crypto/platform requirements; never auto-enable passkey. |
| `security` | `passkey` | **OTP/WebAuthn integration** | WebAuthn ceremonies via `web-auth/webauthn-lib ^5.3.9` and selected OTP support; **dependency sharing does not imply automatic activation of MFA**. |
| `execution` | `messaging` | **Omnibus** `^3.0` | Events, queues, durable transports. Portable messaging remains independent of the Core Runwire integration; durable storage may conditionally require `data.database`. |
| `execution` | `communication` | **TalkingBytes** `^2.3` | HTTP/email/webhook/gRPC integration; choose features without activating unrelated runtime/messaging. |

**Eight feature identities:** `data.database`, `data.filesystem`, `security.crypto`, `security.validation`, `security.mfa`, `security.passkey`, `execution.messaging`, `execution.communication`.

**External/dependency boundaries:** `psr/log` belongs to Core; WebAuthn is selected only for `security.passkey`; Sodium requirements are determined by the selected crypto/MFA feature and platform; `opis/closure ^4.5` remains a **development dependency**, not a mandatory execution package. PHP `^8.4` and Composer Runtime API `^2.0` remain platform requirements. The actual Composer install plan must be computed from feature dependencies and root ownership, never from the whole category.

### 3.3 Opt-in behavior and dependency handling

- **Default installation:** Foundation Core works without any optional module. A top-level category name is a namespace for features, **not an instruction to install all of its libraries**.
- **Feature installation:** Install precisely the selected feature's required packages and any real conditional prerequisite, with explicit dry-run planning and shared-package ownership.
- **Feature activation:** Installation and enablement are different operations. Enable only selected feature capabilities; never auto-enable siblings.
- **Conditional dependencies:** Durable `execution.messaging` can require `data.database` when a DB-backed transport is chosen; `security.passkey` can require OTP/WebAuthn packages and platform support, without enabling `security.mfa`. No global mandatory data/security/execution dependency chain.
- **Proposed public CLI** (not implemented): `module:install data --feature=database`, `module:enable security --feature=passkey`, `module:plan execution --feature=messaging`. Command and machine schema details will be finalized in D09.
- **Runtime performance:** only the selected feature's native providers reach the compiled InterMix/Webrick/Runwire execution graph; no Composer, filesystem discovery, category iteration or PHPForge activity on the production request path.

### 3.4 Current 11-entry catalog → approved target

This is a **design migration within the Foundation 4 candidate**, not backward compatibility for Foundation 2.x/3.x.

| Current catalog entry / integration | Target ownership |
| --- | --- |
| `database` | `data.database` |
| `filesystem` | `data.filesystem` |
| `security` (current package/integration role) | `security.crypto` |
| `validation` | `security.validation` |
| `auth` core policy | **Foundation Core auth**; no optional auth module |
| `auth` OTP and passkey selections | `security.mfa` / `security.passkey`, separately activated |
| `messaging` | `execution.messaging` |
| `communication` | `execution.communication` |
| Runwire integration | Required Core runtime with native host ownership |
| `logging`, `operations`, `resources`, `session` | **Foundation Core built-ins**, not exposed as optional installable modules |
| CacheLayer/`cache` and native notifications | **Foundation Core**, never optional installable modules |
| PHPForge | **Engineering/QA only**, never runtime module |

**Public catalog acceptance:** exactly 3 module IDs and 8 feature IDs, with no fourth pseudo-module for Core, CacheLayer or PHPForge. Dedicated built-in introspection may remain available, but it is not counted as an installable module. All 11 current entries must be accounted for by this ownership map, with no orphaned config/schema or accidental deletion of persisted data.


## 4. Target contracts for the approved three-module architecture

### 4.1 Logical contracts

Prefer a **curated, deterministic catalog**, potentially split into small declarative definition files by purpose/domain, rather than filesystem scanning, plugin autoload magic or mandatory runtime reflection. Suggested conceptual structures (naming not approved yet):

- **Category presentation metadata:** three fixed module names and feature ordering only; Core and QA remain outside the module catalog. No generic domain-plugin runtime layer.
- **ModuleDefinition:** exactly `data`, `security`, `execution`; stable public ID, feature ownership, config/schema owners and supported feature dependencies/platform checks. Built-in/core capabilities are separate non-installable Foundation metadata.
- **FeatureDefinition:** exactly eight approved optional feature IDs (see §3.2); their own packages, platform, config predicate, native capabilities and conditional dependency edges.
- **CapabilityPolicy:** single canonical normalization of `app.capabilities` and module overrides; separate developer detection from explicit production activation.
- **ModuleResolution:** immutable read-only, derived state for available vs root-direct/transitive, selected/active, installed, configured/published, platform, dependency and schema readiness.
- **ModuleChangePlan:** versioned deterministic **non-mutating** install/enable/disable/remove/repair intent with exact required mutations, blockers, warnings and effects.
- **LifecycleExecutor:** applies an explicitly approved change plan, owns safe boundaries of Composer/config/cache/schema operations, reports phase results and supports idempotent repair; no impossible global rollback.
- **SchemaOwnerMap:** native-owner or Foundation-owned mappings; no duplicate domain schema paths in generic database ownership.

These are semantic boundaries, **not** an instruction to create one class per noun. Avoid DTO layers, wrapper services, resolvers, builders and adapters unless measurements or testability justify them. Reuse validated native owners and the smallest possible graph.

### 4.2 Lifecycle and execution invariants

- `catalog → normalize intent → resolve dependencies/ownership/platform → immutable plan → explicit apply → re-resolve state → report`; reads never mutate.
- Separate package installation, declared feature state, runtime activation, config materialization and schema migrations. All side effects must be clearly announced in a plan.
- Fail closed on unknown root Composer ownership, ambiguous aliases, unsatisfied dependents, insecure config paths and unsupported native runtime/platform.
- Shared optional package cannot be removed when another selected feature/module still needs it; **Core and PHPForge cannot be module-installed, disabled or removed**.
- Versioned machine-readable API with additive compatibility *within the chosen 4.0 schema version only*. If rework makes a breaking JSON change, increment schema and document migration for current-4.0 tooling; there is **no Foundation 2.x/3.x parity obligation**.
- Stateless, deterministic, allocation-aware read/list/status/plan paths; do not include runtime request/secret state in a persistent catalog.
- Production graph compiles only selected feature-capability nodes from **the three public modules** plus prebuilt Core. No package scanning, Composer process, mutable activation, module schema IO or business-policy interpretation on every request.
- No duplicated native HTTP router, message bus, transaction manager, identity token implementation, persistence engine or scheduler.
- All new CLI side effects execute only in explicit write commands, with stable exit codes, lock/atomic write safety where applicable, audit output and phase-specific failure recovery.

## 5. Architectural decisions and remaining design questions

**The user has explicitly approved** Core + 3 optional modules + 8 feature IDs, with PHPForge preconfigured separately. That **resolves the top-level taxonomy and installability**, not every lifecycle, API or migration detail.

| ID | Decision | Status | Selected direction / remaining question |
| --- | --- | --- | --- |
| D01 | Catalog scope | **DESIGN SELECTED** | Three first-party curated modules, eight features, deterministic validation; no filesystem/package discovery or third-party registry in this rework. |
| D02 | Public module structure | **APPROVED** | Exactly `data`, `security`, `execution`; no five-category runtime hierarchy. Core and QA outside the optional module catalog. |
| D03 | Core and built-in semantics | **APPROVED** | Foundation Core prebuilt/required. Auth policy, cache, Runwire integration, logging, operations, resources, session, notifications stay Core-owned; no built-in pseudo-modules. |
| D04 | Capability source | **DESIGN SELECTED** | Explicit production `app.capabilities` governs optional feature providers; Core Runwire input integration remains available without granting specialist capabilities; partial dev overrides permitted when no full topology is declared. |
| D05 | Package/feature granularity | **APPROVED** | Eight independently selectable features, precisely scoped packages, real conditional prerequisites and shared ownership. No install-all-on-category-selection. |
| D06 | Plan/apply | **DESIGN SELECTED** | One pure versioned plan and smallest practical phase executor; Composer/config/schema cannot be one atomic transaction and repair must be idempotent. |
| D07 | Config ownership | **DESIGN SELECTED** | Feature-owned config only, preserving application files; reuse staging/backup/symlink guards and distinguish publication from validity. |
| D08 | Schema ownership | **DESIGN SELECTED** | Core auth/session/cache schemas remain Core; Omnibus messaging schema belongs to `execution.messaging`; DBLayer is infrastructure, not other domains' schema owner. |
| D09 | Public CLI/JSON contracts | **DESIGN SELECTED** | `module:<verb> <data|security|execution> --feature=<name>` for mutations; bare module names inspect/plan only; version breaking machine payloads as v2; no ambiguous `runtime` bare alias. |
| D10 | Performance guardrails | **DESIGN SELECTED** | Static catalog and compiled native graph, no hot-path Composer/module scans; characterize cold/warm CLI and enforce 2% sustained successful host RPM budget. |
| D11 | Engineering tooling | **APPROVED** | PHPForge preconfigured for development/CI and release QA only; never installed or activated as a production module. |
| D12 | Core native runtime | **APPROVED 2026-10-16; supersedes optional runtime** | Runwire is required Core; host ownership and capability checks remain native. Omnibus remains optional `execution.messaging`. |

For implementation-stage decisions, keep the **selected design** and source-backed rationale in [Batch 0 audit](foundation-4-module-batch0-audit.md); re-open only on concrete conflicting native-library evidence, tests or performance. D02/D03/D05/D11/D12 were approved by the user; D01/D04/D06–D10 are **engineering design selections pending implementation and QA**, not additional user approvals.


## 6. Historical implementation batches and acceptance tracker

The recorded counts and immutable-source acceptances below precede the Core Runwire correction. They remain historical proof; fresh validation of the eight-feature/Core contract is recorded in the current correction section.

**Progress rule:** A batch is accepted only after every item and all batch QA gates are complete, committed to the working branch, verified on exact source, and reflected in this tracker. Drafting this plan does not count as implementation.

| Batch | Workstream | Status | Acceptance |
| --- | --- | --- | --- |
| **0** | Audit, baseline evidence and remaining design decisions | **COMPLETE (5/5)** | Audit report + concrete design decisions + baseline tests/metrics |
| **1** | Exactly three public modules and nine feature definitions | **COMPLETE (5/5)** | Stable definitions, validated category/ownership contracts |
| **2** | Dependency/state resolution and pure planning | **COMPLETE (6/6)** | Deterministic topology, blockers, versioned no-side-effect plans |
| **3** | Install/enable/disable/remove/repair execution | **COMPLETE (7/7 implemented and accepted)** | Safe explicit mutations, atomic boundaries, idempotent recovery |
| **4** | Three-module selected feature graph and prebuilt Core | **COMPLETE (6/6 implemented and QA-accepted)** | One immutable selected native graph, no runtime discovery |
| **5** | All nine specialist features, schemas and native ownership | **COMPLETE (7/7 implemented and QA-accepted)** | Auth, cache, database, messaging and other owners proven |
| **6** | CLI/API UX, docs, templates and upgrade notes | **COMPLETE (6/6 implemented and QA-accepted)** | Machine/human UX verified with doc/test parity |
| **7** | Full regression, performance and release re-certification | **IN PROGRESS (4/7 staged/QA-checked; 0/7 whole batch accepted)** | Matched-host sustained RPM and final release decision blocked |
| **Total** | **49 scoped acceptance items** | **46/49 implemented/QA-checked (93.9%); 42/49 fully accepted (85.7%)** | **7/8 batches accepted** |

### Batch 0 — Audit and approved architecture

- [x] Trace module operations from CLI through catalog, state, activation, Composer runner, config publication, schema manager and native graph. **Evidence:** [Batch 0 source audit](foundation-4-module-batch0-audit.md) §A.
- [x] Record current commands, JSON schema contracts, error/exit codes and real root-package ownership cases with golden fixtures. **Evidence:** [source audit](foundation-4-module-batch0-audit.md) §A and version-1 contract tests, [QA #38570463538](https://github.com/infocyph/Foundation/actions/runs/38570463538).
- [x] Inventory all 11 **current** catalog entries and their exact mapping into prebuilt Core, three approved modules, nine features, PHPForge QA and external-dependency roles. **Evidence:** [Batch 0 audit](foundation-4-module-batch0-audit.md) §A.
- [x] Establish representative baseline: module:list/show/plan/doctor cold/warm, install/remove dry-run, selected/disabled compiled runtime, worker lifecycle and memory; record machine/toolchain/environment. **Evidence:** [baseline CLI report, PHP 8.5.11 (15 warm samples)](foundation-4-module-batch0-audit.md) §C in [QA artifact #38570463538](https://github.com/infocyph/Foundation/actions/runs/38570463538), [native host #38570462819](https://github.com/infocyph/Foundation/actions/runs/38570462819). These do not certify production-equivalent RPM.
- [x] Resolve detailed engineering decisions D01/D04/D06–D10 (including D09 CLI JSON), confirm the **APPROVED** Core + 3 modules/9 features boundary, source ownership and batch order. **Evidence:** [Batch 0 audit](foundation-4-module-batch0-audit.md) §B. These are selected for implementation validation; no runtime code has changed.

**QA gate:** no behavior changes; exact-branch baseline QA recorded, design choices explicit, no invented performance or existing defect claims.

### Batch 1 — Canonical module and domain model

- [x] Define **exactly 3 public module IDs** and **9 independently selectable feature IDs**, stable fully qualified feature IDs, dependency/platform invariants and precise package ownership; Core is not a module kind. **Evidence:** `ModuleArchitectureCatalog`, `ModuleArchitectureCatalogTest`; [QA #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789).
- [x] Implement approved `data`/`security`/`execution` taxonomy and nine feature definitions as **canonical target metadata** (`ModuleCatalog::architecture()`), without deleting built-in Core functionality. **Staged acceptance:** existing 11-entry legacy CLI/state remains live until the coordinated **Batch 6 public catalog cutover**; this is **not** a claim that `module:list` already shows three modules. See explicit Batch 6 cutover gate below.
- [x] Keep required Core and development-only PHPForge outside optional module definitions; distinguish PSR Log, WebAuthn, Sodium and Opis/Closure from optional native feature package ownership. **Evidence:** metadata and Composer floor/ownership tests, [QA #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789).
- [x] Validate catalog collisions, package feature co-ownership, dependency cycles, config/schema identifiers and supported library floors. **Evidence:** `ModuleArchitectureValidator` + negative fixture tests (including Core schema, duplicate config and OTP shared constraint), [QA #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789).
- [x] Keep curated metadata static and move structural validation into focused `ModuleArchitectureValidator` methods instead of expanding the legacy catalog or hot request graph. **Evidence:** 2/2 PHPStan, 4/4 PHPForge QA, benchmark jobs and existing native FPM/Runwire host workflow **SUCCESS** at `4f307fba`, [QA #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789) / [host #38577821971](https://github.com/infocyph/Foundation/actions/runs/38577821971). No claim of 2% production-RPM certification.

**Batch 1 QA ACCEPTED:** fixed **11-current → 3-module/9-feature** ownership mapping, 3 target modules / 9 feature IDs in deterministic golden **target catalog** tests, Core/QA exclusion and no dynamic scans. Existing CLI output remains 11 until **Batch 6**. [Exact source `4f307fba`](https://github.com/infocyph/Foundation/commit/4f307fba765be4c08f440a2a31ab082650cf35d7): [Security & Standards #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789) **SUCCESS**; [FPM/Runwire host #38577821971](https://github.com/infocyph/Foundation/actions/runs/38577821971) **SUCCESS**.

### Batch 2 — Pure state/dependency resolver and planner

- [x] One normalization pipeline for the **3 approved public module IDs + 9 feature IDs**, their requested feature aliases and explicit capability policy.
- [x] Correct direct/transitive/unknown package-state and supported root Composer constraint decisions.
- [x] Evaluate conditional dependencies, native runtime/platform requirements and dependency cycles in predictable order.
- [x] Build immutable read-only plan records for install/enable/disable/remove/repair, including phase effects and blockers.
- [x] Keep observability/read status separate from mutation; planner must have zero FS/network/Composer side effects.
- [x] Version schema of machine-readable plan/state output if semantics change; compare deterministic fixtures.

**Batch 2 QA ACCEPTED at exact executable source [`dc7a4039`](https://github.com/infocyph/Foundation/commit/dc7a40392e784e885613e736d9e4ec117e3a15d8):** [Security & Standards #38599560658](https://github.com/infocyph/Foundation/actions/runs/38599560658) **SUCCESS** (PHPForge QA 4/4, PHPStan analysis 2/2, production consumers 4/4, benchmarks 2/2, clean install, release evidence); [native host #38599560098](https://github.com/infocyph/Foundation/actions/runs/38599560098) **SUCCESS** (native FPM/Runwire hosted smoke). Nine-feature pure planner tests cover conditional database/extension prerequisites, direct/transitive/unknown Composer ownership, unsupported constraints, disabled-feature recovery, shared OTP/WebAuthn removal, deterministic schema-version-2 effects and read-only inputs. The previously reported Pint failures and complexity 15 > 12 in `ModuleFeaturePlanner::plan()` have been resolved and verified. The PHP 8.4 stable duplicate scanner timed out in an earlier run but **passed** in the accepted exact-source run. This is a **module Batch 2 implementation/QA certification**, not a claim that the 3-module CLI already cut over or that Foundation 4 sustained host performance is certified.

### Batch 3 — Safe lifecycle mutations

- [x] **Staged internal Composer phase only:** `ModuleFeatureComposerExecutor::applyPackages()` reconciles explicitly selected feature packages, protects Core/PHPForge, checks live root ownership, serializes mutations and preserves dry-run/no-rollback boundaries. Six fixture Composer tests. **Public `module:install` stays on its existing 11-entry interface until coordinated Batch 6; do not claim the new CLI is live.** Evidence: [source `aac1f39`](https://github.com/infocyph/Foundation/commit/aac1f39bc035d28068ecb8ee9b11b6e18807fd1d), [Security & Standards #38604462397](https://github.com/infocyph/Foundation/actions/runs/38604462397) and [native host #38604461132](https://github.com/infocyph/Foundation/actions/runs/38604461132).
- [x] **Staged internal feature activation:** `ModuleActivationManager::applyFeatures()` validates the pure plan, writes only selected `modules.features` overrides under the existing lock and rejects stale snapshots/active dependents; it preserves legacy capability policy and does **not** yet wire production graph policy (B4/B6). Five regression tests. [Security & Standards #38609784501](https://github.com/infocyph/Foundation/actions/runs/38609784501), [native HTTP host #38609782896](https://github.com/infocyph/Foundation/actions/runs/38609782896) at `64f87bd`.
- [x] **Staged internal feature config:** `ModuleFeatureConfigPublisher` uses the established atomic `ModuleConfigPublisher` for planned, prerequisite-inclusive templates, without forced replacement; dry-run has no mutation, and actual changes invalidate config cache. Four regression tests, including application-owned config preservation. Public cutover deferred B6. [Security & Standards #38609784501](https://github.com/infocyph/Foundation/actions/runs/38609784501), [native HTTP host #38609782896](https://github.com/infocyph/Foundation/actions/runs/38609782896) at `64f87bd`.
- [x] Schema actions are targeted by owner, idempotent, additive/non-destructive by default. **Implementation:** `ModuleFeatureSchemaExecutor` resolves selected schema ownership, refuses unrelated Core schemas and missing native messaging/DBLayer packages, reuses owner-provided additive `ModuleSchemaManager`, and fails closed if durable native capability is inactive or schema remains unavailable. [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270), [native HTTP host #38614848062](https://github.com/infocyph/Foundation/actions/runs/38614848062).
- [x] Removal checks shared feature ownership, direct root requirements, dependent features and data retention; **Core and QA are not removable modules**. **Implementation:** pure planner + root-verified Composer executor + `ModuleFeatureLifecycleExecutor` require known selected ownership, preserve shared OTP, prevent enabled/dependent removal, never delete application config/schema/data. Regression tests for actual shared-package removal argv and retained application data. [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270).
- [x] Repair handles partial phase completion and repeat invocations without imaginary Composer rollback. **Implementation:** source-scoped lifecycle executor requires a fresh post-Composer context, verifies no unresolved requirements, then runs config and selected owner schema; reports phase failure and actionable re-plan/repair. Fixtures cover nonzero Composer exits, stale successful Composer snapshots, interrupted config publication and repeated repair without package reinstallation. [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270).
- [x] Side-effect order, invalidation, failure summaries and actionable recovery paths are deterministic. **Implementation:** versioned pure plan; explicit Composer → refreshed ownership → atomic config/cache invalidation → owner schema phases; separate activation; deterministic structured outcomes and partial/recovery descriptions. Persistent activation/Composer locks, synchronized process-start regression, symlink-file/directory rejection, dry-run without side effects. [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270), [native HTTP host #38614848062](https://github.com/infocyph/Foundation/actions/runs/38614848062).

**QA gate:** fixture Composer processes, injected failures at each step, multi-process writer safety, rollback/repair invariants, zero deleted app data, no unexpected package installs.

**Batch 3 ACCEPTED (2026-10-14):** **7/7 scoped implementation and QA gates** closed at immutable source [`3675f8b`](https://github.com/infocyph/Foundation/commit/3675f8bd8f6256f0a4e28fd9fc743d8498f7ffe2). [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270) **SUCCESS** (PHP 8.4/8.5 × lowest/stable 4/4 QA, PHPStan 2/2, consumers 4/4, benchmarks 2/2, clean install, release evidence), [native HTTP host #38614848062](https://github.com/infocyph/Foundation/actions/runs/38614848062) **SUCCESS** (native FPM/Runwire HTTP, soak, failure/cancellation, host telemetry). Batch 3 covers independently selected Composer packages, locked explicit feature activation, safe atomic app config, owner-scoped additive messaging schema, selected/shared/dependent-safe removal without data deletion, deterministic partial-phase repair and concurrency/failure fixtures. Prior run-specific Pint/complexity violations were fixed; the last formatter-only issue was corrected by [`3675f8b`](https://github.com/infocyph/Foundation/commit/3675f8bd8f6256f0a4e28fd9fc743d8498f7ffe2). **Boundary:** this certifies the *staged internal module lifecycle*, not the public CLI cutover or a fully compiled three-module production graph; those belong to Batches 4 and 6. Foundation 4 sustained matched-hardware host RPM certification belongs to its separate release tracker; do not claim publication readiness. Infbyte and upstream projects untouched.

### Batch 4 — Native capability composition and production compilation

- [x] **Staged build-context policy** connects only approved `data.*`, `security.*`, `execution.*` feature selections to canonical `FoundationBuildContext`, preserving prebuilt Core and the legacy fallback only when `modules.features` is absent. Validated all nine flags and conditional dependencies; selected native provider absence fails closed; security.mfa and security.passkey cannot silently enable each other; `ConfiguredCapabilities` follows the same policy. Build-time selection cannot override an explicit production capability allowlist. **Exact-source** [Security & Standards #38621413325](https://github.com/infocyph/Foundation/actions/runs/38621413325) and [native HTTP host #38621412456](https://github.com/infocyph/Foundation/actions/runs/38621412456) **SUCCESS** at [`6094136`](https://github.com/infocyph/Foundation/commit/609413680f30d31d8cbdcbae102c45736a2d5d2e). Public CLI cutover still B6.
- [x] **Staged InterMix provider finalization** is deterministic and complete before immutable compilation. `ServiceRegistry` seals provider membership before invoking the first contribution; subsequent builder contributions reuse the same ordered owners and exact `FoundationBuildContext`. Late registration, registration from inside a provider callback, or re-contribution with a changed context now fails closed. The native web and non-web release compilers already reuse the sealed registry before their respective InterMix/Webrick compilation. Four regression tests assert ordering, reproducibility, rejected late changes and context identity. **Exact source** [`521f2a7`](https://github.com/infocyph/Foundation/commit/521f2a78df1c8058cfddd826365d1438ca15c84f), [Security & Standards #38624650626](https://github.com/infocyph/Foundation/actions/runs/38624650626) **SUCCESS**, [native HTTP host #38624649819](https://github.com/infocyph/Foundation/actions/runs/38624649819) **SUCCESS**.
- [x] Keep **Webrick route/HTTP ownership** and **Runwire host-owned runtime identity** intact. `ModuleNativeOwnershipGraphTest` covers Webrick route/HTTP providers only in web topology, exclusion in CLI/worker/scheduler graphs, and independent `execution.runtime` selection (no implicit Omnibus or TalkingBytes). Native Runwire input identity and web host smoke are verified by production consumers + [native HTTP host #38630076643](https://github.com/infocyph/Foundation/actions/runs/38630076643); Foundation creates no competing router/runtime owner. [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952) **SUCCESS**, source [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14).
- [x] Ensure **worker/scheduler/CLI execution scopes** never leak request principal or mutable module state. `ExecutionScopeIsolationTest` now checks selected-feature scoped `RuntimeExecutionState` remains stable within a job and fresh on subsequent jobs, `CurrentPrincipalContext` clears between runs in all three non-web modes, and forged Runwire host inputs fail. Existing native web request/fiber principal reset and host-owned scope tests remain green. [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952), [native HTTP host #38630076643](https://github.com/infocyph/Foundation/actions/runs/38630076643), source [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14).
- [x] Verify **compiled release ABI/trust/generation and real-topology-only invalidation**. `GeneratedNonWebRuntimeTest` rejects changed feature flags, corrupted metadata and mismatched trusted artifact identities. `FoundationBuildContext` canonicalizes selected feature flags *before* InterMix freezes its graph, so the same flags in different key order retain frozen graph identity without weakening InterMix verification. `ModuleActivationManager` now preserves the config file/inode and existing cache if the locked feature-state mutation is a no-op; any real state change keeps the existing atomic write/invalidation semantics. Native release generation/manifest trust tests remain green. [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952) and [native HTTP host #38630076643](https://github.com/infocyph/Foundation/actions/runs/38630076643) **SUCCESS**, [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14).
- [x] Verify **Core-only**, **each of nine individual features**, representative shared/conditional combinations and **all nine together**, with disabled specialist providers absent in production `--no-dev` compiled installs. `tests/Fixtures/ModuleFeatureProductionMatrix.php` is executed by all four isolated Composer consumer permutations (PHP 8.4/8.5 × lowest/stable), checks 13 explicit feature topologies with `FoundationBuildContext` + `Bootstrapper` + native providers, and compiles/loads each via `GeneratedRuntimeCompiler`/InterMix. Core-only consumers install no specialist packages; full consumers explicitly install all supported specialists. Disabled providers never appear in selected graphs; production authentication security guard remains unchanged. [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952) **SUCCESS**, source [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14).

**QA gate:** native compiled Webrick+InterMix and Runwire exact-host tests; source-free release trust; PHP 8.4/8.5 stable/lowest.

**Batch 4 ACCEPTED (2026-10-15, 6/6):** Exact executable source [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14), [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952) **SUCCESS** (QA 4/4, both PHPStan analyses, all four fully isolated production `--no-dev` consumers including selected-feature compilation matrix, both benchmarks, clean install and release-evidence generation), and [native HTTP host #38630076643](https://github.com/infocyph/Foundation/actions/runs/38630076643) **SUCCESS** (native Webrick FPM/Runwire host). The **3-module/9-feature compile-time selection**, sealed context-pinned InterMix contribution graph, native Webrick/Runwire identity, non-web scoped execution/principal isolation, trusted compiled runtime generation and graph identity, topology-only cache invalidation, and 13-config compiled feature matrix are verified. The `modules.features` map remains opt-in during the migration; 11-entry public CLI replacement is deliberately deferred to B6. Foundation 4's separate sustained matched-hardware RPM release gates remain **32/37** and **not certified by this module QA**. No Infbyte, merge, tag, publication or upstream changes.

### Batch 5 — Domain/feature integration and persistence ownership

- [x] **Staged native Core-auth/MFA/passkey isolation:** prebuilt AuthServiceProvider remains Core; selected OTP MFA now declares **conditional `security.crypto`/Epicrypt** prerequisite because native `AuthMfaRegistrar` requires Epicrypt KeyDeriver. `security.passkey` stays independently selectable with shared OTP package, without activating OTP MFA or Epicrypt; simple MFA remains independent. Pure planner/selected build policy and compiled graph tests, alongside retained OTP/CacheLayer replay-safe unit coverage. [`829044d`](https://github.com/infocyph/Foundation/commit/829044da3bf3b812553fc92c427a7f2f91453f17); [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) and [native HTTP host #38674333265](https://github.com/infocyph/Foundation/actions/runs/38674333265) **SUCCESS**. Public activation CLI cutover remains B6.
- [x] **Staged specialist security owners:** `ModuleSpecialistSecurityGraphTest` composes native Epicrypt, ReqShield and WebAuthn InterMix services with exactly one dedicated provider per selected security owner, no unintended DB/messaging providers, no accidental OTP MFA on passkey-only paths, and Core auth unaffected. [`829044d`](https://github.com/infocyph/Foundation/commit/829044da3bf3b812553fc92c427a7f2f91453f17); [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) **SUCCESS**.
- [x] **Staged data owner isolation:** `ModuleSpecialistDataGraphTest` checks Core-only/database-only/filesystem-only/both combinations, actual DBLayer `Connection` and Pathwise `FilesystemOperator` definitions, CacheLayer Core provider retention, owner-scoped `database.php`/`filesystem.php` configs and no optional Core schema acquisition. [`829044d`](https://github.com/infocyph/Foundation/commit/829044da3bf3b812553fc92c427a7f2f91453f17); [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) **SUCCESS**.
- [x] **Staged execution owner isolation:** `ModuleSpecialistExecutionGraphTest` verifies Omnibus `MessageBus` and TalkingBytes `HttpClient` appear only when selected, including combination scenarios; Runwire remains a host-owned native runtime input and never implicitly selects messaging or communication. Durable messaging requires explicitly selected DBLayer, not implicit Runwire. Existing native host identity tests + [native HTTP host #38674333265](https://github.com/infocyph/Foundation/actions/runs/38674333265) **SUCCESS**; [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) **SUCCESS**.
- [x] Preserve **Core auth/cache/logging/operations/resources/session/notifications** outside all optional module lifecycle operations. `CoreCapabilityDiagnostics` emits seven stable `core:*` readiness checks with explicit enabled/policy-disabled and non-installable status; `ReadinessReport` avoids stale legacy module checks when `modules.features` exists. Two regression tests reject `core.*`/`qa.*` in the nine-feature planner. [`c2d04ba`](https://github.com/infocyph/Foundation/commit/c2d04bad6af9a3c9bacc4ea27a2a050a5004862e), [Security & Standards #38676123308](https://github.com/infocyph/Foundation/actions/runs/38676123308) **SUCCESS**, [native HTTP host #38676123115](https://github.com/infocyph/Foundation/actions/runs/38676123115) **SUCCESS**.
- [x] **Staged external dependency audit:** `ModuleExternalDependencyRoleTest` checks `psr/log` is required Core runtime, `web-auth/webauthn-lib` belongs only to passkey's shared OTP feature package set, `opis/closure` remains dev-only, and feature-specific PDO/fileinfo/hash/mbstring/ctype requirements are distinguished from optional sodium/pcntl/posix. Real `--no-dev` consumer matrix [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) **SUCCESS**; [`829044d`](https://github.com/infocyph/Foundation/commit/829044da3bf3b812553fc92c427a7f2f91453f17).
- [x] Verify **DB/cache/session/queue failure, cancellation, durable recovery, native schema ownership** against selected services. Added DBLayer/Omnibus SQLite durable message test with exactly `data.database` + `execution.messaging`, failed delivery retained in DBLayer native failure store, next delivery succeeds, in-transaction cancel/rollback never enqueues, selected durable schema stays installed, and missing DB feature fails before storage access. Existing SessionLockContention, CacheLayer atomic/outbox and generated worker cancellation/release suites remain green on the same PHPForge matrix. [`c2d04ba`](https://github.com/infocyph/Foundation/commit/c2d04bad6af9a3c9bacc4ea27a2a050a5004862e); [Security & Standards #38676123308](https://github.com/infocyph/Foundation/actions/runs/38676123308) **SUCCESS**, [native HTTP host #38676123115](https://github.com/infocyph/Foundation/actions/runs/38676123115) **SUCCESS**.

**QA gate:** native-library integration matrix, no accidental capability activation, no data loss, schema/capability security isolation, production `--no-dev` portability.

**Batch 5 ACCEPTED (2026-10-15, 7/7):** Immutable source [`c2d04ba`](https://github.com/infocyph/Foundation/commit/c2d04bad6af9a3c9bacc4ea27a2a050a5004862e): [Security & Standards #38676123308](https://github.com/infocyph/Foundation/actions/runs/38676123308) **SUCCESS** (PHPForge QA 4/4, static analysis 2/2, production `--no-dev` 4/4, benchmarks 2/2, clean install and release evidence), [native HTTP host #38676123115](https://github.com/infocyph/Foundation/actions/runs/38676123115) **SUCCESS**. Core introspection stays non-installable; native security/data/execution integrations remain independently selectable; selected OTP MFA has conditional Epicrypt owner prerequisite. Real selected SQLite DBLayer/Omnibus durable failure, recovery and rollback tests plus existing CacheLayer, session-lock, auth replay and worker cancellation tests certify persistence boundaries without synthetic data deletion. **Next Batch 6** coordinated public catalog/CLI cutover: legacy 11-entry commands are not yet replaced; do not claim B6 acceptance. Separate Foundation 4 release audit 32/37 open for matched-host sustained RPM. Infbyte untouched.

### Batch 6 — Command UX, documentation and catalog publishing

- [x] **Public CLI cutover complete:** `ModuleSystemCommand` and `CommandCatalog` now use exactly **3 public modules / 9 qualified feature IDs**, with shared `ModuleFeatureCommandCatalog`, `ModuleFeatureCommandSnapshot`, B2 planner and B3 lifecycle across list/show/doctor/plan/install/remove/enable/disable/repair/config/schema. Built-in Core/QA have no optional lifecycle and no v1 aliases or implicit install-all. Verified by executable `ModuleLifecycleIntegrationTest`, [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597), [native HTTP host #38686270918](https://github.com/infocyph/Foundation/actions/runs/38686270918) at [`6358b97`](https://github.com/infocyph/Foundation/commit/6358b975a12add6d598e3e06e2724edd9a3cecfd).
- [x] **Deterministic human and machine contracts:** catalog lists three ordered groups, nine stable qualified feature IDs, and explicit `schema_version:2` inventory, state, plan and lifecycle JSON. Command metadata and human tables use the same projection and validation; golden integration tests exercise real `CommandDispatcher` output. [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597) **SUCCESS**.
- [x] **Strict explicit selection boundary:** read-only group inspection is allowed, but bare-group mutations, retired 11-entry aliases, Core/QA installation, and cross-group implicit selection are rejected. Explicit `module.feature` or `module --feature=...` requests normalize deterministically; shared/conditional packages stay owner-safe. [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597) **SUCCESS**.
- [x] **Documentation and executable examples synchronized:** `docs/modules.md`, `docs/architecture.md`, `docs/configuration.md`, README and Composer suggestions distinguish Core, PHPForge QA, three groups/nine features, shared OTP/WebAuthn and Epicrypt dependencies; `ModuleCommandDocumentationParityTest` verifies documented module commands and feature list against `CommandCatalog`. Release-evidence module microbenchmark now uses v2 CLI commands and 3/9 inventory, and generated v2 evidence uploaded successfully by [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597).
- [x] **Breaking 4.0 contract documented:** legacy 11-entry names are removed from installable public API, scripts and machine parsers must adopt qualified IDs and JSON schema v2; application requirements, feature flags, owner configs, conditional prerequisites and compiled release generation are spelled out in `docs/modules.md`. **No older-major shim or fallback** was added.
- [x] **Errors, plans and recovery examples updated:** explicit-feature errors reject ambiguous group requests and legacy aliases, package ownership snapshots fail closed, dry runs cannot mutate, failed Composer phases report partial status and repair paths without blind rollback; config publication and native schema commands are owner-scoped. `ModuleLifecycleIntegrationTest`, `ModuleFeatureCommandCatalogTest`, `ModuleFeatureCommandSnapshotTest` and documentation parity checks pass; CLI release baseline verified by [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597).

**QA gate:** exactly 3 module entries and 9 features in CLI golden outputs, Core/QA exclusion, executable documented feature commands, help/completion checks, no-doc-drift guard and scoped security review.

**Batch 6 ACCEPTED (2026-10-15, 6/6):** Exact executable source [`6358b97`](https://github.com/infocyph/Foundation/commit/6358b975a12add6d598e3e06e2724edd9a3cecfd): [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597) **SUCCESS** (PHPForge QA 4/4, PHPStan 2/2, isolated PHP 8.4/8.5 stable/lowest consumers 4/4, benchmarks 2/2, clean installation, v2 module release-evidence artifact), [native HTTP host #38686270918](https://github.com/infocyph/Foundation/actions/runs/38686270918) **SUCCESS**. Coordinated public v2 `ModuleSystemCommand` cutover replaced the 11-entry public catalog with 3 public modules and 9 fully qualified features, one snapshot/planner/lifecycle source for all read/mutation/config/schema commands, deterministic human and JSON schema-v2 outputs, explicit activation and no implicit sibling/Core/QA installs. A snapshot safety regression preserves configured-disabled shared feature ownership. Documentation, architecture/configuration owner tables, upgrade/breaking-contract instructions, executable CLI goldens, docs-command parity and release-evidence benchmark now describe the same **3/9** interface. The obsolete 11-entry catalog remains only as an internal adapter for native-owned schema compatibility where needed; **no v1 public CLI shim** remains. **Next: Batch 7, 7 QA/release gates still open.** Separate Foundation 4 release audit and sustained performance evidence stay **32/37**, untouched by this module CLI certification. No Infbyte, merge, tag, publication or upstream changes.

### Batch 7 — Full QA, performance and release closure

Current evidence is recorded in [Batch 7 certification evidence](foundation-4-module-batch7-evidence.md). The following checklist supersedes the dated historical checkpoints below.

- [x] Complete focused/full PHPForge QA, analysis, formatting, references, duplicate and configured security gates. Executable `3f295de` passed [Security & Standards 39007156463](https://github.com/infocyph/Foundation/actions/runs/39007156463): QA 4/4, analysis 2/2, benchmarks 2/2, clean install and release evidence. The conditional Security Report job is skipped by upstream rules; no test/detector exemption was introduced.
- [x] Verify PHP 8.4/8.5 × stable/lowest, Core-only and selected native eight-feature graphs, trusted compiled generations and all four isolated `--no-dev` production consumers on `3f295de`.
- [ ] Compare before/after module inspect/plan/actual-enable and compiled startup metrics plus authenticated successful RPM. The complete [CPU-controlled comparison 39010682913](https://github.com/infocyph/Foundation/actions/runs/39010682913) uses unchanged candidate `3f295de`, one lock and frozen baseline `6274939`, FPM/Runwire, one/four workers and concurrency 1/10/50. Eight cases pass and four exceed the variance budget; complete performance acceptance remains blocked.
- [ ] Prove no >2% median successful-RPM regression with five >=60-second measured trials after a separate 30-second warm-up, CV <=2.5% and bounded resources. Unstable or incomplete measurements remain inconclusive/blocking.
- [x] Stress actual Composer publication/removal churn, stale snapshots, partial failure, idempotent repair, native persisted contention/rollback, scoped principal/session state, lifecycle, cancellation and recovery. Exact-source suites pass, with local Docker native-host error recovery, worker replacement and soak diagnostics.
- [x] Inspect all 63 PR #20 inline review threads (zero unresolved, no further page), record executable `3f295de`, hosted PHP/toolchain/dependency provenance and synchronized trackers. Paired lock SHA-256: `b557fba7662dba32601c3840f69f7ac394f253d729246fcba08db44e26067cb9`. Recheck PR state before final readiness.
- [ ] Review final module API docs, release notes/metadata and mark rework ready only after all preceding gates pass. The 3-module/8-feature v2 public contract and Composer metadata are reviewed and regression-guarded; publication approval remains withheld, candidate notes remain unpublished, and the performance-dependent decision stays open.

**Current count:** B7 4/7 individually checked, 0/7 whole-batch accepted; module totals 46/49 engineering, 42/49 batch accepted. Foundation release ledger **35/37** after verifying telemetry, functional hosting/soaks and publication metadata; strict full-matrix performance and dependent final acceptance remain open. Keep PR #20 draft while host performance/final acceptance remain open. Merge, tag, publication and Infbyte migration remain separate operator actions.

**Historical B7 checkpoints:** These preserve prior-source provenance and do not replace the current checklist.

**2026-10-15 Batch 7 evidence checkpoint (NOT RELEASE-READY):** **4/7 independently QA-checked engineering gates; 0/7 whole-batch accepted**. The immutable implementation [`8fa9fd4`](https://github.com/infocyph/Foundation/commit/8fa9fd48952dbfaa49e2258e5afa6a3264fa4658) passed [Security & Standards #38688337213](https://github.com/infocyph/Foundation/actions/runs/38688337213) **SUCCESS** and [native HTTP host #38688336732](https://github.com/infocyph/Foundation/actions/runs/38688336732) **SUCCESS** (correct-response HTTP FPM/Runwire, failure/cancellation, native-read and worker replacement). New `FoundationModuleCliComparison` validates comparable schema-v2 cold/warm CLI metrics and explicitly labels outputs *diagnostic only*, while the host RPM comparator now rejects self-comparison of an identical source SHA. Module no-op activation retains trusted generations. See [Batch 7 evidence and controlled-host procedure](foundation-4-module-batch7-evidence.md). **Three gates remain OPEN:** (3) actual baseline/candidate CLI/compiled boot **and** production-equivalent sustained host measurements, (4) physically matched 5×≥30s stable correct-RPM proof within the strict 2% budget plus representative auth/database/cache workload and telemetry, and (7) final release-note/readiness sign-off dependent on 3–4. Current shared GitHub CI host runs use only three minimal/two native repetitions and cannot satisfy this budget. Separate Foundation 4 release audit stays **32/37**. PR draft; no Infbyte, merge, tag or publish.

**2026-10-15 final documentation review (complete as engineering work; B7 release sign-off still OPEN):** Aligned `docs/foundation-4-migration.md` with the accepted 3 public modules, nine qualified feature IDs and JSON schema v2, replacing executable legacy examples. Expanded `docs/foundation-4.0-release-notes-draft.md` to describe the breaking CLI, independent passkeys/OTP MFA/Epicrypt and durable messaging requirements, explicit no-implicit-activation and missing controlled host performance evidence. Two additional `ModuleCommandDocumentationParityTest` tests check command existence, absence of retired runnable aliases, exact nine-feature coverage and honest release status. [`592d43d`](https://github.com/infocyph/Foundation/commit/592d43d75ec61225c9668bd7be10826c2a54e9b3), [Security & Standards #38689963558](https://github.com/infocyph/Foundation/actions/runs/38689963558) **SUCCESS** (full quality/production matrix), [native HTTP host #38689962743](https://github.com/infocyph/Foundation/actions/runs/38689962743) **SUCCESS** (native host smoke, **not** performance sign-off). The existing B7 gate 7 explicitly also requires successful host gates 3–4 and final approval, so it must remain **[ ]**. Quantitative controlled-host baseline/candidate, stability, latency/RSS/CPU and signed final release decision remain **missing**. No change to **46/49** verified items, **42/49 batch accepted**, **4/7** B7 QA checkpoints, or separate Foundation release **32/37**.

**QA gate:** all exact-source workflows green; repeatable host evidence, no native owner duplication, no unresolved blockers. PR stays draft; user handles merge, tag and publish.

## 7. Design and QA guardrails

- **Performance:** sustainable correct RPM takes precedence over microbenchmark speed. No accidental Composer reads, metadata traversal, disk/network discovery or reflection on hot/compiled routes. Benchmark cold and warmed module operations independently of web/runtime requests. Use source-owner native APIs.
- **Security:** no insecure module paths, arbitrary code evaluation from discoverable manifests, silent extension/package activation or overwriting application-owned files. Existing platform readiness, Composer ownership and user intent remain explicit.
- **Data integrity:** never delete database tables, queues, session state, credentials or config merely due to package/module removal. Schema revisions and migrations must declare ownership and require explicit operator changes when destructive.
- **Reliability:** no partial Composer failure auto-rollback promises; re-plan, re-resolve and repair idempotently. Test signal, concurrency, locks, cancellation, handler failure and persistent worker reset.
- **Engineering:** follow [PHPForge engineering principles](https://raw.githubusercontent.com/infocyph/PHPForge/refs/heads/main/resources/engineering-principles.md). Keep files/abstractions few, code strict and readable; don't rename or reformat unrelated source. No compatibility layers for previous Foundation major versions.
- **Evidence:** every checked item names its test, run, exact source SHA and resolution. No 100% or release-ready label without verified CI and representative host gates.
- **Source boundary:** Foundation only. No Infbyte or other library changes until separately requested. Current PR #20 stays draft. No merge, release, tag or publish.

## 8. Decision log (architecture selected; keep synchronized)

| Decision | Selected resolution | Rationale/evidence | Status |
| --- | --- | --- | --- |
| D02 | Exactly 3 optional public modules: `data`, `security`, `execution` | Explicit user approval; three modules replace five functional categories as public module hierarchy; no implicit install-all | **APPROVED — 2026-10-14** |
| D03 | Required Foundation Core and built-ins do not become installable modules | Explicit user approval that Core comes with Foundation; prevents pseudo-module removal/activation | **APPROVED — 2026-10-14** |
| D05 | Exactly 8 independently selectable features: 2 data + 4 security + 2 execution | Core Runwire correction removes optional runtime; group selection still does not install all libraries | **APPROVED — 2026-10-16** |
| D11 | PHPForge remains its own preconfigured development/QA layer | Explicit user approval; no production PHPForge module/runtime activation | **APPROVED — 2026-10-14** |
| D12 | Runwire is required Core; `execution.runtime` is removed | User correction; environment-portable native runtime integration, separate from optional Omnibus | **APPROVED — 2026-10-16** |
| D01, D04, D06–D10 | Curated first-party catalog; production explicit capability allow-list; pure planner; feature-owned config; Core/native schema ownership; versioned feature-level CLI; measured hot-path guardrails | [Source-backed decisions, options and rationale](foundation-4-module-batch0-audit.md) §B. Engineering selections, **not** user approval of every implementation detail. Reopen on test/native evidence. | **DESIGN SELECTED — 2026-10-14** |

## 9. Change log / progress journal

| Date | Milestone | Exact code/QA evidence | Tracker |
| --- | --- | --- | --- |
| 2026-10-14 | Drafted dedicated Foundation 4 module architecture and lifecycle plan. No module implementation, no QA acceptance claimed. | Plan-only commit `b36749c0`; implementation source baseline retained | **0/49 tasks, 0/8 batches** |
| 2026-10-14 | **Approved target: prebuilt Core + separate PHPForge QA + exactly 3 optional public modules / 9 independent features**. Rewrote ownership mapping, decisions, batch acceptance and lifecycle examples accordingly. | **Plan-only update; no source changes or acceptance QA claimed.** Previous 11-entry catalog remains current runtime baseline. | **0/49 implementation tasks, 0/8 batches; 5 architecture decisions approved** |
| 2026-10-14 | Traced old module CLI/catalog/resolver/activation/config/schema/native graph; inventoried 11→3/9 mapping and selected D01/D04/D06–D10. Added isolated CLI cold/warm benchmarking runner and release-evidence capture. | [Source audit](foundation-4-module-batch0-audit.md); `benchmarks/foundation4-module-cli-baseline.php`; `.github/workflows/security-standards.yml`. **Baseline CI/fixtures remain pending.** | **Batch 0 3/5; 3/49 tasks, 0/8 batches** |
| 2026-10-14 | **Batch 0 complete: 5/5**. Existing 11-entry contract, CLI golden fixture, cold/warm observations, 16 MiB PHP allocation, native selected/disabled host paths and design decisions verified on exact source `a4afa89c`. | [QA #38570463538](https://github.com/infocyph/Foundation/actions/runs/38570463538) **SUCCESS**; [native host #38570462819](https://github.com/infocyph/Foundation/actions/runs/38570462819) **SUCCESS**; [audit evidence](foundation-4-module-batch0-audit.md) §C. | **5/49; Batch 0 5/5 accepted** |


| 2026-10-14 | **Batch 1 complete: 5/5**. Canonical immutable 3-module/9-feature taxonomy, validated package floors/shared OTP, conditional dependencies, Core/QA exclusion and schema/config collision guards, fully qualified features. **11-entry public CLI cutover explicitly deferred to B6**; new metadata is read-only, avoiding broken mid-migration install/repair. | [QA #38577822789](https://github.com/infocyph/Foundation/actions/runs/38577822789) **SUCCESS 15 jobs / 1 intentionally skipped**, [native host #38577821971](https://github.com/infocyph/Foundation/actions/runs/38577821971) **SUCCESS** at exact source `4f307fba`; `ModuleArchitectureCatalogTest`. | **10/49; Batch 0 5/5, Batch 1 5/5** |

| 2026-10-14 | **Batch 2 implementation checkpoint: 6/6 scoped areas implemented, 0/6 accepted pending QA.** Pure `ModuleFeatureResolver` and `ModuleFeaturePlanner`, 12 feature tests, versioned schema 2, dependency and package ownership, selected-feature retention; prior QA `38584332155` RED from Pint/Rector (not accepted). Correct unused parameter and class method separation and repeat exact-head QA. | [Prior QA #38584332155](https://github.com/infocyph/Foundation/actions/runs/38584332155) **RED**; latest follow-up must be green before accepting. No module CLI/runtime cutover. | **10/49 accepted; Batch 2 0/6 accepted (6/6 coded)** |

| 2026-10-14 | **B2 detailed checklist synchronized: six tasks checked as implemented/unit-tested, batch remains unaccepted.** QA #38588533187 passed Pest/PHPCS/Deptrac/Rector and PHPStan analyses but failed Pint on constructors; several required jobs and #38588532052 native host failed dependency download (GitHub codeload 429). Refactored constructors with explicit readonly initialization and retain all other native/feature invariants. | [QA #38588533187](https://github.com/infocyph/Foundation/actions/runs/38588533187) RED (Pint + dependency throttling), [host #38588532052](https://github.com/infocyph/Foundation/actions/runs/38588532052) RED (HTTP 429); **no acceptance** pending rerun. | **16/49 implementation checkboxes; 10/49 certified, B2 0/6 certified** |

| 2026-10-14 | **B2 QA status on HEAD `65d818fd`: 6/6 implemented, 0/6 certified.** Host fully **GREEN**; quality workflow **RED**. 4/4 production consumer installations, 2/2 benchmark jobs, PHP 8.5 analysis and clean install green; source-specific Pint violations remain in planner/resolver. PHP 8.4 analysis/lowest QA fail before full execution from GitHub codeload HTTP 429. All code acceptance remains blocked until a clean exact-source matrix; core/QA/Infbyte untouched. | [Host #38590087888](https://github.com/infocyph/Foundation/actions/runs/38590087888) **SUCCESS**; [QA #38590088927](https://github.com/infocyph/Foundation/actions/runs/38590088927) **FAILURE**; no whole-batch acceptance. | **16/49 checked; 10/49 certified; B2 0/6 certified** |

| 2026-10-14 | **B2 acceptance: 6/6**, closes pure resolver/planner after correcting conditional-prerequisite effects, shared package removal, invalid-package recovery, PHPForge Pint and complexity. Four new focused regression tests. Do not conflate with Batch 6 CLI cutover or separate Foundation 4 release certification. | [Security & Standards #38599560658](https://github.com/infocyph/Foundation/actions/runs/38599560658) **SUCCESS** (QA 4/4, PHPStan 2/2, consumers 4/4, benchmarks 2/2, evidence), [native host #38599560098](https://github.com/infocyph/Foundation/actions/runs/38599560098) **SUCCESS**, exact source [`dc7a4039`](https://github.com/infocyph/Foundation/commit/dc7a40392e784e885613e736d9e4ec117e3a15d8) | **16/49 implementation and certified; 3/8 batches accepted; B3 0/7** |

| 2026-10-14 | **B3 Composer phase checkpoint, 1/7 implemented and QA-checked, 0/7 full-batch accepted.** Feature-scoped Composer executor with live ownership guard, dry-run, shared-package protection, concurrency lock and six integration tests. Telemetry collection fixed to write complete atomic CSV rows. Native/public commands not cut over; other lifecycle phases remain. | [Security & Standards #38604462397](https://github.com/infocyph/Foundation/actions/runs/38604462397) **SUCCESS**, [native host #38604461132](https://github.com/infocyph/Foundation/actions/runs/38604461132) **SUCCESS** at [`aac1f39`](https://github.com/infocyph/Foundation/commit/aac1f39bc035d28068ecb8ee9b11b6e18807fd1d); [prior host failure](https://github.com/infocyph/Foundation/actions/runs/38603513595) traced to incomplete telemetry row and corrected. | **17/49 implemented; 16/49 accepted; B3 0/7 fully accepted** |

| 2026-10-14 | **B3 3/7 staged/QA-checked, 0/7 full-batch accepted.** Added internal feature activation and feature config publisher (9 new tests) atop Composer executor, without public cutover. Refactored activation callback for PHPForge complexity and Pint; unchanged legacy capability topology. | [Security & Standards #38609784501](https://github.com/infocyph/Foundation/actions/runs/38609784501) **SUCCESS**, [native HTTP host #38609782896](https://github.com/infocyph/Foundation/actions/runs/38609782896) **SUCCESS** at [`64f87bd`](https://github.com/infocyph/Foundation/commit/64f87bdc8c89f988379a423347eda4340116ee1a) | **19/49 staged implemented, 16/49 accepted, 3/8 batches accepted** |

| 2026-10-14 | **B3 accepted: 7/7.** Full internal feature-scoped lifecycle across Composer/activation/config/schema/remove/repair, native-owner boundaries, context refresh, structured phase and recovery reports, shared package/data retention, concurrent activation writers, symlink guards, and failure-injected fixtures. No public CLI/native graph cutover. | [`3675f8b`](https://github.com/infocyph/Foundation/commit/3675f8bd8f6256f0a4e28fd9fc743d8498f7ffe2), [Security & Standards #38614849270](https://github.com/infocyph/Foundation/actions/runs/38614849270) **SUCCESS** (all QA/analysis/consumer/benchmark/evidence jobs), [native HTTP host #38614848062](https://github.com/infocyph/Foundation/actions/runs/38614848062) **SUCCESS** (host correctness/soak). | **23/49 checked + accepted (46.9%); 4/8 batches accepted** |

| 2026-10-15 | **B4 started: 1/6 implemented/QA-checked, 0/6 whole-batch accepted.** Selected 3-module/9-feature policy wired at `FoundationBuildContext`; only opted optional providers can compile, Core and production allowlists remain authoritative, conditional DB and MFA/passkey independence validated; readiness mirrors build policy. No runtime discovery, CLI cutover or native owner replacement. | [`6094136`](https://github.com/infocyph/Foundation/commit/609413680f30d31d8cbdcbae102c45736a2d5d2e), [Security & Standards #38621413325](https://github.com/infocyph/Foundation/actions/runs/38621413325) **SUCCESS** (QA 4/4, PHPStan 2/2, consumers 4/4, benchmarks 2/2, evidence), [native HTTP host #38621412456](https://github.com/infocyph/Foundation/actions/runs/38621412456) **SUCCESS** | **24/49 implemented, 23/49 accepted; 4/8 batches accepted; B4 1/6 staged** |

| 2026-10-15 | **B4 staged 2/6, whole-batch acceptance 0/6.** Native `ServiceRegistry` seals owners at first contribute, prevents any late or callback provider additions, and pins repeated contributions to one `FoundationBuildContext`. Four regression tests. Existing Webrick and non-web compilers still own their native compilation paths. | [`521f2a7`](https://github.com/infocyph/Foundation/commit/521f2a78df1c8058cfddd826365d1438ca15c84f), [Security & Standards #38624650626](https://github.com/infocyph/Foundation/actions/runs/38624650626) **SUCCESS** (QA 4/4, PHPStan 2/2, consumers 4/4, benchmarks 2/2, release evidence), [native HTTP host #38624649819](https://github.com/infocyph/Foundation/actions/runs/38624649819) **SUCCESS** | **25/49 implemented, 23/49 accepted; 4/8 batches accepted; B4 2/6 staged** |

| 2026-10-15 | **B4 FULLY ACCEPTED: 6/6.** Verified Webrick + Runwire native owner graph, non-web principal and mutable-state isolation, frozen InterMix feature ordering, prevalidated runtime trust/ABI and generation, no-op feature activation preserving config cache, and 13 compiled topologies in four `--no-dev` dependency/PHP combinations. Existing legacy CLI remains intentionally live until B6. | [`1b11d0f`](https://github.com/infocyph/Foundation/commit/1b11d0f0453fb355a39023f9a3931aac049e0b14); [Security & Standards #38630078952](https://github.com/infocyph/Foundation/actions/runs/38630078952) **SUCCESS** (4/4 PHPForge QA, 2/2 analysis, 4/4 production consumers, 2/2 benchmarks, clean install/evidence), [native HTTP host #38630076643](https://github.com/infocyph/Foundation/actions/runs/38630076643) **SUCCESS** on same SHA | **29/49 implemented and accepted (59.2%); 5/8 batches accepted; B4 6/6** |

| 2026-10-15 | **B5 checkpoint: 5/7 staged and QA-checked, 0/7 whole-batch accepted.** Added selected native Epicrypt/ReqShield/OTP/WebAuthn graph ownership, conditional Epicrypt dependency on OTP MFA, DBLayer/Pathwise independence and Core cache, Omnibus/TalkingBytes/Runwire isolation, selected external role/extension audit. Core diagnostics and durable multi-service fault/recovery remain open. | [`829044d`](https://github.com/infocyph/Foundation/commit/829044da3bf3b812553fc92c427a7f2f91453f17), [Security & Standards #38674333814](https://github.com/infocyph/Foundation/actions/runs/38674333814) **SUCCESS** (4/4 PHPForge QA, 2/2 PHPStan, 4/4 `--no-dev` consumers, 2/2 benchmarks, release evidence), [native HTTP host #38674333265](https://github.com/infocyph/Foundation/actions/runs/38674333265) **SUCCESS** | **34/49 implemented (69.4%); 29/49 batch accepted (59.2%); B5 5/7 staged; 5/8 batches accepted** |

| 2026-10-15 | **B5 fully ACCEPTED 7/7.** Core-only diagnostics separate from nine optional features and real selected SQLite DBLayer/Omnibus durable failure-retention/transaction rollback/recovery/native-schema tests, alongside previous native selected specialist/capability and external dependencies. | [`c2d04ba`](https://github.com/infocyph/Foundation/commit/c2d04bad6af9a3c9bacc4ea27a2a050a5004862e); [Security & Standards #38676123308](https://github.com/infocyph/Foundation/actions/runs/38676123308) **SUCCESS** (4/4 QA, 2/2 PHPStan, 4/4 `--no-dev` consumers, 2/2 benchmarks, release evidence), [native HTTP host #38676123115](https://github.com/infocyph/Foundation/actions/runs/38676123115) **SUCCESS** | **36/49 accepted (73.5%); 6/8 batches accepted; next B6 public cutover** |

| 2026-10-15 | **B6 implementation started, 0/6 accepted.** Canonical fixed 3-group/9-feature command catalog + fresh CLI project Composer/package/feature snapshot adapter, with 5 focused tests; no live command cutover. | [`e63b331`](https://github.com/infocyph/Foundation/commit/e63b331da7c5b2d1ea1e9cac6ee417fa6910f708); [Security & Standards #38677508315](https://github.com/infocyph/Foundation/actions/runs/38677508315) and [native HTTP host #38677507985](https://github.com/infocyph/Foundation/actions/runs/38677507985) still running at checkpoint | **36/49 accepted (73.5%), B6 0/6 accepted, 6/8 batches accepted** |

| 2026-10-15 | **B6 ACCEPTED 6/6.** Coordinated live v2 3-group/9-feature CLI, shared safe Composer snapshot/planner/lifecycle, Core/QA exclusion, machine/human JSON and CLI goldens, breaking docs/help/config ownership, docs-parity guard, and fixed v2 release-evidence baseline. | [`6358b97`](https://github.com/infocyph/Foundation/commit/6358b975a12add6d598e3e06e2724edd9a3cecfd); [Security & Standards #38686271597](https://github.com/infocyph/Foundation/actions/runs/38686271597) **SUCCESS** (4/4 QA, 2/2 analyses, 4/4 consumers, 2/2 benchmarks, release artifact), [native HTTP host #38686270918](https://github.com/infocyph/Foundation/actions/runs/38686270918) **SUCCESS** | **42/49 accepted (85.7%); 7/8 batches accepted; B7 next** |

| 2026-10-15 | **B7 4/7 QA-checked, 0/7 whole-batch accepted.** Exact-source PHPForge 4/4, PHPStan 2/2, `--no-dev` consumers 4/4, benchmarks 2/2, native host smoke, lifecycle/concurrency/durability/worker recovery and 52/52 resolved PR review threads. CLI cold/warm comparison only diagnostic; no-op CLI preserves trusted release; matched-host ≥5×30s candidate/base 2% RPM proof missing. | [`8fa9fd4`](https://github.com/infocyph/Foundation/commit/8fa9fd48952dbfaa49e2258e5afa6a3264fa4658); [Security & Standards #38688337213](https://github.com/infocyph/Foundation/actions/runs/38688337213) and [native HTTP host #38688336732](https://github.com/infocyph/Foundation/actions/runs/38688336732) both **SUCCESS**; release evidence artifact SHA256 `759532cc428d9ff44c46196f0faee89a68c8530f700059869188726b812c6a20` | **46/49 implemented/QA-checked (93.9%); 42/49 batch-accepted (85.7%); 7/8 batches; B7 NOT accepted** |

| 2026-10-15 | **Final module API, candidate notes and Composer publication metadata reviewed; B7 release sign-off WITHHELD.** Checked the canonical 3/9 v2 docs, upgrade guidance, runtime vs optional/dev packages, MIT Composer library/PSR-4/bin contract and unpublished draft, added automated metadata/docs parity tests. The composite B7 final readiness checkbox remains open until matched physical-host throughput gates 3/4 and exact-source final go/no-go decision pass. | [`4de9034`](https://github.com/infocyph/Foundation/commit/4de9034c874190ffe6adbb7996dc0b6c2d4c8386); [`b8dad2c`](https://github.com/infocyph/Foundation/commit/b8dad2c4d619f917c660a85e68480e7fdcb29c2a), [Security & Standards #38722750952](https://github.com/infocyph/Foundation/actions/runs/38722750952) **SUCCESS** (4/4 QA, 2/2 PHPStan, 4/4 consumers, 2/2 benchmarks, clean install, release evidence); [native HTTP host #38722750628](https://github.com/infocyph/Foundation/actions/runs/38722750628) **SUCCESS**; controlled-host evidence missing | **46/49 engineering-checked; 42/49 batch-accepted; B7 4/7 checked; 0/7 B7 accepted; Foundation release 32/37** |

**Next action (as of 2026-10-15):** Run the documented two-commit, physically matched dedicated-host representative workload comparison for Foundation 4: identical PHP/INI/extensions and dependency lock, baseline vs current executable, 5×≥30-second observations across FPM/Runwire worker/concurrency matrix, auth/database/cache and native selected specialists, longer soak, latency/CPU/live RSS/queue metrics. Require all stable cases ≥98% of baseline successful RPM and attach the comparison + raw artifacts. Do not mark B7 or separate release 32/37 as complete without that evidence. No Infbyte, merge, tag or publication.
