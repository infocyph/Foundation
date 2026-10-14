# Foundation 4 — Module Architecture & Lifecycle Rework Plan

> **Status:** DRAFT — design and audit only. **Implementation: not started.**
>
> **Working branch:** `feature/foundation-3` · **PR:** [#20](https://github.com/infocyph/Foundation/pull/20) (keep draft)  
> **Plan created:** 2026-10-14 · **Scope:** Foundation repository exclusively  
> **Companion release tracker:** [Foundation 4 release audit](foundation-4.0-release-audit-and-plan.md)  
> **Implementation cadence:** one complete batch → focused QA → full QA → evidence → tracker sync → next batch.

## 1. Intent and non-negotiable boundaries

Design a coherent, performance-first Foundation **module architecture** for substantial rework. Start from the actual Foundation 4 implementation, not published 2.x contracts or an imagined greenfield framework. The work may intentionally reshape the current Foundation 4 module API and CLI output **once the design is approved**, but must not reintroduce previous-major compatibility shims.

The new design must clearly separate:

1. **Ecosystem domain/category:** for documentation, navigation, discoverability, grouping and optional policy.
2. **Composer package:** direct vs transitive installation ownership and supported package constraints.
3. **Application module:** deployable application purpose and its install/config/schema/diagnostics surface.
4. **Runtime capability:** explicit opt-in to an actual native integration/provider.
5. **Feature:** an independently selectable subset of a module, with its own package and platform needs.
6. **Native-library owner:** the upstream package responsible for its own mechanisms.
7. **Engineering tooling:** tools for building/testing Foundation; never silently made production modules.

Do **not** make all ecosystem libraries “modules” just to match a grouping diagram. Do **not** create Foundation wrappers around native InterMix/Webrick/Runwire/DBLayer/CacheLayer/Omnibus/Epicrypt/OTP/Pathwise/ReqShield/TalkingBytes mechanisms already owned upstream.

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

## 3. Proposed ecosystem domains (classification ≠ installable modules)

Five documentation/organizational domains, each with Infocyph and directly declared external packages. **This is a proposed presentation taxonomy, not five runtime modules.**

| Domain | Infocyph library members | External direct declarations | Runtime/installation note |
| --- | --- | --- | --- |
| **Core & Runtime** | ArrayKit, InterMix, UID, Webrick | `psr/log ^3.0.2` | Four required native libraries; `psr/log` required. |
| **Data & Storage** | CacheLayer, DBLayer, Pathwise | — | CacheLayer required; DBLayer/Pathwise optional specialists. |
| **Security & Identity** | Epicrypt, OTP, ReqShield | `web-auth/webauthn-lib ^5.3.9`, `ext-sodium` | Specialists optional; WebAuthn selected feature; Sodium selected cryptographic feature/development extension. |
| **Execution & Communication** | Runwire, Omnibus, TalkingBytes | `opis/closure ^4.5` | Runtime specialists optional; `opis/closure` currently require-dev, not a mandatory production runtime. |
| **Engineering & Quality Assurance** | **PHPForge alone** | — | `infocyph/phpforge dev-main@dev`, development-only. Never a production module/capability. |

**Platform baseline:** PHP `^8.4` and Composer runtime API `^2.0`. Composer required set = ArrayKit, CacheLayer, InterMix, UID, Webrick, PSR Log. Optional specialists currently appear in `require-dev` for the Foundation integration-test matrix and `suggest` for applications. Domain membership must **not** change installation scope by implication.

**Further decision required:** Should the taxonomy appear only in documentation/CLI grouping metadata, or become a first-class `category` property of catalog entries? It should not become extra runtime indirection unless a verified functional use exists.

## 4. Proposed target model (subject to Batch 1 design decisions)

### 4.1 Logical contracts

Prefer a **curated, deterministic catalog**, potentially split into small declarative definition files by purpose/domain, rather than filesystem scanning, plugin autoload magic or mandatory runtime reflection. Suggested conceptual structures (naming not approved yet):

- **DomainDescriptor:** presentation name, description and member ordering only.
- **ModuleDefinition:** stable purpose ID; scope and ownership (`specialist`, `core-backed`, `built-in`); managed package roles; config/schema owners; supported feature definitions; conditional dependencies; platform checks.
- **FeatureDefinition:** selectable feature ID, feature packages/platform, config predicate, native capabilities and dependency edges.
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
- Shared optional package cannot be removed when another selected feature/module still needs it; built-in entries cannot be removed.
- Versioned machine-readable API with additive compatibility *within the chosen 4.0 schema version only*. If rework makes a breaking JSON change, increment schema and document migration for current-4.0 tooling; there is **no Foundation 2.x/3.x parity obligation**.
- Stateless, deterministic, allocation-aware read/list/status/plan paths; do not include runtime request/secret state in a persistent catalog.
- Production graph compiles only selected capability nodes. No package scanning, Composer process, mutable activation, module schema IO or business-policy interpretation on every request.
- No duplicated native HTTP router, message bus, transaction manager, identity token implementation, persistence engine or scheduler.
- All new CLI side effects execute only in explicit write commands, with stable exit codes, lock/atomic write safety where applicable, audit output and phase-specific failure recovery.

## 5. Design decisions to resolve *before implementation*

| ID | Decision | Options / question | Default design hypothesis |
| --- | --- | --- | --- |
| D01 | Catalog scope | Static curated catalog, explicit extension registration, or arbitrary third-party discovery? | Curated + explicit registration only; **no scanning**. |
| D02 | Five domains | CLI grouping metadata or first-class runtime hierarchy? | Metadata, no nested module runtime. |
| D03 | Built-in entry semantics | Keep `built_in` plus `core_backed` distinctions? | Yes; don't conflate lifecycle types. |
| D04 | Capability source | `app.capabilities` and partial `modules.capabilities` override precedence | One normalized policy; explicit production topology remains authoritative. |
| D05 | Packages/features | Compose package requirements per feature and shared ownership | Separate immutable feature selection/ownership. |
| D06 | Plan/apply | One pure planner and minimal phase-based executor vs separate command mutation logic | One planner; explicit executor only for side effects. |
| D07 | Config ownership | Publish templates separately from effective config validity | Keep separate; no implicit overwrite. |
| D08 | Schema ownership | Application/core/native upstream responsibilities, explicit destructive actions | Owner map; no auto-destructive change. |
| D09 | Public contracts | Command naming, plan/show/status JSON version, human output | Minimize commands and version changed machine payloads. |
| D10 | Performance guardrails | When to precompute, cache, compile or measure module data | Cache metadata, not mutable resolution; benchmark representative paths. |
| D11 | Developer tooling | PHPForge role and package grouping | Development-only, never installable as module. |
| D12 | Optional native runtime | Runwire and messaging/package role boundaries | Native host owns runtime; no parallel server/process API. |

For each decision, log **chosen option, rejected alternatives, rationale, affected files, QA/benchmark impact, date and status** in the decision log below. Avoid deciding on assumptions alone.

## 6. Implementation batches and acceptance tracker

**Progress rule:** A batch is accepted only after every item and all batch QA gates are complete, committed to the working branch, verified on exact source, and reflected in this tracker. Drafting this plan does not count as implementation.

| Batch | Workstream | Status | Acceptance |
| --- | --- | --- | --- |
| **0** | Audit, baseline evidence and design decision closure | **OPEN (0/5)** | Audit report + concrete design decisions + baseline tests/metrics |
| **1** | Module/domain model and curated metadata | **OPEN (0/5)** | Stable definitions, validated category/ownership contracts |
| **2** | Dependency/state resolution and pure planning | **OPEN (0/6)** | Deterministic topology, blockers, versioned no-side-effect plans |
| **3** | Install/enable/disable/remove/repair execution | **OPEN (0/7)** | Safe explicit mutations, atomic boundaries, idempotent recovery |
| **4** | Native capability graph and compiled runtime policy | **OPEN (0/6)** | One immutable selected native graph, no runtime discovery |
| **5** | Specialist features, schemas and external ownership | **OPEN (0/7)** | Auth, cache, database, messaging and other owners proven |
| **6** | CLI/API UX, docs, templates and upgrade notes | **OPEN (0/6)** | Machine/human UX verified with doc/test parity |
| **7** | Full regression, performance and release re-certification | **OPEN (0/7)** | Complete QA, no-dev matrix, measured performance, final evidence |
| **Total** | **49 scoped acceptance items** | **0/49 (0%)** | **0/8 batches accepted** |

### Batch 0 — Audit and approved architecture

- [ ] Trace module operations from CLI through catalog, state, activation, Composer runner, config publication, schema manager and native graph.
- [ ] Record current commands, JSON schema contracts, error/exit codes and real root-package ownership cases with golden fixtures.
- [ ] Inventory all 11 catalog entries, Foundation core/native exceptions, five ecosystem domains and direct external-dependency roles.
- [ ] Establish representative baseline: module:list/show/plan/doctor cold/warm, install/remove dry-run, selected/disabled compiled runtime, worker lifecycle and memory; record machine/toolchain/environment.
- [ ] Resolve D01–D12 in a design review; approve exact target semantics, file ownership and implementation sequence **before** source changes.

**QA gate:** no behavior changes; exact-branch baseline QA recorded, design choices explicit, no invented performance or existing defect claims.

### Batch 1 — Canonical module and domain model

- [ ] Define stable ID, alias, kind (`specialist`, `core-backed`, `built-in`), feature, dependency and platform invariants.
- [ ] Introduce five optional ecosystem presentation categories without turning required libraries into installable modules.
- [ ] Keep core/external/optional/dev ownership precise (including PHPForge alone under engineering tooling).
- [ ] Validate catalog collisions, package feature co-ownership, dependency cycles, config/schema identifiers and supported library floors.
- [ ] Refactor oversized metadata only when tests and profiling show improved clarity/maintainability without hot-path regressions.

**QA gate:** golden 11-entry inventory; deterministic category ordering and snapshot; catalog+validator tests; no dynamic scans.

### Batch 2 — Pure state/dependency resolver and planner

- [ ] One normalization pipeline for requested module/feature alias and explicit capability policy.
- [ ] Correct direct/transitive/unknown package-state and supported root Composer constraint decisions.
- [ ] Evaluate conditional dependencies, native runtime/platform requirements and dependency cycles in predictable order.
- [ ] Build immutable read-only plan records for install/enable/disable/remove/repair, including phase effects and blockers.
- [ ] Keep observability/read status separate from mutation; planner must have zero FS/network/Composer side effects.
- [ ] Version schema of machine-readable plan/state output if semantics change; compare deterministic fixtures.

**QA gate:** exhaustive dependency/feature matrix, absent/partial config, root ownership unknown, malformed inputs, platform conditions; no mutation during dry-run; benchmark state resolution.

### Batch 3 — Safe lifecycle mutations

- [ ] Composer install reconciles only selected direct packages and preserves host dev-dependency mode.
- [ ] Activation writes safe partial overrides; disable checks active dependents; production release still has full explicit topology.
- [ ] Config publication is staging/atomic, safe against path traversal/symlink replacement and preserves project ownership.
- [ ] Schema actions are targeted by owner, idempotent, additive/non-destructive by default.
- [ ] Removal checks shared feature ownership, direct root requirements, built-ins, active dependencies and data retention.
- [ ] Repair handles partial phase completion and repeat invocations without imaginary Composer rollback.
- [ ] Side-effect order, invalidation, failure summaries and actionable recovery paths are deterministic.

**QA gate:** fixture Composer processes, injected failures at each step, multi-process writer safety, rollback/repair invariants, zero deleted app data, no unexpected package installs.

### Batch 4 — Native capability composition and production compilation

- [ ] Connect selected module/feature state to one canonical FoundationBuildContext capability policy.
- [ ] Ensure InterMix provider contributions are deterministic and complete before immutable graph compilation.
- [ ] Keep Webrick route/HTTP ownership and Runwire host-owned runtime identity intact.
- [ ] Ensure worker/scheduler/CLI scopes never leak request principal or mutable module state.
- [ ] Verify compiled release ABI/trust/generation and cache invalidation only for real topology changes.
- [ ] Verify core-only, database-only, fully selected and disabled modules in `--no-dev` installs.

**QA gate:** native compiled Webrick+InterMix and Runwire exact-host tests; source-free release trust; PHP 8.4/8.5 stable/lowest.

### Batch 5 — Domain/feature integration and persistence ownership

- [ ] Core-backed auth: OTP/passkey package selection, independent features, replay-safe state and selected policies.
- [ ] Security: Epicrypt, ReqShield and WebAuthn boundary; no duplicate crypto or validation internals.
- [ ] Data: required CacheLayer outside install/remove, optional DBLayer/Pathwise, owner-scoped schema/config.
- [ ] Messaging and communication: Omnibus/TalkingBytes and optional Runwire host selection without shadow ownership.
- [ ] Built-in logging/operations/resources/session and native notifications remain explicit, discoverable and non-removable as appropriate.
- [ ] Audit all external declarations (`psr/log`, `web-auth/webauthn-lib`, `opis/closure`, relevant extensions) for actual selected-role requirements.
- [ ] Verify DB/cache/session/queue failure, cancellation, durable recovery and native schema ownership against real selected services.

**QA gate:** native-library integration matrix, no accidental capability activation, no data loss, schema/capability security isolation, production `--no-dev` portability.

### Batch 6 — Command UX, documentation and catalog publishing

- [ ] Reconcile CLI list/show/doctor/plan/install/remove/enable/disable/repair/config/schema behavior with approved model.
- [ ] Keep deterministic human tables and machine JSON contracts, including versioned changes.
- [ ] Add domain/category browsing only if approved; do not hide module installation scope behind category names.
- [ ] Synchronize `docs/modules.md`, architecture/configuration guides, Composer suggestions and generated examples.
- [ ] Document current-4.0 breaking module contract changes and explicit application updates; **no older-major shim**.
- [ ] Update user-facing errors, recovery recipes, plan safety instructions and catalog publishing examples.

**QA gate:** CLI golden outputs, executable documented commands, help/completion checks, no-doc-drift guard, scoped security review.

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
| D01–D12 | Pending Batch 0 design review | Baseline inventory in this draft, code walkthrough and performance/contract measurements to follow | **OPEN** |

## 9. Change log / progress journal

| Date | Milestone | Exact code/QA evidence | Tracker |
| --- | --- | --- | --- |
| 2026-10-14 | Drafted dedicated Foundation 4 module architecture and lifecycle plan. No module implementation, no QA acceptance claimed. | Plan-only commit on `feature/foundation-3`; implementation source baseline retained | **0/49 tasks, 0/8 batches** |

**Next action:** complete **Batch 0**; inspect current code execution flow and collect decision records. Once the target model is approved, proceed batch-by-batch with QA and tracker synchronization. Do not start “heavy module rework” by changing APIs on assumptions.
