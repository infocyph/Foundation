# CLI, schedules, and workers

Foundation 2.0 has no `FoundationConsole`, `src/Console` hierarchy, or second
console framework. The root `infbyte` executable delegates to Foundation's
`CommandDispatcher`.

CLI bootstrap is separate from Web bootstrap and activates only capabilities
required by the selected command.

## Application commands

Register application commands explicitly in `routes/console.php`:

```php
return [
    App\Command\Reports\DailyCommand::class,
    'billing:reconcile' => App\Command\ReconcileBillingCommand::class,
];
```

There is no command-directory discovery. Foundation system commands and
application commands share one `CommandRegistry`; application names cannot
override system commands.

Metadata-only operations such as list/help/version/completion run through
bootless preflight and do not construct the application merely to display
metadata.

## Command execution

Foundation owns command definitions, parsing, IO, execution policy, overlap
coordination, and subprocess supervision.

Global options include:

```text
-q | --quiet
--silent
-v | -vv | -vvv
--profile
--json
--env=<environment>
-n | --no-interaction
```

Commands that request isolation, timeout, memory limits, or overlap handling are
executed through `ProcessRunner`. Supervised child processes inherit the same
Foundation execution ID. A child suppresses duplicate `--profile` output; the
parent reports one command-level profile.

Overlap policies use CacheLayer only when the command actually requests locking.
Long isolated executions refresh the ownership lease through process heartbeat
callbacks.

## Generated artifacts

`create:*` generators create application starting points without scanning or
silently editing registration/configuration:

```bash
php infbyte create:command Reports/Daily
php infbyte create:controller Admin/User
php infbyte create:request StoreUser
php infbyte create:rule ValidVatNumber
php infbyte create:resource User
php infbyte create:job GenerateReport
php infbyte create:handler GenerateReport
php infbyte create:job-middleware AuditJob
php infbyte create:mail Welcome
php infbyte create:notification PasswordChanged
php infbyte create:notification-channel Sms
php infbyte create:migration CreateUsers
php infbyte create:repository User
php infbyte create:seeder Production
php infbyte create:worker Metrics
```

Optional generators are enabled only when their real backing contract/package is
available.

## Compiled metadata

Deployment may compile command and schedule metadata:

```bash
php infbyte command:cache
php infbyte schedule:cache
php infbyte optimize
php infbyte optimize:report
```

Command metadata is stored at `bootstrap/cache/commands.php`; schedule metadata
is stored at `bootstrap/cache/schedule.php`. Valid compiled artifacts avoid
loading their source route files. Invalid/stale artifacts fall back to source
where that cache is an optimization.

Clear generated deployment artifacts with:

```bash
php infbyte command:clear
php infbyte schedule:clear
php infbyte optimize:clear
```

Generated optimized artifacts are deployment-owned and should not be committed.

## Scheduler runtime

Foundation has an explicit Scheduler runtime:

```bash
php infbyte schedule:list
php infbyte schedule:run
php infbyte schedule:test <key-or-unique-command>
php infbyte schedule:work --sleep=60
php infbyte schedule:interrupt
```

Schedule definitions live in `routes/schedule.php`. `schedule:test` resolves a
stable schedule identity first and only falls back to a unique command string.
Duplicate command strings therefore require a unique schedule key.

Overlap/single-server locks are provided by CacheLayer. Ownership is refreshed
while a child process runs; lost ownership terminates the child and fails the
run. Execution-history last status is resolved by schedule identity rather than
command text.

`schedule:interrupt` updates the persistent scheduler generation token. It
requests cooperative shutdown; Foundation does not respawn the scheduler.

## Worker runtime

Foundation distinguishes two worker families:

1. application maintenance workers declared in `routes/workers.php` and
   implementing `WorkerProvider`;
2. Omnibus messaging workers declared under `messaging.workers`.

Commands:

```bash
php infbyte worker:list
php infbyte worker:run reports
php infbyte worker:status reports
php infbyte worker:restart reports
```

Provider workers are unlocked by default. `singleton=true` opts into CacheLayer
ownership, and `WorkerRuntime::heartbeat()` refreshes that ownership and checks
runtime/worker restart generations.

Single Omnibus 2.5 workers use native `WorkerLifecycle` callbacks for heartbeat
and graceful stop requests. Optional Omnibus `WorkerPool` remains a Unix
`pcntl`/`posix` feature; each child constructs a fresh Foundation worker
application after fork.

External Supervisor/systemd/Docker/Kubernetes remains responsible for process
count, replacement, and deployment supervision.

## Runtime generation control

```bash
php infbyte runtime:reload
php infbyte worker:restart
php infbyte worker:restart reports
php infbyte schedule:interrupt
```

These commands mutate persistent generation state atomically. File mode uses a
stable file lock plus atomic replacement; cache mode uses CacheLayer
coordination. They are graceful control signals, not a process supervisor.
