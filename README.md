# Foundation

`Foundation` is the Infocyph application integration layer.

It does not replace the standalone packages. It gives a host project one place to bootstrap config, providers, routing, auth, cache, database, validation, filesystem paths, and runtime wiring.

## Install

```bash
composer require infocyph/foundation
```

The core installation contains Foundation, Console, and Webrick. Feature
libraries are direct, optional project dependencies: Foundation does not
install unused database, cache, cryptography, filesystem, validation,
communication, identifier, OTP, or WebAuthn packages.

Install a feature through the Foundation console:

```bash
php infbyte module:install db
php infbyte module:install cache
php infbyte module:list
```

These commands install the existing package directly; no Foundation-prefixed
bridge package is created. A successful install also publishes that module's
documented Foundation config from `vendor/infocyph/foundation/resources/config`
into the host `config/` directory. Existing project config is never
overwritten, and the compiled config cache is invalidated after publication.

| Module | Composer package | Published configuration |
| --- | --- | --- |
| `cache` | `infocyph/cachelayer` | `config/cache.php` |
| `communication` | `infocyph/talkingbytes` | `config/communication.php`, `config/notifications.php` |
| `crypto` | `infocyph/epicrypt` | `config/security.php` |
| `db` | `infocyph/dblayer` | `config/database.php` |
| `filesystem` | `infocyph/pathwise` | `config/filesystem.php` |
| `otp` | `infocyph/otp` | None |
| `passkeys` | `web-auth/webauthn-lib` | None |
| `validation` | `infocyph/reqshield` | `config/validation.php` |

Cryptographic policy and adapter options live under `security.*`; the
configuration filename and keys describe application capabilities rather than
the implementation library. The installed provider supplies its secure
cryptographic profile; Foundation exposes only settings with meaningful
application choices. Authentication remains under `auth.*`, including
`auth.token_secret`, while Webrick route signing remains under
`router.signed_urls.*`. Foundation does not create parallel secrets or URL-
signing configuration.

`infocyph/uid` is already part of the runtime core through Console. Foundation
still activates identifier services only when the application requests them or
selects a UID-backed identifier driver.

The equivalent direct Composer command remains valid, for example
`composer require infocyph/dblayer`; use `module:install` when config should be
published automatically. Remove a direct module with
`php infbyte module:remove db`. Removal deliberately retains project config
because it may contain application-owned changes.

## Expected Project Structure

Your main app should look like this:

```text
project-root/
  app/
  bootstrap/
    providers.php
  config/
    app.php
    auth.php
    ids.php
    router.php
    # Optional module config is published here when installed.
  database/
  public/
    index.php
  resources/
  routes/
    web.php
    api.php
    auth.php
    console.php
    schedule.php
    workers.php
  storage/
    cache/
    logs/
    sessions/
    uploads/
  tests/
  composer.json
```

Foundation already knows these paths by default:

- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `public/`
- `resources/`
- `routes/`
- `storage/`
- `storage/cache/`
- `storage/logs/`
- `storage/sessions/`
- `storage/uploads/`

## Basic Bootstrap

In `public/index.php`:

```php
<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Foundation::local([
    'base_path' => dirname(__DIR__),
]);

// Then hand off to your HTTP entry flow.
```

Use one of these entry modes:

- `Foundation::local([...])`
- `Foundation::production([...])`
- `Foundation::api([...])`
- `Foundation::web([...])`
- `Foundation::console([...])`

Foundation loads `.env` and `.env.local` from the project root before evaluating `config/*.php`, so host apps do not need an external dotenv package just to make `$_ENV` values available.

## Config

Foundation loads configuration in this order:

1. Foundation defaults
2. preset defaults
3. `config/*.php`
4. inline config passed at boot

That means your app config files override the preset, and inline config overrides both.

Environment files are loaded earlier as part of boot preparation:

1. `.env`
2. `.env.local`
3. `config/*.php`
4. inline config passed at boot

You can disable env loading or replace the file list with inline app config:

```php
Foundation::web([
    'base_path' => dirname(__DIR__),
    'app' => [
        'load_env' => true,
        'env_files' => ['.env', '.env.testing'],
    ],
]);
```

Example `config/app.php`:

```php
<?php

return [
    'name' => 'My App',
    'env' => 'local',
    'debug' => true,
];
```

Example `config/auth.php`:

```php
<?php

return [
    'drivers' => [
        'storage' => 'memory',
        'cache' => 'array',
        'passwords' => 'native', // native|security
        'tokens' => 'simple', // simple|security
        'notifications' => 'collect',
        'passkey' => 'memory',
    ],
];
```

## Runtime Modes

Choose the runtime explicitly at the entry point:

```php
$web = Foundation::web(['base_path' => __DIR__]);
$console = Foundation::console(['base_path' => __DIR__]);
```

The mode is not inferred from `PHP_SAPI`, because tests and worker processes can
legitimately execute web behavior under the CLI SAPI.

The web runtime eagerly registers filesystem, routing, and HTTP services and
loads configured route files when booted. The console runtime eagerly registers
only filesystem paths. A command activates its optional providers on demand;
for example, route-cache commands activate routing without registering the HTTP
kernel or loading project routes through the normal web boot sequence.

Calling `http()` or `handle()` on a console application fails immediately.
Console integrations likewise require an application created by
`Foundation::console()`.

Console applications receive an explicit command route map:

```php
$manifestPath = $basePath . '/bootstrap/cache/console/commands.php';
$manifest = is_file($manifestPath) ? $manifestPath : null;
$commands = $manifest === null
    ? require $basePath . '/routes/console.php'
    : [];

$console = FoundationConsole::create(
    applicationFactory: static fn (?string $profile) => Foundation::console([
        'base_path' => $basePath,
        'env' => $profile ?? 'local',
    ]),
    commands: $commands,
    commandManifest: $manifest,
);
```

`routes/console.php` maps command names to command classes. Foundation does not
scan command directories or register application commands implicitly.
When the compiled command manifest exists, the CLI entry point does not load
the project command route file during preflight or dispatch.
Foundation's operational commands, including `config:*`, `route:*`,
`schedule:*`, `worker:*`, `create:*`, `module:*`, `auth:schema:*`, and
`app:ready`, are predefined by Foundation and must not be redeclared in the
application route map.

Foundation keeps its application stubs in the package and writes an artifact
only when its generator is invoked:

```bash
php infbyte create:controller Admin/User
php infbyte create:command Reports/Daily
php infbyte create:service Billing
php infbyte create:job SendReceipt
php infbyte create:middleware EnsureTenant
php infbyte create:policy Invoice
php infbyte create:provider Billing
php infbyte create:repository User
php infbyte create:repository Reporting/Person --table=reporting.people
php infbyte create:worker Queue
php infbyte create:event UserRegistered
php infbyte create:listener SendWelcomeEmail
php infbyte create:enum OrderStatus
php infbyte create:exception BillingFailed
php infbyte create:interface BillingGateway
php infbyte create:trait FormatsMoney
php infbyte create:class Services/ReportBuilder
php infbyte create:test Http/UserAccess
```

Names may use `/` or `\` namespace separators. Conventional suffixes are added
once, so both `User` and `UserController` produce `UserController.php`.
Repositories extend Foundation's thin DBLayer bridge, infer a plural
snake_case table (`UserRepository` becomes `users`), and accept `--table` for
an explicit table or schema-qualified identifier. The DB module must be
installed before repository generation.
Jobs and listeners are plain invokable application classes; Foundation does not
impose a queue backend or event dispatcher on them.
Generators reject absolute paths and traversal, preserve existing files by
default, and accept `--force` for an explicit atomic replacement. Generated
commands, providers, and workers still require explicit registration in
`routes/console.php`, `bootstrap/providers.php`, and `routes/workers.php`
respectively; generators do not silently change application composition.

Scheduled commands are defined only in `routes/schedule.php`, which returns a
`Schedule` or a callable receiving one. `schedule:run`, `schedule:work`,
`schedule:list`, `schedule:cache`, and `schedule:clear` are built in.
`optimize` compiles the schedule when that route file exists, and
`optimize:clear` removes it.

```php
use Infocyph\Console\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule
        ->command('reports:daily')
        ->arguments(['--tenant=acme'])
        ->dailyAt('02:00')
        ->onOneServer(leaseSeconds: 180)
        ->withoutOverlap(leaseSeconds: 180)
        ->timeout(120)
        ->memoryLimit(256);
};
```

Dynamic worker definitions live in `routes/workers.php` as
`name => WorkerProvider::class`. A provider supplies a safe argv command,
`WorkloadProbe`, and `WorkerOptions`; `worker:run <name>` supervises it and
`worker:list` inspects the map without autoloading its providers. Locks are
resolved lazily through CacheLayer only for locked schedule entries, supervised
workers, or command execution policies with overlap controls. File, Redis,
Valkey, Memcached, and PDO (including SQLite's file-backed fallback) use one
common locking path.

## Providers

Register extra app providers in `bootstrap/providers.php`:

```php
<?php

return [
    'common' => [
        App\Providers\SharedServiceProvider::class,
    ],
    'web' => [
        App\Providers\WebServiceProvider::class,
    ],
    'console' => [
        App\Providers\ConsoleServiceProvider::class,
    ],
];
```

Each provider must implement Foundation's `ServiceProviderInterface`.
`common` providers run in both modes; use it only for services genuinely needed
by both paths. Flat provider lists are not accepted: every provider must be
assigned deliberately to `common`, `web`, or `console`.

## Routes

Foundation auto-loads these files when present:

- `routes/web.php`
- `routes/api.php`
- `routes/auth.php`

Inside a route file, use the injected `$router`.

Example `routes/web.php`:

```php
<?php

$router->get('/', fn () => 'Hello from Foundation');
```

## Runtime Directories

Your app should have these writable runtime directories:

- `storage/`
- `storage/cache/`
- `storage/logs/`
- `storage/sessions/`
- `storage/uploads/`

Local preset can auto-create them. Production should create them ahead of time with correct permissions.

## What To Configure First

For a new app, usually start in this order:

1. `base_path`
2. `config/app.php`
3. `config/auth.php`
4. Install required modules so their config is published
5. `bootstrap/providers.php`
6. `routes/*.php`

## Production Notes

- Do not keep memory auth storage in production.
- Do not keep simple token drivers in production.
- Configure real database connections before using `dblayer`.
- Configure real cache stores before using `cachelayer`.
- Configure `notifications.auth.transport` before using the `talkingbytes` notification driver.
- If you use WebAuthn, set `auth.webauthn.rp_id` and `auth.webauthn.origin`.
- Set a unique `auth.token_secret` of at least 32 bytes.
- Install the auth schema before enabling DBLayer-backed authentication:

```php
$app->boot()->db()->authSchema()->install();
```

Use the built-in report as part of deployment health checks. It validates the
production configuration and reports cache, database, auth-schema, and runtime
directory issues without exposing secrets:

```php
$report = $app->boot()->readinessReport();

if (!$report['production_ready']) {
    throw new RuntimeException('Foundation is not ready for production.');
}
```

DBLayer diagnostics remain opt-in. Enable telemetry only where query-shape
aggregation is required, and use native execution plans explicitly:

```php
DB::enableTelemetry();

$plan = DB::explain('SELECT * FROM users WHERE id = ?', [$id]);
$shapes = DB::queryShapeReport(minimumMs: 5.0, limit: 20);
```

`EXPLAIN ANALYZE` executes the query; leave `analyze` false unless runtime
execution is intentional. QueryBuilder also exposes DBLayer's `joinSub()`,
`leftJoinSub()`, `rightJoinSub()`, and `explain()` methods directly.

## Integrated Capabilities

Foundation remains an integration layer: the standalone packages own their
domain behavior while Foundation supplies application configuration,
container registration, facades, and HTTP-aware composition.

- ArrayKit: environment, array-shape, and data helpers
- CacheLayer: local, database, Redis, Valkey, Memcached, SQLite, and tiered caches
- DBLayer: connections, repositories, execution plans, query-shape telemetry, and auth-schema installation
- Epicrypt: production password, token, and encryption-backed auth services
- Intermix: application container, providers, scopes, and invocation
- OTP and WebAuthn: TOTP, HOTP, OCRA, recovery codes, MFA, and passkeys
- Pathwise and Webrick: filesystem operations, uploads, ranged/conditional downloads, HTTP responses, routing, and route caches
- ReqShield: request schemas and database-backed validation rules
- TalkingBytes: email, HTTP, gRPC, signatures, inbound processing, and DKIM helpers
- UID: UUID, ULID, Snowflake, and related identifier generation

## Release Process

Run the release guard before tagging a release:

```bash
composer ic:release:guard
```

Foundation follows semantic versioning. Release tags are the public package
versions; update `CHANGELOG.md`, run the guard in CI, then create the signed
tag and publish from that immutable commit.

See `SECURITY.md` for private vulnerability reporting and `CONTRIBUTING.md`
for the development workflow.

## Quick Start Goal

The intended flow is simple:

1. Require `infocyph/foundation`
2. Create the standard app folder structure
3. Point Foundation at your project root with `base_path`
4. Add config files
5. Add providers
6. Add route files
7. Boot the app from `public/index.php`

That is the main host-project shape Foundation expects.
