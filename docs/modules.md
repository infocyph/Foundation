# Foundation 4 optional modules

Foundation 4 has **prebuilt Core**, engineering-only **PHPForge QA**, and exactly **three optional public modules with nine individually selectable features**. No Core or QA component has an optional installation lifecycle.

| Module | Qualified feature | Native package | Published configuration |
| --- | --- | --- | --- |
| data | `data.database` | DBLayer ^6.1.1 | database.php |
| data | `data.filesystem` | Pathwise ^4.2 | filesystem.php |
| security | `security.crypto` | Epicrypt ^3.2 | security.php |
| security | `security.validation` | ReqShield ^3.3 | validation.php |
| security | `security.mfa` | OTP ^7.0 | none |
| security | `security.passkey` | OTP ^7.0, web-auth/webauthn-lib ^5.3.9 | none |
| execution | `execution.runtime` | Runwire ^2.1.1 | none |
| execution | `execution.messaging` | Omnibus ^3.0.1 | messaging.php |
| execution | `execution.communication` | TalkingBytes ^2.3 | communication.php |

Core supplies auth, cache, logging, operations, resources, sessions, notifications, plus the native schema and readiness systems. Seven `core:*` diagnostics report Core availability without granting package installation rights. CacheLayer is a required Core dependency. PHPForge is dev-only; `opis/closure` is dev-only and `psr/log` is Core runtime. Webrick, InterMix and Runwire retain native router, DI and host ownership.

## Inspect and plan (machine schema v2)

```bash
php infbyte module:list
php infbyte module:show data
php infbyte module:doctor security.passkey
php infbyte module:plan data.database
php infbyte module:plan security --feature=mfa
```

`module:list` returns `schema_version: 2` with exactly three `modules` objects containing `name`, `description`, and qualified `features`. `module:show` and `module:doctor` accept a group as a **read-only** view or a qualified feature. `module:plan` requires explicit features and returns a schema-v2 `requested`, `required`, `states`, `effects`, `blockers`, `warnings`, `applicable` plan. A group alone never selects all nine or all its siblings. Missing root Composer ownership is reported and blocks mutations.

## Install, activate, repair and remove

```bash
php infbyte module:install data.filesystem --dry-run
php infbyte module:install data --feature=database
php infbyte module:enable data.database
php infbyte module:disable data.database
php infbyte module:repair data.filesystem
php infbyte module:remove data.filesystem --dry-run
php infbyte module:remove data.filesystem
```

Operations capture a fresh project-root `composer.json`, installed Composer package versions, selected `modules.features`, loaded PHP extensions and existing published configuration. Missing or symlinked root ownership fails closed. `install` reconciles the requested packages, then publishes missing feature-owned config and provisions only applicable specialist schemas; **install never enables a feature**. `enable` and `disable` atomically update `config/modules.php` after dependency validation. Unchanged feature topology does not rewrite config or invalidate caches.

Removing requires a prior disable, preserves packages shared with selected siblings, and never deletes application data, tables or migrations. Composer errors may leave a *partial* install; no unsafe compensation or blind rollback is attempted. Read the response `phases` and `recovery`, recheck root and installed Composer metadata, then run `module:repair` on the same explicit features. Only a real mutation should trigger a compiled-release rebuild.

## Selected feature dependencies

- OTP-backed MFA (`auth.drivers.mfa=otp`) selects `security.mfa` plus the **explicit** `security.crypto` Epicrypt prerequisite; simple MFA does not. WebAuthn `security.passkey` shares an OTP package but never implicitly activates MFA or Epicrypt.
- Durable Omnibus messaging (`messaging.durable.enabled=true`) requires `execution.messaging` and explicit `data.database`. The DBLayer transport, failure store and native schema remain Omnibus/DBLayer-owned. Neither messaging nor TalkingBytes activates Runwire automatically.
- Database-aware ReqShield requires `data.database`. PDO, fileinfo, hash, mbstring and ctype requirements depend on the selected feature. Sodium, PCNTL and POSIX are optional host accelerators and are not universal Core requirements.
- Direct root dependency identity matters: a Composer-transitive package does not become an application-owned optional module.

## Feature-owned config and schema

```bash
php infbyte module:config:publish security.crypto
php infbyte module:config:publish execution --feature=communication
php infbyte module:schema:status execution.messaging
php infbyte module:schema:install execution.messaging
php infbyte module:schema:sync
php infbyte cache:schema:status
```

Config publishing creates only missing feature-owned templates and never force-overwrites application files. The only specialist database schema is the conditional Omnibus **durable messaging** schema. `module:schema:sync` applies only to explicitly enabled optional features. Core auth, session and cache schemas stay Core-owned and cannot be reached through the optional module lifecycle. Durable failures and transactions retain stored messages and cancellation safety.

## Foundation 4 breaking application changes

The former 11-entry public catalog is removed: names such as `auth`, `database`, `filesystem`, `validation`, `messaging`, `communication`, `logging`, `operations`, `resources` and `session` are **not** installable aliases. This is a major-version contract change without an older-major shim. Replace `module:install database` with `module:install data.database`, `module:install auth --feature=otp` with `module:install security.mfa` (and `security.crypto` when OTP is selected), and `module:config:publish messaging` with `module:config:publish execution.messaging`.

Upgrade by declaring current Composer package majors in the application's root manifest, selecting only the required `modules.features` and their conditional dependencies, validating plans/extension constraints, publishing missing owner configuration, and compiling a new immutable release generation. Migrate automated scripts and JSON parsers from v1 to **v2**. See [Configuration](configuration.md) and [Architecture](architecture.md). For the final API/documentation audit and conditional release-approval evidence, see [Batch 7 certification evidence](plans/foundation-4-module-batch7-evidence.md). The CLI contract is QA-accepted, but the architecture rework and Foundation 4 release are **not release-ready** until the controlled-host performance and final sign-off gates pass.
