# Configuration

Foundation loads values in this order:

1. framework defaults;
2. the selected preset;
3. project `config/*.php` files;
4. inline bootstrap values.

`.env` and `.env.local` are loaded before project config unless
`app.load_env=false`. Configuration may be cached as `single` or `sharded`;
sharded caching lazily loads one compiled config group at first access.

Choose the cache layout by workload:

- `sharded` is the default for lean HTTP routes because untouched namespaces
  remain unloaded;
- `single` avoids per-namespace file loads when most requests consume much of
  the application configuration.

Both layouts move normalization to deployment. Benchmark representative
minimal, authenticated, session, and database routes before changing the
default; there is no universal fastest layout.

## Canonical key reference

Every publishable key is documented inline in its canonical template:

| Configuration | Template | Published by |
| --- | --- | --- |
| CacheLayer stores, counters, locks, clusters | `resources/config/cache.php` | `module:install cache` |
| HTTP, webhook, gRPC | `resources/config/communication.php` | `module:install communication` |
| DBLayer connections, pool, migrations, seeders | `resources/config/database.php` | `module:install db` |
| Pathwise disks, upload/download policy | `resources/config/filesystem.php` | `module:install filesystem` |
| PSR-3 logging and exception detail | `resources/config/logging.php` | `module:install logging` |
| Omnibus routes, handlers, listeners, retry | `resources/config/messaging.php` | `module:install messaging` |
| Email and auth notifications | `resources/config/notifications.php` | `module:install communication` |
| JsonDispatch response profile | `resources/config/responses.php` | `module:install resources` |
| Epicrypt application security policy | `resources/config/security.php` | `module:install crypto` |
| Browser sessions and CSRF | `resources/config/session.php` | `module:install session` |
| ReqShield validation | `resources/config/validation.php` | `module:install validation` |

Each template states the key type, default, all predefined values, and an
example for open-ended strings, class maps, paths, durations, or identifiers.
Infrastructure values remain in their owning config; auth does not duplicate
database, cache, cryptography, or notification settings.

## Validation

Run:

```bash
php infbyte app:ready --json=true
```

Foundation validates driver names, logging levels and redaction, DB migration
classes and lock bounds, Omnibus maps and retry ranges, JsonDispatch media
tokens, CacheLayer topology, WebAuthn policy, and production auth requirements
before resolving the corresponding runtime graph.

## Caching

```bash
php infbyte config:cache
php infbyte config:clear
```

Callable values are valid only in live configuration. Cached handler, listener,
migration, seeder, provider, command, schedule, and worker definitions must use
class names or serializable scalar/array values.

`app.container.compiled` selects the application-owned resolver artifact path
and defaults to `bootstrap/cache/container.php`. The
`app.container.compiled_activation` values are:

- `off` (default): keep request-bound applications on InterMix's dynamic
  resolver, while still allowing `optimize` to build and validate the artifact;
- `always`: load the matching deployment-prevalidated artifact during web boot.

Use `always` only after measuring the complete boot plus request cost. It is
primarily useful when one application instance handles many requests. A
missing, stale, malformed, or mismatched artifact falls back to the dynamic
resolver; `app:ready` reports artifact readiness separately.
