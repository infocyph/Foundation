# Foundation 2.0 — Live Work Plan

> `foundation_plan.md` is historical architecture/reference material. This file is the evidence-driven source of truth for current Foundation 2.0 implementation and release closure.

## Working branches

- Foundation: `feature/foundation-2.0`
- Infbyte: `feature/foundation-2.0`

## Active closure run

- Started: 2026-08-24 (Asia/Dhaka)
- Original closure checkpoint: `16d60f114314544a5c6db91c0e986423fa6fbb70`
- Dependency-rebaseline checkpoint: `6663dad26e75453fcebb7975dda2ad0b49661951`
- Latest verified implementation checkpoint: `2cd7060717e2ec9352a9a1cac60e8c992d481851`
- Authoritative Security & Standards run: `32819176255`
- Authoritative dedicated PHPStan run: `32819175905`
- Current phase: **optimization/operations/environment safety closure, then soak/baseline and final Foundation/Infbyte release audit**.
- Architecture/public ownership boundaries are frozen. Correct integration defects, tests, diagnostics and docs only; do not restore retired convenience APIs or duplicate specialist engines.
- Final finish condition: updated dependencies resolve, PHPUnit/PHPForge/static-analysis matrices are green, specialist/runtime/security/process/deployment behavior is evidenced, Infbyte is aligned, benchmarks remain acceptable, and every checklist item below has explicit evidence.

## Dependency baseline

### Core

- PHP `^8.4`
- `composer-runtime-api ^2.0`
- `infocyph/arraykit ^5.1.1`
- `infocyph/intermix ^9.2`
- `infocyph/uid ^5.0`
- `infocyph/webrick ^4.0.2`
- `psr/log ^3.0.2`

### Optional/dev capabilities

- `infocyph/cachelayer ^3.2.0`
- `infocyph/dblayer ^5.0`
- `infocyph/epicrypt ^2.1`
- `infocyph/omnibus ^2.5`
- `infocyph/otp ^6.0`
- `infocyph/pathwise ^3.1`
- `infocyph/phpforge dev-main@dev`
- `infocyph/reqshield ^3.1`
- `infocyph/talkingbytes ^2.0`
- `web-auth/webauthn-lib ^5.3.5`

`ModuleCatalog` must stay aligned with Composer. Stable refs were verified directly for DBLayer `5.0`, Omnibus `2.5`, and ReqShield `3.1`.

# Frozen architecture

Exactly four runtimes exist: Web, CLI, Worker, Scheduler.

Specialist ownership remains:

- InterMix — DI/lifetimes/scopes;
- Webrick — HTTP/router/request/response/emission;
- UID — identifiers;
- DBLayer — database/query/schema/migration/transaction infrastructure;
- CacheLayer — cache/locks/counters/shared coordination;
- Omnibus — message transport/handlers/retry/failure/workers/pools/workflows;
- TalkingBytes — HTTP/email/webhook/gRPC;
- ReqShield — validation;
- Epicrypt — cryptographic primitives;
- Pathwise/Flysystem — filesystem/storage mechanics;
- OTP — OTP/replay;
- WebAuthn library — WebAuthn protocol.

`Application` stays narrow: boot/runtime state, config/container/providers, generic DI, execution coordination, canonical Web handling and application paths. Do not add broad auth/cache/database/filesystem/messaging/security/testing/response facades.

Purpose-first public modules remain `auth`, `cache`, `communication`, `database`, `filesystem`, `logging`, `messaging`, `operations`, `resources`, `security`, `session`, `validation`. No standalone OTP/passkey modules.

Module removal never deletes schema/data. Schema ownership remains auth → Foundation auth schema, cache → CacheLayer provisioners, session → Foundation session schema.

## Verified release evidence — 2026-08-25

### Dependency/specialist compatibility

- Composer and `ModuleCatalog` target DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
- ReqShield DB validation physically chunks queries through DBLayer 5 `safeBatchSize()` without changing ReqShield's public contract.
- DBLayer 5 migration/pretend/status/rollback/fresh/refresh/reset/wipe/monitor behavior is executed and green.
- destructive DB commands are tested through the real dispatcher and require explicit authorization/`--force` in non-interactive and production-sensitive paths.
- module lifecycle/config/schema install/sync/remove/dry-run/rollback behavior is executed and preserves application config/schema/data ownership.

### Optional capability isolation

Security run `32812503732`, PHPStan `32812503419`:

- dependency-free base boot is healthy;
- optional packages are not eagerly loaded;
- unavailable managed capabilities return stable install guidance;
- OTP/WebAuthn fail explicitly only when their drivers are selected;
- notification core stays available without forcing TalkingBytes email bindings.

### Web runtime

Security run `32812796414`, PHPStan `32812795816`:

- route files/cache/middleware/maintenance/exception handling are green;
- InterMix request scopes reset per request;
- principal/session/DB state is isolated, including rollback/failure and concurrent-fiber paths;
- optional auth adapters remain lazy.

### CLI runtime

Security run `32813737909`, PHPStan `32813737450`:

- discovery/cache fallback, aliases, help/version/completion, descriptor parsing, global options and machine-readable output are green;
- handler exit/exception behavior and execution history are green;
- overlap cancellation uses shared lock ownership;
- destructive confirmation is independently exercised through the real dispatcher.

### Worker runtime

Security run `32814167565`, PHPStan `32814167162`:

- fresh execution scopes per provider/message unit;
- restart/heartbeat/process registry cleanup;
- singleton lock ownership/refresh;
- pool configuration/fork-before-resource-open guards;
- current Omnibus `^2.5` diagnostics.

### Pool/fork safety

Security run `32814512066`, PHPStan `32814511676`:

- pre-fork resolved cache, opened DB and TalkingBytes HTTP resources are rejected;
- process-local transports/runtime-bearing config are rejected for pools;
- real non-skipped `pcntl` child creation, child-only init, SIGTERM shutdown, reap and signal-handler restoration are verified.

### Scheduler runtime

Security run `32815333061`, PHPStan `32815332711`:

- fresh Scheduler execution scopes;
- real `schedule:run`, `schedule:test`, `schedule:work`, `schedule:interrupt`, `runtime:reload`, and `schedule:dispatch-message` paths;
- deterministic due filtering, subprocess non-zero/timeout history, overlap cancellation, control-token interruption and registry cleanup;
- scheduled subprocesses use argument-list process execution rather than shell execution.

### Persistent execution-state isolation

Security run `32816540790`, PHPStan `32816540419`:

- InterMix scoped services, principal/session state, DB transaction/runtime state, CacheLayer Memoizer/OnceMemoizer and Omnibus execution seeds are isolated between persistent units;
- the same cleanup is verified after exceptions.

### Auth/session/token and advanced auth

Security run `32818552123`, PHPStan `32818551519`:

- account/password/login, email verification notification/token, auth-session rotation/logout, password change/reset/replay, passwordless, remember-me, access/refresh token rotation/family revoke/replay, lockout/unlock;
- browser session persistence/flash/regeneration/CSRF/file/cache/database error paths/locks/fiber isolation;
- permissions/roles/delegation/gates/audit, devices, MFA/recovery, HOTP/OCRA replay rejection, passkeys/WebAuthn fail-closed behavior, impersonation and explicit recent-auth/step-up semantics.

### Production security posture

Security run `32818552123`, PHPStan `32818551519`:

- production rejects development/weak token secrets, memory auth persistence, process-local auth state, weak password minimums, unsafe auth notification delivery and insecure remote WebAuthn origins;
- Redis/Valkey atomic counters are required for auth lockout state;
- distributed cache/challenge/lock/OTP replay state must be cluster-visible;
- distributed DB-backed auth persistence must be cluster-visible: host-local SQLite is rejected while cluster-visible DB persistence is accepted;
- secure single-node and distributed configurations pass production/readiness validators.

### Omnibus 2.5 release matrix

Security run `32819176255`, dedicated PHPStan `32819175905` are fully green across PHP 8.4/8.5 lowest/stable, analyzers, clean install and both benchmark validators.

`MessagingIntegrationTest`, `Omnibus25FailureCompatibilityTest`, `Omnibus25WorkerLifecycleCompatibilityTest`, `OmnibusWorkerIntegrationTest`, and `Omnibus25ReleaseClosureTest` verify:

- dispatch/consume and route handling through Omnibus;
- fresh Foundation/InterMix execution scope for successful and failed message handling;
- retry/release/failure storage behavior and retry-claim/send/removal;
- failure list/show/prune/forget/flush semantics;
- real `queue:failed`, `queue:failed:show`, `queue:retry`, `queue:monitor`, `queue:prune-failed`, `queue:forget`, and `queue:flush` system-command paths through fresh `CommandDispatcher` applications;
- queue monitoring reports actual Omnibus transport size;
- destructive failure flush refuses non-interactively without `--force` and succeeds with it;
- Worker/WorkerOptions and Foundation-to-Omnibus lifecycle callbacks;
- a real message worker consumes one queued message, receives named `worker:jobs` restart control from the handler, exits gracefully before consuming the next message, unregisters its process record, and leaves later work queued;
- existing pool/fork evidence remains owned by Omnibus rather than duplicated in Foundation.

## Authoritative QA baseline

Verified implementation checkpoint: `2cd7060717e2ec9352a9a1cac60e8c992d481851`.

Security & Standards `32819176255`:

- matrix preparation: PASS;
- clean production install: PASS;
- PHP 8.4 analysis: PASS;
- PHP 8.5 analysis: PASS;
- PHP 8.4 prefer-lowest QA: PASS;
- PHP 8.4 prefer-stable QA: PASS;
- PHP 8.5 prefer-lowest QA: PASS;
- PHP 8.5 prefer-stable QA: PASS;
- PHP 8.4 representative benchmark validation: PASS;
- PHP 8.5 representative benchmark validation: PASS;
- benchmark comparison: skipped because no baseline artifact is configured;
- Security SVG report: skipped and not a release gate.

QA uses `fail_on_skipped_tests: true` and enforces Pest, Pint, PHPCS, PHPProbe, Deptrac, Rector and Composer Normalize. Analyzer jobs enforce PHPStan and Psalm. Dedicated PHPStan `32819175905` passed.

# 32-point verification / release closure checklist

1. [x] Freeze Foundation 2.0 architecture/public ownership boundaries.
2. [x] Freeze narrow `Application` API and remove retired convenience proxies/managers.
3. [x] Align Composer capability baseline to DBLayer `^5.0`, Omnibus `^2.5`, ReqShield `^3.1`.
4. [x] Align `ModuleCatalog` constraints with Composer baseline.
5. [x] Normalize PHPForge reusable-workflow configuration to repository-specific overrides only.
6. [x] Production clean-install gate passes on the current dependency set (`32819176255`).
7. [x] PHP 8.4 representative benchmark validation passes (`32819176255`).
8. [x] PHP 8.5 representative benchmark validation passes (`32819176255`).
9. [x] Psalm passes on PHP 8.4 and PHP 8.5 analyzer jobs (`32819176255`).
10. [x] Deptrac passes across the current QA matrix (`32819176255`).
11. [x] Current syntax/PHPProbe/Pest blocking defects are cleared (`32819176255`).
12. [x] DBLayer 5 migration/rollback/status/reset/refresh/wipe/monitor compatibility matrix is green.
13. [x] Destructive database safeguard/confirmation matrix is green through the real CLI dispatcher.
14. [x] Module list/show/install/remove/config-publish/schema-status/schema-install/schema-sync plus dry-run/duplicate/failure rollback behavior is green.
15. [x] Optional capability isolation, dependency-free base boot and graceful unavailable-capability errors are green (`32812503732`, `32812503419`).
16. [x] Web routes/cache/middleware/session/auth-principal/maintenance/exception behavior is green (`32812796414`, `32812795816`).
17. [x] CLI discovery/cache/status/exit/overlap/global options/help/completion/machine-readable/destructive-confirmation behavior is green (`32813737909`, `32813737450`).
18. [x] Worker scope reset/restart/heartbeat/singleton/pools/fork-before-resource-open behavior is green (`32814167565`, `32814167162`).
19. [x] Scheduler once/work/interrupt/runtime-reload/overlap/scheduled-message behavior is green without shell execution (`32815333061`, `32815332711`).
20. [x] Persistent execution-state isolation across InterMix/principal/session/DB/cache/messaging is green on success and failure paths (`32816540790`, `32816540419`).
21. [x] Pool/fork safety is green: no pre-fork DB/network/cache resources and real child init/termination/reaping/signal restoration are verified (`32814512066`, `32814511676`).
22. [x] Full auth/session/token/password/email/passwordless/lockout flows are green (`32818552123`, `32818551519`).
23. [x] MFA recovery/passkey/step-up/recent-auth/authorization/impersonation flows are green (`32818552123`, `32818551519`).
24. [x] Production security posture is green for secrets, secure defaults, OTP/WebAuthn/shared-state topology and unsafe-memory rejection (`32818552123`, `32818551519`).
25. [x] Omnibus 2.5 dispatch/consume/retry/failure/prune/monitor/execution-scope/shutdown/restart matrix is green (`32819176255`, `32819175905`).
26. [ ] Verify config/route/command/schedule/container optimize/clear idempotency.
27. [ ] Verify maintenance/runtime reload/worker restart/scheduler interrupt/process-registry/status/stale cleanup.
28. [ ] Verify env encrypt/decrypt/temp-file/permissions/overwrite and storage link/unlink safety.
29. [x] Pint/Rector/Composer Normalize/PHPCS/PHPStan/Psalm/Deptrac are green on the current PHP 8.4/8.5 lowest/stable matrix with `fail_on_skipped_tests: true` (`32819176255`, `32819175905`).
30. [ ] Review soak-sensitive persistent-runtime paths and establish/compare a representative benchmark baseline where appropriate.
31. [ ] Final dependency/package/stale-version/retired-API audit plus Foundation/Infbyte stable-release alignment.
32. [ ] Record final source/CI checkpoints and all verified results with zero ambiguous closure items.
