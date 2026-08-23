# Operations

Foundation owns application/runtime orchestration. External supervisors, web
servers, database engines, cache engines, and queue transports remain separate
systems rather than being reimplemented by Foundation.

## Local development server

```bash
php infbyte serve
php infbyte serve --host=localhost --port=8080
php infbyte serve --dry-run
```

The built-in server is for local development only. Production deployments
should use a real web server/process manager.

## Runtime and configuration inspection

```bash
php infbyte about
php infbyte env:show
php infbyte config:show router.matcher
php infbyte config:validate
php infbyte config:validate --production
php infbyte route:list
```

`config:validate --production` applies production requirements even when the
current application environment is not production. When OTP MFA is active, the
same production assumption is passed through OTP replay/state-topology checks.

Machine-readable output is available through the global `--json` option.

## Application installation and secret generation

Initialize a newly created application:

```bash
php infbyte app:install
```

Generate/replace application secret material explicitly:

```bash
php infbyte secret:generate
php infbyte secret:generate --force
```

Foundation does not print generated secret material.

## Environment-file protection

Epicrypt-backed environment protection is available when the `security` module
is installed:

```bash
php infbyte module:install security
php infbyte env:encrypt --key-file=/secure/env.key
php infbyte env:decrypt --key-file=/secure/env.key
```

An external environment variable may supply the key instead:

```bash
ENV_ENCRYPTION_KEY='...' php infbyte env:encrypt
```

Use `--key-env=<name>` to select another environment variable. Foundation does
not provide a literal `--key=<secret>` option, avoiding command history/process
list leakage. Input/output writes are staged; `--force` replacement preserves
rollback safety. Symbolic-link destinations are refused.

The protection key must not be stored inside the `.env` file it protects.

## Cache operations

```bash
php infbyte cache:clear
php infbyte cache:clear --store=redis
php infbyte cache:forget application:key
php infbyte cache:forget application:key --store=redis
```

CacheLayer is activated only when the cache capability is actually selected.

## Execution history

Execution history is disabled by default because every transition adds
operational I/O. Configure `operations.history.enabled=true` when required.

```bash
php infbyte execution:list
php infbyte execution:list --kind=schedule --limit=50
php infbyte execution:show <execution-id>
php infbyte execution:clear
php infbyte execution:clear --force
```

Command and scheduler records use one execution ID across their lifecycle.
Scheduled executions also carry a stable `schedule_identity`, so two schedules
that invoke the same command do not share last-run status accidentally.

## Maintenance mode

```bash
php infbyte maintenance:enable
php infbyte maintenance:enable --retry=60 --message='Maintenance in progress'
php infbyte maintenance:status
php infbyte maintenance:disable
```

The default backend is a dependency-free file state. Set
`operations.maintenance.driver=cache` to share maintenance state across a
multi-node deployment. Cache-backed state is validated against the configured
deployment topology.

In the Web runtime, enabled maintenance state produces HTTP 503 and includes
`Retry-After` when configured.

## Persistent-runtime generation control

Foundation uses persistent generation tokens for graceful cooperative shutdown:

```bash
php infbyte runtime:reload
php infbyte worker:restart
php infbyte worker:restart reports
php infbyte schedule:interrupt
```

Foundation does not respawn processes. Supervisor, systemd, Docker, Kubernetes,
or another process manager remains responsible for replacement processes.

File-backed runtime-control mutations use a stable file lock and atomic state
replacement. Cache-backed runtime control uses CacheLayer coordination around a
single read/modify/write operation, preventing concurrent generation updates
from overwriting one another.

For distributed deployments, cache-backed runtime control must use a store and
coordination mechanism with deployment-wide visibility.

## Worker visibility

```bash
php infbyte worker:list
php infbyte worker:status
php infbyte worker:status reports
```

`worker:list` reports configured worker definitions. `worker:status` also shows
heartbeat-visible runtime process records.

The registry is observational metadata, not process-supervision authority:

```php
'operations' => [
    'runtime_registry' => [
        'path' => 'storage/framework/runtime',
        'visibility' => 'host', // host|shared
        'stale_seconds' => 15,
    ],
],
```

- `host` reports records written by the current host only.
- `shared` intentionally aggregates records in a shared registry directory.
- stale heartbeats are reported as not running; Foundation does not use PID
  probing as cross-platform supervision truth.

Omnibus 2.4 single messaging workers use native `WorkerLifecycle` callbacks for
heartbeat and graceful generation checks, so Foundation does not require
`pcntl` simply to observe restart requests. Omnibus `WorkerPool` remains a
Unix/pcntl process-pool feature and retains the corresponding Unix watchdog.

## Scheduler operations

```bash
php infbyte schedule:list
php infbyte schedule:run
php infbyte schedule:test reports:daily
php infbyte schedule:work --sleep=60
php infbyte schedule:interrupt
```

Commands that prevent overlap or run on one server use CacheLayer ownership.
During a long child execution Foundation refreshes the lease through
`ProcessRunner` heartbeat callbacks. If ownership refresh fails, the child is
terminated and the schedule run fails instead of silently continuing without
ownership.

`schedule:test` returns a failing process exit when the scheduled child fails or
when the required ownership lock cannot be obtained.

## File logging

When `logging.driver=file`:

```bash
php infbyte log:tail
php infbyte log:tail --lines=250
php infbyte log:tail --follow
```

Follow mode detects truncation and file replacement/rotation and reopens the
active file rather than remaining attached permanently to an old inode.

## Public storage links

Install the filesystem module when required:

```bash
php infbyte module:install filesystem
php infbyte storage:status
php infbyte storage:link
php infbyte storage:unlink
```

Foundation applies application path/symlink policy while Pathwise/Flysystem own
generic storage behavior. Unlink refuses normal files/directories and refuses a
link whose target does not match the configured target.

## Deployment optimization

```bash
php infbyte optimize
php infbyte optimize:report
php infbyte app:ready
```

Clear generated deployment artifacts with:

```bash
php infbyte optimize:clear
```

Individual cache builders remain available (`config:cache`, `command:cache`,
`route:cache`, `schedule:cache`). Generated artifacts belong to deployment and
must not be committed to the application repository.

`app:ready` checks production configuration policy, active optional package
requirements, applicable module-owned schemas, storage readiness, and runtime
basics. Package presence alone is not treated as capability activation.

## CLI process controls

Global process/output options include:

```text
-q | --quiet
--silent
-v | -vv | -vvv
--profile
--json
--env=<environment>
-n | --no-interaction
```

`--profile` writes diagnostics to STDERR and never contaminates normal or JSON
stdout. A supervised isolated child suppresses its own profile output so the
parent reports one command-level profile only. `--silent` disables profiling
output entirely.

## Release verification status

Foundation's full Composer/PHPForge/static-analysis/PHPUnit/integration/runtime
and benchmark matrix is a separate release phase. Do not infer that those gates
have run merely because source/config documentation is reconciled.

The repository's explicit representative benchmark entry point is:

```bash
composer benchmark:representative
```

Additional release gates should be invoked only when their corresponding
Composer/PHPForge scripts are actually present in the release candidate.
