# Console Migration Parity Gate

Foundation 2.0 must not ship until every capability previously owned by `infocyph/console` is explicitly resolved as one of:

- **MIGRATED** — Foundation owns the application/runtime capability directly.
- **DELEGATED** — a specialist Infocyph library now owns the engine and Foundation only composes it.
- **RETIRED** — the capability is intentionally removed because it is redundant, obsolete, or contrary to the Foundation 2.0 architecture.

No Console capability may disappear implicitly. `PENDING` and `PARTIAL` remain release blockers unless they are deliberately reclassified.

## Application and bootstrap

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Application runtime | `Application`, `Bootstrap`, runtime entry points | MIGRATED |
| Application builder | Foundation bootstrap/composition root | MIGRATED |
| Application metadata | Foundation application/config metadata | MIGRATED |
| Console-specific container/bootstrap | ArrayKit + InterMix + Foundation bootstrap | RETIRED |

## Commands

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Command contract/base command | `Command`, `CommandHandlerInterface` | MIGRATED |
| Command definition | `CommandDefinition` | MIGRATED |
| Command descriptor/help metadata | `CommandDescriptor`, `CliPreflight` | MIGRATED |
| Command registry | `CommandRegistry` | MIGRATED |
| Command resolver | `CommandResolver` + InterMix | MIGRATED |
| Command routes/aliases | `routes/console.php` + `CommandRegistry` | MIGRATED |
| Command execution coordinator | `CommandExecutionCoordinator` + `ProcessRunner` | MIGRATED |
| Command execution policy | `CommandExecutionPolicy` | MIGRATED |
| Command capability metadata | `CommandDefinition` + lazy provider activation | MIGRATED |
| Command context | `CommandContext` + canonical `ExecutionScope` | MIGRATED |
| Exit-code model | `ExitCode` | MIGRATED |
| Argument/option parsing | `ParsedInput` + definition-aware metadata | MIGRATED |
| Command list/help presentation | `CliPreflight` | MIGRATED |
| Command manifest/cache | `CommandCacheManager` + scalar command manifests | MIGRATED |
| Command mutex/overlap | CacheLayer + `CommandExecutionCoordinator` | MIGRATED |
| Command lifecycle state model | `CommandStatus` | MIGRATED |
| Command execution history persistence | Foundation operational adapter | PENDING |

System commands are grouped into internal Foundation handlers by application domain rather than one class per command. The catalog owns their definitions and maps every advertised built-in command to a real handler. Optional package providers are activated from command capability metadata before the handler is resolved.

## Terminal and interaction

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Terminal input/output | `CommandIO`, `TerminalIO` | MIGRATED |
| Non-interactive/quiet modes | `TerminalIO` + global CLI options | MIGRATED |
| Prompt/confirm/choice/password | `CommandIO`, `TerminalIO`, `Command` helpers | MIGRATED |
| Tables and semantic messages | `CommandIO`, `TerminalIO` | MIGRATED |
| Decorative boxes | Plain semantic/table output | RETIRED |
| Progress bars/spinners/tasks | Foundation command component layer | PENDING |
| Width/ANSI capability handling | `TerminalIO` | PENDING |
| JSON/machine-readable output | `TerminalIO` + global `--json` | MIGRATED |
| Shell completion manifest | compiled command manifest | MIGRATED |
| Bash/Zsh/Fish completion generation | `CompletionGenerator` | MIGRATED |

Decorative box rendering is retired deliberately: it adds terminal-specific surface without improving application semantics. Rich progress/task feedback remains a separate capability because it is useful for genuinely long-running interactive commands.

## Processes

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Subprocess execution | `ProcessRunner` | MIGRATED |
| Argument-array/no-shell default | `ProcessRunner` | MIGRATED |
| cwd/environment control | `ProcessOptions`, `ProcessRunner` | MIGRATED |
| stdout/stderr capture | `ProcessRunner` | MIGRATED |
| streaming output/callbacks | `ProcessRunner` | MIGRATED |
| bounded output | `ProcessOptions`, `ProcessRunner` | MIGRATED |
| timeout/idle-timeout | `ProcessRunner` | MIGRATED |
| cancellation/heartbeat termination | `ProcessRunner` | MIGRATED |
| signal/exit/termination metadata | `ProcessResult`, `ProcessTerminationReason` | MIGRATED |
| graceful TERM-to-KILL cleanup | `ProcessRunner` | MIGRATED |
| process-group/descendant cleanup | `ProcessRunner` | PENDING |
| cross-platform capability guards | `ProcessRunner` | PARTIAL |

`pcntl` signal handling is optional and feature-detected. The remaining process work is specifically descendant/process-group ownership and documenting/guarding the best achievable behavior on Windows rather than creating another generic process framework.

## Workers and messaging

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Maintenance-worker definitions/routes | Foundation `Worker` | MIGRATED |
| Maintenance-worker runtime/execution scopes | Foundation `WorkerRuntime` + `ExecutionScope` | MIGRATED |
| Single-process message worker | Omnibus `Worker` + Foundation composition | DELEGATED |
| Parallel process worker | Omnibus `WorkerPool` + Foundation post-fork child bootstrap | DELEGATED |
| Queue consumer | Omnibus `Consumer` | DELEGATED |
| Retry/failure store | Omnibus | DELEGATED |
| Message uniqueness/overlap | Omnibus + CacheLayer | DELEGATED |
| Singleton maintenance-worker lock/heartbeat | Foundation + CacheLayer | MIGRATED |
| Worker process supervision | Omnibus WorkerPool or external supervisor | DELEGATED |
| Per-message runtime isolation/reset | Foundation canonical `ExecutionScope` | MIGRATED |
| Worker CLI list/run/consume commands | Foundation `Command` + Omnibus | MIGRATED |

## Scheduling

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Application schedule definitions | `Scheduling` | MIGRATED |
| Cron parsing | `CronExpression` | MIGRATED |
| Schedule run/work loop | `ScheduleManager` | MIGRATED |
| Schedule overlap locks | Foundation + CacheLayer | MIGRATED |
| Scheduled message dispatch | Omnibus | DELEGATED |
| Durable schedule/message semantics | Omnibus/DBLayer where configured | DELEGATED |
| Schedule cache/manifest | `ScheduleManager` | MIGRATED |
| Schedule CLI list/run/work/cache/clear | Foundation `Command` | MIGRATED |
| Schedule execution history/status persistence | Foundation operational adapter | PENDING |

## Configuration and container

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Dot/config mechanics | ArrayKit | DELEGATED |
| Dotenv parsing/environment expansion | ArrayKit | DELEGATED |
| Lazy config loading | ArrayKit | DELEGATED |
| Compiled config | ArrayKit + Foundation cache conventions | DELEGATED |
| DI/scoped services | InterMix | DELEGATED |
| Compiled container | InterMix + Foundation cache conventions | DELEGATED |
| Runtime scope reset | InterMix + Foundation external reset tracker | PARTIAL |

## Cache, database, validation and security

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Cache stores | CacheLayer | DELEGATED |
| Locks/mutexes | CacheLayer | DELEGATED |
| Atomic counters | CacheLayer | DELEGATED |
| Node/cluster cache | CacheLayer | DELEGATED |
| Database connection/query/schema/migration engine | DBLayer | DELEGATED |
| Foundation DB configuration conventions | Foundation `Database` | MIGRATED |
| Database/auth-schema/migration/seeding CLI | Foundation `Command` + DBLayer | MIGRATED |
| Validation/sanitization/schema compilation | ReqShield | DELEGATED |
| Foundation validation adapters | Foundation `Validation` | PARTIAL |
| Cryptography/password/token primitives | Epicrypt | DELEGATED |
| OTP math/replay primitives | OTP | DELEGATED |
| Authentication workflows | Foundation `Auth` | MIGRATED |

## Filesystem and communication

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Application paths | Foundation `PathManager` | MIGRATED |
| Generic filesystem/storage workflows | Pathwise | DELEGATED |
| Storage-link/application HTTP bridges | Foundation `Filesystem` | MIGRATED |
| HTTP client | TalkingBytes | DELEGATED |
| Outbound/inbound email | TalkingBytes | DELEGATED |
| Webhook protocol | TalkingBytes | DELEGATED |
| gRPC client/server dispatch | TalkingBytes | DELEGATED |

## Operational commands

Every command removed from the old `src/Console/Command` tree must be represented in the Foundation command catalog and have one of these outcomes:

1. implemented as a Foundation command handler;
2. delegated to a current package operation through a thin Foundation command handler; or
3. explicitly retired with a documented replacement.

The current catalog has real handlers for:

- about/readiness/install/environment/serve/secret generation;
- cache/config/command/container/route/schedule optimization operations;
- database/migration/seeding/auth-schema commands;
- module install/list/remove;
- artifact generation commands;
- schedule list/run/work/cache/clear and durable scheduled-message dispatch;
- worker list/run and bounded queue consume;
- session schema/prune;
- storage links.

Generated artifacts are also part of the boundary: Foundation stubs must not reintroduce `Infocyph\Console\*` or `Infocyph\Foundation\Console\*` dependencies.

## Remaining Console-parity blockers

The absorbed Console surface is now narrowed to these genuine remaining source capabilities:

1. command/schedule execution history persistence and query/reporting adapter;
2. progress/spinner/task UI for long-running interactive commands;
3. terminal width/ANSI capability handling;
4. process-group/descendant cleanup plus final Windows capability policy;
5. final canonical runtime reset audit across the remaining optional integrations.

Validation integration remains a broader Foundation package-integration task rather than a Console implementation concern, but it is still a Foundation 2.0 release blocker while marked `PARTIAL` above.

## Release rule

Foundation 2.0 release is blocked while any row above is `PENDING` or `PARTIAL`, unless that row is deliberately reclassified as `RETIRED` with an explicit rationale and replacement where applicable.
