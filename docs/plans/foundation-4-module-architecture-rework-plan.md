# Foundation 4 — Module Architecture & Lifecycle Rework Plan

> **Status:** ARCHITECTURE DIRECTION APPROVED — detailed design/audit still open. **Implementation: not started.**
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
| Optional runtime integration | Runwire | Host/runtime-owned; may be reported as optional messaging integration, not a parallel Foundation server or scheduler. |

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

### 3.2 Three public optional modules / nine optional features

| Public module | Feature ID | Primary Infocyph owner | Optional/external dependency and intended scope |
| --- | --- | --- | --- |
| **`data` — Data & Storage** | `database` | **DBLayer** `^6.1` | Connections, migrations, schema/read-write persistence. Core CacheLayer is reused, not installed. |
| `data` | `filesystem` | **Pathwise** `^4.2` | Filesystem/storage features; no requirement to enable database. |
| **`security` — Security & Identity** | `crypto` | **Epicrypt** `^3.2` | Cryptography, secrets, tokens and applicable OAuth primitives; use actual native APIs. |
| `security` | `validation` | **ReqShield** `^3.3` | Validation/sanitization/request security; independently selectable. |
| `security` | `mfa` | **OTP** `^7.0` | OTP/MFA factors and their applicable crypto/platform requirements; never auto-enable passkey. |
| `security` | `passkey` | **OTP/WebAuthn integration** | WebAuthn ceremonies via `web-auth/webauthn-lib ^5.3.9` and selected OTP support; **dependency sharing does not imply automatic activation of MFA**. |
| **`execution` — Execution & Communication** | `runtime` | **Runwire** `^2.1.1` | Optional **host-owned** persistent runtime; no Foundation-owned duplicate listener/scheduler. |
| `execution` | `messaging` | **Omnibus** `^3.0` | Events, queues, durable transports. Portable messaging does not automatically require Runwire; durable storage may conditionally require `data.database`. |
| `execution` | `communication` | **TalkingBytes** `^2.3` | HTTP/email/webhook/gRPC integration; choose features without activating unrelated runtime/messaging. |

**Nine feature identities:** `data.database`, `data.filesystem`, `security.crypto`, `security.validation`, `security.mfa`, `security.passkey`, `execution.runtime`, `execution.messaging`, `execution.communication`.

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
| Existing optional Runwire capability | `execution.runtime` (native host-owned) |
| `logging`, `operations`, `resources`, `session` | **Foundation Core built-ins**, not exposed as optional installable modules |
| CacheLayer/`cache` and native notifications | **Foundation Core**, never optional installable modules |
| PHPForge | **Engineering/QA only**, never runtime module |

**Public catalog acceptance:** exactly 3 module IDs and 9 feature IDs, with no fourth pseudo-module for Core, CacheLayer or PHPForge. Dedicated built-in introspection may remain available, but it is not counted as an installable module. All 11 current entries must be accounted for by this ownership map, with no orphaned config/schema or accidental deletion of persisted data.


## 4. Target contracts for the approved three-module architecture

### 4.1 Logical contracts

Prefer a **curated, deterministic catalog**, potentially split into small declarative definition files by purpose/domain, rather than filesystem scanning, plugin autoload magic or mandatory runtime reflection. Suggested conceptual structures (naming not approved yet):

- **Category presentation metadata:** three fixed module names and feature ordering only; Core and QA remain outside the module catalog. No generic domain-plugin runtime layer.
- **ModuleDefinition:** exactly `data`, `security`, `execution`; stable public ID, feature ownership, config/schema owners and supported feature dependencies/platform checks. Built-in/core capabilities are separate non-installable Foundation metadata.
- **FeatureDefinition:** exactly nine approved optional feature IDs (see §3.2); their own packages, platform, config predicate, native capabilities and conditional dependency edges.
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

**The user has explicitly approved** Core + 3 optional modules + 9 feature IDs, with PHPForge preconfigured separately. That **resolves the top-level taxonomy and installability**, not every lifecycle, API or migration detail.

| ID | Decision | Status | Selected direction / remaining question |
| --- | --- | --- | --- |
| D01 | Catalog scope | **OPEN** | Prefer a curated deterministic catalog; decide whether explicit third-party feature registration is permitted without auto-discovery. |
| D02 | Public module structure | **APPROVED** | Exactly `data`, `security`, `execution`; no five-category runtime hierarchy. Core and QA outside the optional module catalog. |
| D03 | Core and built-in semantics | **APPROVED** | Foundation Core prebuilt/required. Auth policy, cache, logging, operations, resources, session, notifications stay Core-owned; no built-in pseudo-modules. |
| D04 | Capability source | **OPEN** | Establish exact `app.capabilities` vs partial module override precedence; explicit production topology remains authoritative. |
| D05 | Package/feature granularity | **APPROVED** | Nine independently selectable features, precisely scoped packages, real conditional prerequisites and shared ownership. No install-all-on-category-selection. |
| D06 | Plan/apply | **OPEN** | Prefer one pure planner and phase-based executor; verify error recovery/atomicity boundaries. |
| D07 | Config ownership | **OPEN** | Respect application-owned config; publish and validate separately. |
| D08 | Schema ownership | **OPEN** | Real native/core schema owner map; no auto-destructive module action. |
| D09 | Public CLI/JSON contracts | **OPEN** | Proposed commands use `--feature` under 3 module IDs; settle help, alias removal and JSON schema revision. |
| D10 | Performance guardrails | **OPEN** | Decide verified cache/compile points after measuring cold/warm module operations. |
| D11 | Engineering tooling | **APPROVED** | PHPForge preconfigured for development/CI and release QA only; never installed or activated as a production module. |
| D12 | Optional native runtime | **APPROVED** | `execution.runtime` is Runwire and remains host-owned. `execution.messaging` is Omnibus; neither silently activates the other. |

For remaining open decisions, log **chosen option, rejected alternatives, rationale, affected files, QA/benchmark impact, date and status** in the decision log. Do not reopen D02/D03/D05/D11/D12 without a new explicit architecture decision.


## 6. Implementation batches and acceptance tracker

**Progress rule:** A batch is accepted only after every item and all batch QA gates are complete, committed to the working branch, verified on exact source, and reflected in this tracker. Drafting this plan does not count as implementation.

| Batch | Workstream | Status | Acceptance |
| --- | --- | --- | --- |
| **0** | Audit, baseline evidence and remaining design decisions | **OPEN (0/5)** | Audit report + concrete design decisions + baseline tests/metrics |
| **1** | Exactly three public modules and nine feature definitions | **OPEN (0/5)** | Stable definitions, validated category/ownership contracts |
| **2** | Dependency/state resolution and pure planning | **OPEN (0/6)** | Deterministic topology, blockers, versioned no-side-effect plans |
| **3** | Install/enable/disable/remove/repair execution | **OPEN (0/7)** | Safe explicit mutations, atomic boundaries, idempotent recovery |
| **4** | Three-module selected feature graph and prebuilt Core | **OPEN (0/6)** | One immutable selected native graph, no runtime discovery |
| **5** | All nine specialist features, schemas and native ownership | **OPEN (0/7)** | Auth, cache, database, messaging and other owners proven |
| **6** | CLI/API UX, docs, templates and upgrade notes | **OPEN (0/6)** | Machine/human UX verified with doc/test parity |
| **7** | Full regression, performance and release re-certification | **OPEN (0/7)** | Complete QA, no-dev matrix, measured performance, final evidence |
| **Total** | **49 scoped acceptance items** | **0/49 (0%)** | **0/8 batches accepted** |

### Batch 0 — Audit and approved architecture

- [ ] Trace module operations from CLI through catalog, state, activation, Composer runner, config publication, schema manager and native graph.
- [ ] Record current commands, JSON schema contracts, error/exit codes and real root-package ownership cases with golden fixtures.
- [ ] Inventory all 11 **current** catalog entries and their exact mapping into prebuilt Core, three approved modules, nine features, PHPForge QA and external-dependency roles.
- [ ] Establish representative baseline: module:list/show/plan/doctor cold/warm, install/remove dry-run, selected/disabled compiled runtime, worker lifecycle and memory; record machine/toolchain/environment.
- [ ] Resolve the remaining **OPEN** decisions D01/D04/D06–D10 (and D09 CLI JSON specifics), confirm the already **APPROVED** Core + 3 modules/9 features boundary, file ownership and batch order before source changes.

**QA gate:** no behavior changes; exact-branch baseline QA recorded, design choices explicit, no invented performance or existing defect claims.

### Batch 1 — Canonical module and domain model

- [ ] Define **exactly 3 public module IDs** and **9 independently selectable feature IDs**, stable aliases, dependency/platform invariants and precise package ownership; Core is not a module kind.
- [ ] Implement the approved `data`/`security`/`execution` taxonomy and nine feature definitions; remove the 11-entry optional/public pseudo-module shape without deleting built-in Core functionality.
- [ ] Keep required Core and development-only PHPForge outside module installation; distinguish PSR Log, WebAuthn, Sodium and Opis/Closure from optional native package ownership.
- [ ] Validate catalog collisions, package feature co-ownership, dependency cycles, config/schema identifiers and supported library floors.
- [ ] Refactor oversized metadata only when tests and profiling show improved clarity/maintainability without hot-path regressions.

**QA gate:** full **11-current → 3-module/9-feature** ownership map; exactly 3 public modules and 9 features in deterministic golden output; Core/QA not installable; catalog/validator tests; no dynamic scans.

### Batch 2 — Pure state/dependency resolver and planner

- [ ] One normalization pipeline for the **3 approved public module IDs + 9 feature IDs**, their requested feature aliases and explicit capability policy.
- [ ] Correct direct/transitive/unknown package-state and supported root Composer constraint decisions.
- [ ] Evaluate conditional dependencies, native runtime/platform requirements and dependency cycles in predictable order.
- [ ] Build immutable read-only plan records for install/enable/disable/remove/repair, including phase effects and blockers.
- [ ] Keep observability/read status separate from mutation; planner must have zero FS/network/Composer side effects.
- [ ] Version schema of machine-readable plan/state output if semantics change; compare deterministic fixtures.

**QA gate:** all **9 selected features** independently covered (including passkey without MFA activation, messaging without Runwire, filesystem without DB), conditional dependency matrix, absent/partial config, root ownership unknown, malformed inputs and platform conditions; no mutation during dry-run.

### Batch 3 — Safe lifecycle mutations

- [ ] Composer install reconciles **only the explicitly selected feature packages** of `data`, `security` or `execution`; core dependencies and PHPForge are never managed by module install.
- [ ] Activation writes safe partial overrides; disable checks active dependents; production release still has full explicit topology.
- [ ] Config publication is staging/atomic, safe against path traversal/symlink replacement and preserves project ownership.
- [ ] Schema actions are targeted by owner, idempotent, additive/non-destructive by default.
- [ ] Removal checks shared feature ownership, direct root requirements, dependent features and data retention; **Core and QA are not removable modules**.
- [ ] Repair handles partial phase completion and repeat invocations without imaginary Composer rollback.
- [ ] Side-effect order, invalidation, failure summaries and actionable recovery paths are deterministic.

**QA gate:** fixture Composer processes, injected failures at each step, multi-process writer safety, rollback/repair invariants, zero deleted app data, no unexpected package installs.

### Batch 4 — Native capability composition and production compilation

- [ ] Connect only approved `data.*`, `security.*`, `execution.*` selected feature states to one canonical FoundationBuildContext policy alongside prebuilt Core.
- [ ] Ensure InterMix provider contributions are deterministic and complete before immutable graph compilation.
- [ ] Keep Webrick route/HTTP ownership and Runwire host-owned runtime identity intact.
- [ ] Ensure worker/scheduler/CLI scopes never leak request principal or mutable module state.
- [ ] Verify compiled release ABI/trust/generation and cache invalidation only for real topology changes.
- [ ] Verify **Core only**, any one of the nine features, representative feature combinations and all optional features, with disabled features absent in `--no-dev` compiled installs.

**QA gate:** native compiled Webrick+InterMix and Runwire exact-host tests; source-free release trust; PHP 8.4/8.5 stable/lowest.

### Batch 5 — Domain/feature integration and persistence ownership

- [ ] Verify **Core auth policy** remains prebuilt while `security.mfa` and `security.passkey` have independent activation, shared dependency selection and replay-safe state.
- [ ] Verify `security.crypto` (Epicrypt), `security.validation` (ReqShield) and `security.passkey` (WebAuthn) with no duplicate cryptography or validation internals.
- [ ] Verify `data.database`/DBLayer and `data.filesystem`/Pathwise independently, keeping required CacheLayer Core-owned and owner-scoped schema/config.
- [ ] Verify independent `execution.messaging`/Omnibus, `execution.communication`/TalkingBytes, `execution.runtime`/Runwire selection with no shadow native ownership.
- [ ] Preserve prebuilt auth/cache/logging/operations/resources/session/notifications in Foundation Core, discoverable through Core diagnostics but not optional module lifecycle commands.
- [ ] Audit all external declarations (`psr/log`, `web-auth/webauthn-lib`, `opis/closure`, relevant extensions) for actual selected-role requirements.
- [ ] Verify DB/cache/session/queue failure, cancellation, durable recovery and native schema ownership against real selected services.

**QA gate:** native-library integration matrix, no accidental capability activation, no data loss, schema/capability security isolation, production `--no-dev` portability.

### Batch 6 — Command UX, documentation and catalog publishing

- [ ] Reconcile CLI list/show/doctor/plan/install/remove/enable/disable/repair/config/schema behavior with exactly `data`, `security`, `execution` and their feature-level operations.
- [ ] Keep deterministic human tables and machine JSON contracts, including versioned changes.
- [ ] Display the approved three modules, nine feature IDs and separate Core/QA boundary; never make choosing a top-level module install all its features.
- [ ] Synchronize `docs/modules.md`, architecture/configuration guides, Composer suggestions and generated CLI examples for Core + **3 modules / 9 features** and their external dependencies.
- [ ] Document current-4.0 breaking module contract changes and explicit application updates; **no older-major shim**.
- [ ] Update user-facing errors, recovery recipes, plan safety instructions and catalog publishing examples.

**QA gate:** exactly 3 module entries and 9 features in CLI golden outputs, Core/QA exclusion, executable documented feature commands, help/completion checks, no-doc-drift guard and scoped security review.

### Batch 7 — Full QA, performance and release closure

- [ ] Complete focused and full PHPForge QA, PHPStan, formatting, references, duplicate and security gates with no unjustified skips.
- [ ] Verify PHP 8.4/8.5 × prefer-lowest/stable and all selected native specialists; core/full `--no-dev` consumers.
- [ ] Compare before/after module inspect/plan/enable/compiled boot metrics and production-equivalent successful RPM against controlled matched hardware.
- [ ] Prove no >2% median successful-RPM regression for stable comparable workloads; flag variance or missing host evidence as **inconclusive/blocking**, never silently pass.
- [ ] Stress concurrent Composer/config mutation, partial failure/repair, worker lifecycle, persistence durability, cancellation, restore and shutdown paths.
- [ ] Close new PR review findings, record exact immutable commit/dependency manifest and synchronize this tracker with release audit tracker.
- [ ] Review final module API docs, release notes/metadata and mark rework ready only after every necessary gate passes.

**QA gate:** all exact-source workflows green; repeatable host evidence, no native owner duplication, no unresolved blockers. PR stays draft; user handles merge, tag and publish.

## 7. Design and QA guardrails

- **Performance:** sustainable correct RPM takes precedence over microbenchmark speed. No accidental Composer reads, metadata traversal, disk/network discovery or reflection on hot/compiled routes. Benchmark cold and warmed module operations independently of web/runtime requests. Use source-owner native APIs.
- **Security:** no insecure module paths, arbitrary code evaluation from discoverable manifests, silent extension/package activation or overwriting application-owned files. Existing platform readiness, Composer ownership and user intent remain explicit.
- **Data integrity:** never delete database tables, queues, session state, credentials or config merely due to package/module removal. Schema revisions and migrations must declare ownership and require explicit operator changes when destructive.
- **Reliability:** no partial Composer failure auto-rollback promises; re-plan, re-resolve and repair idempotently. Test signal, concurrency, locks, cancellation, handler failure and persistent worker reset.
- **Engineering:** follow [PHPForge engineering principles](https://raw.githubusercontent.com/infocyph/PHPForge/refs/heads/main/resources/engineering-principles.md). Keep files/abstractions few, code strict and readable; don't rename or reformat unrelated source. No compatibility layers for previous Foundation major versions.
- **Evidence:** every checked item names its test, run, exact source SHA and resolution. No 100% or release-ready label without verified CI and representative host gates.
- **Source boundary:** Foundation only. No Infbyte or other library changes until separately requested. Current PR #20 stays draft. No merge, release, tag or publish.

## 8. Decision log (update before coding)

| Decision | Selected resolution | Rationale/evidence | Status |
| --- | --- | --- | --- |
| D02 | Exactly 3 optional public modules: `data`, `security`, `execution` | Explicit user approval; three modules replace five functional categories as public module hierarchy; no implicit install-all | **APPROVED — 2026-10-14** |
| D03 | Required Foundation Core and built-ins do not become installable modules | Explicit user approval that Core comes with Foundation; prevents pseudo-module removal/activation | **APPROVED — 2026-10-14** |
| D05 | Exactly 9 independently selectable features: 2 data + 4 security + 3 execution | Explicit user approval; one module does not install all grouped libraries | **APPROVED — 2026-10-14** |
| D11 | PHPForge remains its own preconfigured development/QA layer | Explicit user approval; no production PHPForge module/runtime activation | **APPROVED — 2026-10-14** |
| D12 | Runwire under `execution.runtime`, native host-owned and separate from Omnibus | Approved grouping and established native runtime-ownership requirement | **APPROVED — 2026-10-14** |
| D01, D04, D06–D10 | Detailed curated catalog registration, capability precedence, lifecycle, schemas, CLI JSON and performance | To be resolved from Batch 0 source audit; do not infer implementation details from the high-level approval | **OPEN** |

## 9. Change log / progress journal

| Date | Milestone | Exact code/QA evidence | Tracker |
| --- | --- | --- | --- |
| 2026-10-14 | Drafted dedicated Foundation 4 module architecture and lifecycle plan. No module implementation, no QA acceptance claimed. | Plan-only commit `b36749c0`; implementation source baseline retained | **0/49 tasks, 0/8 batches** |
| 2026-10-14 | **Approved target: prebuilt Core + separate PHPForge QA + exactly 3 optional public modules / 9 independent features**. Rewrote ownership mapping, decisions, batch acceptance and lifecycle examples accordingly. | **Plan-only update; no source changes or acceptance QA claimed.** Previous 11-entry catalog remains current runtime baseline. | **0/49 implementation tasks, 0/8 batches; 5 architecture decisions approved** |

**Next action:** complete **Batch 0** against the **already-approved 3-module/9-feature target**; inspect current execution flow, settle only remaining open decision records and benchmark the current 11-entry catalog. Then implement each batch with QA and tracker synchronization. Do not change Foundation module APIs before completing the source audit.
