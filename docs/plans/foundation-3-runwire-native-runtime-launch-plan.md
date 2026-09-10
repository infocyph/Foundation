# Foundation 3 — Runwire 1.0 Native Runtime Launch Plan

## Status

**Proposed canonical point:** 26.12 — Runwire 1.0 Native Process & Server Runtime  
**Foundation branch:** `foundation-3/runwire-launch-plan`  
**Runwire target:** `infocyph/runwire:^1.0`  
**Webrick integration:** native `RunwireRuntimeAdapter`  
**Messaging integration:** Omnibus delegates generic process supervision to Runwire  
**Priority:** correctness → security/process ownership → persistent-runtime isolation → performance → scalability → operational ergonomics

This document is an explicit addendum to `docs/plans/foundation-3-final-runtime-development-plan.md`. Before Foundation 3 final release, its accepted decisions and completion gates must be reconciled into that canonical plan/tracker.

Runwire launches together with Foundation 3 as Foundation's native long-running process and network runtime. Webrick remains the sole application-facing HTTP runtime owner. Omnibus remains the messaging/queue semantics owner. Foundation remains the application composition, execution-scope, release-generation and deployment-policy owner.

---

# 1. New ownership map

Foundation's lower-library ownership map becomes:

```text
InterMix     DI graph/scopes/generated container
Webrick      HTTP application request/response/routing/middleware
Runwire      process/supervisor/event-loop/network/server mechanics
DBLayer      DB runtime/query/pool/transaction mechanics
CacheLayer   cache/locks/atomic coordination
Pathwise     filesystem/storage/upload trust boundaries
ReqShield    validation/sanitization/schema mechanics
Omnibus      messaging/events/queues/consumer/retry/workflow semantics
TalkingBytes outbound/inbound communication protocol mechanics
OTP          OTP/Passkey mechanics
Epicrypt     cryptography/auth protocol core
Foundation   application policy/composition/release/execution adaptation
```

Hard invariant:

> Foundation must not retain a second generic process supervisor, network event loop, native socket server, shell runner or OS process-management implementation above Runwire once Runwire 1.0 owns that mechanism.

---

# 2. Runwire is below Webrick, not a replacement for Webrick

Native web path:

```text
Foundation CLI / release selection
        ↓
Runwire Runtime + Supervisor
        ↓
Runwire TCP/TLS + HTTP/1 transport
        ↓
Webrick RunwireRuntimeAdapter
        ↓
Foundation fresh web execution scope
        ↓
Webrick compiled kernel
        ↓
Webrick Response
        ↓
Runwire response writer
```

Runwire owns bytes/connections/processes. Webrick owns HTTP application semantics.

Do not move into Runwire:

- routes;
- middleware;
- controllers;
- Webrick `Request`/`Response`;
- content negotiation;
- application cookies/security headers;
- application error rendering.

---

# 3. Native Foundation server

Foundation should expose a native serving path through its existing/selected CLI conventions, conceptually:

```bash
php foundation serve
```

Foundation command/config layer owns:

- host/port selection;
- Runwire runtime profile selection;
- worker count policy/defaults;
- selected Foundation release generation;
- compiled Webrick/InterMix artifact selection;
- logging/telemetry integration;
- operational status/control presentation;
- application-specific health/readiness policy.

Runwire owns the actual:

- bind/listen;
- fork/spawn;
- event loop;
- signal/wait/reap;
- connection accept/read/write;
- generic worker restart/reload/shutdown;
- transport limits/backpressure.

Foundation should not wrap these with another process engine.

---

# 4. Composer/release policy

During coordinated development, Foundation may use an explicit development Runwire constraint only on integration branches.

Final Foundation 3 release requirements:

```json
"infocyph/runwire": "^1.0"
```

The final release must not depend on a branch/dev alias for Runwire.

Webrick may keep Runwire optional for standalone installations; Foundation installs both because Runwire is Foundation's native server runtime.

---

# 5. Trusted master pre-fork cleanliness

Foundation already has process-bound services that must not be inherited from an application-booted master.

Before Runwire forks Foundation application workers, the master must not resolve/open process-bound application resources such as:

- DBLayer connections/leases/PDO;
- CacheLayer network clients/locks that are not fork-safe;
- Redis/Valkey clients;
- Omnibus durable transport connections;
- TalkingBytes outbound connection pools;
- application sockets;
- mutable transaction/session state;
- InterMix scoped application state.

Preferred lifecycle:

```text
minimal Foundation launch/bootstrap
        ↓
validate release/config/artifacts
        ↓
configure Runwire listeners/worker groups
        ↓
Runwire binds intended inheritable listeners
        ↓
Runwire forks child
        ↓
child signal/process normalization
        ↓
boot fresh Foundation worker application
        ↓
open child-owned DB/cache/broker resources
        ↓
serve/consume
```

Keep and evolve Foundation's existing `assertPoolParentClean()` / fork-safety checks where they know Foundation-specific container/config state. Runwire cannot inspect arbitrary host containers to prove this.

---

# 6. Application execution scopes remain Foundation-owned

Do not map Runwire worker lifetime onto Foundation request lifetime.

Stable Foundation scopes remain:

```text
webrick.request
foundation.cli
foundation.worker
foundation.scheduler
```

For native HTTP:

```text
one Runwire worker process
    ├─ request A -> fresh Foundation/web execution A -> cleanup
    ├─ request B -> fresh Foundation/web execution B -> cleanup
    └─ request C -> fresh Foundation/web execution C -> cleanup
```

Prove no principal/session/request/input/DB transaction/validation state leaks between keep-alive requests or Fibers.

---

# 7. Foundation process mechanics to move downward

Rescan all Foundation direct uses of:

```text
pcntl_*
posix_*
proc_open
popen
exec
system
shell_exec
passthru
```

Classify every site.

Generic mechanics should move to Runwire, including where currently Foundation-owned solely because no lower package exists:

- generic child spawn/fork;
- PID tracking;
- signal registration/dispatch;
- wait/reap;
- generic process-group/session operations;
- graceful terminate → forced kill;
- generic restart/backoff;
- generic worker generation/reload;
- bounded process I/O;
- timeout/output limits;
- structured executable + argv execution.

Foundation retains application/deployment policy:

- `RuntimeProcessRegistry`;
- `RuntimeControl` semantics;
- release-generation identity/activation;
- desired application process roles;
- application readiness/heartbeat meaning;
- runtime configuration/defaults;
- authorization to invoke registered privileged operations;
- mapping Runwire status into Foundation diagnostics/CLI.

---

# 8. Remove Foundation's Omnibus SIGALRM watchdog duplication

The current Foundation Omnibus parent supervision workaround must not survive the final Runwire/Omnibus architecture.

Current generic behavior such as:

```text
Foundation SIGALRM
  -> heartbeat
  -> stopRequested
  -> Omnibus WorkerPool::requestStop()
```

should be replaced by:

```text
Foundation application lifecycle/control adapter
        ↓
Omnibus queue Worker semantics
        ↓
Runwire generic Supervisor
```

Foundation supplies application-specific lifecycle state; it does not install a second signal timer just to wake a queue pool.

Coordinate this with Omnibus 2.6.

---

# 9. Omnibus relationship

Foundation Point 26.8 should target the Runwire-aligned Omnibus 2.6 plan rather than finalizing a duplicate process supervisor.

Final ownership:

```text
Foundation
  application messaging configuration / runtime control
          ↓
Omnibus
  queue/message Worker semantics
          ↓
Runwire
  OS process worker-group supervision
```

Omnibus owns retry/failure/settlement/prefetch/message recycle decisions. Runwire owns process fork/wait/reap/signals/restart/termination.

Foundation should not call raw `pcntl` around an Omnibus pool after this migration.

---

# 10. Scheduler and other long-running worker groups

Evaluate reusing Runwire's generic supervisor for Foundation scheduler/daemon process groups where this removes existing duplicate OS process mechanics.

Potential topology:

```text
Runwire Supervisor
   ├─ web group       -> Webrick/Foundation
   ├─ queue group     -> Omnibus/Foundation
   ├─ scheduler group -> Foundation scheduler callback
   └─ trusted custom group
```

Do not force these workloads into the same PHP worker or event loop. They may remain separate OS process groups with separate application boot/lifetime policies.

Foundation decides which groups exist; Runwire merely supervises them.

---

# 11. Structured privileged operations

For application features that need process execution, Foundation should expose **registered operations**, not arbitrary shell commands.

Correct conceptual flow:

```text
user/application input
        ↓
ReqShield validates operation ID + structured arguments
        ↓
Foundation authorizes capability
        ↓
Foundation resolves any Pathwise-managed artifact safely
        ↓
Foundation maps operation ID to trusted Runwire command definition
        ↓
Runwire executes executable + argv under selected execution policy
```

Example operation identifiers:

```text
image.thumbnail
pdf.inspect
git.status
```

Do not expose:

```text
command = "anything the requester typed"
```

as a normal Foundation API.

---

# 12. ReqShield boundary

ReqShield validates data/intent only.

Foundation must not ask ReqShield to make shell/PHP code "safe" by blocking substrings such as:

```text
exec
system
shell_exec
proc_open
pcntl_fork
posix_kill
```

Those are ordinary strings until interpreted/executed.

ReqShield can validate a registered operation identifier and its bounded structured arguments. Foundation authorizes. Runwire executes.

No Runwire dependency is required in ReqShield.

---

# 13. Pathwise boundary

Pathwise owns filesystem/upload containment and data-only storage safety.

For an uploaded/stored artifact:

```text
Webrick input
    ↓
Foundation upload policy
    ↓
Pathwise materialization/validation/scan/storage
    ↓
Foundation authorization
    ↓
Runwire registered process operation, if intentionally needed
```

Never use an upload path directly as a PHP `include`, script filename or shell fragment.

Runwire does not replace Pathwise root containment, symlink/race policy, archive limits or scanner contracts.

If an external executable malware scanner is needed, Foundation/application may implement Pathwise's scanner contract using Runwire's structured process runner. Pathwise itself remains independent of Runwire.

---

# 14. Trusted workers vs untrusted code execution

Foundation's normal Runwire web/worker/scheduler children execute **trusted deployed application code**.

A `pcntl_fork()` child is not an untrusted-code sandbox because it inherits the loaded PHP runtime/capabilities.

If Foundation intentionally supports uploaded/user-controlled scripts/plugins as executable code, route them through a separate execution profile:

```text
Foundation authorization
      ↓
Runwire structured spawned process
      ↓
separate PHP binary/php.ini / sandbox launcher
      ↓
dedicated UID/GID
      ↓
OS isolation
  seccomp / AppArmor / SELinux / namespaces / container / stronger boundary
```

Never execute hostile uploaded PHP in the normal persistent Runwire application worker.

---

# 15. PHP runtime capability separation

Document recommended deployment profiles conceptually:

```text
foundation-supervisor.ini
    trusted process supervision capabilities

foundation-worker.ini
    capabilities needed by trusted application workers only

foundation-untrusted-executor.ini
    separately restricted runtime used only behind OS sandbox
```

Foundation startup diagnostics should surface missing Runwire capabilities required by selected runtime features.

Do not use `disable_functions` alone as a claimed sandbox.

Do not run the long-lived Foundation master as root merely because Runwire exposes POSIX identity APIs.

If privileged bind/bootstrap is needed, keep it minimal and drop privileges before application processing wherever architecture permits.

---

# 16. Server configuration

Add a compact Foundation-owned configuration surface mapping into frozen Runwire definitions.

Potential configuration categories:

```text
server.enabled
server.host
server.port
server.workers
server.loop
server.backlog
server.tls
server.connections.max
server.timeouts.*
server.http.* hard/selected limits
server.reload.*
```

Do not mirror every Runwire internal option into Foundation configuration.

Foundation should expose stable application/deployment knobs and allow an advanced trusted configuration extension only where needed.

Validate incompatible Webrick/Runwire limits at startup.

---

# 17. Persistent HTTP request cleanup

Native server acceptance must prove cleanup on:

- normal response;
- exception;
- 404/405;
- middleware rejection;
- streaming response completion;
- client disconnect during request body;
- client disconnect during response;
- timeout/cancellation;
- worker drain/reload.

Every started Foundation request execution is closed exactly once.

No request-scoped DB transaction, cache lock, auth principal, validation context or session state remains reachable after cleanup.

---

# 18. After-response semantics

Review Foundation/Omnibus after-response behavior under native Runwire output.

Distinguish:

```text
Webrick Response produced
Runwire response queued
Runwire response flushed/completed
client disconnected
```

Define Foundation's after-response boundary intentionally.

For work that must happen after application response production but need not wait for all client bytes, document that semantics. For transport completion-sensitive work, use explicit Runwire/Webrick completion state.

Do not make DB transaction correctness depend on a client successfully reading all response bytes.

---

# 19. Release generation and Runwire worker generation

Keep identities separate:

```text
Foundation release generation
    application artifacts/config/release identity

Runwire worker generation
    supervisor process replacement/reload identity
```

Foundation may annotate/map a worker generation to the Foundation release it booted, but Runwire must not parse Foundation release manifests.

For release activation:

- build/validate new Foundation release;
- ask Runwire/Foundation supervisor policy to start replacement worker generation using new release;
- verify readiness;
- drain old generation;
- enforce grace deadline;
- terminate old generation;
- preserve rollback capability according to existing Foundation release policy.

Do not overwrite Foundation's existing release-generation semantics with a generic Runwire reload ID.

---

# 20. Runtime process registry integration

Keep `RuntimeProcessRegistry` Foundation-owned because it expresses Foundation release/application process identity.

Adapt Runwire lifecycle events/status into it rather than duplicating Runwire's child table manually.

Foundation registry may record:

- role/group;
- PID;
- Runwire worker generation;
- Foundation release generation;
- heartbeat/readiness;
- started/draining/stopping state.

Runwire remains authoritative for its actual live child/process state; Foundation remains authoritative for application/release meaning.

Avoid two independent sources both claiming ownership of generic child liveness.

---

# 21. Control plane

Foundation CLI should present user-facing operational commands, while Runwire supplies generic runtime control/status primitives.

Potential Foundation UX:

```text
serve
server:status
server:reload
server:stop
```

Exact command names should follow existing Foundation console conventions.

Foundation can translate these into Runwire control calls while adding release/application context.

Do not expose a Foundation command that forwards arbitrary Runwire control payloads or shell commands from untrusted input.

---

# 22. Webrick adapter integration

Foundation should consume Webrick's Runwire adapter rather than create its own parallel Runwire→HTTP request converter.

Hard rule:

> There must be one generic Runwire↔Webrick adaptation in Webrick, and Foundation only supplies application execution/composition around it.

Foundation may implement a request execution callback that:

1. starts `webrick.request` execution scope;
2. binds request/correlation state;
3. invokes compiled Webrick kernel;
4. maps application exceptions according to Foundation policy;
5. cleans execution state in `finally`;
6. returns Webrick response to adapter/Runwire writer.

---

# 23. Existing Workerman/Swoole/RoadRunner support

Do not remove Webrick compatibility adapters.

Foundation's **native/default persistent server** can be Runwire while advanced deployments may still integrate Webrick through another supported runtime where Foundation explicitly supports that mode.

Do not make Foundation's application semantics depend on Runwire-specific request objects.

Runtime portability remains a useful correctness check even though Runwire is the native path.

---

# 24. Omnibus/Runwire process groups

When Foundation runs queue workers under Runwire:

```text
Runwire child process
   ↓
boot Foundation worker app
   ↓
construct child-owned DB/cache/broker resources
   ↓
construct Omnibus Worker
   ↓
Omnibus consumes messages
```

Queue reservation occurs using the worker-process-owned transport before per-message Foundation execution scope as Omnibus requires.

Each message handler still gets a fresh Foundation `foundation.worker` execution.

Do not resolve DB/cache/broker resources in the Runwire master and inherit them into queue children.

---

# 25. Process operation registry

Foundation may add a small application-owned registry/policy mapping operation IDs to Runwire command definitions.

Requirements:

- registry is built from trusted application/config code;
- frozen before normal runtime handling;
- no user-controlled executable path;
- executable path/identity is trusted configuration;
- arguments are built structurally;
- environment keys are filtered;
- cwd is trusted/restricted;
- timeout/output/resource profile is mandatory or has secure defaults;
- operation authorization occurs before Runwire invocation;
- audit metadata records operation identity, not secrets/full argv by default.

Do not create another process runner inside Foundation; registry resolves policy and calls Runwire.

---

# 26. Security test matrix

Add Foundation integration tests proving:

- harmless request data containing `exec(` / `pcntl_fork` / shell-looking text remains data;
- unregistered process operation cannot execute;
- registered operation with invalid ReqShield args is rejected before Runwire;
- unauthorized registered operation cannot execute;
- Pathwise artifact cannot escape allowed storage/root policy before process use;
- no raw user shell string is constructed by process-operation bridge;
- master application resources remain clean before fork;
- child application resources are opened post-fork;
- repeated keep-alive requests do not share security/session/DB state;
- client cancellation cleans request execution;
- reload drains old release workers without serving new requests from stale application generation after cutoff;
- untrusted-script mode cannot silently fall back to normal trusted Runwire worker execution.

---

# 27. Performance acceptance

Keep performance attribution layered:

```text
Runwire raw HTTP
Runwire + Webrick
Runwire + Webrick + Foundation
Workerman + Webrick reference
existing Apache/FPM Foundation baseline where useful
```

Measure:

- RPS;
- p50/p95/p99;
- CPU;
- master/worker RSS;
- memory growth during soak;
- connections;
- keep-alive;
- large/streaming response;
- slow-client behavior;
- request execution bridge overhead;
- reload capacity dip;
- worker startup/release switch time.

Foundation optimizations must not bypass Webrick/InterMix/security semantics merely to improve benchmarks.

---

# 28. Fault/soak acceptance

Run native Foundation/Runwire soak scenarios:

- sustained small requests;
- keep-alive churn;
- mixed authenticated/unauthenticated requests;
- uploads/body streaming;
- large downloads/streaming;
- slow clients;
- worker crash;
- repeated worker recycle;
- rolling reload to a new Foundation release generation;
- DB/cache outage during active requests;
- control stop/reload during traffic.

Require:

- bounded RSS/FDs;
- no zombies;
- no stale execution state;
- no old release serving after completed drain;
- no parent-inherited DB/cache/broker resource use;
- no unbounded queues/buffers;
- deterministic shutdown/reload.

---

# 29. Development sequence

Foundation-side sequence should follow released/lower-layer readiness:

```text
1. Runwire process/supervisor/loop/network core
2. Runwire HTTP/1 transport
3. Webrick RunwireRuntimeAdapter
4. Foundation server config + native serve wiring
5. persistent request-scope/cancellation/streaming acceptance
6. Omnibus 2.6 Runwire delegation + Foundation queue integration
7. structured Foundation operation registry over Runwire ProcessRunner
8. Pathwise/ReqShield boundary integration tests
9. runtime/release-generation control integration
10. full benchmark + soak + security acceptance
11. Runwire 1.0 release
12. pin Foundation final graph to released ^1.0
```

Runwire can develop in parallel with remaining 26.7/26.8/26.9 specialist passes, but Foundation final aggregate release readiness is blocked until Point 26.12 closes.

---

# 30. Proposed canonical tracker update

When this addendum is reconciled into the canonical Foundation plan, add:

```text
| 26.12 | Runwire | ^1.0 | open / launch dependency |
```

Update the lower-library ownership list with:

```text
Runwire: process execution, worker/process supervision, event-loop, network listener/connection/server mechanics and generic runtime control.
```

Update the Foundation invariant so it also prohibits a second generic process/server runtime above Runwire.

Update the current execution order so Point 27/Phase 10 runs only after **26.12** is closed.

---

# 31. Proposed Point 26.12 completion gate

Foundation Point 26.12 closes only when:

- [ ] released `infocyph/runwire:^1.0` is in the final Composer graph;
- [ ] Webrick Runwire adapter is released/consumable and is Foundation's native HTTP server path;
- [ ] Foundation does not implement a duplicate generic socket/event-loop/process supervisor;
- [ ] generic Foundation process execution uses Runwire structured process APIs;
- [ ] normal web workers boot Foundation application resources only after fork;
- [ ] every HTTP request receives a fresh `webrick.request` execution and deterministic cleanup;
- [ ] keep-alive/Fiber/persistent isolation is proven;
- [ ] Runwire backpressure and transport limits compose correctly with Webrick/Foundation limits;
- [ ] Foundation release generations integrate with Runwire rolling worker generations without conflating identity;
- [ ] Omnibus process pool no longer requires Foundation raw-signal watchdog behavior;
- [ ] process operation authorization/ReqShield/Pathwise boundaries are proven;
- [ ] untrusted code cannot run in normal trusted workers by accidental fallback;
- [ ] PHP 8.4/8.5 stable/lowest QA is green;
- [ ] native runtime benchmarks and production-style soak/fault tests are recorded and acceptable;
- [ ] final release/security documentation describes capability profiles and OS sandbox boundary accurately.

---

# 32. Aggregate release gate change

Foundation's final Point 27 / aggregate Phase 10 must not run to completion until:

```text
26.4 / 26.5 / 26.7 / 26.8 / 26.9 / 26.10 / 26.12
```

are closed according to their final accepted plans.

Runwire is therefore a **Foundation 3 launch dependency**, not an optional post-release experiment.

---

# 33. Non-goals

Do not use this move to put into Foundation:

- Runwire event-loop internals;
- raw socket parser state;
- direct `pcntl` child tables;
- generic process-result/PID abstractions;
- a second Workerman-style server;
- Omnibus queue semantics;
- Pathwise upload mechanics;
- ReqShield shell scanning;
- a fake PHP sandbox;
- cluster/service-discovery orchestration.

Foundation is the orchestrating application framework; Runwire is the low-level process/network runtime.

---

# 34. Immediate handoff

Do not implement the Foundation adapter before Runwire's low-level HTTP transport contract and Webrick adapter boundary are sufficiently stable.

The first Foundation integration milestone should prove:

```text
Runwire prefork worker
  -> child boots Foundation application
  -> Webrick adapter receives one native request
  -> fresh Foundation request execution
  -> compiled Webrick route
  -> response streamed back through Runwire
  -> exact cleanup
  -> same connection serves second request with no leaked state
```

Only after that path is correct should Foundation add operational reload/control conveniences and broader process-operation APIs.