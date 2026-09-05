# Foundation

`infocyph/foundation` is the performance-first application composition layer for
the Infocyph PHP ecosystem. It owns application bootstrap, explicit runtime
selection, provider graph composition, and application-level policy while
specialist libraries retain their domain engines and public APIs.

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

Foundation never infers the runtime from `PHP_SAPI`. Non-web commands, worker
units, and scheduled invocations use stable semantic InterMix scopes. Web request
and scope ownership belongs to Webrick's compiled execution plan, so a minimal
route can remain Request-free and scope-free.

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

Providers contribute deterministic graph definitions before production
compilation; production boot does not reopen the mutable development container.

See [Architecture and lifecycle](docs/architecture.md),
[Configuration](docs/configuration.md), and the
[Foundation 3 migration guide](docs/foundation-3-migration.md) for the complete
runtime contract.

## Capabilities

Foundation modules describe application capabilities rather than package names.
Optional packages remain inactive until the application selects the corresponding
capability. Production release compilation uses an explicit capability topology;
installed CacheLayer, DBLayer, Omnibus, TalkingBytes, Epicrypt, Pathwise,
ReqShield, OTP, or WebAuthn packages do not activate themselves merely because
they are installed.

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

Production deployment publishes one immutable Foundation release generation.
The generation owns the normalized config snapshot, compiled Webrick web bundle,
compiled InterMix CLI/worker/scheduler containers, worker topology, and trust
metadata. Production release loading does not fall back to project route,
provider, or config discovery when a generated artifact is missing or invalid.

Run `php infbyte list` for the active command catalog. Generated artifacts are
deployment-owned and should not be committed. Use `php infbyte optimize:clear`
for explicit build-plane cleanup.

Operational behavior and safety constraints are documented in
[CLI, schedules, and workers](docs/console.md),
[Messaging](docs/messaging.md), and [Operations](docs/operations.md).

## Documentation

The [documentation index](docs/README.md) links the focused guides for HTTP,
authentication, sessions, database, storage, communication, JSON resources,
logging, testing, security, and Foundation 3 migration. Configuration templates
under `resources/config/` are the canonical key-by-key reference.

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
