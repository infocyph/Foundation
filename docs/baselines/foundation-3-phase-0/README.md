# Foundation 3 Phase 0 Baselines

Phase 0 freezes the performance and semantic reference points that Foundation 3 must be compared against. The freeze was completed on 2026-09-02 before continuing the runtime-architecture migration.

The evidence deliberately uses two measurement surfaces:

1. **Attribution baselines** on GitHub Actions: direct InterMix 10.0.3 DI plus the pre-architecture Foundation snapshot's representative workloads and boot cost.
2. **Real HTTP baseline** from the InfByte benchmark suite: Apache + OPcache measurements for standalone Webrick 5.1 and released Foundation 2.1.1.

Do not compare absolute numbers across those two environments.

## Frozen source identities

| Component | Frozen source |
| --- | --- |
| InterMix 10.0.3 | `a9ec6d5852eae34e4829108a811b68fc6be6baa5` |
| Webrick 5.1 | `7d1527f7b9076087549d64bf543e54c96a37911f` |
| Foundation pre-architecture snapshot | `b6fce52d5580f6940f2a2647dff3040e351e15a3` |
| Foundation 2.1.1 release | `f6749ebe0aa1f93c6b3586e8cb63ecc1f552d98b` |
| InfByte Benchmark suite | `d1b130302e9f8918f81c613f6f038c35eb67ce76` |

The pre-architecture snapshot resolved InterMix 9.2 at `7f4c71920471a53f11609ed6ae9ca4e26e83c487` and Webrick 4.0.2 at `417a1c62f4899e7a2a72960920d59fb44c636192`.

## Direct InterMix 10.0.3 baseline

Runtime: PHP 8.4.25 CLI, OPcache CLI enabled. Graph: singleton leaf plus transient constructor-recipe node. 200,000 operations × 7 repetitions with 1,000 warmups per repetition.

| Measurement | Result |
| --- | ---: |
| Development cold build + first resolve | 15.461 ms |
| Development warm transient resolve median | 1,297.75 ns |
| Development warm throughput | 770,563.50 ops/s |
| Strict validation errors | 0 |
| Compile time | 8.657 ms |
| Generated production load + first resolve | 0.601 ms |
| Generated production warm transient median | 211.27 ns |
| Generated production warm throughput | 4,733,173.61 ops/s |
| Benchmark services compiled | 2 |
| Process memory / peak | 2 MiB / 2 MiB |

The compile report contains one skipped infrastructure binding, `Psr\Container\ContainerInterface`, with reason `definition requires the dynamic runtime`. Both benchmark-owned services compiled successfully and strict validation returned no errors.

## Pre-architecture Foundation representative baseline

Runtime: PHP 8.4.25 CLI on a GitHub-hosted AMD EPYC 7763 runner, OPcache and JIT enabled. Every workload completed 5,000 timed operations with zero failures and zero timeouts.

| Workload | Successful RPM | p50 | p95 | p99 | Avg / peak memory |
| --- | ---: | ---: | ---: | ---: | ---: |
| Minimal JSON warm | 1,498,515 | 0.035 ms | 0.054 ms | 0.075 ms | 8 / 8 MiB |
| Route + array session warm | 614,617 | 0.090 ms | 0.116 ms | 0.132 ms | 10.2 / 12 MiB |
| Application bearer, OAuth disabled | 939,369 | 0.060 ms | 0.083 ms | 0.093 ms | 12 / 12 MiB |
| OAuth client-credentials bearer resolution | 73,669 | 0.807 ms | 0.840 ms | 1.072 ms | 14 / 14 MiB |

These are attribution microbenchmarks. The shared runner reported them as unverified for stability, so they are not release-performance acceptance numbers.

## Pre-architecture Foundation boot baseline

15 repetitions on PHP 8.4.25 CLI with OPcache CLI enabled.

| Measurement | p50 | p95 | Memory / peak |
| --- | ---: | ---: | ---: |
| Cold Composer/autoload portion | 31.176 ms | 42.334 ms | — |
| Cold Foundation application boot | 46.556 ms | 47.775 ms | — |
| Cold total process + application boot | 78.299 ms | 89.909 ms | 8 / 10 MiB |
| Warm fresh-Application boot | 3.168 ms | 6.841 ms | 8 / 8 MiB |

Hosted-runner cold boot is naturally noisy. Later attribution runs must use the same harness and execution profile.

## Standalone Webrick 5.1 / released Foundation HTTP baseline

Source: InfByte Benchmark `opcache/2026-09-02T050214Z` at commit `d1b130302e9f8918f81c613f6f038c35eb67ce76`.

Runtime: PHP 8.5.10 `apache2handler`, Apache, OPcache production profile, JIT disabled, `php-curl-multi` load generator. The published run used 5,000 requests, two repetitions, a 30-second minimum window, 10 warm-up requests, and a 10% stability threshold.

| Target | Best stable RPM @ c63 | Serial p50 / p95 / p99 | c63 p50 / p95 / p99 | Remote memory |
| --- | ---: | --- | --- | ---: |
| Webrick generated 5.1 | 167,743 | 0.71 / 0.78 / 1.14 ms | 18.48 / 53.37 / 72.48 ms | 0.44 MiB |
| InfByte / Foundation 2.1.1 | 81,375 | 1.53 / 1.71 / 2.90 ms | 43.30 / 102.20 / 132.43 ms | 0.55 MiB |
| InfByte full / Foundation 2.1.1 | 80,692 | 1.54 / 1.75 / 2.37 ms | — | — |

At concurrency 63 both the standalone generated Webrick target and the Foundation target recorded a 0% error rate. This real Apache + OPcache run is the primary HTTP acceptance reference for Foundation 3.

## Semantic baseline

The complete pre-architecture Foundation test tree was executed under the frozen historical source snapshot.

- Tests: **263**
- Assertions: **15,111**
- Errors: **0**
- Failures: **0**
- Skipped: **5**
- Runtime: **7.865 s**

The five skips are the shared-lock contention datasets requiring external Redis, Valkey, Memcached, MySQL PDO, or PostgreSQL PDO services that are not provisioned on the GitHub-hosted baseline runner. They are infrastructure skips, not failing semantics.

## Reproduction and evidence

GitHub Actions workflow: `.github/workflows/phase0-baselines.yml`.

Successful freeze run: `33599547700` at Foundation branch commit `a22ee20f312d87e7008dd727123f77821891c869`.

The workflow artifacts retain the raw benchmark outputs, JUnit semantic result, resolved historical source identities, and the generated Composer lock for the pre-architecture snapshot. This directory commits the durable Phase 0 evidence needed for future comparison.

The raw historical representative JSON remains in the successful workflow artifact. Its `environment.release` value was the workflow-trigger SHA because that field used `GITHUB_SHA`; the committed summary intentionally replaces that ambiguous field with the authoritative pre-architecture Foundation source from `manifest.json`.

## Comparison rules for Foundation 3

- Compare direct DI and boot changes against the same Phase 0 harness; do not mix them with Apache HTTP numbers.
- Compare HTTP release performance against the real Apache + OPcache benchmark profile, and repeat in the production-like environment used by InfByte.
- Pin exact package/repository identities for every comparison.
- Preserve semantic and security behavior before accepting a performance gain.
- Treat GitHub-hosted microbenchmark absolute values as attribution evidence, not a universal performance budget.
- Investigate only attributable Foundation overhead after subtracting the standalone InterMix/Webrick baselines.
