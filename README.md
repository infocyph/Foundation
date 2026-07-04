# Foundation

`Foundation` is the Infocyph application integration layer.

It does not replace the standalone packages. It gives a host project one place to bootstrap config, providers, routing, auth, cache, database, validation, filesystem paths, and runtime wiring.

## Install

```bash
composer require infocyph/foundation
```

Optional:

```bash
composer require infocyph/talkingbytes
```

Install `TalkingBytes` only if you want the `talkingbytes` notification driver.

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
- `Foundation::create([...])`

## Config

Foundation loads configuration in this order:

1. Foundation defaults
2. preset defaults
3. `config/*.php`
4. inline config passed at boot

That means your app config files override the preset, and inline config overrides both.

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

## Providers

Register extra app providers in `bootstrap/providers.php`:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

Each provider must implement Foundation's `ServiceProviderInterface`.

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
- If you use `talkingbytes`, install `infocyph/talkingbytes` and configure `notifications.auth.transport`.
- If you use WebAuthn, set `auth.webauthn.rp_id` and `auth.webauthn.origin`.

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
