# Foundation 3.0 runtime support

This document is the release support statement for Foundation 3.0. A runtime is
"Foundation-certified" only when the Foundation release-candidate workflow
executes the relevant integration contract. Availability of a Webrick or Runwire
adapter is not, by itself, a Foundation compatibility claim.

## Certified matrix

| Area | Foundation 3.0 certified support | Evidence |
| --- | --- | --- |
| PHP | PHP 8.4 and PHP 8.5 | PHPForge stable/lowest CI matrix |
| OS | Linux release/CI contract | Foundation GitHub Actions release matrix |
| ordinary web response path | Webrick SAPI response semantics: status/headers/body, file and streaming output | `WebRuntimeEmissionTest`, `FilesystemHttpBridgeTest` |
| generated web release | immutable Webrick + InterMix generated release artifacts and trusted manifest loading | `WebReleaseRuntimeTest`, `FoundationReleaseEndToEndTest`, `Phase10ReleaseSourceIsolationTest` |
| persistent execution semantics | Foundation/Webrick persistent-adapter scope isolation and exactly-one response write | `WebRuntimeEmissionTest`, `Phase9RuntimeParityTest` |
| CLI | generated Foundation CLI runtime | `GeneratedNonWebRuntimeTest`, `FoundationReleaseEndToEndTest` |
| workers | generated worker runtime, execution-scope reset and generation replacement | `GeneratedNonWebRuntimeTest`, `FoundationReleaseWorkerReplacementTest` |
| scheduler | generated scheduler runtime | `FoundationReleaseEndToEndTest`, scheduler closure tests |
| shared session locks | Redis, Valkey, Memcached, MySQL/PDO and PostgreSQL/PDO in the PHPForge service matrix | `SessionLockContentionTest` |
| persistent-state isolation | request/job execution scopes under sustained success/failure pressure | `PersistentRuntimeSoakTest`, OAuth/worker persistent-runtime suites |

## Adapter availability is not certification

Foundation 3.0 does **not** claim repository-level release certification for the
following host/server combinations because this candidate does not execute them
as native hosts in the Foundation release workflow:

- PHP-FPM as a separately managed native-host acceptance job;
- Runwire portable/listener hosting;
- FrankenPHP;
- RoadRunner;
- Swoole/OpenSwoole;
- Workerman or other third-party persistent hosts.

Webrick/Runwire may provide adapters for these environments. Applications may
validate and use those upstream adapters, but Foundation 3.0 does not translate
their existence into a tested support promise. A later Foundation release may
widen this matrix after native-host jobs exist.

Foundation also keeps two Runwire concerns separate: HTTP host adaptation belongs
to the Webrick/Runwire runtime boundary, while Omnibus worker-pool/process
supervision is a messaging/worker concern. Foundation does not start a competing
HTTP listener or process pool under a host-owned server.

## Persistent-host policy

Foundation does not infer web-host persistence from `RuntimeMode::Web`, an
extension, or a process name. Runtime capabilities come from the Webrick runtime
adapter. Generated CLI/worker/scheduler modes retain their explicit Foundation
lifecycle semantics.

Because Foundation 3.0 does not certify a native persistent HTTP host, its release
qualification uses deterministic sustained execution evidence instead of claiming
a 30-minute native-host soak:

- 1,000 alternating successful/failing execution-scope iterations with weak
  reference release checks;
- persistent worker/OAuth state-isolation regressions;
- repeated representative request benchmarks with latency, failure and memory
  metrics;
- generated-runtime replacement and cleanup tests.

This is a deliberate support-boundary decision, not a substitute for a future
native-host soak when such a host is added to the certified matrix.

## Representative performance evidence

The release benchmark suite separates Foundation integration overhead from
specialist-library/native-protocol benchmarks.

- `RepresentativeBenchmark`: minimal JSON, route-selected browser session,
  application bearer auth and durable OAuth bearer resolution, with successful
  RPM, p50/p95/p99, failures and memory growth.
- `dblayer-51-utilization.php`: Foundation DBLayer integration attribution.
- `omnibus-26-utilization.php`: Foundation messaging ingress/consumer
  integration attribution.
- `phase9-http-attribution.php`: Foundation versus direct Webrick HTTP/runtime
  attribution under the same process/environment.
- specialist utilization benchmarks retain package-owner mechanics rather than
  duplicating them inside Foundation.

Stable performance enforcement requires matching environment fingerprints.
Noisy shared CI results remain diagnostic and are not promoted to a regression
gate merely because a benchmark ran.

## Deployment assumptions

Production generated releases require:

- immutable application/source inputs during a build;
- a writable release-generation directory for build/activation;
- writable application runtime storage where selected capabilities require it;
- deployment-provided trusted Foundation manifest SHA-256 for prevalidated
  process bootstrap;
- required PHP extensions on the **host PHP runtime**. Service containers do not
  install PDO/Redis/Memcached extensions into the host interpreter.

See [Foundation 3.0 migration](foundation-3-migration.md) and
[Architecture](architecture.md) for the production bootstrap contract.
