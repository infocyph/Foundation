# Configuration

Foundation loads values in this order:

1. Foundation defaults;
2. the selected preset;
3. project `config/*.php` files;
4. inline bootstrap values.

`.env` and `.env.local` are loaded before project config unless
`app.load_env=false`. Configuration may be cached as `single` or `sharded`;
sharded caching lazily loads one compiled config group at first access.

Choose the cache layout by measured workload:

- `sharded` is the default for lean routes because untouched namespaces remain
  unloaded;
- `single` can reduce per-namespace file loads when most executions consume much
  of the configuration graph.

There is no universal fastest layout; benchmark representative application
workloads before changing the default.

## Canonical key reference

Every publishable key is documented inline in its Foundation template:

| Configuration | Template | Canonical module |
| --- | --- | --- |
| CacheLayer stores/counters/locks/clusters | `resources/config/cache.php` | `cache` |
| HTTP/webhook/gRPC profiles | `resources/config/communication.php` | `communication` |
| DBLayer connections/migrations/seeders | `resources/config/database.php` | `database` |
| Pathwise disks/upload/download policy | `resources/config/filesystem.php` | `filesystem` |
| PSR-3 logging/exception policy | `resources/config/logging.php` | `logging` |
| Omnibus routes/handlers/middleware/retry/workers | `resources/config/messaging.php` | `messaging` |
| Email and application notifications | `resources/config/notifications.php` | `communication` |
| Runtime operations/history/maintenance | `resources/config/operations.php` | `operations` |
| JsonDispatch response profile | `resources/config/responses.php` | `resources` |
| Epicrypt application security policy | `resources/config/security.php` | `security` |
| Browser sessions/CSRF | `resources/config/session.php` | `session` |
| ReqShield validation | `resources/config/validation.php` | `validation` |

Publish without overwriting host config:

```bash
php infbyte module:config:publish database
php infbyte module:config:publish operations
```

Explicit replacement requires `--force`:

```bash
php infbyte module:config:publish cache --force
```

`module:install <module>` also publishes missing config as part of installation,
but never silently overwrites existing application config.

Infrastructure values remain in their owning config; auth does not duplicate
database/cache/security/communication settings.

## Validation

Run normal configuration validation with:

```bash
php infbyte config:validate
```

Apply production requirements explicitly with:

```bash
php infbyte config:validate --production
php infbyte app:ready
```

Runtime validation covers Foundation-owned structure/ranges and the application
policy needed to compose selected specialist capabilities. Production checks add
security/shared-state requirements such as OTP replay coordination when OTP MFA
is active.

Package presence is not activation. `app:ready` adds exact package/schema checks
for capabilities selected by the resolved configuration.

## Configuration caching

```bash
php infbyte config:cache
php infbyte config:clear
```

Callable/runtime-object values may be useful in live single-process config, but
deployment caches and pooled-worker reconstruction require serializable explicit
class/scalar/array definitions where the corresponding surface is compiled or
forked.

`app.container.compiled` selects the application-owned InterMix resolver artifact
path and defaults to `bootstrap/cache/container.php`.
`app.container.compiled_activation` supports:

- `off` (default) — use normal dynamic/lazy resolution while still allowing
  deployment optimization to build/inspect an artifact;
- `always` — activate the matching compiled resolver artifact at application
  construction.

Choose `always` only after measuring the complete workload. Optimized artifacts
are deployment-owned and should not be committed.

## Environment helpers

Foundation's global config helper surface is intentionally limited to:

```php
env('KEY');
env_bool('KEY', false);
env_int('KEY', 10);
env_string('KEY', 'default');
```

Application paths remain declarative and are not exposed as global helper
functions.
