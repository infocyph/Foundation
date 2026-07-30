# Operations

## Deployment caches

```bash
php infbyte optimize
php infbyte app:ready --json=true
```

`optimize` builds configuration, route, command, schedule, and third-party
module manifests. The corresponding clear operation is idempotent:

```bash
php infbyte optimize:clear
```

Individual `config:*`, `route:*`, `command:*`, and `schedule:*` commands remain
available. Compiling may spend more time during deployment to remove discovery,
parsing, and normalization from requests.

## Readiness

`app:ready` reports:

- production auth driver policy and auth schema;
- CacheLayer topology and warnings;
- config validation;
- database and registered migration status;
- logging policy;
- Omnibus map counts;
- notification transport;
- compiled module count;
- cache artifact status;
- path writability;
- JsonDispatch profile;
- browser-session driver and database schema.

Pending registered migrations and a selected database-session driver without
its schema make the application not ready.

## Permissions

Run Composer, optimize, clear, tests, and deployment commands as the same
runtime owner whenever practical. The application user needs write access only
to runtime paths such as `bootstrap/cache` and configured storage directories;
Foundation does not require root or recursive ownership changes.

Commands report unwritable targets and leave unrelated cache files intact.
Repair ownership explicitly outside Foundation when a previous privileged
process created root-owned artifacts.

## Workers

HTTP and queue workers may reuse an application. Set bounded limits for time,
memory, message/request count, retry, queue depth, database connections, and
locks. Use Console supervision and Omnibus delivery controls rather than adding
unbounded loops in Foundation.

## Release checks

Before release, run:

```bash
composer ic:ci
composer ic:release:constraints
composer ic:bench:quick
```

Benchmark comparisons must use equivalent validated responses, the same PHP
and extension set, warmed production caches, repeated runs, and explicit
regression budgets.
