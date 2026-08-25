# Retired Console migration parity record

Foundation 2.0 no longer depends on or contains the former
`infocyph/console`/`Infocyph\Foundation\Console` architecture. This document is
the closure record for that migration, not a second live implementation plan.

The authoritative release requirements are documented in the
[README verification section](../../README.md#verification) and enforced by
the [Security & Standards workflow](../../.github/workflows/security-standards.yml).

## Migration rule

Every useful old Console capability was resolved as one of:

- **MIGRATED** — Foundation now owns the application/runtime capability;
- **DELEGATED** — a specialist package owns the engine and Foundation composes
  it;
- **RETIRED** — the old surface was intentionally removed because it duplicated
  another engine or contradicted Foundation 2.0 architecture.

There are no remaining Console-parity blockers.

## Application and CLI

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Console application/bootstrap | Four explicit Foundation runtimes + `CommandDispatcher` | MIGRATED |
| Console-specific DI/bootstrap | InterMix + Foundation runtime composition | RETIRED |
| Command base/contract | `Command`, `CommandHandlerInterface` | MIGRATED |
| Definitions/descriptors/registry | `CommandDefinition`, `CommandDescriptor`, `CommandRegistry` | MIGRATED |
| Argument/option parsing | `ParsedInput` | MIGRATED |
| Help/list/completion preflight | `CliPreflight`, `CompletionGenerator` | MIGRATED |
| Command resolution | `CommandResolver` + InterMix | MIGRATED |
| Execution policy/supervision | `CommandExecutionPolicy`, `CommandExecutionCoordinator`, `ProcessRunner` | MIGRATED |
| Command cache | `CommandCacheManager` -> `bootstrap/cache/commands.php` | MIGRATED |
| Overlap/mutex | CacheLayer coordination | DELEGATED |
| Execution lifecycle/history | `CommandStatus`, `ExecutionHistory` | MIGRATED |

There is no `FoundationConsole`, `Foundation::console()`, or `src/Console`
compatibility hierarchy.

## Terminal and interaction

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Terminal IO | `CommandIO`, `TerminalIO` | MIGRATED |
| Quiet/silent/non-interactive | global CLI parsing + `TerminalIO` | MIGRATED |
| Prompts/confirm/password/choice | Foundation command helpers | MIGRATED |
| Tables/semantic output | `TerminalIO` | MIGRATED |
| Progress/spinner/task feedback | `ProgressIndicator`/command helpers | MIGRATED |
| Width-aware rendering | `TerminalIO` | MIGRATED |
| JSON machine output | global `--json` | MIGRATED |
| Decorative Console box API | intentionally omitted | RETIRED |
| ANSI enable/disable switches without ANSI rendering | intentionally omitted | RETIRED |

Foundation keeps terminal UX proportional to actual CLI behavior rather than
recreating the old decorative surface.

## Processes

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Subprocess execution | `ProcessRunner` | MIGRATED |
| Argument-array/no-shell default | `ProcessRunner` | MIGRATED |
| cwd/environment | `ProcessOptions` | MIGRATED |
| capture/passthrough/bounded output | `ProcessRunner` | MIGRATED |
| wall/idle timeouts | `ProcessRunner` | MIGRATED |
| cancellation/heartbeat | `ProcessRunner` | MIGRATED |
| signal/termination metadata | `ProcessResult`, `ProcessTerminationReason` | MIGRATED |
| TERM -> KILL cleanup | `ProcessRunner` | MIGRATED |
| descendant/process-group cleanup | platform-aware `ProcessRunner` policy | MIGRATED |

Unix process groups are used where safe/available; Windows uses its supported
process-tree termination strategy. Foundation feature-detects platform
capabilities rather than pretending every OS has POSIX semantics.

## Scheduling

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Schedule definitions/cron | Foundation `Scheduling` | MIGRATED |
| run/work loop | `ScheduleManager` | MIGRATED |
| overlap/single-server ownership | Foundation + CacheLayer | MIGRATED/DELEGATED |
| long-run lease refresh | `ProcessRunner` heartbeat + CacheLayer refresh | MIGRATED |
| schedule cache | `bootstrap/cache/schedule.php` | MIGRATED |
| schedule execution history | `ExecutionHistory` with `schedule_identity` | MIGRATED |
| scheduled message dispatch | Omnibus | DELEGATED |
| graceful scheduler interrupt | persistent runtime-control generation | MIGRATED |

## Workers and messaging

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Maintenance worker routes | `routes/workers.php` + `WorkerProvider` | MIGRATED |
| Maintenance worker runtime | `WorkerRuntime` | MIGRATED |
| Singleton worker ownership | Foundation + CacheLayer | MIGRATED/DELEGATED |
| Queue consumer | Omnibus `Consumer` | DELEGATED |
| Single message worker | Omnibus 2.4 `Worker` | DELEGATED |
| Worker lifecycle callback | Omnibus 2.4 `WorkerLifecycle` + Foundation adapter | DELEGATED/MIGRATED |
| Process pool | Omnibus `WorkerPool` | DELEGATED |
| Retry/failure store | Omnibus | DELEGATED |
| Per-message execution isolation | Foundation execution scope | MIGRATED |
| Worker list/run/restart/status | Foundation commands | MIGRATED |

Foundation does not own a second queue/retry/failure/worker engine. External
Supervisor/systemd/Docker/Kubernetes remains responsible for daemon scaling and
replacement.

## Configuration and container

| Former capability | Foundation 2.0 result | State |
| --- | --- | --- |
| Dot/config/env parsing | ArrayKit | DELEGATED |
| Config file ordering/cache conventions | Foundation | MIGRATED |
| DI/scoped services | InterMix | DELEGATED |
| Compiled resolver | InterMix + Foundation artifact convention | DELEGATED/MIGRATED |
| Runtime cleanup | InterMix scope + `RuntimeContextTracker` | MIGRATED |

Global Foundation helpers are intentionally limited to `env()`, `env_bool()`,
`env_int()`, and `env_string()`.

## Specialist capability ownership

| Capability | Owner | Foundation role |
| --- | --- | --- |
| Cache/locks/counters/node+cluster cache | CacheLayer | application selection/policy |
| Database/query/schema/migration engine | DBLayer | application connection/migration orchestration |
| Validation | ReqShield | request/profile composition |
| Cryptography | Epicrypt | application auth/security policy |
| OTP algorithms/replay primitives | OTP | MFA application mapping |
| Filesystem/storage engine | Pathwise/Flysystem | application disks/HTTP bridges |
| HTTP/email/webhook/gRPC | TalkingBytes | named application profiles/notifications |
| Events/messages/retry/failure/workers | Omnibus | application handler/job/runtime composition |
| IDs | UID | direct native use + narrow auth ID policy |

No broad specialist Foundation facade/manager layer was retained.

## Module/schema migration

The old package-oriented module model was retired. Foundation modules are now
purpose-first (`database`, `security`, `auth`, etc.).

Public schema lifecycle is unified:

```text
module:schema:status
module:schema:install
module:schema:sync
```

Specialized duplicate `auth:schema:*` and `session:schema:*` command families
were retired. Module removal never drops schema/data.

## Operational capability closure

The current Foundation command catalog includes real handlers for application
inspection/install/readiness; configuration/cache/optimization; DB/migrations;
module config/schema lifecycle; scheduling; workers; queue failure operations;
maintenance/runtime control; execution history; environment protection;
storage; logging; auth pruning; and application generators.

Removed old command names are not preserved merely for command-count parity when
a current canonical capability supersedes them.

## Release status

Console migration parity is **closed**. Remaining Foundation 2.0 work is not a
Console-migration blocker; it is the normal documentation/public-surface freeze
and then the explicitly deferred Composer/PHPForge/static/PHPUnit/integration/
performance release verification matrix.
