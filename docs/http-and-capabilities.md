# HTTP and optional capabilities

## Routing and middleware

Webrick owns route matching, middleware execution, requests, responses,
emitters, signed URLs, streaming, and range handling. Foundation loads the
explicit files configured under `router.route_files`; it does not discover
controllers or routes.

Compile the selected matcher mode during deployment:

```bash
php infbyte route:cache
php infbyte route:clear
```

The matcher mode and every router key are documented in
`resources/config/router.php`. Middleware is route-selected. In particular,
auth, browser sessions, CSRF, throttling, validation, and cache-backed behavior
must not be placed in a global stack unless every route genuinely needs it.

Foundation does not bundle a template language. Applications that render HTML
bind an implementation to Webrick's view boundary.

## Validation

ReqShield owns schemas, normalization, validation rules, database batching,
typed values, and localized messages. Install it with:

```bash
php infbyte module:install validation
```

Foundation adapts ReqShield failures to the configured HTTP/JsonDispatch
response without reparsing the issue list. See
`resources/config/validation.php` for the published application policy.

## Cache

CacheLayer owns stores, tags, tiers, locks, counters, memoization, invalidation,
and cluster behavior:

```php
$cache = $app->cache()->store();
$value = $cache->remember('reports:today', $loader, 300);
```

`resources/config/cache.php` documents each driver and its effective keys.
Database-backed stores activate DBLayer only when selected. Cache topology and
unsafe cluster uses appear in `app:ready`.

## Files

Pathwise owns filesystem adapters, uploads, downloads, security policy, and
file operations:

```php
$files = $app->files();
$files->write('reports/today.json', $payload, 'local');
```

Install with `php infbyte module:install filesystem`. Configured disks are
mounted only when the filesystem manager is resolved. See
`resources/config/filesystem.php` for disks, paths, and upload/download policy.

## Outbound communication and notifications

TalkingBytes owns HTTP, email, webhook, gRPC, retry, signing, and protocol
fakes. Install it with:

```bash
php infbyte module:install communication
```

Foundation composes configured clients and auth notifications; Omnibus may
queue an application message that eventually calls them, but neither
Foundation nor Omnibus duplicates protocol transports. Canonical settings live
in `resources/config/communication.php` and
`resources/config/notifications.php`.

