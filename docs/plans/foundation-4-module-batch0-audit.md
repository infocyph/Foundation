# Foundation 4 — Batch 0 Module Lifecycle Audit & Design Record

> **Scope:** pre-rework source and reproducible characterization. **Approved target:** Foundation Core + PHPForge QA + **three optional public modules with nine independent features**.  
> **Branch:** `feature/foundation-3` · **PR:** [#20](https://github.com/infocyph/Foundation/pull/20) (draft).  
> **Tracker:** [Module architecture rework](foundation-4-module-architecture-rework-plan.md).  
> **Status:** Source audit and design decisions recorded; cold/warm CLI measurements require successful exact-head CI artifact. This document is **not** evidence that the new module architecture is implemented.

## A. Verified pre-rework source boundaries

The code currently exposes **11 catalog entries**, seven specialist-purpose namespaces and four built-in entries. Names are **current-state observations**, not the proposed API:

| Existing entry | Current role | Package/config/schema owner | Approved target |
| --- | --- | --- | --- |
| `auth` | Core-backed special module with `otp` and `passkey` package features | Core auth; OTP `^7.0`; WebAuthn `^5.3.9`; auth schema is Core-owned | Core auth; `security.mfa` / `security.passkey` |
| `communication` | Specialist | TalkingBytes `^2.3`, `communication.php`; optional gRPC | `execution.communication` |
| `database` | Specialist | DBLayer `^6.1`, `database.php` | `data.database` |
| `filesystem` | Specialist | Pathwise `^4.2`, `filesystem.php`; optional native adapters | `data.filesystem` |
| `logging` | Built-in | Foundation `logging.php` | Core logging |
| `messaging` | Specialist | Omnibus `^3.0`, `messaging.php`, durable schema; optional Runwire/SQS integrations | `execution.messaging`; separate `execution.runtime` |
| `operations` | Built-in | Foundation `operations.php` | Core operations |
| `resources` | Built-in | Foundation `responses.php` | Core response resources |
| `security` | Specialist | Epicrypt `^3.2`, `security.php` | `security.crypto` |
| `session` | Built-in | Foundation `session.php`, session schema | Core sessions |
| `validation` | Specialist | ReqShield `^3.3`, `validation.php` | `security.validation` |

**Outside catalog today:** CacheLayer `^4.0` is required/Core-owned (including `cache:schema:*`); native notifications are Foundation-owned and not a TalkingBytes module alias; `infocyph/runwire` is optional host engine, not an Omnibus scheduler. PHPForge remains only a `require-dev` engineering dependency. Core also requires ArrayKit, InterMix, UID, Webrick and PSR Log. External `opis/closure` is development-scoped, WebAuthn is selected passkey support, and Sodium is an optional selected cryptographic/runtime extension.

### Call-chain observations

| Current operation | Actual flow and side effects | File |
| --- | --- | --- |
| `module:list` | `ModuleSystemCommand::listing() → ModuleManager::all() → ModuleStateResolver::all()`, reading application `composer.json`, resolved Composer package state, configured capability overrides, dependency edges and platform requirements. Output machine array contains 11 entries, each `schema_version: 1`. | `src/Command/System/ModuleSystemCommand.php:245`; `src/Module/ModuleManager.php:27`; `src/Module/ModuleStateResolver.php:121` |
| `module:show` / `module:doctor` | Resolve aliases and selected features → state → config publication/validity → schema status → readiness. `doctor` currently dispatches to the same `show()` method. | `ModuleSystemCommand.php:30,743` |
| `module:plan` | Resolve catalog → current module state → local arrays for `packages_to_add`, `packages_direct`, `packages_transitive`, dependencies/config/schemas/blockers. Emits `schema_version: 1`. **No Composer execution or config publication** in the planner. | `ModuleSystemCommand.php:340–402` |
| `module:install` | Resolve requested feature → Composer `require` `--with-all-dependencies` `--no-interaction` (optionally `--dry-run`); on success and not dry-run, publish owned config, invalidate compiled runtime and install only applicable schemas of requested module. Core auth without selected feature is a no-op. | `ModuleSystemCommand.php:58–105,188–230`; `ModuleManager.php:33–56` |
| `module:enable` / `module:disable` | Check module mutability/readiness/dependents → write staged `config/modules.php` partial capabilities override → invalidate config cache. Does not install Composer packages. | `ModuleSystemCommand.php:128–172`; `ModuleActivationManager.php:18–160` |
| `module:remove` | Refuse built-ins/active dependents/shared selected ownership/unknown root ownership; remove only eligible **direct** Composer requirements, preserve config/schemas/data; invalidate compiled release on real mutation. | `ModuleSystemCommand.php:432–460`; `ModuleManager.php:70–253` |
| `module:repair` | Inspect state → install missing Composer-owned packages only → publish config → invalidate compiled runtime → target applicable schemas. Records phase results, not a transaction across Composer and database. | `ModuleSystemCommand.php:629–678` |
| `module:schema:status/install/sync` | `ModuleSchemaManager` owns current Foundation auth/session and Omnibus durable messaging applicability; targeted install is explicit, aggregate sync traverses active conditions; CacheLayer schema remains separate. | `ModuleSystemCommand.php:679–743`; `ModuleSchemaManager.php:23–82,125–132` |
| Compiler/provisioning boundary | `FoundationBuildContext::fromConfig()` normalizes `app.capabilities` and `modules.capabilities`. Module operations must prepare policy **before** InterMix native compilation; never mutate existing Webrick or Runwire server mechanisms per request. | `src/Application/FoundationBuildContext.php:38–87`; `src/Config/Internal/ConfiguredCapabilities.php:18–63` |

### Golden pre-rework CLI contracts (read-only characterization)

Commands are run through `CommandDispatcher::project()` on an isolated fixture, not by changing Infbyte:

| Command | Existing machine-read contract / expectation |
| --- | --- |
| `module:list` | 11-entry array; every item has `schema_version: 1`, canonical `name`, direct/transitive/unknown ownership and enabled/configured/readiness fields |
| `module:show database` | Alias and source identity, `schema_version: 1`, `packages`, `requested`, `schema_status` |
| `module:doctor messaging` | Currently same handler as `show`; diagnoses blockers/dependencies/platform/readiness |
| `module:plan auth --feature=passkey` | `schema_version: 1`; selected package requirements include OTP plus WebAuthn; never silently enables OTP MFA |
| `module:install database --dry-run` | Composer stub `require infocyph/dblayer:^6.1 --with-all-dependencies --no-interaction --dry-run`, no config/schema mutation |
| `module:remove database --dry-run` | Composer stub `remove infocyph/dblayer --with-all-dependencies --no-interaction --dry-run` when safe, no config/schema/data deletion |

The measured baseline uses a mock **Composer subprocess** to isolate the command planner/executor overhead without network or real package mutations. The mock is explicitly identified in the artifact; it **does not** measure real Composer dependency resolution time.

### Verified failure and ownership cases represented in existing tests

- Direct requirement vs transitive availability vs unavailable root `composer.json`: `ModuleStateResolverTest.php`.
- Unsupported direct version floors, ambiguous OTP/passkey package selection, alias collisions and dependency cycles: `ModuleCatalogModelTest.php`, `ModuleStateResolverTest.php`.
- Non-mutating `module:plan`, non-mutating Composer dry-run, built-in removal rejection, auth passkey shared ownership, safe config publication, repair, session/messaging schema applicability and non-deletion of data: `ModuleLifecycleIntegrationTest.php`, `ModuleConfigPublicationTest.php`.
- Foundation compilation/context and selected capabilities: `NativeCapabilityPolicyIntegrationTest.php`, `RuntimeCapabilityConfigTest.php`, `Foundation4PublicContractTest.php`.
- **Migration friction to address:** existing built-in `operations` advertises the alias `runtime`; new `execution.runtime` needs a **qualified feature ID**. Do not reuse ambiguous bare `runtime` as a module alias. Existing capability/service keys include `db` versus module name `database`; align these at the feature-to-native provider bridge rather than naively renaming all underlying native keys.

## B. Rework design decisions (2026-10-14)

User approved the high-level Core + 3 + 9 architecture in this conversation. The following **implementation design resolutions** are proposed/selected based on current code boundaries; they must be verified in Batch 1–3 tests and re-opened if concrete source behavior disproves them.

| ID | Resolution | Rationale and rejected alternatives |
| --- | --- | --- |
| **D01** | Retain **curated, statically validated definitions** of exactly three first-party modules and nine features. No filesystem manifest discovery or third-party module registry during this 4.0 rework. | Bounded deterministic metadata and compiled graph; arbitrary discovery introduces security and hot-path costs. An explicit future extension API requires separate design approval. |
| **D02** | **APPROVED:** `data`, `security`, `execution`. | Exactly three optional public modules; no fourth Core/QA module. |
| **D03** | **APPROVED:** Core auth/cache/logging/operations/resources/session/notifications are prebuilt; no mutable pseudo-module installation. | Native owners and stable application baseline. |
| **D04** | Compile only explicitly allowed production feature capabilities; **module override may narrow**, but must not resurrect a capability absent from production's full `app.capabilities` topology. During development, partial module overrides may remain effective when no full topology is declared. | One clear final source of truth; current `FoundationBuildContext` supports override precedence that can broaden explicit topology, requiring a deliberate change with tests. |
| **D05** | **APPROVED:** nine independent features: 2 data, 4 security, 3 execution. Shared OTP package does not couple MFA and passkey activation. | Avoid top-level install-all and unnecessary native services. |
| **D06** | Reuse existing managers where possible; **pure versioned planner** supplies requested feature/package/activation/config/schema effects and blockers; a minimal phase-based executor handles explicit write commands. | Existing `module:plan` lives in CLI; avoid duplicating planning rules between dry-run, install and repair. No hypothetical Composer rollback. |
| **D07** | Publish config only for selected feature-owned templates, using existing staging/backup/symlink guards; preserve application files by default and report effective config separately. | No project-config overwrite or accidental cross-feature publication. |
| **D08** | **Owner-specific schemas:** auth/session belong to Core, durable messaging belongs to `execution.messaging`/Omnibus, data database is the **infrastructure provider**, and CacheLayer schema remains Core-owned. Targeted schema operations only. | Avoid DBLayer taking over other domains' tables and avoid destructive “module remove” migration. |
| **D09** | Public commands use `module:<verb> <data|security|execution> --feature=<feature>`. Require a selected feature for install/enable/disable/remove when the whole module has multiple independent features; **bare module names are overview/plan selectors, not install-all**. Publish changed machine output as **`schema_version: 2`**, with fully qualified canonical feature IDs. Remove ambiguous previous draft aliases; no Foundation 2.x/3.x BC requirement. | Versioned, unambiguous CLI and safe opt-in. |
| **D10** | Keep catalog static and avoid Composer/module metadata scans in native request execution. Benchmark cold/warm CLI `list/show/plan/doctor`, Composer-stub dry-run, memory and compiled graph; compare controlled sustained successful host RPM before accepting source rework. | Source-defined bounded metadata; 2% max successful-RPM regression rule remains. |
| **D11** | **APPROVED:** PHPForge in development tooling only. | No production QA module. |
| **D12** | **APPROVED:** `execution.runtime` uses host-owned Runwire, separate from portable Omnibus `execution.messaging`. | No shadow worker/server owner. |

**Priority source changes likely in later batches:** `ModuleCatalog` and validator; `ModuleStateResolver` + ownership/feature resolver; `ModuleSystemCommand` payloads and plan/apply; `ModuleActivationManager` and canonical capability policy; `ModuleSchemaManager` owner map; focused tests and docs. No production module file changes in Batch 0.

## C. Performance & QA baseline

The baseline runner is [`benchmarks/foundation4-module-cli-baseline.php`](../../benchmarks/foundation4-module-cli-baseline.php). On an isolated application root it measures a fresh dispatcher cold invocation and 15 warmed invocations for six existing module CLI operations, with p50/p95/p99, peak PHP allocated memory, exact PHP/runtime version and Foundation source. Its dry-run Composer subprocess is **stubbed** and never alters root requirements; it asserts the 11-entry inventory and plan schema version 1.

The executable runner **must pass in CI** and its JSON artifact must be attached before declaring this baseline established. It is a **within-run microbenchmark/characterization only**. It cannot certify realistic disk/network Composer execution, true operating-system process-tree RSS, production FPM/Runwire host sustained RPM, or a 2% performance improvement. Existing [Foundation 4 native host procedure](../foundation-4-host-benchmark-certification.md) remains the release host benchmark requirement.

**Exact-head evidence ledger (pending on audit creation):**

| Gate | Source / evidence | State |
| --- | --- | --- |
| Current module contracts, feature aliases, root package and schema behavior | `ModuleCatalogModelTest`, `ModuleStateResolverTest`, `ModuleLifecycleIntegrationTest`, `ModuleConfigPublicationTest` | Existing regression tests; re-run for Batch 0 |
| PHP 8.4/8.5 QA, production consumers, PHPStan, benchmarks and release evidence | [Security & Standards workflow](https://github.com/infocyph/Foundation/actions/workflows/security-standards.yml) | Await exact-head result |
| Pre-rework CLI cold/warm module baseline | `build/release-evidence/module-cli-baseline.json` | Await exact-head CI artifact |
| FPM/Runwire native host / selected DB/cache workload and cancellation | [Native host workflow](https://github.com/infocyph/Foundation/actions/workflows/foundation4-host-certification.yml) | Existing host matrix; re-run exact head |

**Batch 0 acceptance policy:** source tracing, 11→3/9 inventory and D01–D12 design records can be accepted from this audit; golden command and benchmark baselines require the new runner to succeed in CI and be archived. Do not claim a completed batch from documentation alone.
