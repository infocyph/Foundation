# Foundation 3 — Specialist Module System Hardening Plan

## Status

**Branch:** `foundation-3/close-26.6`  
**Target:** Foundation 3 module-system hardening before release  
**Plan state:** PLANNED  
**Scope:** later-required specialist modules only

Foundation core/built-in capabilities are outside this module-system pass.

CacheLayer is promoted to a direct Foundation runtime dependency and `cache` is removed from
the public module system entirely. Cache remains a core Foundation capability/configuration
domain, but it is not installable/removable/listed as a module.

The module-system pass covers exactly seven specialist modules:

- `auth`
- `communication`
- `database`
- `filesystem`
- `messaging`
- `security`
- `validation`

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
  "cache module".

This plan must not reintroduce cache through feature aliases, dependency graph nodes, module
status JSON, install/remove planning, or module schema ownership.

---

# 0. CacheLayer Core Integration — COMPLETE

- [X] Promote `infocyph/cachelayer ^3.4` from `require-dev`/suggest to Foundation `require`.
- [X] Remove `cache` and `cachelayer` from `ModuleCatalog`.
- [X] Remove all cache install/remove/config-publication behavior from the module system.
- [X] Move CacheLayer schema orchestration from `Module\Internal` to the core `Cache` subsystem.
- [X] Add `cache:schema:status` and `cache:schema:install`.
- [X] Remove optional-package guards/messages that tell applications to install a cache module.
- [X] Keep runtime activation explicit through the `cache` capability.
- [X] Make `config/cache.php` a default application-skeleton config.

---

# 1. Current Specialist Module Baseline

| Module | Foundation install target | Current config publication | Current schema ownership |
| --- | --- | --- | --- |
| `auth` | `infocyph/otp ^6.1`, `web-auth/webauthn-lib ^5.3.5` | none | `auth` |
| `communication` | `infocyph/talkingbytes ^2.1` | `communication.php`, `notifications.php` | none |
| `database` | `infocyph/dblayer ^5.1` | `database.php` | none |
| `filesystem` | `infocyph/pathwise ^4.1` | `filesystem.php` | none |
| `messaging` | `infocyph/omnibus ^2.6` | `messaging.php` | `messaging` |
| `security` | `infocyph/epicrypt ^3.1` | `security.php` | none |
| `validation` | `infocyph/reqshield ^3.2` | `validation.php` | none |

## Important transitive package relationships

Package presence still remains insufficient as a specialist-module state signal. CacheLayer
dependencies are no longer relevant to module ownership because CacheLayer belongs to
Foundation core.

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

Replace the current overly broad installed/partial/available interpretation with distinct
state dimensions.

Conceptual state:

```text
package_available
package_direct
package_transitive
installed_by_module
enabled
configured
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

### installed_by_module

The module's required root package requirements are present directly and satisfy the module
catalog constraints.

For modules with feature packages, this applies only to the base module and selected features.

### enabled

The canonical capability is selected by the explicit Foundation capability topology.

When capability topology is omitted in development, report that auto-discovery compatibility
mode is active rather than pretending enablement is explicit.

### configured

Required Foundation config exists and selected feature configuration is structurally valid.

### dependencies_satisfied

All unconditional and currently active conditional module dependencies are available/ready.

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

Composer package presence alone still cannot determine whether an application intentionally
owns a specialist module requirement. This distinction must be modeled generically for the
seven specialist modules.

CacheLayer is explicitly excluded from this ownership logic because Foundation owns it as a
core runtime dependency.

## Work

- [ ] Read the application root `composer.json` when determining direct package ownership.
- [ ] Distinguish direct package requirement from transitive package availability.
- [ ] Keep installed-package availability separately visible for diagnostics.
- [ ] Define module installation from direct required package ownership, not only vendor presence.
- [ ] Preserve constraint compatibility checks.
- [ ] Add fixture-driven tests for:
  - [ ] specialist package present only transitively;
  - [ ] specialist package directly required by the application;
  - [ ] package present but constraint incompatible;
  - [ ] direct package missing while a transitive copy remains installed.
- [ ] Explicitly exclude Foundation core dependencies such as CacheLayer from module ownership
  calculations.
- [ ] Ensure `module:list` and `module:show` expose the distinction clearly.

## Acceptance

A transitive package never silently becomes a directly installed Foundation module.

---

# 6. P0 — Required / Optional / Feature Package Roles

## Problem

The current flat package map cannot describe the real package topology.

## Work

- [ ] Split module package declarations conceptually into:
  - required/base packages;
  - feature packages;
  - optional integrations.
- [ ] Keep package constraint ownership centralized in `ModuleCatalog`.
- [ ] Do not install optional packages merely because the module exists.
- [ ] Allow module status to report optional integration availability separately.
- [ ] Add catalog tests that every package role is internally consistent.
- [ ] Add a drift guard against Foundation's supported package floors.

## Acceptance

The module catalog can explain why a package is required and whether it is base, feature or
optional integration state.

---

# 7. P0 — Restructure Auth as Core + Selectable Features

## Problem

Foundation auth is already a native Foundation capability, but:

```text
module:install auth
```

currently installs both:

- OTP;
- WebAuthn library.

That is unnecessarily broad.

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
      web-auth/webauthn-lib ^5.3.5

    database-storage
      database module / DBLayer

    shared-cache
      Foundation core cache capability / CacheLayer

    security
      security module / Epicrypt

    notifications
      communication/notifications capability
```

## Decisions to implement

- [ ] `auth` itself must not automatically install both OTP and WebAuthn.
- [ ] Determine the public CLI shape for feature installation.
- [ ] Preferred direction:
  - `module:install auth --feature=otp`
  - `module:install auth --feature=passkey`
  - repeatable `--feature` if both are wanted.
- [ ] Preserve `otp`, `mfa`, `passkey`, `webauthn` aliases only if they can resolve
  unambiguously to auth features rather than silently install the whole auth bundle.
- [ ] Core auth remains available without either feature package.
- [ ] Readiness follows selected auth drivers:
  - OTP only required for OTP MFA;
  - WebAuthn only required for WebAuthn passkeys.
- [ ] Auth database/security/communication relationships remain conditional module dependencies.
- [ ] Auth shared-cache behavior targets Foundation's core cache capability directly and never
  introduces a core cache capability dependency.
- [ ] Add install/show/remove tests for each feature combination.

## Acceptance

An application can use Foundation core auth without installing OTP or WebAuthn, and can install
only the auth feature it actually needs.

---

# 8. P0 — Resolve Communication vs Notifications Ownership

## Problem

Current public behavior:

- `notifications` aliases to `communication`;
- `communication` publishes both `communication.php` and `notifications.php`.

Current runtime behavior:

- `communication` and `notifications` are distinct explicit capabilities/providers.

These models disagree.

## Work

- [ ] Choose one canonical model and make catalog/runtime/config/docs agree.
- [ ] Preferred direction: keep `communication` and `notifications` as distinct public
  capabilities/modules while both may use TalkingBytes where appropriate.
- [ ] `communication` owns protocol profiles:
  - HTTP;
  - webhook;
  - gRPC.
- [ ] `notifications` owns application outbound/inbound email notification composition.
- [ ] Determine whether notifications needs a direct TalkingBytes root requirement or may rely
  on a shared package requirement model.
- [ ] Stop publishing `notifications.php` as a side effect of unrelated communication install
  if notifications becomes a first-class module.
- [ ] Preserve migration aliases only where semantics remain clear.
- [ ] Add explicit topology tests proving communication can be active without notifications
  and vice versa where supported.

## Acceptance

Public module names, config ownership and runtime capabilities describe the same topology.

---

# 9. P0 — Conditional Module Dependencies

## Problem

Dependencies depend on selected configuration/features.

Examples:

- messaging durable mode -> database;
- auth database storage -> database;
- auth shared cache/replay state -> Foundation core cache capability;
- auth security drivers -> security;
- auth TalkingBytes notifications -> communication/notifications;
- ReqShield database rules -> database integration only when selected.

Core cache adapter/database relationships are outside the module graph and belong to Foundation's
cache capability lifecycle.

## Work

- [ ] Add conditional dependency declarations to module definitions.
- [ ] Evaluate conditions against normalized Foundation config.
- [ ] Report active and inactive dependency edges separately.
- [ ] Do not auto-enable conditional dependencies silently.
- [ ] `module:show` must explain why a dependency is required.
- [ ] `module:install` should be able to plan required dependency installs.
- [ ] If dependencies are not installed/enabled, provide deterministic actionable output.
- [ ] Prevent dependency cycles in catalog definitions.
- [ ] Add catalog graph validation tests.

## Acceptance

Foundation can answer:

```text
Why does messaging require database here?
Why does auth require the core cache capability in this app?
Why is database not required in another app?
```

without hard-coded command-specific logic.

---

# 10. P0 — Make Installation and Activation Explicit

## Problem

Installing packages/config and enabling runtime capability are currently separate concepts but
the CLI does not communicate this strongly enough.

## Work

- [ ] Keep installation separate from runtime activation.
- [ ] Add an explicit activation mechanism.
- [ ] Preferred CLI direction:
  - `module:install <module>`
  - `module:enable <module>`
  - `module:disable <module>`
- [ ] Optionally allow `module:install <module> --enable`.
- [ ] Do not silently rewrite explicit `app.capabilities` unless the user explicitly requests
  activation.
- [ ] Define behavior when capability topology is omitted:
  - development compatibility mode remains available;
  - CLI should clearly report that activation is inferred, not explicit.
- [ ] Enabling must validate required/active conditional dependencies.
- [ ] Disabling must not uninstall packages or delete config/data.
- [ ] Capability mutation must use a deterministic application-owned source of truth; do not
  edit compiled release artifacts.

## Acceptance

Users can tell whether a module is installed versus enabled, and no command conflates the two.

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

- [ ] Schema applicability must require:
  1. owning capability enabled, or explicit schema-management override;
  2. feature/config condition active;
  3. required dependency available;
  4. database connection available where applicable.
- [ ] `module:schema:status` must still be observational and able to explain non-applicability.
- [ ] `module:schema:install` may explicitly manage an inactive module only when the user
  deliberately targets it; document this behavior.
- [ ] `module:schema:sync` must follow the active application topology only.
- [ ] Keep schema ownership with the current owning component/library.
- [ ] Preserve no-drop/no-data-destruction guarantees.

## Acceptance

Stale config from a disabled module does not cause aggregate schema sync to create infrastructure
the runtime does not use.

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
- config publication state;
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

- [ ] Each published config file must have one clear public module owner.
- [ ] Resolve `communication.php` vs `notifications.php` ownership during the
  communication/notifications decision.
- [ ] Feature-specific config should not be published merely because another feature in the
  module exists.
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

- [ ] Add one authoritative test/table of supported module package floors.
- [ ] Compare catalog package floors with Foundation's tested dependency set where applicable.
- [ ] Allow deliberate exceptions only with explicit test/documentation.
- [ ] Cover:
  - OTP 6.1;
  - DBLayer 5.1;
  - Pathwise 4.1;
  - Omnibus 2.6;
  - ReqShield 3.2;
  - TalkingBytes 2.1;
  - Epicrypt 3.1;
  - WebAuthn library floor.
- [ ] Keep docs generated/verified against catalog values where practical.
- [ ] Guard CacheLayer ^3.4 separately as a Foundation core dependency, not a ModuleCatalog floor.

---

# 20. P1 — Alias and Feature Semantics

## Work

Review every alias and decide whether it names:

- a module;
- a module feature;
- a package compatibility alias.

Current high-risk aliases are the auth-related aliases:

- `otp`;
- `mfa`;
- `passkey`;
- `webauthn`.

These should not silently mean "install the entire auth bundle."

Also review:

- `notifications` -> communication;
- `queue` / `events` -> messaging;
- `db` / `dblayer` -> database;
- `crypto` / `epicrypt` -> security;
- `files` / `storage` / `pathwise` -> filesystem;
- `reqshield` / `validator` -> validation.

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

features
enabled
configured

dependencies
platform

config
schemas

ready
blockers
warnings
```

Do not expose internal class names as the primary public contract unless necessary.

---

# 22. Module-Specific Review Checklist

## auth

- [ ] core auth remains Foundation-native;
- [ ] OTP feature independent;
- [ ] passkey feature independent;
- [ ] database/security/notification module dependencies conditional;
- [ ] shared-cache behavior targets the Foundation core cache capability;
- [ ] aliases map to features correctly;
- [ ] feature removal does not remove unrelated auth dependencies.

## communication

- [ ] communication/notifications ownership resolved;
- [ ] TalkingBytes package ownership correct;
- [ ] optional gRPC/email/platform features reported without becoming unconditional dependencies;
- [ ] webhook replay may consume Foundation's core cache capability without creating a module
  dependency.

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

## Batch 1 — Module state foundation

- direct/transitive package ownership;
- richer state model;
- module status JSON;
- constraint drift guard.

## Batch 2 — Catalog model

- package roles;
- feature declarations;
- dependency declarations;
- graph validation;
- platform requirement representation.

## Batch 3 — Auth decomposition

- core vs OTP/passkey features;
- auth aliases;
- conditional auth dependencies;
- install/show/remove behavior.

## Batch 4 — Communication/notifications ownership

- settle public module/capability model;
- config ownership;
- TalkingBytes package ownership;
- topology tests.

## Batch 5 — Dependency engine

- conditional dependency evaluation;
- plan/explain output;
- blockers;
- dependency-aware enable/disable/removal.

## Batch 6 — Activation lifecycle

- install vs enable;
- disable;
- explicit capability topology mutation;
- development compatibility-mode reporting.

## Batch 7 — Schema lifecycle

- capability-aware applicability;
- active-topology schema sync;
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

- module docs;
- migration notes;
- CLI JSON contract;
- exact-head PHP 8.4/8.5 lowest/stable QA;
- clean install;
- static analysis;
- release benchmarks where affected;
- final tracker closure.

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

- [ ] later-library package presence no longer equals module installation;
- [ ] transitive ownership for later-installed packages is reported correctly;
- [ ] required/feature/optional package roles are modeled;
- [ ] auth no longer installs OTP + WebAuthn unconditionally;
- [ ] communication/notifications ownership is internally consistent;
- [ ] conditional specialist-module dependencies are explicit and explainable;
- [ ] no cache/cachelayer module entry, alias, install/remove path, status entry or schema
  ownership remains in the module subsystem;
- [ ] install and enable are separate lifecycle concepts;
- [ ] removal is dependency-aware and preserves application config/data;
- [ ] schema sync follows active capability topology;
- [ ] module status includes configuration/dependency/platform/schema readiness;
- [ ] partial installations have an idempotent repair path;
- [ ] Composer mutation no longer forces inappropriate no-dev behavior;
- [ ] module package floors are guarded from version drift;
- [ ] aliases do not unexpectedly broaden requested features;
- [ ] all seven specialist modules have module-specific acceptance coverage;
- [ ] exact-head PHPForge matrix is green.

---

# 26. Immediate Starting Point

Start with **Batch 1 — Module state foundation**.

Do not change auth/communication CLI semantics before the state model is fixed.

First implementation target:

1. teach the module layer to distinguish application-direct specialist-package requirements
   from transitively available packages while excluding Foundation core dependencies such as
   CacheLayer;
2. expose direct/transitive/enabled/configured/ready as separate state;
3. update `module:list` and `module:show` JSON/tests;
4. add the package-floor drift guard.

Only after this base is stable should the catalog be expanded with feature/dependency semantics.


---

# 27. Cache Removal from Module Subsystem

This is a prerequisite cleanup before Batch 1.

- [ ] Move `infocyph/cachelayer ^3.4` into Foundation `require`.
- [ ] Remove CacheLayer from `require-dev` and `suggest`.
- [ ] Remove canonical `cache` entry from `ModuleCatalog`.
- [ ] Remove `cachelayer` module alias.
- [ ] Remove cache from `module:list`, `module:show`, `module:install`,
  `module:remove`, module planning and module repair.
- [ ] Remove cache from module schema ownership/dispatch.
- [ ] Move any cache schema readiness/install behavior to Foundation's core cache capability
  lifecycle.
- [ ] Keep cache capability activation explicit and cold until selected.
- [ ] Keep `config/cache.php` as default InfByte application config.
- [ ] Update module tests so cache is not counted as a module.
- [ ] Add a guard proving CacheLayer is a Foundation core dependency and cannot drift back into
  module package ownership.
- [ ] Update docs/migration guidance so "cache module" terminology no longer appears.

## Acceptance

`cache` is absent from the module subsystem while CacheLayer remains always available to
Foundation core and the cache capability remains independently activatable.
