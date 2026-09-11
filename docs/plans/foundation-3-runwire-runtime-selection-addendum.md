# Foundation 3 — Runwire Runtime Selection Addendum

**Status:** normative addendum to `foundation-3-runwire-native-runtime-launch-plan.md`  
**Branch:** `foundation-3/runwire-launch-plan`  
**Runwire target:** 1.0

Foundation must not hard-wire Runwire 1.0 to only the Runwire-native server. Foundation selects a Runwire runtime driver while keeping the same Webrick/application execution semantics.

Supported selection values:

```text
auto
native
fpm
frankenphp
swoole
roadrunner
```

OPcache is configured separately:

```text
auto
on
off
required
```

Recommended Foundation configuration:

```php
'runwire' => [
    'runtime' => env('RUNWIRE_RUNTIME', 'auto'),
    'opcache' => env('RUNWIRE_OPCACHE', 'auto'),
];
```

Recommended CLI selection for server-capable modes:

```bash
php foundation serve --runtime=native
php foundation serve --runtime=frankenphp
php foundation serve --runtime=swoole
php foundation serve --runtime=roadrunner
```

FPM remains host-launched; Foundation detects/selects the FPM driver while executing inside the FPM request rather than spawning PHP-FPM itself.

Ownership rules:

- Runwire `native`: Runwire owns listener/event loop/HTTP transport/process supervision.
- FPM: PHP-FPM owns FastCGI listener and process pool; Runwire is request-bound adaptation/lifecycle only.
- FrankenPHP: FrankenPHP owns server/thread/worker mechanics; Runwire adapts classic/worker lifecycle.
- Swoole/OpenSwoole: host owns event loop/server/workers/coroutines; Runwire adapts lifecycle and request transport.
- RoadRunner: RR owns external server/process pool/worker dispatch; Runwire adapts worker lifecycle/transport.
- Webrick remains the application HTTP semantics owner in every mode.
- Foundation remains application graph, execution-scope, release-generation, authorization and deployment-policy owner.

Foundation must consume Runwire capability reporting instead of scattering runtime-name conditionals across application services.

Explicit runtime selection must fail fast when unavailable; production must never silently fall back to a different engine. `auto` may detect an already active host runtime and otherwise use Runwire-native only when the platform satisfies its required capabilities.

Persistent-mode acceptance is mandatory for FrankenPHP worker mode, Swoole/OpenSwoole and RoadRunner: every request gets a fresh Foundation execution state and cleanup runs in `finally`; globals/statics/application singleton state must not become accidental request state.

OPcache must be treated as a runtime acceleration policy, not a server choice. Foundation/Runwire may validate whether it is enabled, but must not claim it can always enable system-level OPcache settings from application code.

Add these items to the Foundation 26.12 completion gate:

- [ ] select Runwire driver through config/CLI without changing the application/Webrick API;
- [ ] support `auto|native|fpm|frankenphp|swoole|roadrunner`;
- [ ] support separate `opcache=auto|on|off|required` policy;
- [ ] explicit unavailable runtime fails before serving traffic;
- [ ] host runtimes do not get nested Runwire listener/event-loop/process pools;
- [ ] persistent driver request-state isolation passes;
- [ ] FPM remains request-bound and does not boot the native server;
- [ ] runtime capability diagnostics identify the selected driver and important supported features;
- [ ] benchmark direct host integration versus Foundation→Webrick→Runwire adapter overhead for each supported runtime.

This addendum must be reconciled into the canonical Foundation runtime plan before Foundation 3 release.
