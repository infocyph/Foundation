# Console, schedules, and workers

Foundation uses Console for command parsing, rendering, locks, schedules,
process control, and worker supervision. CLI bootstrap is separate from web
bootstrap and does not construct the router, HTTP kernel, auth, database, cache,
or Omnibus unless the selected command requires one.

Application commands are explicit in `routes/console.php`:

```php
return [
    'reports:daily' => App\Console\Commands\Reports\DailyCommand::class,
];
```

System commands are defined by Foundation and appear under `System`, split into
module headings such as `Database`, `Routing`, `Generators`, `Sessions`, and
`Workers`. User commands are grouped by the namespace before the first colon.
Names cannot override a system command.

## Generated artifacts

The `create:*` commands generate controllers, repositories, commands, jobs,
listeners, events, workers, policies, providers, middleware, services, tests,
and basic PHP types. A repository is generated instead of an Active Record
model because DBLayer is repository-first.

## Compiled metadata

```bash
php infbyte command:cache
php infbyte schedule:cache
php infbyte optimize
```

Compiled command metadata lives directly under `bootstrap/cache/console`.
Schedule and command route files are not loaded when a valid compiled manifest
is available. `optimize:clear` removes all Foundation-built artifacts
idempotently.

## Runtime control

`schedule:run` executes the current due set; `schedule:work` remains a bounded
long-running loop. `worker:run` delegates dynamic process supervision to
Console. `queue:consume` delegates delivery to Omnibus. Configure overlap
locks, maximum time, memory, iteration/message limits, scaling bounds, and
shutdown behavior rather than creating an unbounded application loop.

Locks use Console's cache-layer bridge, so the configured CacheLayer store may
be file, Redis/Valkey, Memcached, SQLite/PDO, or another supported backend.
