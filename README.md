# Foundation

`Foundation` is the Infocyph application integration layer.

It does not replace the standalone packages. It gives a host project one place to bootstrap config, providers, routing, auth, cache, database, validation, filesystem paths, and runtime wiring.

## Install

```bash
composer require infocyph/foundation
```

Foundation installs the Infocyph integration packages it needs, including
TalkingBytes for notification delivery. Host applications configure the
drivers they use; they do not need to wire each integration manually.

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
    cache.php
    database.php
  database/
  public/
    index.php
  resources/
  routes/
    web.php
    api.php
    auth.php
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
4. `config/cache.php`
5. `config/database.php`
6. `bootstrap/providers.php`
7. `routes/*.php`

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

## Integrated Capabilities

Foundation remains an integration layer: the standalone packages own their
domain behavior while Foundation supplies application configuration,
container registration, facades, and HTTP-aware composition.

- ArrayKit: environment, array-shape, and data helpers
- CacheLayer: local, database, Redis, Valkey, Memcached, SQLite, and tiered caches
- DBLayer: connections, repositories, telemetry, and auth-schema installation
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
