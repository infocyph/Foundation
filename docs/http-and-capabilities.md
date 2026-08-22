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
and cluster behavior. Foundation owns application cache-store selection and the
few cross-package workflows that genuinely require it.

`resources/config/cache.php` documents each driver and its effective keys.
Database-backed stores activate DBLayer only when selected. Cache topology and
unsafe cluster uses appear in `app:ready`.

## Files

Pathwise owns filesystem adapters, file operations, uploads, downloads,
security policy, archives, indexing and retention. Foundation owns application
disk configuration, transfer policy, paths and the Webrick bridge.

Install with:

```bash
php infbyte module:install filesystem
```

Use native Flysystem/Pathwise operations rather than a Foundation filesystem
facade. See [Filesystem and storage](filesystem.md).

## Communication and notifications

TalkingBytes owns HTTP, email, webhook, gRPC, retry, signing, parsing, protocol
fakes and protocol transport behavior. Install it with:

```bash
php infbyte module:install communication
```

Foundation provides narrow named-profile composers and application integration
only. The default configured HTTP client and email sender are native
TalkingBytes objects, inbound email uses native TalkingBytes receivers and
mailboxes, and configured inbound gRPC handlers resolve through InterMix into a
native TalkingBytes dispatcher.

Canonical settings live in `resources/config/communication.php` and
`resources/config/notifications.php`. See [Communication and email](communication.md)
for the ownership boundary, direct native bindings, persistent-runtime
lifetimes and gRPC/email examples.
