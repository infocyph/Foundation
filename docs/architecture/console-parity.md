# Console Migration Parity Gate

Foundation 2.0 must not ship until every capability previously owned by `infocyph/console` is explicitly resolved as one of:

- **MIGRATED** — Foundation owns the application/runtime capability directly.
- **DELEGATED** — a specialist Infocyph library now owns the engine and Foundation only composes it.
- **RETIRED** — the capability is intentionally removed because it is redundant, obsolete, or contrary to the Foundation 2.0 architecture.

No Console capability may disappear implicitly.

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
| Command contract/base command | `Command` | PENDING |
| Command definition | `Command` | MIGRATED |
| Command descriptor/help metadata | `Command` | PENDING |
| Command registry | `Command` | PENDING |
| Command resolver | `Command` | PENDING |
| Command routes/aliases | `Command` | PENDING |
| Command execution coordinator | `Command` + `Runtime` | PENDING |
| Command execution policy | `Command` | PENDING |
| Command capability metadata | `Command` | PENDING |
| Command context | `Command` + `ExecutionScope` | PENDING |
| Exit-code model | `Command` | PENDING |
| Argument/option parsing | `Command` | PARTIAL |
| Command list/help presentation | `Command` | PENDING |
| Command manifest/cache | `CommandCacheManager` + compiled command metadata | PARTIAL |
| Command mutex/overlap | CacheLayer + Foundation command policy | PENDING |
| Command execution history/state | Foundation operational adapter | PENDING |

## Terminal and interaction

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Terminal input/output | `Command` IO | PARTIAL |
| Non-interactive/quiet modes | `Command` IO | PENDING |
| Prompt/confirm/choice/password | `Command` prompt layer | PENDING |
| Tables/lists/messages/boxes | `Command` component layer | PENDING |
| Progress bars/spinners/tasks | `Command` component layer | PENDING |
| Width/ANSI capability handling | `Command` IO | PENDING |
| JSON/machine-readable output | `Command` IO | PENDING |
| Shell completion manifest | `Command` completion | PENDING |
| Bash/Zsh/Fish completion generation | `Command` completion | PENDING |

## Processes

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Subprocess execution | `ProcessRunner` | PARTIAL |
| cwd/environment control | `ProcessRunner` | PARTIAL |
| stdout/stderr capture | `ProcessRunner` | PARTIAL |
| streaming output | `ProcessRunner` | PENDING |
| timeout | `ProcessRunner` | PARTIAL |
| cancellation/termination | `ProcessRunner` + `Runtime` | PENDING |
| signal/exit metadata | `ProcessResult` | PENDING |
| process-group cleanup | `ProcessRunner` | PENDING |
| cross-platform capability guards | `ProcessRunner` | PENDING |

## Workers and messaging

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Worker definitions/routes | Foundation `Worker` | MIGRATED |
| Worker runtime | Foundation `WorkerRuntime` | PARTIAL |
| Single-process message worker | Omnibus `Worker` + Foundation composition | DELEGATED |
| Parallel process worker | Omnibus `WorkerPool` + Foundation child bootstrap | DELEGATED |
| Queue consumer | Omnibus `Consumer` | DELEGATED |
| Retry/failure store | Omnibus | DELEGATED |
| Message uniqueness/overlap | Omnibus + CacheLayer | DELEGATED |
| Generic singleton maintenance-worker lock | Foundation + CacheLayer | PARTIAL |
| Worker process supervision | Omnibus WorkerPool or external supervisor | DELEGATED |

## Scheduling

| Console capability | Foundation 2.0 owner | State |
| --- | --- | --- |
| Application schedule definitions | `Scheduling` | MIGRATED |
| Cron parsing | `CronExpression` | MIGRATED |
| Schedule run/work loop | `Scheduling` | PARTIAL |
| Schedule overlap locks | Foundation + CacheLayer | PARTIAL |
| Scheduled message dispatch | Omnibus | DELEGATED |
| Durable schedule/message semantics | Omnibus/DBLayer where configured | DELEGATED |
| Schedule cache/manifest | `Scheduling` | PENDING |
| Schedule execution history/status | Foundation operational adapter | PENDING |

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

This includes, at minimum:

- about/readiness/install
- cache/config/container/route/command optimization commands
- database/migration/seeding commands
- module install/list/remove
- schedule list/run/work/cache/clear
- worker list/run
- session/schema/prune
- auth schema/status
- storage link
- serve
- secret generation
- artifact generation commands

## Release rule

Foundation 2.0 release is blocked while any row above is `PENDING` or `PARTIAL`, unless that row is deliberately reclassified as `RETIRED` with an explicit rationale and replacement where applicable.
