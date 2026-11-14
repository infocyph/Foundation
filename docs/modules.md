# Foundation 4 optional modules

Foundation 4 has **prebuilt Core**, engineering-only **PHPForge QA**, and exactly **three optional public modules with eight individually selectable features**. No Core or QA component has an optional installation lifecycle.

| Module | Qualified feature | Native package | Published configuration |
| --- | --- | --- | --- |
| data | `data.database` | DBLayer ^6.1.4 | database.php |
| data | `data.filesystem` | Pathwise ^4.2.1 | filesystem.php |
| security | `security.crypto` | Epicrypt ^3.3 | security.php |
| security | `security.validation` | ReqShield ^3.3.1 | validation.php |
| security | `security.mfa` | OTP ^7.0.1 | none |
| security | `security.passkey` | OTP ^7.0.1, web-auth/webauthn-lib ^5.3.12 | none |
| execution | `execution.messaging` | Omnibus ^3.0.3 | messaging.php |
| execution | `execution.communication` | TalkingBytes ^2.3.1 | communication.php |

Core supplies auth, cache, logging, operations, resources, sessions, notifications, plus the native schema and readiness systems. Eight `core:*` diagnostics report Core availability without granting package installation rights. CacheLayer and Runwire are required Core dependencies. PHPForge is dev-only; `opis/closure` is dev-only and `psr/log` is Core runtime. Webrick, InterMix and Runwire retain native router, DI and host ownership. Runwire is installed in production even without PCNTL/POSIX or a persistent host; available capabilities govern its behavior.

## Inspect and plan (machine schema v2)

```bash
php infbyte module:list
php infbyte module:show data
php infbyte module:doctor security.passkey
php infbyte module:plan data.database
php infbyte module:plan security --feature=mfa
```

`module:list` returns `schema_version: 2` with exactly three `modules` objects containing `name`, `description`, and qualified `features`. `module:show` and `module:doctor` accept a group as a **read-only** view or a qualified feature. `module:plan` requires explicit features and returns a schema-v2 `requested`, `required`, `states`, `effects`, `blockers`, `warnings`, `applicable` plan. A group alone never selects all eight features or all its siblings. Missing root Composer ownership is reported and blocks mutations.

## Install, activate, repair and remove

```bash
php infbyte module:install data.filesystem --dry-run
php infbyte module:install data --feature=database
php infbyte module:enable data.database
php infbyte module:disable data.database
php infbyte module:repair data.filesystem
php infbyte module:remove data.filesystem --dry-run
php infbyte module:remove data.filesystem
```

Operations capture a fresh project-root `composer.json`, installed Composer package versions, selected `modules.features`, loaded PHP extensions and existing published configuration. Missing or symlinked root ownership fails closed. `install` reconciles the requested packages, then publishes missing feature-owned config and provisions only applicable specialist schemas; **install never enables a feature**. `enable` and `disable` atomically update `config/modules.php` after dependency validation. Unchanged feature topology does not rewrite config or invalidate caches.

Removing requires a prior disable, preserves packages shared with selected siblings, and never deletes application data, tables or migrations. Composer errors may leave a *partial* install; no unsafe compensation or blind rollback is attempted. Read the response `phases` and `recovery`, recheck root and installed Composer metadata, then run `module:repair` on the same explicit features. Only a real mutation should trigger a compiled-release rebuild.

## Selected feature dependencies

- OTP-backed MFA (`auth.drivers.mfa=otp`) selects `security.mfa` plus the **explicit** `security.crypto` Epicrypt prerequisite; simple MFA does not. WebAuthn `security.passkey` shares an OTP package but never implicitly activates MFA or Epicrypt.
- Durable Omnibus messaging (`messaging.durable.enabled=true`) requires `execution.messaging` and explicit `data.database`. The DBLayer transport, failure store and native schema remain Omnibus/DBLayer-owned. Neither messaging nor TalkingBytes activates Runwire automatically.
- Database-aware ReqShield requires `data.database`. PDO, fileinfo, hash, mbstring and ctype requirements depend on the selected feature. Sodium, PCNTL and POSIX are optional host accelerators and are not universal Core requirements.
- Direct root dependency identity matters: a Composer-transitive package does not become an application-owned optional module.

## Ordinary PHP/FPM and Runwire-bound selected features

The same explicit `modules.features` selection works in an ordinary PHP/CLI/FPM
process and a Runwire-managed worker or HTTP host. Foundation 4 requires
**64-bit PHP 8.4+**, Composer 2 and its Core dependencies, including Runwire
3.0. Installing Runwire does not start a listener or require PCNTL, POSIX,
Swoole or OpenSwoole. A selected optional feature requires its Composer package
*before compilation*; a missing package fails with the selected feature ID,
package and minimum supported constraint instead of silently disabling it.

| Selected feature | Selected-package/platform prerequisite | Ordinary PHP behavior | When a host supplies Runwire context |
| --- | --- | --- | --- |
| `data.database` | DBLayer `^6.1.4`, PDO and selected database driver | Native synchronous connection/query | Use the execution's native DBLayer binding; do not store a request in the connection factory |
| `data.filesystem` | Pathwise `^4.2.1`, fileinfo; zip optional | Native disk, upload and stream operations | Bind the actual Pathwise processor operation, then restore the borrowed context; Webrick owns streamed HTTP responses |
| `security.crypto` | Epicrypt `^3.3`; sodium where the chosen primitive requires it | Native local key/token/password operations | Preserve cancellation for a deliberately selected remote JOSE resolver; no extra runtime is created |
| `security.validation` | ReqShield `^3.3.1`, fileinfo, hash, mbstring; select `data.database` for configured DB-backed validation | Same native rule and sanitizer contracts | Request scoping and database work remain with their respective owners |
| `security.mfa` | OTP `^7.0.1`, ctype; select `security.crypto` when `auth.drivers.mfa=otp` | Native verification, counter CAS and recovery storage | Borrow the current execution context for stateful OTP issue/verify and replay operations; no singleton scope capture |
| `security.passkey` | OTP `^7.0.1`, WebAuthn `^5.3.12`, ctype | Native RP/origin checks, ceremony verification and credential CAS | Bind each challenge and begin/finish operation; retain the same credential identity and single-use semantics |
| `execution.messaging` | Omnibus `^3.0.3`; explicitly select `data.database` for durable messaging | Native synchronous dispatch and configured queue transport | Forward the current borrowed bus/consumer/worker scope; no implicit listener or second process pool |
| `execution.communication` | TalkingBytes `^2.3.1`; transport-specific network/extension requirements | Native HTTP, SMTP, webhook and gRPC where installed | Pass the active context to the actual network operation, preserving deadlines, retries and transport cleanup |

To install and activate a feature, keep package installation and feature activation
as separate operations:

```bash
php infbyte module:install data.database
php infbyte module:enable data.database
php infbyte module:doctor data.database
```

For ordinary PHP/CLI/worker execution, use the normal Foundation entrypoint,
select the feature in `modules.features`, and call native specialist operations
inside `$app->execution()->run(...)` where their execution scope is active.
For example, after installing DBLayer:

```php
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;

$app = Foundation::worker([
    'app' => [
        'base_path' => __DIR__,
        'env' => 'local',
        'capabilities' => ['database'],
    ],
    '_config_cache' => false,
    'modules' => ['features' => ['data.database' => true]],
])->boot();

$app->execution()->run(static function (ExecutionId $id): void {
    // Resolve the configured native DBLayer operation inside this execution.
    echo (string) $id;
});
```

In a **host-owned** Runwire execution, do not manufacture a second
`RuntimeContext`, `RequestContext`, `CoroutineScope`, event loop or worker
pool. Pass the *exact live* objects supplied by the host to Foundation's
execution inputs. Native specialist bindings then borrow that scope for the
actual operation; the host, not Foundation, completes the host-owned request.

```php
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;

// Within an existing host callback: $hostRuntime and $hostRequest are
// the objects supplied by that host, not newly allocated stand-ins.
$app->execution()->run(
    static function (ExecutionId $id) use ($app, $hostRuntime, $hostRequest): void {
        if ($app->make(RuntimeContext::class) !== $hostRuntime
            || $app->make(RequestContext::class) !== $hostRequest
        ) {
            throw new \LogicException('Borrowed Runwire identity changed.');
        }

        // Execute selected native specialist work in this borrowed scope.
    },
    [RuntimeContext::class => $hostRuntime, RequestContext::class => $hostRequest],
);
```

A runnable standalone *demonstration* that owns and completes its own test
request is [`examples/foundation-4-runwire-worker.php`](examples/foundation-4-runwire-worker.php).
Production hosts own their request completion instead. For HTTP hosts, Webrick
retains response and request-scope ownership; see [Runtime hosting](runtime-hosting.md),
[OTP](otp.md), [Messaging](services/queues-workers.rst), [Communication](services/communication.rst)
and [Filesystem](data/filesystem.rst).

## Feature-owned config and schema

```bash
php infbyte module:config:publish security.crypto
php infbyte module:config:publish execution --feature=communication
php infbyte module:schema:status execution.messaging
php infbyte module:schema:install execution.messaging
php infbyte module:schema:sync
php infbyte cache:schema:status
```

Config publishing creates only missing feature-owned templates and never force-overwrites application files. The only specialist database schema is the conditional Omnibus **durable messaging** schema. `module:schema:sync` applies only to explicitly enabled optional features. Core auth, session and cache schemas stay Core-owned and cannot be reached through the optional module lifecycle. Durable failures and transactions retain stored messages and cancellation safety.

## Foundation 4 breaking application changes

The former 11-entry public catalog is removed: names such as `auth`, `database`, `filesystem`, `validation`, `messaging`, `communication`, `logging`, `operations`, `resources` and `session` are **not** installable aliases. This is a major-version contract change without an older-major shim. Replace `module:install database` with `module:install data.database`, `module:install auth --feature=otp` with `module:install security.mfa` (and `security.crypto` when OTP is selected), and `module:config:publish messaging` with `module:config:publish execution.messaging`.

Upgrade by declaring current Composer package majors in the application's root manifest, selecting only the required `modules.features` and their conditional dependencies, validating plans/extension constraints, publishing missing owner configuration, and compiling a new immutable release generation. Migrate automated scripts and JSON parsers from v1 to **v2**. See [Configuration](reference/configuration.rst) and [Architecture](architecture/application-lifecycle.rst). See the [host certification procedure](foundation-4-host-benchmark-certification.md) for release validation. A candidate is **not release-ready** until the complete performance matrix and final source/dependency QA gates pass.
