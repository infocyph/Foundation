# Foundation

`infocyph/foundation` is the performance-first application composition layer for
the Infocyph PHP ecosystem.

Foundation owns application bootstrap, runtime selection, application paths,
provider activation, authentication workflows, sessions, scheduling, command
orchestration, worker composition, operational policy, and HTTP-aware bridges.
It does **not** copy the engines provided by specialist packages.

## Requirements

- PHP 8.4+
- Composer

Core dependencies are intentionally small:

- `infocyph/arraykit ^5.1.1` — configuration/environment mechanics
- `infocyph/intermix ^9.1` — dependency injection, lifetimes, and execution scopes
- `infocyph/uid ^5.0` — identifier algorithms
- `infocyph/webrick ^4.0.1` — request/response/routing engine
- `psr/log ^3.0.2`

Install Foundation with:

```bash
composer require infocyph/foundation
```

Foundation installs a Composer binary named `infbyte`:

```bash
vendor/bin/infbyte list
vendor/bin/infbyte --version
vendor/bin/infbyte help route:list
```

The binary treats the current working directory as the host application root.
Metadata-only commands such as list/help/version/completion execute without
booting an application.

## Runtime model

Foundation 2.0 has four explicit runtimes:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
$worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
$scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);
```

Runtime mode is never inferred from `PHP_SAPI`. Tests, workers, schedulers, and
web applications may all execute under the CLI SAPI, so the host selects the
runtime deliberately.

Every Web request, CLI command, worker unit, and scheduler unit executes inside
a fresh InterMix scope. Foundation assigns an execution ID, seeds runtime
context, performs the unit, resets touched external/static state, and leaves the
scope in `finally`.

## Project shape

A conventional host application looks like:

```text
project-root/
  app/
  bootstrap/
    providers.php
  config/
    app.php
    auth.php
    ids.php
    router.php
  database/
  public/
    index.php
  resources/
  routes/
    web.php
    api.php
    auth.php
    console.php
    schedule.php
    workers.php
  storage/
    cache/
    logs/
    sessions/
    uploads/
  tests/
  composer.json
```

Foundation knows the standard application, bootstrap, config, database, public,
resources, routes, storage, cache, logs, sessions, and uploads paths. Override
only application-specific differences.

## Web bootstrap

`public/index.php` can be as small as:

```php
<?php

declare(strict_types=1);

use Infocyph\Foundation\Foundation;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Foundation::web([
    'base_path' => dirname(__DIR__),
]);

// Convert the server request to Webrick Request and hand it to $app->handle().
```

The Web runtime eagerly prepares only the services required to receive HTTP
traffic. Optional package providers remain lazy until a configured capability is
actually resolved.

## Configuration and environment

Foundation delegates configuration mechanics to ArrayKit. Configuration loads
in this order:

1. Foundation application defaults
2. optional Foundation preset
3. host `config/*.php`
4. inline configuration passed to the runtime entry point

Environment files are loaded before host config evaluation. By default:

1. `.env`
2. `.env.local`

ArrayKit owns dotenv parsing, reference expansion, lazy config mechanics, and
dot access. Foundation owns only application file ordering, host paths, config
cache conventions, and process-environment hydration policy.

Example:

```php
Foundation::web([
    'base_path' => dirname(__DIR__),
    'app' => [
        'load_env' => true,
        'env_files' => ['.env', '.env.production'],
    ],
]);
```

## Dependency injection

InterMix is the sole DI/lifetime/scope engine. Foundation constructs and
configures an InterMix container; it does not implement a parallel container.
Compiled-container artifacts are InterMix artifacts stored using Foundation's
application cache conventions.

Application providers are grouped explicitly in `bootstrap/providers.php`:

```php
<?php

return [
    'common' => [
        App\Providers\SharedServiceProvider::class,
    ],
    'web' => [
        App\Providers\WebServiceProvider::class,
    ],
    'cli' => [
        App\Providers\CliServiceProvider::class,
    ],
    'worker' => [
        App\Providers\WorkerServiceProvider::class,
    ],
    'scheduler' => [
        App\Providers\SchedulerServiceProvider::class,
    ],
];
```

Custom providers used by forked worker pools must keep `register()` resource
free. Database connections, network clients, queue receivers, sockets, and
similar process-bound resources must be created lazily after fork.

## Optional modules

Specialist packages are optional host dependencies. Install them directly or
through Foundation's module command when you also want canonical config
published:

```bash
vendor/bin/infbyte module:install cache
vendor/bin/infbyte module:install db
vendor/bin/infbyte module:install filesystem
vendor/bin/infbyte module:install communication
vendor/bin/infbyte module:install messaging
vendor/bin/infbyte module:list
```

| Module | Package | Foundation role |
| --- | --- | --- |
| `cache` | `infocyph/cachelayer ^3.1.3` | store/lock/counter composition |
| `db` | `infocyph/dblayer ^4.1` | connection and migration composition |
| `crypto` | `infocyph/epicrypt ^2.1` | application security policy/auth adapters |
| `filesystem` | `infocyph/pathwise ^3.1` | application storage/HTTP bridges |
| `validation` | `infocyph/reqshield ^3.0` | named schema/profile composition |
| `communication` | `infocyph/talkingbytes ^2.0` | configured HTTP/email/webhook/gRPC services |
| `messaging` | `infocyph/omnibus dev-main@dev` | queue/event/worker composition |
| `otp` | `infocyph/otp ^6.0` | MFA factor mapping and durable state bridge |
| `passkeys` | `web-auth/webauthn-lib ^5.3.5` | WebAuthn application workflow |
| `logging` | built in | PSR-3 application logging policy |
| `resources` | built in | JsonDispatch application resources |
| `session` | built in | browser session/CSRF application lifecycle |

Package presence, module configuration, and a live capability are separate
states. Installing a package never opens a database/network/cache connection by
itself.

## Native package ownership

Foundation intentionally avoids generic forwarding managers.

- Use `Infocyph\UID\Id` directly for UUID/ULID/NanoID/CUID2/Snowflake/etc.
- Use native DBLayer connection/query/repository APIs for database work.
- Use native CacheLayer cache/lock/counter APIs for generic cache work.
- Use native Pathwise/Flysystem services for generic filesystem work.
- Use native ReqShield validators returned by Foundation's named-schema factory.
- Use native TalkingBytes clients/services for communication operations.
- Use native Epicrypt primitives for cryptographic operations.
- Use native Omnibus `MessageBus`, `EventDispatcher`, `Consumer`, `Worker`, and
  `WorkerPool` services for messaging.
- Use native OTP algorithms for non-Foundation OTP use.

Foundation keeps only application-specific policy and workflows around those
engines.

## Identifier policy

UID 5.0 is a core dependency. Foundation does not expose an identifier facade.
Application code calls UID directly:

```php
use Infocyph\UID\Id;

$id = Id::uuid7();
$correlation = Id::ulid();
```

Foundation authentication has one narrow policy map under `ids.auth`: durable
auth entities default to `uuid7`, while correlation IDs default to `ulid`.
Those are the only UID choices Foundation needs to understand.

## Commands

Application commands are registered explicitly in `routes/console.php`; no
command-directory scanning occurs.

```php
<?php

return [
    App\Command\ReportsDaily::class,
    'billing:reconcile' => App\Command\ReconcileBilling::class,
];
```

Foundation owns its command contract, parser, help/list/completion preflight,
execution policy, overlap coordination, subprocess supervision, and application
capability activation. Commands execute through the canonical execution scope.

Common operations include:

```bash
vendor/bin/infbyte about
vendor/bin/infbyte app:ready
vendor/bin/infbyte config:show app
vendor/bin/infbyte command:cache
vendor/bin/infbyte command:clear
vendor/bin/infbyte route:list
vendor/bin/infbyte route:cache
vendor/bin/infbyte cache:clear
vendor/bin/infbyte db:show
vendor/bin/infbyte migrate
vendor/bin/infbyte schedule:list
vendor/bin/infbyte schedule:run
vendor/bin/infbyte worker:list
vendor/bin/infbyte queue:consume
vendor/bin/infbyte storage:link
```

Command cache is a scalar metadata optimization at
`bootstrap/cache/commands.php`. The cache includes a SHA-256 fingerprint of
`routes/console.php`; malformed or stale cache is ignored and source routes are
used instead.

`help`, `list`, `--version`, and completion generation are bootless preflight
operations:

```bash
vendor/bin/infbyte help
vendor/bin/infbyte help migrate
vendor/bin/infbyte completion bash
vendor/bin/infbyte completion zsh
vendor/bin/infbyte completion fish
```

### Command interaction

The base `Command` provides prompts, password input, semantic messages, tables,
progress/task helpers, arguments/options, and machine-readable output.

`progress()` renders a progress bar for known totals and spinner-style feedback
for unknown totals. `task()` reports success/failure around a callable. Both
avoid decorative terminal updates in quiet, JSON, and non-interactive modes.

Terminal tables ignore ANSI escape sequences when calculating width and use
Unicode display width when `mb_strwidth()` is available. `--json` keeps normal
output on stdout and emits structured error objects on stderr.

## Command execution policy and processes

Command execution can request isolation, timeout/idle-timeout, memory limits,
mutexes, and overlap behavior (`allow`, `skip`, or `wait`). CacheLayer is loaded
only when an overlap policy actually requires a distributed lock.

Foundation subprocesses default to argument arrays with shell bypass. The
process runner supports:

- working directory and environment overrides
- stdout/stderr capture or passthrough
- bounded output
- wall and idle timeouts
- cancellation/heartbeat checks
- signal metadata
- graceful TERM-to-KILL cleanup

Non-interactive Unix subprocesses are moved into a dedicated process group when
POSIX process-group APIs are available, so cancellation/timeout also terminates
descendants. Windows uses process-group creation plus `taskkill /T`, with forced
fallback. Truly interactive Unix subprocesses retain foreground-terminal
ownership and therefore use direct-child termination rather than being moved to
a background process group.

## Operational execution history

Command and scheduler lifecycle history is available without introducing a
database dependency. It is disabled by default to keep the hot path free of
extra I/O:

```php
'operations' => [
    'history' => [
        'enabled' => true,
        'path' => 'storage/logs/executions.jsonl',
    ],
],
```

When enabled, Foundation writes atomic JSONL state transitions for pending,
waiting, running, succeeded, failed, cancelled, and timed-out executions. The
same execution ID follows a supervised command into its child process and its
InterMix scope.

`Infocyph\Foundation\Operations\ExecutionHistory` exposes bounded streaming
`recent()` queries and `clear()`; querying does not load the entire history file
into memory.

## Scheduling

Schedules live only in `routes/schedule.php`:

```php
<?php

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule
        ->command('reports:daily')
        ->arguments(['--tenant=acme'])
        ->dailyAt('02:00')
        ->onOneServer(leaseSeconds: 180)
        ->withoutOverlap(leaseSeconds: 180)
        ->timeout(120)
        ->memoryLimit(256);
};
```

Available operations include:

```bash
vendor/bin/infbyte schedule:list
vendor/bin/infbyte schedule:run
vendor/bin/infbyte schedule:work
vendor/bin/infbyte schedule:cache
vendor/bin/infbyte schedule:clear
```

The compiled schedule manifest fingerprints `routes/schedule.php`; stale or
invalid cache falls back to the source definition.

## Workers and messaging

Foundation distinguishes application maintenance workers from queue workers.

- Foundation `Worker`/`WorkerRuntime` own maintenance-worker application loops.
- Omnibus `Consumer`, `Worker`, and `WorkerPool` own messaging consumption and
  queue concurrency.
- Foundation supplies post-fork application composition and the canonical
  execution scope around each message/unit.

`foundation.messaging` resolves the native Omnibus `MessageBus`; there is no
Foundation messaging forwarding manager.

## Filesystem

Pathwise owns generic storage operations, uploads/downloads, archives,
copy/move/delete, synchronization, retention, and storage capability mechanics.
Foundation keeps only application path policy, storage registry composition,
public/storage linking, and HTTP upload/download response bridges.

Application code may resolve native `League\Flysystem\FilesystemOperator` or
Pathwise services directly.

## Database

DBLayer owns database engines, query building, transactions, schema, migrations,
replicas, pooling, query cache, and telemetry. Foundation owns connection-name
configuration, application migration manifests, auth-schema composition, and
runtime cleanup.

Install the optional database module and auth schema with:

```bash
vendor/bin/infbyte module:install db
vendor/bin/infbyte auth:schema:install
vendor/bin/infbyte auth:schema:status
```

Foundation 2.0 MFA storage includes a scalar `revision` column used for portable
compare-and-swap updates across MySQL, MariaDB, PostgreSQL, SQL Server, and
SQLite. JSON metadata is payload, never the CAS token.

See [Database migrations and seeding](docs/database.md).

## Validation

ReqShield owns schema compilation, validation, sanitization, casts, nested
handling, request extraction, limits, and database batching. Foundation keeps
only named application schema registration/profile composition and a direct
DBLayer adapter for ReqShield's database contract.

There is no Foundation validator facade or validation forwarding manager.

## Security and MFA

Epicrypt owns cryptographic primitives and secure key/cipher/password/token
operations. Foundation owns application security policy and authentication
workflow composition.

OTP 6.0 owns TOTP, HOTP, OCRA, Base32 provisioning, recovery-code cryptography,
and replay mechanics. Foundation maps those results to durable MFA factor state.
TOTP/counterless OCRA replay state uses CacheLayer; HOTP/counter-bearing OCRA
counter transitions and recovery-code consumption use the MFA factor revision
CAS contract.

See [OTP-backed MFA](docs/otp.md) and
[Authentication and authorization](docs/authentication.md).

## Communication

TalkingBytes owns HTTP, inbound/outbound email, webhooks, and gRPC request/
response mechanics. Foundation only builds configured application profiles and
application notification/auth bridges. Generic communication work should use
native TalkingBytes services.

## Browser sessions

Browser sessions are built into Foundation but activated only when configured.
Session storage can use array/file storage or optional CacheLayer/DBLayer
backends. Session and current-principal state are reset at every persistent
execution boundary.

```bash
vendor/bin/infbyte module:install session
vendor/bin/infbyte session:schema:install
vendor/bin/infbyte session:schema:status
vendor/bin/infbyte session:prune --limit=1000
```

See [Browser sessions](docs/browser-sessions.md).

## Artifact generation

Foundation generators create application starting points without scanning or
silently editing application composition:

```bash
vendor/bin/infbyte create:controller Admin/User
vendor/bin/infbyte create:command Reports/Daily
vendor/bin/infbyte create:service Billing
vendor/bin/infbyte create:job SendReceipt
vendor/bin/infbyte create:middleware EnsureTenant
vendor/bin/infbyte create:migration CreateUsers
vendor/bin/infbyte create:provider Billing
vendor/bin/infbyte create:repository User
vendor/bin/infbyte create:seeder Production
vendor/bin/infbyte create:worker Queue
vendor/bin/infbyte create:event UserRegistered
vendor/bin/infbyte create:listener SendWelcomeEmail
vendor/bin/infbyte create:test Http/UserAccess
```

Commands, providers, schedules, and workers remain explicit registrations.
Generators never modify those registration files implicitly.

## Persistent-runtime cleanup

Foundation resets only mutable state that actually exists:

- InterMix opens/closes the execution scope.
- current principal and browser session contexts are cleared.
- fresh DBLayer connections are reset/disconnected.
- shared DBLayer connections are rolled back/sanitized for reuse.
- CacheLayer process-local memoizers are flushed when loaded.

OTP, Epicrypt, Pathwise, TalkingBytes, and Foundation's Omnibus composition keep
no request/account-specific singleton state requiring an additional reset hook.
Network/database/queue resources remain lazily constructed according to their
own package lifetimes.

## Production guidance

- use durable authentication/session/MFA stores where persistence is required;
- configure a real CacheLayer backend before relying on distributed locks or
  replay state;
- configure DBLayer before enabling database-backed services;
- set a unique `auth.token_secret` of at least 32 bytes;
- configure real notification transports before production auth delivery;
- install/upgrade the Foundation auth schema when using DBLayer-backed auth;
- compile config/container/route/command/schedule artifacts during deployment
  when those optimizations fit the application;
- run `vendor/bin/infbyte app:ready` as a deployment readiness check.

## Documentation

Start with [docs/README.md](docs/README.md). The documentation covers lifecycle,
configuration, modules, authentication, OTP, sessions, database migrations,
Omnibus integration, filesystem/communication ownership, logging, testing,
operations, and the Console migration parity gate.

The old `infocyph/console` package is **not** part of Foundation 2.0. Its useful
application/runtime capabilities are absorbed into Foundation or delegated to
the specialist libraries listed above; redundant Console-specific abstraction
layers are retired explicitly.

## Release

Foundation 2.0 is a major architecture release. Backward compatibility with the
old Console/Foundation bridge surface is intentionally not retained. The
release gate is defined in
[docs/architecture/console-parity.md](docs/architecture/console-parity.md).

The remaining test/release matrix is intentionally separate from the main
source architecture: package combinations, databases, cache backends,
persistent-runtime soak/isolation, fork behavior, CLI/process behavior, and
performance regression checks must pass before the final tag.
