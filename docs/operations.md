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

Both aggregate commands are safe to repeat. Foundation's integration suite
runs each command twice and verifies that a second optimization preserves every
artifact while a second clear remains a successful no-op.

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
composer ic:release:guard
composer ic:bench:quick
composer benchmark:representative
composer ic:benchmark:validate build/benchmark-result.json
```

The representative benchmark exercises a complete warmed Foundation request
for a minimal JSON route and a route-selected browser session. It validates the
exact status and response body and records successful RPM, latency percentiles,
errors, timeouts, memory, runtime metadata, and repetition spread in PHPForge's
benchmark-result format.

Ordinary machines and hosted CI declare the result environment as
``stable=false``. Such results are useful diagnostics and schema-validated
artifacts, but they must not enforce a release comparison. A controlled runner
may opt in:

```bash
FOUNDATION_BENCHMARK_STABLE=1 \
FOUNDATION_BENCHMARK_FINGERPRINT=foundation-prod-runner-v1 \
composer benchmark:representative
```

Only compare baselines from the same explicit fingerprint, PHP and extension
set, warmed production caches, workload metadata, operation counts, and
repetition settings. The initial successful-RPM regression budget is two
percent, with zero permitted operation errors or timeouts. Application-level
Infbyte comparisons remain the final release gate because they include the
actual skeleton, server, deployment caches, and production runtime.
