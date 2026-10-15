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
[Foundation 4 setup guide](docs/foundation-4-migration.md),
[current 4.0 public/provider/configuration contract](docs/foundation-4-api-map.md), and
[Foundation 4 persistence/recovery guide](docs/foundation-4-retained-data-rehearsal.md). The [Foundation 3 migration guide](docs/foundation-3-migration.md)
remains as historical background, not a prerequisite.

## Runtime hosts

The application modes are independent of the HTTP host. Ordinary PHP/FPM can
use Webrick's SAPI path. Applications selecting Runwire use Webrick's Runwire
bridge; Runwire owns native portable/prefork execution and its FPM, FrankenPHP,
RoadRunner and Swoole/OpenSwoole host adaptation. Foundation supplies the
compiled application and its scoped services.

Runwire is optional and does not activate merely because it is installed. See
[Runtime hosting](docs/runtime-hosting.md) for ownership, selection and the
verification boundary.

## Optional modules

Foundation 4 supplies prebuilt Core plus **three optional modules with nine individually selected features**:

| Module | Qualified features |
| --- | --- |
| data | `data.database` (DBLayer), `data.filesystem` (Pathwise) |
| security | `security.crypto` (Epicrypt), `security.validation` (ReqShield), `security.mfa` (OTP), `security.passkey` (OTP + WebAuthn) |
| execution | `execution.runtime` (Runwire), `execution.messaging` (Omnibus), `execution.communication` (TalkingBytes) |

Core authentication, CacheLayer, logging, operations, resources, sessions and notifications are not installable modules. PHPForge remains development/QA-only. Webrick, InterMix and Runwire retain native routing, container and host ownership.

```bash
php infbyte module:list
php infbyte module:show data
php infbyte module:plan data.database
php infbyte module:install data.database --dry-run
php infbyte module:config:publish execution.messaging
php infbyte module:schema:status execution.messaging
```

Every mutation requires explicit feature selection, never bare-module install-all. Machine contracts use schema v2. See [Modules](docs/modules.md) for current package requirements, Core/QA boundaries, breaking migration instructions and non-destructive repair.

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
metadata. InterMix generated PHP remains the native DI production cache and is
loaded directly through its own manifest/ProductionContainer path; Foundation
does not redirect compiled containers through CacheLayer. Production release
loading does not fall back to project route, provider, or config discovery when
a generated artifact is missing or invalid.

Run `php infbyte list` for the active command catalog. Generated artifacts are
deployment-owned and should not be committed. Use `php infbyte optimize:clear`
for explicit build-plane cleanup.

Operational behavior and safety constraints are documented in
[CLI, schedules, and workers](docs/console.md),
[Messaging](docs/messaging.md), and [Operations](docs/operations.md).

## Documentation

The [documentation index](docs/README.md) links the focused guides for HTTP,
authentication, sessions, database, storage, communication, JSON resources,
logging, testing, security, and the Foundation 4 upgrade path. Configuration templates
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
