# Runtime hosting

Foundation has four application modes: `web`, `cli`, `worker`, and `scheduler`.
The HTTP server is a separate choice. Foundation composes application services;
Webrick supplies HTTP semantics; a selected Runwire engine supplies runtime and
host lifecycle. Runwire remains an optional application dependency.

## Ownership and selection

| Path | Runtime responsibility | Foundation integration |
| --- | --- | --- |
| Ordinary PHP / direct FPM | Web server/FPM, Webrick SAPI adaptation | Existing generated web release |
| Native Runwire portable/prefork | Runwire listener, event loop and applicable process supervision | Generated web release through Webrick's Runwire bridge |
| FPM through Runwire | Runwire host adaptation; web server/FPM owns listeners/workers | Same bridge, request-lifetime context |
| FrankenPHP through Runwire | Runwire host adaptation; lifetime depends on host mode | Same bridge, lifetime from Runwire context |
| RoadRunner through Runwire | Runwire host adaptation; RoadRunner owns workers | Same bridge, persistent application with isolated requests |
| Swoole/OpenSwoole through Runwire | Explicit Runwire Swoole driver; host owns its execution model | Same bridge, concurrent request isolation |

Runwire's AUTO selection recognizes active FPM, FrankenPHP and RoadRunner hosts.
Installing a Swoole-family extension does not select that host: configure
`RuntimeDriver::SWOOLE` explicitly. Native listener execution and host-owned
serving are different entry paths; do not start a second listener or worker pool
inside a host-managed path.

These host capabilities come from [Runwire's runtime contract](https://github.com/infocyph/Runwire#runtime-modes).
The optional [Webrick bridge](https://github.com/infocyph/Webrick#persistent-runtimes)
provides `RunwireRuntimeApplicationFactory`, `RunwireRuntimeApplication` and
`RunwireRuntimeAdapter`. Use these existing boundaries rather than creating
Foundation versions of Runwire host drivers. Webrick's direct host adapters
remain available for deliberately selected non-Runwire deployments; they are
not automatically rerouted through Runwire.

## Foundation composition contract

A Runwire-hosted Foundation application must:

1. Let Runwire resolve the actual runtime context and capabilities before
   constructing the application lifetime and selecting persistence/concurrency.
2. Load the trusted Foundation web generation using its release bootstrap and
   the Webrick Runwire adapter. Keep Foundation's CLI/worker/scheduler application
   modes separate from HTTP host selection.
3. Pass native Runwire request/writer handles through Webrick's runtime server.
   Webrick owns request adaptation, InterMix request scope and exactly one HTTP
   response emission. Do not invoke an additional Foundation/SAPI emitter.
4. Propagate cancellation/deadlines and admission through the existing bridge.
   Do not duplicate Runwire detection, coroutine scheduling or process pools.
5. Release Foundation-owned session/principal/database state at the established
   request boundary, including failure and cancellation. Never infer web-host
   persistence from `RuntimeMode::isPersistent()` alone.
6. Preserve Runwire drain/shutdown and Foundation generation replacement policy.
   Restart or replace applications through their actual lifetime owner rather
   than replacing a loaded generation in place.

The installed Webrick bridge is an integration building block. Foundation's
concrete host bootstrap recipes and end-to-end certification are tracked in
[F30-06 of the 3.0 plan](plans/foundation-3.0-improvement-and-release-plan.md#f30-06--build-a-real-server-acceptance-matrix).
This document propagates upstream ownership and the required composition
contract; it does not claim that every real-server acceptance job has passed.

## Optional dependency and separate worker support

Applications selecting the Runwire 1.x integration install `infocyph/runwire`
explicitly. Foundation does not require it for ordinary PHP/FPM and installation
alone does not activate a runtime. Omnibus's optional Runwire process-pool backend
is a separate selection from HTTP hosting; see [messaging](messaging.md).

HTTP/2, HTTP/3/QUIC and native transport supervision remain Runwire concerns.
Advertise them for a Foundation deployment only after testing its selected
runtime, extensions and configuration. Do not infer a Runwire Workerman driver
from Webrick's independent Workerman adapter.
