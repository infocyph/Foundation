# Foundation 3 — Specialist Module System Hardening Plan

## Status

**Branch:** `foundation-3/close-26.6`  
**Target:** Foundation 3 module-system hardening before release  
**Plan state:** IN PROGRESS  
**Last synchronized with branch:** 2026-09-23  
**Scope:** later-required specialist modules only

Foundation core/built-in capabilities are outside this module-system pass.

CacheLayer is promoted to a direct Foundation runtime dependency and `cache` is removed from
the public module system entirely. Cache remains a core Foundation capability/configuration
domain, but it is not installable/removable/listed as a module.

The module-system pass covers exactly seven specialist module namespaces:

- `auth`
- `communication`
- `database`
- `filesystem`
- `messaging`
- `security`
- `validation`

`auth` is a virtual/core-backed module namespace: Foundation core authentication exists
without a specialist package, while OTP/passkey behavior is added through selected auth
features.

`notifications` is not an eighth specialist module. It is a Foundation-native capability.
TalkingBytes-backed email support is an optional notifications integration when the package is
available.

The objective is to make module installation, package ownership, capability activation,
feature selection, dependency explanation, readiness, removal and schema lifecycle
match Foundation 3's final explicit-capability runtime model.

## Core Cache Boundary

The module redesign must treat CacheLayer as Foundation infrastructure, not as a module:

- Foundation directly requires `infocyph/cachelayer ^3.4`;
- `cache` is removed from `ModuleCatalog`;
- `cachelayer` is removed as a module alias;
- `module:list/show/install/remove cache` are no longer module operations;
- `cache.php` is a default application config file in InfByte;
- `CacheServiceProvider` remains controlled by Foundation's cache capability/topology;
- cache schemas/adapters are core cache-capability infrastructure, not module-owned lifecycle;
- specialist modules may depend conditionally on the core cache capability, but never on a
  "cache module";
- under explicit `app.capabilities`, cache remains cold until `cache` is selected;
- when `app.capabilities` is omitted, compatibility auto-discovery may infer available
  capabilities, so status/readiness must report inferred activation rather than pretending it
  is explicit.

This plan must not reintroduce cache through feature aliases, dependency graph nodes, module
status JSON, install/remove planning, or module schema ownership.

---

# 0. Batch 0 — Cache/Core Boundary Closure

**Status:** DONE

The structural CacheLayer promotion is already on the branch, but Batch 0 remains open until
the dedicated cache lifecycle is regression-safe and the closure checks are verified.

- [x] Promote `infocyph/cachelayer ^3.4` from `require-dev`/suggest to Foundation `require`.
- [x] Remove `cache` and `cachelayer` from `ModuleCatalog`.
- [x] Remove cache install/remove/config-publication behavior from the module system.
- [x] Move CacheLayer schema orchestration into the core `Cache` subsystem.
- [x] Add `cache:schema:status` and `cache:schema:install`.
- [x] Remove optional-package guards/messages that tell applications to install a cache module.
- [x] Keep runtime cache activation controlled by the `cache` capability.
- [x] Fix the `CacheSchemaManager::resourceStatus()` PDO-ready regression.
- [x] Add database-backed cache schema regression coverage for both manager and public CLI
  status/install paths.
- [x] Pin explicit-vs-inferred cache activation semantics with regression coverage: omitted
  topology is inferred compatibility mode; explicit omission keeps cache cold.
- [x] Verify Foundation ships `resources/config/cache.php` as the canonical cache application
  template. Current InfByte `main` / Foundation-3 handoff synchronization is intentionally
  deferred to Batch 10/post-Foundation consumer handoff, matching the release sequence.
- [x] Add module-boundary tests proving `cache` / `cachelayer` are not catalog entries or
  resolvable module aliases.
- [x] Add a guard proving CacheLayer remains in Foundation `require`, absent from
  `require-dev`/`suggest`, and outside specialist module ownership.
- [x] Update Foundation docs to describe CacheLayer as core infrastructure rather than a module.
- [x] Review the previously affected runtime/config/docs/module surfaces and confirm no stale
  `module:install cache`, cache-module schema ownership or equivalent lifecycle semantics remain
  on this branch.

## Acceptance

`cache` is absent from the module subsystem, CacheLayer remains available to Foundation core,
cache schemas are managed only through the core cache lifecycle, and explicit topology can keep
the cache capability cold until selected.

**Batch 0 status:** DONE. InfByte repository synchronization of the default `config/cache.php`
file is a consumer handoff item for Batch 10 and does not reopen Foundation's cache/module
boundary.

## Progress Tracker

Status legend:

- **DONE** — acceptance criteria and relevant tests are complete.
- **PARTIAL** — implementation exists, but the batch acceptance gate is not closed.
- **NOT STARTED** — batch implementation has not started.

| Batch | Scope | Status | Current checkpoint |
| --- | --- | --- | --- |
| **0** | Cache/core boundary closure | **DONE** | Cache core ownership, schema CLI lifecycle, activation semantics and module exclusion are regression-covered. |
| **1** | Specialist module state foundation | **PARTIAL** | Resolver, ownership/readiness JSON, constraint validation and floor guards are implemented; PHPForge QA rerun is in progress after CI-specific fixes. |
| **2** | Catalog model | **PARTIAL** | Package roles, feature/platform metadata, optional-integration status, conditional dependency declarations and graph validation are implemented; matrix closure remains. |
| **3** | Auth decomposition | **PARTIAL** | Core-backed auth, selective OTP/passkey install/remove, feature aliases/state and shared-package protection are implemented; dependency-engine/matrix closure remains. |
| **4** | Communication / notifications ownership | **PARTIAL** | Ownership/config/topology/package-isolation changes are implemented; exact-head PHPForge closure remains. |
| **5** | Dependency engine | **PARTIAL** | Conditional module/core-capability evaluation, blockers, show output and module:plan are implemented; exact-head QA remains. |
| **6** | Activation lifecycle | **PARTIAL** | Atomic module-owned activation overrides plus enable/disable runtime semantics are implemented; exact-head QA remains. |
| **7** | Schema lifecycle | **PARTIAL** | Capability-aware aggregate sync and explicit targeted schema installation are implemented; exact-head QA remains. |
| **8** | Install/remove/repair hardening | **NOT STARTED** | Composer policy, shared ownership, safe removal and repair/resume. |
| **9** | Platform readiness | **NOT STARTED** | Selected-feature extension/adapter readiness and doctor output. |
| **10** | Documentation and release acceptance | **NOT STARTED** | Migration/docs/JSON contract/final QA and release gate. |

Tracker rule: update this table and the detailed checkboxes in the same commit as meaningful
implementation progress. A batch becomes **DONE** only when its acceptance criteria and relevant
tests are satisfied.

---

# 1. Current Specialist Module Baseline

| Module | Foundation install target | Current config publication | Current schema ownership |
| --- | --- | --- | --- |
| `auth` | currently installs `infocyph/otp ^6.1` + `web-auth/webauthn-lib ^5.3.5`; target is feature-driven | none | `auth` |
| `communication` | `infocyph/talkingbytes ^2.1` | `communication.php` | none |
| `database` | `infocyph/dblayer ^5.1` | `database.php` | none |
| `filesystem` | `infocyph/pathwise ^4.1` | `filesystem.php` | none |
| `messaging` | `infocyph/omnibus ^2.6` | `messaging.php` | `messaging` |
| `security` | `infocyph/epicrypt ^3.1` | `security.php` | none |
| `validation` | `infocyph/reqshield ^3.2` | `validation.php` | none |

## Important transitive package relationships

Package presence still remains insufficient as a specialist-module state signal. CacheLayer
dependencies are no longer relevant to module ownership because CacheLayer belongs to
Foundation core.

The current branch already reads the application root `composer.json` and reports a per-package
`direct` flag. However, module `installed` state is still primarily derived from
`Composer\InstalledVersions::isInstalled()`, so a transitive package can still make a module
look installed. Batch 1 completes this separation rather than rebuilding it from scratch.

Important integration relationships include:

- Omnibus may integrate with DBLayer and the core cache capability depending on selected
  messaging behavior.
- ReqShield may integrate with DBLayer for database-aware validation.
- auth features may consume the core cache capability without creating a cache module edge.
- TalkingBytes has optional runtime/platform integrations for gRPC, IMAP, charset handling,
  DKIM Ed25519 and POSIX process handling.
- Pathwise exposes optional storage adapters/extensions beyond its required Flysystem core.
- Epicrypt has optional development/integration relationships with Pathwise but does not make
  Pathwise part of the Foundation security module.

---

# 2. Non-Negotiable Module-System Principles

## 2.1 Purpose-first public modules

Foundation owns the public module vocabulary.

Composer package names must not become the primary application-facing module API.

Keep aliases for migration/convenience, but canonical module names remain Foundation-owned:

`auth`, `communication`, `database`, `filesystem`, `messaging`, `security`, `validation`.

## 2.2 No Composer package auto-discovery

Do not scan installed Composer packages and automatically turn them into Foundation modules.

`ModuleCatalog` remains curated and explicit.

## 2.3 Installation is not activation

A package being present in `vendor/` does not mean:

- the application directly installed the Foundation module;
- the module is enabled in `app.capabilities`;
- its config exists;
- its selected feature is configured;
- its schema is applicable;
- the runtime provider is active;
- the module is production-ready.

These states must remain distinct.

## 2.4 Foundation owns policy; libraries own mechanics

The module layer may coordinate package selection, config publication, application capability
state and Foundation-owned schemas.

It must not duplicate protocol/storage/crypto/validation mechanics owned by the specialist
libraries.

## 2.5 Explicit production topology remains authoritative

When `app.capabilities` is explicitly present, only selected capabilities are runtime-active.

Module commands may inspect/install/repair inactive modules, but runtime validation and schema
applicability must respect explicit capability activation.

---

# 3. Target Module State Model

Replace the current overly broad installed/partial/available interpretation with independent
state dimensions.

Conceptual state:

```text
package_available
package_direct
package_transitive
package_ownership_unknown
installed_by_module
enabled
activation_explicit
configured
config_published
dependencies_satisfied
platform_ready
schema_ready
ready
```

## Required meanings

### package_available

The package is present in Composer's installed graph.

This may be direct or transitive.

### package_direct

The application root `composer.json` directly requires the package.

This is the strongest available local signal that the application intentionally owns the
package requirement.

### package_transitive

The package is installed, but the application does not directly require it.

This must not make the corresponding Foundation module appear fully installed.

### package_ownership_unknown

Foundation could not reliably read or parse the application root Composer manifest.

This must be an actionable diagnostic state. Do not silently convert an unreadable/invalid root
manifest into "nothing is directly required."

### installed_by_module

The module's required root package requirements are directly application-owned and satisfy the
catalog constraints.

For modules with feature packages, this applies only to the base requirements and selected
features. Shared feature packages must be reference-aware during removal.

### enabled

The canonical capability is active under the resolved Foundation capability topology.

### activation_explicit

Whether enablement came from explicit `app.capabilities` rather than compatibility
auto-discovery.

### configured

Resolved Foundation configuration is structurally valid for the module and selected feature
set.

This must not be inferred only from whether a physical `config/*.php` exists because
`FoundationDefaults` may provide valid effective configuration without publication.

### config_published

The application has physical module-owned config templates published where publication applies.

Publication state is application-ownership/diagnostic information and is distinct from effective
configuration validity.

### dependencies_satisfied

All unconditional and currently active conditional module dependencies and Foundation-core
capability requirements are satisfied.

### platform_ready

Required PHP extensions/platform capabilities for the active feature set are available.

### schema_ready

All applicable module-owned schemas are installed.

### ready

The module can actually serve the currently selected application configuration.

---

# 4. Module Definition Model

Evolve the catalog away from one flat:

```text
packages
config
schemas
```

definition.

The implementation does not have to use exactly these class/property names, but the model
must represent these concepts explicitly.

```text
ModuleDefinition
  identity
    name
    aliases
    description

  package requirements
    required
    optional
    features

  capability
    provided capability
    activation semantics

  features
    feature name
    package requirements
    conditional dependencies
    platform requirements
    config predicates

  dependencies
    unconditional
    conditional

  config templates
    owner
    feature association

  schemas
    owner
    applicability

  state/readiness
    package ownership
    enabled
    configured
    dependency readiness
    platform readiness
    schema readiness
```

Do not build a generic package-management framework. Keep the model only as rich as the
seven specialist modules require.

---

# 5. P0 — Separate Package Presence from Module Ownership

## Problem

`Composer\InstalledVersions::isInstalled()` cannot determine application intent.

The branch already reads root Composer requirements, but current module status still conflates
installed-package availability with module installation. CacheLayer is explicitly excluded from
this ownership model because Foundation owns it as core infrastructure.

## Work

- [x] Read the application root `composer.json` when determining direct package ownership.
- [x] Move Composer/config inspection behind a dedicated read-only `ModuleStateResolver` so
  lifecycle mutation code does not own status interpretation.
- [x] Read/normalize root Composer metadata once per status operation rather than once per module.
- [x] Distinguish direct package requirement from transitive package availability.
- [x] Represent unreadable/invalid root Composer metadata as ownership-unknown with an actionable
  blocker.
- [x] Keep installed-package availability separately visible for diagnostics.
- [x] Define module installation from direct required package ownership, not only vendor presence.
- [x] Preserve package constraint compatibility checks for the catalog's supported caret ranges,
  including direct root-constraint floor safety.
- [ ] Add fixture-driven tests for:
  - [x] specialist package present only transitively;
  - [x] specialist package directly required by the application;
  - [x] package present with an incompatible direct/catalog range;
  - [x] direct package missing while a transitive copy remains installed;
  - [x] missing/unreadable/invalid application root Composer manifest.
- [x] Explicitly exclude Foundation core dependencies such as CacheLayer from module ownership
  calculations.
- [x] Ensure `module:list` and `module:show` expose direct/transitive/ownership, activation,
  effective-config/publication and readiness distinctions clearly.

## Acceptance

A transitive package never silently becomes a directly installed Foundation module, and
unreadable application ownership metadata is never silently interpreted as "transitive."

**Batch 1 implementation status:** COMPLETE. Tracker remains **PARTIAL** until the latest
PHP 8.4/8.5 PHPForge matrix completes successfully.

---

# 6. P0 — Required / Optional / Feature Package Roles

## Problem

The current flat package map cannot describe the real package topology.

## Work

- [x] Split module package declarations into typed required/base, feature and optional package roles.
- [x] Keep package constraint ownership centralized in `ModuleCatalog`.
- [x] Exclude optional integration packages from the existing module install/remove package set.
- [x] Surface optional integration availability separately through module state without counting
  optional packages toward module installation.
- [x] Allow one package requirement to declare multiple feature owners, including OTP shared by
  `otp` and `passkey`.
- [x] Add catalog validation/tests for package roles, feature ownership, aliases, platform
  declarations and dependency graph cycles.
- [x] Keep the existing supported-package floor drift guard operating over managed
  required/feature packages.

## Acceptance

The module catalog can explain why a package is required and whether it is base, feature or
optional integration state.

**Batch 2 implementation status:** COMPLETE. Tracker remains **PARTIAL** until the next PHPForge
matrix validates the catalog model together with Batch 1.

---

# 7. P0 — Restructure Auth as Core + Selectable Features

## Problem

Foundation auth is already a native Foundation capability, but current
`module:install auth` installs both OTP and WebAuthn-related packages.

That is unnecessarily broad and does not match the actual runtime architecture.

Foundation passkeys currently use `Infocyph\OTP\Passkey`, while OTP declares
`web-auth/webauthn-lib` as the optional library required for its passkey integration. Therefore
the passkey feature is not "WebAuthn package only."

## Target model

Conceptually:

```text
auth
  core
    Foundation-owned

  features
    otp
      infocyph/otp ^6.1

    passkey
      infocyph/otp ^6.1
      web-auth/webauthn-lib ^5.3.5

    database-storage
      database module / DBLayer

    shared-cache
      Foundation core cache capability / CacheLayer

    security
      security module / Epicrypt

    notifications
      Foundation notifications capability
      optional TalkingBytes email integration
```

## Decisions to implement

- [x] Treat `auth` as a virtual/core-backed module namespace rather than a base package bundle.
- [x] `auth` itself does not automatically install OTP + WebAuthn.
- [x] Support the preferred repeatable feature CLI:
  - `module:install auth --feature=otp`
  - `module:install auth --feature=passkey`
  - repeatable `--feature` if both are wanted.
- [x] Bare `module:install auth` is a successful core-backed package no-op and explicitly reports
  that specialist packages require `--feature=otp` or `--feature=passkey`.
- [x] Preserve `otp`, `mfa`, `passkey`, `passkeys`, `webauthn` as feature aliases that resolve
  narrowly rather than broadening to the whole auth bundle.
- [x] Keep package-name compatibility only where intent is precise: `web-auth/webauthn-lib`
  resolves to passkey, while shared `infocyph/otp` is rejected as ambiguous unless the feature is
  explicitly requested.
- [x] Passkey selection requires both `infocyph/otp` and `web-auth/webauthn-lib`.
- [x] Passkey package selection does not imply that OTP/TOTP MFA is selected in feature state.
- [x] Core auth remains installed/available without either specialist feature.
- [ ] Complete dependency-aware readiness for selected auth drivers/features:
  - [x] OTP package readiness is required only when OTP MFA is selected;
  - [x] passkey package readiness requires OTP's passkey integration plus WebAuthn;
  - [ ] database/security/communication dependency readiness is evaluated by the Batch 5
    dependency engine (declarations are already catalog-owned).
- [x] Auth OTP/passkey feature metadata targets Foundation's core `cache` capability directly and
  never creates a cache module edge.
- [x] Shared package ownership conservatively prevents feature removal from deleting OTP while
  another auth feature may still own it.
- [x] Add install/show/remove/state tests for core auth, OTP, passkey and combined feature requests.

## Acceptance

An application can use Foundation core auth without installing OTP or WebAuthn, can install only
the auth feature it needs, and passkey package ownership matches the actual OTP integration.

---

# 8. P0 — Resolve Communication vs Notifications Ownership

## Problem

Current public module behavior aliases `notifications` to `communication` and communication
publishes both `communication.php` and `notifications.php`.

Current runtime behavior is different:

- `communication` and `notifications` are distinct explicit capabilities/providers;
- `NotificationServiceProvider` is Foundation-native;
- base notification dispatch/templates/channels do not require TalkingBytes;
- TalkingBytes email services are registered conditionally when available.

The module vocabulary must match that runtime boundary.

## Target decision

Keep exactly seven specialist module namespaces.

- `communication` is the TalkingBytes-backed specialist module.
- `notifications` remains a Foundation-native capability, not an eighth module.
- `communication.php` belongs to the communication module.
- `notifications.php` belongs to the Foundation application/core capability configuration.
- TalkingBytes-backed email is an optional integration consumed by notifications when present.

## Work

- [x] Remove `notifications` as an alias that broadens to the communication module.
- [x] Stop publishing `notifications.php` as a side effect of communication installation.
- [x] Keep `communication` ownership focused on TalkingBytes protocol profiles:
  - HTTP;
  - webhook;
  - gRPC.
- [x] Keep notifications usable without TalkingBytes email support.
- [x] Report TalkingBytes email availability as an optional notifications integration rather
  than a notifications module installation state.
- [x] Preserve migration aliases only where semantics remain clear.
- [x] Add explicit topology tests proving:
  - communication can be active while notifications is disabled;
  - notifications can be active while communication is disabled;
  - notifications can operate without TalkingBytes mail support;
  - TalkingBytes email integration appears when the package is available.

## Acceptance

The seven-module specialist vocabulary remains stable, notifications stays Foundation-native,
and public module/config ownership matches runtime capability behavior.

**Batch 4 implementation status:** COMPLETE. Tracker remains **PARTIAL** until the exact-head
PHPForge matrix validates the ownership and optional-integration boundary.

---

# 9. P0 — Conditional Module Dependencies

## Problem

Dependencies depend on selected configuration/features.

Examples:

- messaging durable mode -> database;
- auth database storage -> database;
- auth shared cache/replay state -> Foundation core cache capability when the selected feature
  actually consumes CacheLayer services;
- auth security drivers -> security;
- auth TalkingBytes notifications -> communication/notifications;
- ReqShield database rules -> database integration only when selected.

Core cache adapter/database relationships are outside the module graph and belong to Foundation's
cache capability lifecycle.

## Work

- [x] Add conditional dependency declarations to module definitions.
- [x] Evaluate conditions against normalized Foundation config.
- [x] Report active and inactive dependency edges separately.
- [x] Do not auto-enable conditional dependencies silently.
- [x] Distinguish specialist-module edges from Foundation-core capability requirements. A selected
  feature may require the `cache` capability, but no `cache` module node may exist.
- [x] `module:show` must explain why a dependency/capability is required.
- [x] `module:install` should be able to plan required dependency installs.
- [x] If dependencies are not installed/enabled, provide deterministic actionable output.
- [x] Prevent dependency cycles in catalog definitions.
- [x] Add catalog graph validation tests.

## Acceptance

Foundation can answer:

```text
Why does messaging require database here?
Why does auth require the core cache capability in this app?
Why is database not required in another app?
```

without hard-coded command-specific logic.

**Batch 5 implementation status:** COMPLETE. Tracker remains **PARTIAL** until exact-head QA.

---

# 10. P0 — Make Installation and Activation Explicit

## Problem

Installing packages/config and enabling runtime capability are currently separate concepts but
the CLI does not communicate this strongly enough.

## Work

- [x] Keep installation separate from runtime activation.
- [x] Add an explicit activation mechanism.
- [ ] Preferred CLI direction:
  - `module:install <module>`
  - `module:enable <module>`
  - `module:disable <module>`
- [ ] Optionally allow `module:install <module> --enable`.
- [x] Do not silently rewrite explicit `app.capabilities` unless the user explicitly requests
  activation.
- [ ] Define behavior when capability topology is omitted:
  - compatibility auto-discovery remains available where Foundation currently permits it;
  - CLI must clearly report that activation is inferred, not explicit;
  - production-readiness policy must explicitly decide whether missing `app.capabilities` is
    acceptable instead of assuming "cold until selected" semantics.
- [x] Enabling must validate required/active conditional dependencies.
- [x] Disabling must not uninstall packages or delete config/data.
- [x] Capability mutation must use a deterministic application-owned source of truth; do not
  edit compiled release artifacts.

## Acceptance

Users can tell whether a module is installed versus enabled, and no command conflates the two.

**Batch 6 implementation status:** COMPLETE. Tracker remains **PARTIAL** until exact-head QA.

---

# 11. P0 — Safe Removal with Dependency Awareness

## Problem

Current removal intentionally preserves config and schemas, which is correct, but a direct
package can still be removed while:

- its capability is enabled;
- another enabled module/feature depends on it;
- active configuration still selects it.

## Work

- [ ] Before removal, inspect:
  - explicit capability activation;
  - active conditional dependency graph;
  - feature package ownership;
  - root Composer ownership.
- [ ] Refuse destructive package removal when the module is still enabled.
- [ ] Refuse removal when another active module/feature requires it.
- [ ] Report every blocker.
- [ ] Support explicit `--disable` or a deliberate force workflow if required.
- [ ] Never delete application config, schemas or data as part of normal package removal.
- [ ] Do not remove a package that is not a direct application requirement.
- [ ] Handle shared package ownership correctly.

## Acceptance

`module:remove` cannot leave the explicit production topology knowingly broken.

---

# 12. P0 — Capability-Aware Schema Applicability

## Problem

Schema applicability currently follows feature config but can still be influenced by stale config
for disabled capabilities.

## Work

- [x] Schema applicability must require:
  1. owning capability enabled, or explicit schema-management override;
  2. feature/config condition active;
  3. required dependency available;
  4. database connection available where applicable.
- [x] `module:schema:status` must still be observational and able to explain non-applicability.
- [x] `module:schema:install` may explicitly manage an inactive module only when the user
  deliberately targets it; document this behavior.
- [x] `module:schema:sync` must follow the active application topology only.
- [x] Normal `module:install <module>` must not trigger an unrelated aggregate schema sync.
  Provision only the targeted module and explicitly planned dependencies/features; keep
  `module:schema:sync` as the deliberate aggregate operation.
- [x] Keep schema ownership with the current owning component/library.
- [x] Preserve no-drop/no-data-destruction guarantees.

## Acceptance

Stale config from a disabled module does not cause aggregate schema sync to create infrastructure
the runtime does not use.

**Batch 7 implementation status:** COMPLETE. Tracker remains **PARTIAL** until exact-head QA.

---

# 13. P1 — Readiness Model

## Goal

Module status should answer whether the application can actually use the module.

## `module:show` should expose

- canonical name;
- aliases;
- description;
- package requirements;
- direct package ownership;
- transitive package availability;
- selected features;
- enabled state;
- effective configuration validity;
- config publication state separately;
- activation explicit/inferred state;
- active dependencies and reasons;
- platform/extension requirements;
- optional integrations;
- schema applicability/readiness;
- final readiness;
- blockers/warnings.

## Suggested high-level states

Do not force everything into one enum if independent flags are clearer.

CLI may summarize as:

```text
available
installed
enabled
ready
blocked
```

while JSON retains the complete structured state.

## Acceptance

No module is called simply "installed" when the only evidence is that its package exists
transitively.

---

# 14. P1 — Platform and Extension Readiness

## Work

Model platform requirements relevant to selected features.

Examples:

### messaging

- Omnibus currently requires `ext-pcntl`;
- Omnibus currently requires `ext-posix`.

### communication

Base TalkingBytes requires:

- curl;
- fileinfo;
- openssl.

Feature availability may additionally depend on:

- ext-grpc / grpc/grpc;
- mbstring/iconv;
- imap;
- sodium;
- posix.

### filesystem

Feature availability may depend on:

- POSIX;
- SimpleXML/XMLReader;
- Zip;
- Flysystem adapter packages.

### database

Active driver requires the corresponding PDO extension.

### security

Epicrypt requires OpenSSL, Sodium and its declared crypto dependencies.

## Rules

- [ ] Do not duplicate Composer's complete platform resolver.
- [ ] Report Foundation-relevant runtime readiness for selected features.
- [ ] Required base extension failures should be hard blockers.
- [ ] Optional feature requirements should only block the selected feature.
- [ ] Keep actionable messages.

---

# 15. P1 — Installation Planning and Explainability

## Goal

Before mutation, users should be able to see what Foundation intends to change.

## Preferred CLI

```bash
php infbyte module:plan auth --feature=otp
php infbyte module:plan messaging
```

or equivalent structured output from `module:install --dry-run`.

## Plan output should include

- root Composer packages to add;
- feature packages to add;
- required module dependencies;
- config templates to publish;
- capability activation change if requested;
- applicable schemas;
- platform blockers;
- packages already available transitively;
- packages already directly owned;
- warnings about application-owned config/data preserved on removal.

## Acceptance

The normal installation workflow is explainable before Composer or filesystem mutation occurs.

---

# 16. P1 — Repair / Resume Partial Installations

## Problem

Composer mutation, config publication, runtime invalidation and schema provisioning cannot be one
real transaction.

## Direction

Do not attempt unsafe automatic Composer rollback.

Instead:

- [ ] make each lifecycle phase idempotent;
- [ ] report completed/failed phases;
- [ ] add a repair/resume path;
- [ ] preferred command: `module:repair <module>`;
- [ ] re-run missing config publication safely;
- [ ] re-run runtime invalidation safely;
- [ ] re-run applicable schema installation safely;
- [ ] never overwrite application-owned config unless explicitly forced;
- [ ] never destroy data during repair.

## Acceptance

A Composer-success/config-failure or config-success/schema-failure state is recoverable without
manual surgery.

---

# 17. P1 — Composer Mutation Policy

## Problem

Current install/remove Composer execution uses `--update-no-dev`.

That can unexpectedly alter the application's dev dependency installation state.

## Work

- [ ] Re-evaluate unconditional `--update-no-dev`.
- [ ] Preserve normal Composer application behavior by default.
- [ ] If deployment wants no-dev behavior, make it explicit.
- [ ] Keep `--with-all-dependencies` where package graph reconciliation requires it.
- [ ] Keep dry-run fully non-mutating.
- [ ] Test projects with and without dev packages installed.
- [ ] Ensure command execution remains non-interactive and deterministic.

---

# 18. P1 — Config Ownership Cleanup

## Work

- [ ] Each published config file must have one clear owner.
- [ ] `communication.php` is owned by the communication specialist module.
- [ ] `notifications.php` is Foundation-native application config and must not be owned or
  published by the communication module.
- [ ] Feature-specific config must not be published merely because another feature in the module
  exists.
- [ ] Effective `configured` state must come from resolved config/validation, not publication
  alone.
- [ ] Existing application-owned config remains untouched unless `--force`.
- [ ] Preserve transactional staging/backup behavior in `ModuleConfigPublisher`.
- [ ] Preserve symbolic-link refusal on forced publication.
- [ ] Keep config publication idempotent.

---

# 19. P1 — Package Constraint Drift Guard

## Problem

The module catalog has already drifted behind Foundation's tested specialist package versions
once.

## Work

- [x] Add one authoritative test of supported specialist-module package floors.
- [x] Compare catalog package floors with Foundation's tested `require-dev` dependency set.
- [x] Require deliberate floor exceptions to be expressed through catalog/test changes rather
  than silent drift.
- [ ] Cover:
  - [x] OTP 6.1;
  - [x] DBLayer 5.1;
  - [x] Pathwise 4.1;
  - [x] Omnibus 2.6;
  - [x] ReqShield 3.2;
  - [x] TalkingBytes 2.1;
  - [x] Epicrypt 3.1;
  - [x] WebAuthn library floor.
- [ ] Keep docs generated/verified against catalog values where practical; final public-doc
  synchronization remains a Batch 10 release task.
- [x] Guard CacheLayer ^3.4 separately as a Foundation core dependency, not a ModuleCatalog floor.

---

# 20. P1 — Alias and Feature Semantics

## Work

Review every alias and decide whether it names:

- a module;
- a module feature;
- a package compatibility alias.

Current high-risk auth aliases:

- `otp`;
- `mfa`;
- `passkey`;
- `passkeys`;
- `webauthn`.

These must not silently mean "install the entire auth bundle."

Also review:

- remove `notifications` -> communication because notifications is a distinct
  Foundation-native capability;
- `queue` / `events` -> messaging;
- `db` / `dblayer` -> database;
- `crypto` / `epicrypt` -> security;
- `files` / `storage` / `pathwise` -> filesystem;
- `reqshield` / `validator` -> validation.

Package-name resolution must not broaden feature requests after the catalog gains package roles.

## Acceptance

Aliases never broaden requested functionality unexpectedly.

---

# 21. P1 — Machine-Readable Module Contract

The CLI JSON payload should remain stable enough for tooling.

Define/document a versioned module status structure, for example:

```text
schema_version
name
requested
aliases
description

packages
  direct
  transitive
  missing
  incompatible
  ownership_unknown

features
enabled
activation_explicit
configured
config_published

dependencies
  modules
  core_capabilities
platform

config
schemas

ready
blockers
warnings
```

Do not expose internal class names as the primary public contract unless necessary.

Changing the contract shape after release requires an intentional `schema_version` change rather
than accidental key drift.

---

# 22. Module-Specific Review Checklist

## auth

- [ ] core auth remains Foundation-native;
- [ ] auth is represented as a virtual/core-backed module namespace;
- [ ] OTP feature independent;
- [ ] passkey feature requires OTP + WebAuthn without selecting OTP/TOTP MFA implicitly;
- [ ] database/security/notifications relationships are conditional;
- [ ] shared-cache behavior targets the Foundation core cache capability;
- [ ] aliases map to features correctly;
- [ ] shared package ownership makes feature removal safe.

## communication

- [ ] communication remains the TalkingBytes-backed specialist module;
- [ ] notifications remains Foundation-native and is not a module alias;
- [ ] `communication.php` / `notifications.php` ownership is separated;
- [ ] TalkingBytes package ownership correct;
- [ ] optional gRPC/email/platform features reported without becoming unconditional dependencies;
- [ ] webhook replay may consume Foundation's core cache capability without creating a cache
  module dependency.

## database

- [ ] DBLayer direct ownership reported;
- [ ] selected PDO driver platform readiness reported;
- [ ] database remains infrastructure rather than schema owner for application domains.

## filesystem

- [ ] Pathwise direct ownership reported;
- [ ] adapter packages/extensions represented as feature/platform availability;
- [ ] no cloud/adapter package auto-install unless explicitly selected.

## messaging

- [ ] Omnibus direct ownership reported;
- [ ] durable mode -> database dependency;
- [ ] core CacheLayer coordination is consumed directly where selected, without a cache module
  dependency;
- [ ] PCNTL/POSIX readiness reported;
- [ ] Runwire remains optional backend rather than forced Foundation module dependency.

## security

- [ ] Epicrypt direct ownership reported;
- [ ] security module remains optional for lean app install;
- [ ] auth security-driver dependency conditional;
- [ ] no Pathwise requirement introduced merely because Epicrypt tests/integrations use it.

## validation

- [ ] ReqShield direct ownership reported;
- [ ] DBLayer remains optional until database rules are selected;
- [ ] database-rule readiness reported without making all validation require DBLayer.

---

# 23. Execution Batches

## Batch 0 — Cache/core boundary closure

- fix the `CacheSchemaManager::resourceStatus()` PDO-ready regression;
- add database-backed cache schema regression coverage;
- verify cache has no module catalog/alias/install/remove/repair/schema ownership path;
- verify dedicated `cache:schema:*` lifecycle remains the cache schema CLI path;
- close the cache boundary tracker.

## Batch 1 — Module state foundation

- dedicated read-only module state resolver;
- direct/transitive/ownership-unknown package ownership;
- effective configured state vs config publication state;
- explicit vs inferred activation state;
- richer readiness model;
- module status JSON;
- constraint drift guard.

## Batch 2 — Catalog model

- [x] required/feature/optional package roles;
- [x] shared feature package ownership;
- [x] feature declarations;
- [x] conditional dependency declaration model;
- [x] graph/alias/package-role validation;
- [x] platform requirement representation;
- [x] expose optional integration metadata separately through module status;
- [ ] PHPForge matrix closure.

## Batch 3 — Auth decomposition

- [x] core-backed auth namespace;
- [x] OTP/passkey features;
- [x] corrected passkey package topology;
- [x] narrow auth feature aliases;
- [x] conditional auth dependency declarations;
- [x] feature-aware install/show/remove behavior;
- [x] shared OTP package preservation on feature removal;
- [ ] dependency-engine evaluation and PHPForge matrix closure.

## Batch 4 — Communication/notifications ownership

- keep communication as the TalkingBytes specialist module;
- keep notifications as a Foundation-native capability;
- remove notifications-to-communication alias broadening;
- split config ownership;
- model optional TalkingBytes email integration;
- topology tests.

## Batch 5 — Dependency engine

- conditional dependency evaluation;
- module vs Foundation-core capability edges;
- plan/explain output;
- blockers;
- dependency-aware enable/disable/removal.

## Batch 6 — Activation lifecycle

- install vs enable;
- disable;
- explicit capability topology mutation;
- compatibility/inferred-mode reporting;
- production-readiness policy for omitted capability topology.

## Batch 7 — Schema lifecycle

- capability-aware applicability;
- active-topology aggregate schema sync;
- targeted module-install schema provisioning;
- observational status;
- inactive targeted install semantics.

## Batch 8 — Install/remove/repair hardening

- Composer mutation policy;
- partial-install repair;
- safe removal;
- shared package ownership;
- config/data preservation.

## Batch 9 — Platform readiness

- PHP extensions;
- selected adapters/features;
- module doctor/readiness output.

## Batch 10 — Documentation and release acceptance

- synchronize InfByte consumer defaults, including default `config/cache.php`, after Foundation
  release/handoff;
- module docs;
- migration notes;
- CLI JSON contract;
- tracker closure;
- exact-head PHP 8.4/8.5 lowest/stable QA;
- clean install;
- static analysis;
- release benchmarks where affected;
- final release acceptance.

---

# 24. Explicitly Out of Scope

For this pass do not redesign Foundation's built-in/core capability set. Cache is explicitly
outside the module system after CacheLayer promotion.

Also out of scope:

- Composer plugin-based module discovery;
- third-party arbitrary Foundation module marketplaces;
- deleting application schemas/data during module removal;
- automatically enabling every installed package;
- converting ArrayKit, InterMix, UID or Webrick into modules;
- reimplementing specialist-library mechanics inside Foundation;
- automatically installing every optional adapter/integration package;
- silently mutating production compiled release artifacts.

---

# 25. Completion Gate

The module-system pass is complete only when:

- [ ] specialist-package presence no longer equals module installation;
- [ ] direct/transitive/ownership-unknown package state is reported correctly;
- [ ] required/feature/optional package roles are modeled;
- [ ] auth no longer installs OTP + WebAuthn unconditionally;
- [ ] passkey ownership correctly requires OTP + WebAuthn without implying OTP/TOTP MFA selection;
- [ ] communication remains the specialist module while notifications remains Foundation-native
  and independently activatable;
- [ ] conditional specialist-module and Foundation-core capability dependencies are explicit and
  explainable;
- [ ] no cache/cachelayer module entry, alias, install/remove path, status entry or schema
  ownership remains in the module subsystem;
- [ ] cache core schema lifecycle regression coverage is green;
- [ ] install and enable are separate lifecycle concepts;
- [ ] removal is dependency-aware and preserves application config/data;
- [ ] aggregate schema sync follows active capability topology;
- [ ] normal module installation does not provision unrelated module schemas;
- [ ] module status separates effective config from publication state;
- [ ] module status includes dependency/platform/schema readiness and explicit/inferred activation;
- [ ] partial installations have an idempotent repair path;
- [ ] Composer mutation no longer forces inappropriate no-dev behavior;
- [ ] module package floors are guarded from version drift;
- [ ] aliases do not unexpectedly broaden requested features;
- [ ] all seven specialist module namespaces have module-specific acceptance coverage;
- [ ] tracker shows Batches 0-10 **DONE**;
- [ ] exact-head PHPForge matrix is green.

---

# 26. Immediate Starting Point

Start with **Batch 0 — Cache/core boundary closure**, then proceed directly to
**Batch 1 — Module state foundation**.

Do not change auth/communication CLI semantics before the state model is stable.

Immediate implementation sequence:

1. fix the `CacheSchemaManager::resourceStatus()` PDO-ready regression;
2. add cache schema regression coverage and close Batch 0 verification;
3. introduce the read-only module state resolver around the already-existing root
   `composer.json` direct-require detection;
4. distinguish direct/transitive/ownership-unknown package state while excluding Foundation core
   dependencies such as CacheLayer;
5. expose enabled + activation-explicit + configured + config-published + ready as separate state;
6. update `module:list` and `module:show` JSON/tests;
7. add the package-floor drift guard.

Only after Batch 1 is stable should the catalog be expanded with feature/dependency semantics.

The top-level Progress Tracker is the authoritative batch-status summary for this plan.
