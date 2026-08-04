# Operations

## Local development server

Start PHP's built-in development server from the application root:

```bash
php infbyte serve
php infbyte serve --host=localhost --port=8080
```

The command serves the configured public directory and defaults to
`127.0.0.1:8000`. Use `--dry-run` to validate and display the resolved endpoint
and document root without starting the process. This server is intended only
for local development; use a production web server and process manager for
deployed applications.

## Runtime inspection

```bash
php infbyte about
php infbyte about --json=true
php infbyte env:show
php infbyte config:show router.matcher
php infbyte route:list
php infbyte route:list --json=true
```

`about` summarizes runtime, package, cache, matcher, and installed-module
information. `env:show` reports the active application environment without
dumping the process environment. `config:show` requires one dot-notation key
and recursively redacts credentials, passwords, private values, secrets,
tokens, cookies, authorization values, and keys. `route:list` loads configured
route files through a temporary CLI registrar and does not construct the HTTP
kernel.

## Application cache

```bash
php infbyte cache:clear
php infbyte cache:clear --store=redis
```

The command resolves CacheLayer only when selected and clears only the named
store. Omitting `--store` selects `cache.default`; a backend rejection is
reported as a failure rather than a successful no-op.

## Application installation

Initialize a newly created application from its project root:

```bash
php infbyte app:install
```

The command creates `.env` from `.env.example`, replaces the example token
secret with 32 random bytes, publishes the file through a mode-`0600` temporary
file and atomic rename, and clears stale compiled configuration. An existing
non-empty secret is preserved. The command is idempotent and is suitable for a
Composer `post-create-project-cmd` hook.

## Secrets and public storage

Generate the Foundation authentication token secret:

```bash
php infbyte secret:generate
php infbyte secret:generate --force
```

The command writes a 32-byte random `AUTH_TOKEN_SECRET` to `.env` through a
mode-`0600` temporary file and atomic rename. It never prints the secret and
refuses to replace an existing value unless `--force` is present. The compiled
configuration cache is cleared before activation so subsequent processes do
not retain the old value.

After installing the filesystem module, create the links declared by
`filesystem.links`:

```bash
php infbyte module:install filesystem
php infbyte storage:link
```

Every link path must remain inside the public directory and every target must
remain inside storage. Correct links are preserved, missing target directories
are created, and conflicting files or links are rejected.

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
artifact while a second clear remains a successful no-op. Route clearing also
removes stale fused, generated, and sharded layouts when the configured matcher
has changed between deployments.

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
