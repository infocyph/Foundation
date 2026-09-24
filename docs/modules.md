# Modules

Foundation's module lifecycle manages **seven specialist capability namespaces**.
Modules are application purposes, not Composer-package discovery aliases.

| Module | Managed package(s) | Module-owned config | Module schema |
| --- | --- | --- | --- |
| `auth` | feature packages: `infocyph/otp ^6.1`, `web-auth/webauthn-lib ^5.3.9` | none | `auth` |
| `communication` | `infocyph/talkingbytes ^2.1` | `communication.php` | none |
| `database` | `infocyph/dblayer ^5.1` | `database.php` | none |
| `filesystem` | `infocyph/pathwise ^4.1` | `filesystem.php` | none |
| `messaging` | `infocyph/omnibus ^2.6` | `messaging.php` | `messaging` |
| `security` | `infocyph/epicrypt ^3.1` | `security.php` | none |
| `validation` | `infocyph/reqshield ^3.2` | `validation.php` | none |

Foundation-native capabilities such as cache, notifications, logging, operations,
responses/resources, and browser sessions are not new specialist package
namespaces. CacheLayer is a direct Foundation dependency and remains completely
outside module install/remove/status ownership. Notifications are Foundation
native; TalkingBytes email support is an optional integration of the native
notification graph, not a `notifications` module alias.

## Built-in catalog entries

`module:list` also includes four Foundation-native entries. They retain their
configuration/schema commands without installing specialist packages:

| Built-in entry | Published config | Schema |
| --- | --- | --- |
| `logging` | `logging.php` | none |
| `operations` | `operations.php` | none |
| `resources` | `responses.php` | none |
| `session` | `session.php` | `session` |

These entries report `built_in: true`; removal is rejected. For example:

```bash
php infbyte module:config:publish session
php infbyte module:schema:status session
php infbyte module:schema:install session
```

Database-backed session schemas require DBLayer and a configured connection.
These built-in commands remain supported in 3.0. The catalog therefore contains
11 entries: seven specialist namespaces and four built-in entries. `auth` is
core-backed with optional features, rather than a `built_in: true` entry.

Cache is not a twelfth entry. Use core `cache:schema:status` and
`cache:schema:install` commands; enable the cache capability explicitly when
needed. Do not run `module:install cache` or `module:remove cache`.

## Inspect and plan

Use the canonical purpose name in automation and documentation:

```bash
php infbyte module:list
php infbyte module:show database
php infbyte module:doctor messaging
php infbyte module:plan auth --feature=otp
```

`module:show` and `module:doctor` expose package ownership, activation,
effective configuration, publication state, active/inactive dependencies,
platform readiness, feature state, schema readiness, blockers, and warnings.

`module:plan` is non-mutating. Its JSON payload is schema-versioned
(`schema_version: 1`) and reports packages to add, packages already direct or
transitive, dependencies, config, schemas, blockers, and warnings.

Module list/show state is also schema-versioned (`schema_version: 1`). Consumers
must tolerate additive fields within that version and should key behavior from
named fields rather than table text.

## Package ownership

Foundation distinguishes:

- **available** — the package exists in the resolved Composer installation;
- **direct** — the application's root `composer.json` owns the requirement;
- **transitive** — available only through another dependency;
- **ownership unknown** — root Composer metadata cannot be read safely.

A transitive package never silently becomes a directly installed Foundation
module. Direct root constraints are also checked against the module catalog's
supported version floor.

Package roles are explicit:

- **required** packages back a specialist module;
- **feature** packages belong only to selected module features;
- **optional** packages are reported integrations and are never added by a base
  module install.

## Auth is core-backed with selectable features

Core authentication stays Foundation native. The `auth` module namespace
orchestrates optional feature packages only.

```bash
php infbyte module:install auth --feature=otp
php infbyte module:install auth --feature=passkey
```

OTP installs only `infocyph/otp`. Passkeys install both the OTP support package
and WebAuthn library because Foundation's passkey implementation consumes both;
this does **not** implicitly select OTP/TOTP MFA in application configuration.

Conditional auth dependencies are evaluated from normalized configuration.
Database-backed auth requires the database module, Epicrypt-backed auth drivers
require the security module, and TalkingBytes auth delivery requires both the
communication module and Foundation's native `notifications` capability. Selected
OTP/passkey state consumes Foundation's core cache capability.

## Install versus enable

Installation and activation are separate operations.

```bash
php infbyte module:plan database
php infbyte module:install database
php infbyte module:enable database
php infbyte module:disable database
```

`module:install`:

1. reconciles only the required/selected feature Composer packages;
2. preserves the application's normal dev-dependency installation state;
3. runs Composer non-interactively with dependency reconciliation;
4. publishes missing module-owned config without overwriting application files;
5. invalidates compiled runtime/config state;
6. provisions only applicable schemas owned by the targeted module.

It does not write activation policy. In development compatibility mode, omitted
topology may still infer available providers; that is reported as inferred
activation rather than explicit activation.

`module:enable` and `module:disable` write an atomic, module-system-owned
`config/modules.php` capability override. These overrides are deliberately
partial: unspecified capabilities retain the existing `app.capabilities` or
development discovery behavior. Enabling validates installation and active
dependencies. Disabling refuses to break an active dependent module and never
uninstalls packages or deletes config/data.

Production release optimization still requires the application's full explicit
`app.capabilities` list/map. `config/modules.php` is not a replacement for
that production topology declaration.

## Conditional dependencies

Dependencies are catalog metadata evaluated against normalized Foundation
configuration. Status output separates active and inactive edges and distinguishes
specialist module dependencies from Foundation-core capability dependencies.

Examples include:

- durable messaging -> database;
- database-aware validation -> database;
- database-backed auth -> database;
- Epicrypt auth drivers -> security;
- TalkingBytes auth notification transport -> communication;
- OTP/passkey feature state -> core cache capability.

Dependencies are never silently auto-enabled. Planning and enablement report
deterministic blockers instead.

## Platform readiness

Foundation reports only platform requirements relevant to its integration
boundary rather than duplicating Composer's platform resolver.

Required PHP extensions are hard blockers for an enabled module. Optional
extensions are diagnostics. Feature-specific platform requirements block only a
selected feature. Database readiness also includes the PDO extension implied by
the configured active driver, such as `pdo_sqlite`, `pdo_mysql`,
`pdo_pgsql`, or `pdo_sqlsrv`.

```bash
php infbyte module:doctor database
```

## Config ownership

`module:config:publish` publishes only config owned by that module.

```bash
php infbyte module:config:publish communication
php infbyte module:config:publish messaging
```

Communication owns `communication.php`; native notifications own
`notifications.php` separately. Existing application files are preserved
unless `--force` is explicitly requested. Forced publication uses staging and
backup/restore behavior and refuses symbolic-link replacement.

Effective `configured` state comes from resolved configuration validity;
`config_published` is separate provenance information.

## Schema lifecycle

Module schema status is observational:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:status messaging
```

A targeted install is an explicit mutation and may manage the named module even
when it is inactive:

```bash
php infbyte module:schema:install messaging
```

Aggregate synchronization follows only the active capability topology and the
current config condition:

```bash
php infbyte module:schema:sync
```

Normal module installation no longer runs an unrelated aggregate schema sync.
It provisions only applicable schemas for the module being installed.

The database module owns database infrastructure, not other domains' tables.
Current module schema owners are Foundation auth, Omnibus durable messaging, and
Foundation browser sessions. CacheLayer schemas remain core cache infrastructure
and use `cache:schema:status` / `cache:schema:install`.

## Repair partial installations

Composer, config publication, runtime invalidation, and schema provisioning
cannot form one transaction. Foundation therefore does not attempt unsafe
automatic Composer rollback.

```bash
php infbyte module:repair database
php infbyte module:repair auth --feature=otp
```

Repair is idempotent by phase: it skips already satisfied Composer ownership,
re-publishes only missing config, invalidates compiled state, and re-runs only
applicable targeted schemas. It never overwrites application-owned config unless
the explicit publication force path is used and never deletes schema/data.

## Safe removal

Disable a whole module before removing its package ownership:

```bash
php infbyte module:disable database
php infbyte module:remove database --dry-run
php infbyte module:remove database
```

Removal is fail-closed. Foundation refuses removal when the module is enabled,
when an active module/feature depends on it, or when a selected feature still
owns the requested package. It removes only direct root requirements, preserves
packages shared by other features, and never deletes application config,
schemas, or data.

## Aliases

Purpose/package aliases remain convenience inputs where they are unambiguous,
for example:

- `db|dblayer` -> `database`;
- `crypto|epicrypt` -> `security`;
- `talkingbytes` -> `communication`;
- `events|omnibus|queue|queues` -> `messaging`;
- `files|pathwise|storage` -> `filesystem`;
- `reqshield|validator` -> `validation`.

Auth aliases `otp|mfa` select the OTP feature and
`passkey|passkeys|webauthn` select the passkey feature. The shared
`infocyph/otp` package requires explicit feature context when its ownership is
otherwise ambiguous.

`notifications` is intentionally **not** a communication/module alias.

## Curated catalog

Foundation does not scan installed packages for arbitrary module manifests.
`ModuleCatalog` is the authoritative curated package/feature/dependency/
platform/config/schema contract, and tests guard its specialist package floors
against Foundation's tested dependency set.
