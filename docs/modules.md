# Modules

Foundation modules represent application **capabilities/purposes**, not Composer
packages. A module may be built into Foundation or backed by one or more
specialist packages.

```bash
php infbyte module:list
php infbyte module:show database
php infbyte module:install database
php infbyte module:remove database
```

Package aliases remain accepted for convenience, but canonical documentation
uses the purpose name.

## Canonical modules

| Module | Backing packages |
| --- | --- |
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built into Foundation |
| `messaging` | `infocyph/omnibus ^2.4` |
| `operations` | built into Foundation |
| `resources` | built into Foundation |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built into Foundation |
| `validation` | `infocyph/reqshield ^3.0` |

Important aliases include:

- `db|dblayer` -> `database`
- `crypto|epicrypt` -> `security`
- `otp|mfa|passkey|passkeys|webauthn` -> `auth`
- `notifications|talkingbytes` -> `communication`
- `events|omnibus|queue|queues` -> `messaging`
- `files|pathwise|storage` -> `filesystem`
- `reqshield|validator` -> `validation`
- `ops|runtime` -> `operations`

There are no standalone public OTP or passkey modules. Those packages are
implementations inside the broader `auth` capability.

## Package state

`module:list` distinguishes:

- `built-in` — Foundation-owned capability requiring no package installation;
- `installed` — every package in the module bundle is installed;
- `partial` — only part of a multi-package bundle is installed;
- `available` — none of the optional packages are installed.

Package presence is not the same as runtime activation. Foundation resolves an
optional provider only when configured application behavior requires it.

For multi-package modules, runtime readiness remains implementation-specific.
For example, selecting OTP MFA requires the OTP package but does not require
WebAuthn; selecting WebAuthn passkeys requires the WebAuthn package but does not
require OTP unless another active auth behavior does.

## Installation

```bash
php infbyte module:install cache
php infbyte module:install database
php infbyte module:install communication
```

For a package-backed module, installation performs application orchestration:

1. Composer installs the module's package bundle;
2. Foundation publishes missing module configuration without overwriting host
   configuration;
3. compiled config/container/optimization state is invalidated where required;
4. schema synchronization runs in a fresh PHP process so newly installed
   namespaces are visible;
5. only schemas required by current configuration are provisioned.

Use `--dry-run` to preview Composer changes:

```bash
php infbyte module:install database --dry-run
```

Built-in modules do not trigger Composer.

## Config publication

Publish module-owned config explicitly:

```bash
php infbyte module:config:publish operations
php infbyte module:config:publish messaging
php infbyte module:config:publish cache
```

Existing files are preserved by default. Explicit replacement requires:

```bash
php infbyte module:config:publish cache --force
```

Force publication stages new files and backs up existing regular files before
activation. Failure before commit restores prior files. Backup cleanup after a
successful commit is finalization only and cannot roll back already-published
configuration. Symbolic-link targets are refused for forced replacement.

## Module-owned schemas

Only capabilities that actually own database schema declare schema provisioners:

| Module | Schema owner |
| --- | --- |
| `auth` | Foundation `AuthSchemaInstaller` |
| `cache` | CacheLayer `PdoCacheSchema` / `PdoInvalidationSchema` |
| `session` | Foundation `SessionDatabaseSchema` |

The `database` module owns database/migration infrastructure, not arbitrary
application tables.

Inspect/install one module's schemas:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
php infbyte module:schema:status cache
php infbyte module:schema:install session
```

Synchronize every schema currently required by configuration:

```bash
php infbyte module:schema:sync
```

Schema status is observational. In particular, checking a configured SQLite
cache schema does not create a missing SQLite database file; explicit schema
installation owns that mutation.

## Removal

```bash
php infbyte module:remove database
php infbyte module:remove database --dry-run
```

Removal only removes package requirements that are direct application
requirements. It never drops module schemas or application data, and Foundation
does not silently remove published application configuration.

## No package discovery protocol

Foundation's public module catalog is curated and purpose-first. It does not
scan installed packages for arbitrary `foundation-module.php` manifests during
normal runtime or optimization. Specialist packages retain their own public
APIs; Foundation adds only the application composition contracts it actually
owns.
