# Foundation

`infocyph/foundation` is the performance-first application composition layer for
the Infocyph PHP ecosystem. It owns application bootstrap, runtime selection,
provider activation, and application-level policy while specialist libraries
retain their domain engines and public APIs.

## Install

Foundation requires PHP `^8.4`.

```bash
composer require infocyph/foundation
```

The package exposes the `infbyte` Composer binary:

```bash
vendor/bin/infbyte list
vendor/bin/infbyte --version
vendor/bin/infbyte help module:install
```

Applications using the standard root script can run the same commands with
`php infbyte`. Metadata-only list, help, version, and completion commands do not
boot the application.

## Bootstrap

Select one of Foundation's four runtimes explicitly:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
$worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
$scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);
```

Foundation never infers the runtime from `PHP_SAPI`. Each request, command,
worker unit, or scheduled execution receives a fresh InterMix execution scope
and a Foundation execution ID.

Application providers are assigned by runtime in `bootstrap/providers.php`:

```php
return [
    'common' => [],
    'web' => [],
    'cli' => [],
    'worker' => [],
    'scheduler' => [],
];
```

See [Architecture and lifecycle](docs/architecture.md) and
[Configuration](docs/configuration.md) for the complete bootstrap contract.

## Capabilities

Foundation modules describe application capabilities rather than package names.
Optional packages remain inactive until installed and selected by configuration.

| Module | Implementation |
| --- | --- |
| `auth` | Foundation auth with optional OTP and WebAuthn providers |
| `cache` | CacheLayer |
| `communication` | TalkingBytes |
| `database` | DBLayer |
| `filesystem` | Pathwise |
| `logging` | Foundation |
| `messaging` | Omnibus |
| `operations` | Foundation |
| `resources` | Foundation |
| `security` | Epicrypt |
| `session` | Foundation with configured storage |
| `validation` | ReqShield |

Use the module commands to inspect, install, configure, and provision a
capability:

```bash
php infbyte module:list
php infbyte module:show database
php infbyte module:install database
php infbyte module:config:publish messaging
php infbyte module:schema:status auth
```

Foundation composes these libraries without replacing their native query,
cache, filesystem, validation, communication, cryptography, or messaging APIs.
See [Modules](docs/modules.md) for package mappings, aliases, activation rules,
and schema ownership.

## Operate and deploy

The CLI provides explicit commands for the application lifecycle. Common entry
points include:

```bash
php infbyte app:ready
php infbyte migrate --pretend
php infbyte schedule:run
php infbyte worker:list
php infbyte runtime:reload
php infbyte optimize
```

Run `php infbyte list` for the active command catalog. Generated config, route,
command, schedule, and container artifacts are deployment-owned and should not
be committed. Use `php infbyte optimize:clear` to remove them.

Operational behavior and safety constraints are documented in
[CLI, schedules, and workers](docs/console.md),
[Messaging](docs/messaging.md), and [Operations](docs/operations.md).

## Documentation

The [documentation index](docs/README.md) links the focused guides for HTTP,
authentication, sessions, database, storage, communication, JSON resources,
logging, testing, and security. Configuration templates under
`resources/config/` are the canonical key-by-key reference.

## Verification

Run the complete release guard before publishing:

```bash
composer ic:release:guard
```

The [Security & Standards workflow](.github/workflows/security-standards.yml)
adds the PHP 8.4/8.5 lowest/stable matrices, service-backed integration checks,
and a clean production install. Run the representative workload separately when
validating performance-sensitive changes:

```bash
composer benchmark:representative
```

Only compare numeric benchmark results from matching, explicitly stable
environments.
