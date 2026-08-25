# Foundation

`infocyph/foundation` is the performance-first application composition layer for
the Infocyph PHP ecosystem.

Foundation owns application bootstrap, explicit runtime selection, application
paths, provider activation, authentication/session workflows, command and
scheduler orchestration, worker composition, operational policy, HTTP-aware
bridges, and purpose-first optional modules. Specialist libraries retain their
own engines and public APIs.

## Requirements

Core runtime dependencies:

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

Install with Composer:

```bash
composer require infocyph/foundation
```

Foundation exposes the `infbyte` Composer binary:

```bash
vendor/bin/infbyte list
vendor/bin/infbyte --version
vendor/bin/infbyte help route:list
```

Metadata-only list/help/version/completion operations do not need to boot an
application.

## Runtime model

Foundation has exactly four explicit runtimes:

```php
use Infocyph\Foundation\Foundation;

$web = Foundation::web(['base_path' => dirname(__DIR__)]);
$cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
$worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
$scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);
```

Runtime mode is never inferred from `PHP_SAPI`. Web requests, CLI commands,
worker messages/units, and scheduler executions each run inside a fresh InterMix
execution scope with a Foundation execution ID and targeted external-state
cleanup.

## Application shape

A conventional application may contain:

```text
project-root/
  app/
  bootstrap/
    cache/
    providers.php
  config/
    app.php
    auth.php
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
  infbyte
```

Optional module configuration is published only when required; applications do
not need to check every Foundation config template into source control.

## Web bootstrap

A host selects the Web runtime explicitly:

```php
use Infocyph\Foundation\Foundation;

$app = Foundation::web([
    'base_path' => dirname(__DIR__),
]);
```

The host converts the incoming request to Webrick's `Request` and calls
`$app->handle($request)`. Optional package providers remain lazy until a
configured capability is resolved.

## Configuration

ArrayKit owns dotenv/config mechanics. Foundation owns application file ordering,
paths, cache conventions, and runtime policy.

Global configuration helpers are deliberately limited to:

```php
env('KEY');
env_bool('KEY', false);
env_int('KEY', 10);
env_string('KEY', 'default');
```

Application paths remain declarative; Foundation does not introduce global path
helpers or static application state.

## Dependency injection

InterMix is the sole DI/lifetime/scope engine. Foundation composes and activates
providers over the public InterMix container APIs; it does not maintain a second
container implementation.

Application providers are grouped explicitly:

```php
return [
    'common' => [],
    'web' => [],
    'cli' => [],
    'worker' => [],
    'scheduler' => [],
];
```

Pooled worker parents must remain resource-free before fork. Resolve PDO,
Redis/Valkey, brokers, sockets, HTTP clients, and other process-bound services
lazily in the child.

## Purpose-first modules

A Foundation module represents an application capability, not a package name.

| Module | Backing packages |
| --- | --- |
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2.0` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^5.0` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built into Foundation |
| `messaging` | `infocyph/omnibus ^2.5` |
| `operations` | built into Foundation |
| `resources` | built into Foundation |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built into Foundation |
| `validation` | `infocyph/reqshield ^3.1` |

Common aliases remain accepted (`db` -> `database`, `crypto` -> `security`,
`otp|mfa|passkeys|webauthn` -> `auth`), but canonical documentation uses the
purpose names.

```bash
php infbyte module:list
php infbyte module:show database
php infbyte module:install database
php infbyte module:config:publish messaging
php infbyte module:remove database --dry-run
```

Package presence is distinct from configured activation. A multi-package module
may be partially installed; readiness requires only the implementations selected
by active configuration.

## Module-owned schema lifecycle

Foundation exposes one schema command family:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
php infbyte module:schema:status cache
php infbyte module:schema:install session
php infbyte module:schema:sync
```

Schema ownership remains with the capability:

- `auth` -> Foundation authentication schema
- `cache` -> CacheLayer public PDO/SQLite/invalidation schema provisioners
- `session` -> Foundation database-session schema

The `database` module owns migration/database infrastructure, not arbitrary
application schema. `module:remove` never drops schemas or application data.

Schema status is observational; for example, checking a missing SQLite cache
database does not create the database file.

## Native specialist ownership

Foundation intentionally avoids generic forwarding facades/managers:

- use `Infocyph\UID\Id` for generated identifiers;
- use native DBLayer APIs for queries/transactions/schema;
- use native CacheLayer APIs for generic cache/lock/counter operations;
- use Pathwise/Flysystem for generic filesystem behavior;
- use ReqShield validation primitives directly;
- use TalkingBytes for HTTP/email/webhook/gRPC mechanics;
- use Epicrypt primitives directly for cryptographic operations;
- use Omnibus `MessageBus`, `EventDispatcher`, `Consumer`, and worker APIs for
  messaging.

Foundation adds application-specific policy/composition only where it has a
real framework responsibility.

## Application contracts and generators

Foundation provides application-level contracts where they add value over the
specialist package:

- `Validation\FormRequest` composes Webrick request input with ReqShield;
- custom validation rules implement ReqShield's native `Contracts\Rule`;
- `Messaging\Job`, `JobContext`, and `JobMiddleware` adapt application jobs to
  Omnibus handler execution;
- `Notifications\Notification`, `NotificationRecipient`, and
  `NotificationChannel` provide application notification routing;
- `Notifications\MailMessage` adds sender-profile selection over TalkingBytes
  `EmailMessage`;
- `Http\Resource\JsonResource` is the application JSON-resource contract.

Available generators include:

```bash
php infbyte create:class Support/Clock
php infbyte create:controller Admin/User
php infbyte create:command Reports/Daily
php infbyte create:config billing
php infbyte create:job GenerateReport
php infbyte create:handler GenerateReport
php infbyte create:job-middleware AuditJob
php infbyte create:request StoreUser
php infbyte create:rule ValidVatNumber
php infbyte create:mail Welcome
php infbyte create:notification PasswordChanged
php infbyte create:notification-channel Sms
php infbyte create:resource User
php infbyte create:migration CreateUsers
php infbyte create:repository User
php infbyte create:seeder Production
php infbyte create:worker Metrics
```

Generators create starting points only; they do not scan the application or
silently edit configuration/registration maps.

## Commands

Application commands are registered explicitly in `routes/console.php`. No
command-directory scanning occurs.

Core command families include:

```text
about | app:install | app:ready | serve
config:* | command:* | route:*
cache:*
db:* | migrate*
module:*
execution:* | maintenance:*
runtime:reload | worker:* | schedule:*
queue:* | messaging:list
storage:* | session:prune | auth:prune
log:tail
env:show | env:encrypt | env:decrypt
optimize | optimize:clear | optimize:report
create:*
```

Global CLI controls include `--json`, `-q|--quiet`, `--silent`,
`-v|-vv|-vvv`, `--profile`, `--env`, and `-n|--no-interaction`.

`--profile` writes command diagnostics to STDERR. Supervised child commands do
not duplicate profile output; the parent owns command-level profiling.

## Database

Install DBLayer through the canonical capability:

```bash
php infbyte module:install database
php infbyte migrate
php infbyte migrate --pretend
php infbyte migrate:status
php infbyte db:monitor --section=status
```

`migrate --pretend` renders DBLayer 5's native pending SQL/bindings preview.
Foundation does not implement another migration SQL compiler.

See [Database migrations and seeding](docs/database.md).

## Cache and coordination

CacheLayer 3.2 provides cache stores, native locks, counters, node/cluster cache,
and shared coordination.

Foundation coordination policy is explicit:

1. an explicitly configured lock driver wins;
2. otherwise Foundation uses the selected store's native lock when available;
3. there is no unsafe implicit fallback to an unrelated file lock.

Optional package presence does not force cache or DB activation.

## Messaging and workers

Omnibus 2.5 is the messaging baseline. Foundation uses one native Omnibus
`HandlerInvoker` for sync and queued handlers. `messaging.handler_middleware`
applies to all handlers; Foundation `messaging.job_middleware` runs only for
messages implementing Foundation `Job`.

Resolve native Omnibus services through DI:

```php
use Infocyph\Omnibus\MessageBus;

$bus = $app->make(MessageBus::class);
$bus->dispatch(new App\Jobs\GenerateReportJob());
```

Persistent single messaging workers use Omnibus 2.5 `WorkerLifecycle` callbacks
for Foundation heartbeat/reload checks. Unix `WorkerPool` remains optional and
requires `pcntl`/`posix`.

```bash
php infbyte worker:list
php infbyte worker:run reports
php infbyte worker:status reports
php infbyte worker:restart reports
php infbyte queue:failed
php infbyte queue:retry <id>
```

`queue:flush` clears the failed-message store; it is not a live queue purge.

See [Events, queues, workers, and scheduled messages](docs/messaging.md).

## Scheduling

Schedules are declared explicitly in `routes/schedule.php`.

```bash
php infbyte schedule:list
php infbyte schedule:run
php infbyte schedule:test reports:daily
php infbyte schedule:work
php infbyte schedule:interrupt
```

Overlap/single-server ownership uses CacheLayer. Long child executions refresh
their lock lease through process heartbeats; loss of ownership terminates the
child rather than allowing unowned continuation. Last-run status is keyed by
schedule identity, not only command text.

## Operations

Built-in operational surfaces include:

```bash
php infbyte config:validate --production
php infbyte execution:list
php infbyte maintenance:enable --retry=60
php infbyte runtime:reload
php infbyte worker:status
php infbyte log:tail --follow
php infbyte storage:status
php infbyte optimize:report
```

Runtime-control mutations are serialized. File mode uses a stable file lock and
atomic replacement; cache mode uses CacheLayer coordination. Runtime process
records are heartbeat-based visibility metadata with explicit `host|shared`
registry visibility; Foundation is not a process supervisor.

See [Operations](docs/operations.md).

## Environment protection

With the `security` module installed:

```bash
php infbyte env:encrypt --key-file=/secure/env.key
php infbyte env:decrypt --key-file=/secure/env.key
```

Key material must remain external to the protected `.env`. Foundation does not
expose a literal command-line `--key=<secret>` option.

## Deployment optimization

Deployment may compile supported artifacts:

```bash
php infbyte optimize
php infbyte optimize:report
php infbyte app:ready
```

Clear them with:

```bash
php infbyte optimize:clear
```

Config, route, command, schedule, and container artifacts are deployment-owned
and should not be committed.

## Documentation

See [docs/README.md](docs/README.md) for focused guides covering architecture,
configuration, authentication, sessions, database, filesystem, communication,
messaging, resources, logging, operations, security, modules, and testing.

## Verification

Foundation's release closure is evidence-driven and tracked in
[`foundation_work_plan.md`](foundation_work_plan.md). The PHP 8.4/8.5
lowest/stable QA matrices, analyzers, clean production install, representative
benchmark validation, runtime isolation, operational safety, and persistent
execution retention checks are required to pass before release closure.

The representative benchmark can be run with:

```bash
composer benchmark:representative
```

Hosted-runner benchmark results validate the benchmark contract and workload.
Numeric regression comparison should only be gated against a baseline captured
from the same explicitly stable environment; Foundation does not treat noisy
hosted runners as a synthetic performance baseline.
