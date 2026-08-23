# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file tracks the current implementation/release state.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

Foundation is the reusable framework/runtime layer. Infbyte is the opinionated application skeleton built on it.

## Current checkpoint

- Date: 2026-08-24
- Foundation source checkpoint: `493c39a7a06bac0455397556254f0f8e7e25f973`
- Foundation documentation checkpoint: `944220490e1c28e9945fd398265dc9d072eb4c93`
- Infbyte source checkpoint: `56cb73e18eab07f34242a929eccbc9e6572d9971`
- Infbyte documentation checkpoint: `26a35da0926285119c31ed880bf1f5aa06f3cf19`
- Current phase: **public-name/config freeze complete; ready for deferred verification matrix**.
- Application-contract/API cleanup: complete.
- Documentation reconciliation: complete for the public architecture/module/runtime/operations/auth/database/messaging/application-contract surfaces.
- Full PHPUnit/static-analysis/PHPForge/runtime/release matrix: **not run yet**.

# Dependency baseline

## Core

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

## Optional/dev capabilities

- `infocyph/cachelayer ^3.2.0`
- `infocyph/dblayer ^4.1`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.4`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.0.1`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

`ModuleCatalog` constraints stay aligned with this baseline.

# Frozen architecture

## Runtimes

Exactly four Foundation runtimes exist:

- Web
- CLI
- Worker
- Scheduler

No `FoundationConsole`, `Foundation::console()`, `src/Console` hierarchy, runtime inference from `PHP_SAPI`, static global application state, generic IdentifierManager, or `app.container.request_scope` compatibility layer is permitted.

## Application surface

`Application` is intentionally a narrow runtime/composition object. Its stable categories are:

- boot/runtime state;
- `config()` / `container()` / `providers()`;
- generic DI resolution through `make()` / `has()`;
- `execution()`;
- canonical Web entry through `handle()` / `http()`;
- Foundation application path methods.

Auth, session, router, response and testing convenience proxies are removed. Consumers resolve concrete services through DI. Do not add broad cache/database/filesystem/messaging/security/application facades.

## Specialist ownership

- InterMix owns DI/lifetimes/scopes.
- Webrick owns HTTP/router/request/response/emission engines.
- UID owns identifier algorithms.
- DBLayer owns DB/query/schema/migration engines.
- CacheLayer owns cache/lock/counter/node/cluster engines.
- Omnibus owns message transport/handlers/retry/failure/workers/pools.
- TalkingBytes owns HTTP/email/webhook/gRPC protocol engines.
- ReqShield owns validation mechanics.
- Epicrypt owns cryptographic primitives.
- Pathwise/Flysystem own generic filesystem/storage behavior.
- OTP owns OTP algorithms/replay primitives.
- WebAuthn library owns WebAuthn protocol validation.

Foundation adds application policy/composition only where it owns a real framework responsibility.

# Frozen purpose-first modules

| Module | Backing package(s) |
|---|---|
| `auth` | `infocyph/otp ^6.0`, `web-auth/webauthn-lib ^5.3.5` |
| `cache` | `infocyph/cachelayer ^3.2` |
| `communication` | `infocyph/talkingbytes ^2.0` |
| `database` | `infocyph/dblayer ^4.1` |
| `filesystem` | `infocyph/pathwise ^3.1` |
| `logging` | built in |
| `messaging` | `infocyph/omnibus ^2.4` |
| `operations` | built in |
| `resources` | built in |
| `security` | `infocyph/epicrypt ^2.1` |
| `session` | built in |
| `validation` | `infocyph/reqshield ^3.0` |

Canonical aliases:

- `db|dblayer -> database`
- `crypto|epicrypt -> security`
- `otp|mfa|passkey|passkeys|webauthn -> auth`
- `notifications|talkingbytes -> communication`
- `events|omnibus|queue|queues -> messaging`
- `files|pathwise|storage -> filesystem`
- `reqshield|validator -> validation`
- `ops|runtime -> operations`

No standalone OTP/passkey public modules.

# Frozen config/schema lifecycle

Public module operations:

- `module:list`
- `module:show <module>`
- `module:install <module>`
- `module:remove <module>`
- `module:config:publish <module> [--force]`
- `module:schema:status <module> [--connection=...]`
- `module:schema:install <module> [--connection=...]`
- `module:schema:sync [--connection=...]`

Schema owners:

- `auth` -> Foundation auth schema;
- `cache` -> CacheLayer public PDO/SQLite/invalidation schema provisioners;
- `session` -> Foundation database-session schema.

`database` owns DB/migration infrastructure, not arbitrary application tables. `module:remove` never drops schema/data. Schema status is observational and must not create a missing SQLite cache DB.

Force config publication stages replacement/backup transactionally, refuses symlink targets, and treats post-commit backup cleanup as best-effort finalization rather than a reason to destructively roll back a successful publication.

# Frozen application contracts

## Validation

- Foundation `FormRequest` composes Webrick request input into ReqShield.
- Custom validation rules implement ReqShield `Contracts\Rule` directly.
- Generators: `create:request`, `create:rule`.

## Notifications/mail

Foundation application routing contracts:

- `Notification`
- `NotificationRecipient`
- `NotificationChannel`
- `NotificationChannelRegistry`
- `NotificationDispatcher`

TalkingBytes-backed mail integration:

- `MailMessage`
- `Mailer`
- `MailNotificationChannel`

Generators: `create:mail`, `create:notification`, `create:notification-channel`.

## Messaging/jobs

- `Job`
- `JobContext`
- `JobMiddleware`
- `JobMiddlewarePipeline`

Omnibus `HandlerInvoker` remains the single sync/async handler execution point. Generators: `create:job`, `create:handler`, `create:job-middleware`.

## Resources/testing

- `JsonResource::resolve(): mixed` is the application resource contract.
- `create:resource` targets that contract.
- `TestKit` is resolved through DI; there is no `Application::testing()` facade.
- JsonDispatch response factory is resolved through DI; there is no `Application::responses()` facade.
- `AuthServices` is resolved through DI; there is no `Application::auth()` facade.

# Runtime correctness completed before freeze

## Execution cleanup

- one InterMix execution scope per execution unit;
- targeted `RuntimeContextTracker` cleanup;
- original application exception remains primary if cleanup also fails;
- reset and scope-leave are both attempted.

## Scheduling

- overlap/single-server lease refresh continues during child execution;
- lost lease -> heartbeat loss -> terminate child -> failed run;
- `schedule:test` returns real failure status;
- every schedule history transition carries stable `schedule_identity`;
- `schedule:list` resolves last state by identity, not only command text.

## Workers / Omnibus 2.4

- Bootstrapper probes `WorkerLifecycle` as the Omnibus 2.4 capability boundary;
- single messaging workers use native Omnibus lifecycle heartbeat/stop callbacks and do not require `pcntl` solely for generation polling;
- Omnibus `WorkerPool` remains Unix/pcntl/posix based and retains the Unix watchdog;
- provider-only workers remain messaging-lazy;
- provider singleton workers refresh CacheLayer ownership through `WorkerRuntime::heartbeat()`.

## Runtime control/process registry

- file runtime-control state uses stable lock + atomic replacement;
- cache runtime-control state uses CacheLayer coordination for one read/modify/write transaction;
- concurrent generation signals cannot silently overwrite one another;
- runtime registry visibility is `host|shared`, default `host`;
- registry records are heartbeat observability, not supervisor truth;
- `worker:status` reports registry visibility.

## Other correctness work

- DB provider activation no longer wakes CacheLayer accidentally;
- supervised child `--profile` output is suppressed so only parent profiles;
- `config:validate --production` passes production intent into OTP topology checks;
- Cache schema readiness is read-only for missing SQLite files;
- `log:tail --follow` handles truncation and replacement/rotation;
- `AuthPruner` matches the current auth schema;
- DBLayer 4.1 `MigrationRunner::pretend()` return shape is used directly;
- storage unlink remains symlink/target safe;
- environment encryption uses external key material and rollback-safe publication.

# Frozen CLI families

Current public command families are source-of-truth in `CommandCatalog` and documentation. Major surfaces:

- application: `about`, `app:install`, `app:ready`, `env:show`, `serve`
- config/cache: `config:*`, `cache:*`, `command:*`
- database: `db:*`, `migrate*`
- modules: `module:*`
- operations: `execution:*`, `maintenance:*`, `runtime:reload`, `log:tail`, env protect
- messaging: `messaging:list`, `queue:*`, `schedule:dispatch-message`
- scheduling: `schedule:*`
- workers: `worker:list|run|restart|status`
- storage/session/auth operational commands
- optimization: `optimize`, `optimize:clear`, `optimize:report`
- `create:*` generators backed by real framework/package contracts.

Do not add command-for-command Laravel parity or commands that duplicate specialist engines.

# Documentation reconciliation completed

Updated/reconciled public docs include:

- root `README.md`;
- architecture/runtime ownership;
- retired Console parity closure record;
- modules/configuration;
- CLI/scheduler/workers;
- operations/runtime control;
- database/migrations/schema lifecycle;
- messaging/Omnibus 2.4;
- authentication/OTP/browser sessions;
- security;
- HTTP/capability ownership;
- testing/resource DI examples;
- Infbyte skeleton README.

Important removed stale references:

- `Foundation::console()` / Console-owned runtime architecture;
- arbitrary `foundation-module.php` package discovery;
- standalone OTP/passkey public modules;
- `auth:schema:*` / `session:schema:*` command families;
- `$app->auth()` / `$app->responses()` / `$app->testing()` convenience proxies;
- `$app->messaging()` forwarding manager;
- nonexistent generic Composer release/test scripts;
- old Omnibus 2.3 / CacheLayer 3.1 dependency wording;
- old `router.route_files` key.

# Infbyte boundary

Infbyte remains intentionally lean:

- root `infbyte` delegates directly to Foundation `CommandDispatcher`;
- Web bootstrap is one `Foundation::web()` call;
- checked-in config remains only `app.php`, `auth.php`, `router.php`;
- optional capability config is publish-on-demand;
- no Foundation runtime/messaging/notification/validation/schema engine is copied into Infbyte;
- `.env.example` contains no environment-encryption key;
- generated optimized artifacts are not committed.

# Verification status

The public names/config/application contracts are now frozen for verification. The full verification matrix is still intentionally **not run**.

Next phase:

1. Composer validation/dependency consistency;
2. PHPForge/static analysis;
3. PHPUnit/unit/integration suites;
4. core-only runtime without optional packages;
5. module install/remove/config/schema matrix, including partial auth bundle state and install ordering;
6. `app:ready` / `config:validate --production` diagnostics;
7. CLI/global-option/help/completion matrix;
8. Web/CLI/Worker/Scheduler execution isolation;
9. maintenance/runtime reload/worker/scheduler lifecycle;
10. Omnibus handler/job middleware and failure/retry paths;
11. pool/fork/process safety;
12. DB pretend/monitor/wipe/rollback-batch behavior;
13. env/storage/destructive-operation rollback/safety cases;
14. optimize/deploy artifact matrix;
15. performance/soak/benchmark review;
16. fix verification defects and prepare Foundation 2.0 + Infbyte stable release alignment.

# Do not regress

- no package-per-module public model;
- no standalone OTP/passkey modules;
- no duplicate specialist schema command families;
- no schema/data deletion during module removal;
- no copied specialist SQL/transport/retry/cache/database engines;
- no unsafe lock fallback;
- no broad `Application` service facade;
- no second messaging/retry/failure/worker engine above Omnibus;
- no Omnibus Envelope/HandlerContext leakage into Foundation JobMiddleware;
- no retired Console runtime hierarchy;
- no static global application state;
- no generic IdentifierManager/request scope;
- no bulk optional-config copy into Infbyte;
- no environment-protection key inside `.env`/`.env.example`;
- no generated optimized artifacts committed.
