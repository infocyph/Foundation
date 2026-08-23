# HTTP and optional capabilities

## Routing and middleware

Webrick owns route matching, middleware execution, requests, responses,
emitters, signed URLs, streaming, and range handling. Foundation loads only the
explicit route files configured under `router.files`; it does not discover
controllers or route directories.

Compile/clear route metadata during deployment with:

```bash
php infbyte route:cache
php infbyte route:clear
php infbyte route:list
```

Matcher and router policy are documented in the application/router configuration.
Middleware is route-selected. Auth, browser sessions, CSRF, throttling,
validation, and cache-backed behavior should not be placed into a global stack
unless every route actually needs them.

Foundation does not bundle a template engine. Applications rendering HTML may
compose their own rendering boundary over Webrick responses.

## Validation

ReqShield owns schema compilation, normalization, validation rules, database
batching, typed values, sanitization, and localized messages.

```bash
php infbyte module:install validation
```

Foundation adds application composition through `FormRequest`, configured
validation profiles, and HTTP/JsonDispatch failure mapping. Custom application
rules implement ReqShield's native `Contracts\Rule` directly.

```bash
php infbyte create:request StoreUser
php infbyte create:rule ValidVatNumber
```

See `resources/config/validation.php` for publishable application policy.

## Cache

CacheLayer owns stores, tiers, locks, counters, memoization, invalidation,
node/cluster cache, and backend semantics. Foundation owns application
store/coordination selection and the cross-capability workflows that require
cache state.

```bash
php infbyte module:install cache
```

`resources/config/cache.php` documents supported application descriptors.
SQLite/direct-PDO stores can operate through their own native configuration;
a cache descriptor that explicitly selects a DBLayer connection activates the
`database` capability only when that connection is actually needed.

Foundation does not treat package presence as cache activation. Coordination
uses an explicit configured lock driver when present, otherwise a suitable
native lock from the selected store; there is no unrelated implicit file-lock
fallback.

## Files

Pathwise/Flysystem own generic filesystem operations, uploads/downloads,
security/capability behavior, archives, sync, and retention. Foundation owns
application disk configuration, paths, public-link policy, and Webrick bridges.

```bash
php infbyte module:install filesystem
php infbyte storage:status
```

Use native Flysystem/Pathwise operations rather than a Foundation filesystem
facade. See [Filesystem and storage](filesystem.md).

## Communication and notifications

TalkingBytes owns HTTP, email, webhook, gRPC, retries, signing, parsing, protocol
fakes, and transport behavior.

```bash
php infbyte module:install communication
```

Foundation provides named application profiles and notification/mail
composition only. Native TalkingBytes HTTP clients, email senders/receivers,
mailboxes, webhook services, and gRPC dispatcher/client APIs remain the protocol
boundary.

Canonical publishable settings live in `resources/config/communication.php` and
`resources/config/notifications.php`. See
[Communication and email](communication.md).

## Lazy capability rule

A package being installed is not sufficient to activate it. Web bootstrap stays
lean; providers are activated only when a selected route/middleware or resolved
application service requires the capability.
