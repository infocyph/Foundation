# Configuration

Foundation loads development/build configuration in this order:

1. Foundation defaults;
2. the selected preset;
3. project `config/*.php` files;
4. inline bootstrap values.

`.env` and `.env.local` are loaded before project config unless
`app.load_env=false`.

Production release execution is different: the release compiler normalizes the
resolved configuration once and publishes an authenticated, OPcache-friendly
`config.php` snapshot inside the immutable Foundation generation. Production
release loading consumes that snapshot and does not rediscover `.env`, project
config, providers, or routes.

## Development/build configuration cache

Development/build composition may cache configuration as `single` or `sharded`:

- `sharded` is the default for lean development/build workloads because untouched
  namespaces can remain unloaded;
- `single` may reduce namespace file loads when composition consumes most of the
  configuration graph.

Choose the cache layout by measured build/development workload. This config cache
is an optimization for source composition; it is not the production runtime
container/route activation mechanism.

```bash
php infbyte config:cache
php infbyte config:clear
```

Callable/runtime-object values can be useful in live development configuration,
but any configuration embedded in an immutable production release must be
exportable. Use scalar/array/class-string descriptors for release-owned surfaces.

## Canonical key reference

Every publishable specialist key is documented inline in its Foundation template:

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

## Runtime/container configuration

The live InterMix composition knobs are intentionally small:

- `app.container.environment` — optional explicit InterMix environment override;
- `app.container.lazy_loading` — development/build lazy-resolution policy;
- `app.container.debug_tracing.enabled` and `.level` — InterMix diagnostic tracing.

The following old runtime switches are removed and must not be carried into
Foundation 3 application configuration:

- `app.container.alias`;
- `app.container.compiled`;
- `app.container.compiled_activation`;
- `router.cache`.

Foundation no longer activates an application-selected resolver map or owns a
parallel route-cache path. Production InterMix/Webrick artifact locations and
trust identities are release-generation metadata. A missing, stale, mismatched,
or corrupt production artifact fails release build/load instead of silently
falling back to the mutable/source runtime.

## Capabilities

Production capability topology is explicit. Passing no production capabilities
means a minimal topology, not “discover every installed optional package.”
Installed CacheLayer/DBLayer/etc. packages remain cold until the application
selects the corresponding capability.

Development composition can still use installed-package discovery where an
explicit capability topology is not supplied. This keeps developer ergonomics
separate from production determinism.

## HTTP configuration

Foundation keeps only settings that map to the current Webrick behavior, such as:

- matcher selection;
- route files and attribute-route sources used at build time;
- slash policy;
- URL and signed-URL configuration;
- explicit middleware definitions/groups/global middleware;
- runtime-specific HTTP options owned by Webrick.

Foundation does not duplicate Webrick release-manifest fields in application
configuration. Route registration is a build concern; production uses the
compiled Webrick artifact.

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
