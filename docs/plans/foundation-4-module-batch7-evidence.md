# Foundation 4 — Module architecture Batch 7 certification evidence

## Published Runwire worker fix adopted — 2026-10-17

Runwire **2.1.2** is published and Composer-resolvable at
`7e9bf20ad0737a187dfed77b723791b141b36378`; its tag includes the validated
worker patch `23011696724bbd86b2d412a07bc34348d8d169e1`. Foundation now
requires **`infocyph/runwire:^2.1.2`**. No Foundation-side worker/fork/context
workaround is introduced. Composer installs the published distribution, with no
sibling path override; all direct dependencies are current.

Real Foundation CLI acceptance now covers explicit Swoole one/two-worker
serving, concurrent bursts of sixteen isolated requests, actual worker PID/context/slot
identity, handler-failure recovery, native AUTO/portable serving and the normal
index entrypoint. A dedicated PHP 8.4/8.5 × Swoole/OpenSwoole hosted matrix
requires the selected extension before running these tests; missing extensions
cannot silently omit the worker cases in that matrix. Local detailed quality
passes all checks (784 tests / 29,807 assertions); the real Docker Swoole
serving/release subset passes 17 tests, including repeated fresh two-worker
starts. All 13 final `composer ic:tests` checks, processors and actionlint
pass. Hosted current-source QA, production
consumers and sustained host acceptance must still be renewed.
The frozen baseline and 2% RPM / 2.5% CV limits remain unchanged. Keep PR #20
draft; merging, tagging, publication and Infbyte changes remain separate.

> Earlier dated checkpoints below retain their original dependency/source evidence.

## Current serving work and completed ARM repeats — 2026-10-17

The serving implementation adds native Runwire command serving alongside normal
`index.php` hosting. Focused tests pass 24 / 328 assertions. The detailed test run
passed **782 tests / 29,780 assertions** before two additional worker scenarios;
all **13 final `composer ic:ci` checks pass**, including the expanded suite.
Stable/lowest no-dev consumers pass Core, database inspection, twelve full
selected-feature configurations and trusted web/CLI/worker/scheduler generation.
Real HTTP failure/recovery probes pass native
prefork, native portable, raw PHP, Docker nginx/FPM and directly hosted
two-worker Swoole. Runwire 2.1.1 with Swoole 6.2.3 has a reproduced ownership
defect with two workers: worker PIDs 7/8 receive runtime PID 1. Its factory is
invoked before Swoole forks. The native owner fix, affected regressions and
exact-source hosted renewal remain required. These are implementation-stage
results and do not certify the serving candidate for release. A separate
Runwire-only Docker reproduction confirms the same wrong-PID context without
any Foundation application code.

The preceding committed source [`99c8921`](https://github.com/infocyph/Foundation/commit/99c89219bbbab63174b066462c636da569ccabae)
passed [Security & Standards 38925103788](https://github.com/infocyph/Foundation/actions/runs/38925103788)
and [native host smoke 38925108609](https://github.com/infocyph/Foundation/actions/runs/38925108609).
Its source tree `a4fac8e7c8721195d1fb696b6d494d36cdf6ee52` matches tested merge
`32e5bae091069db76636098506ea03776ebcf0c8`; the release-evidence archive digest
is `sha256:7eb54083dd5000ad7e6d76f7d160d8ed20dfe98374773e6de8ee7e76b70ced8c`.

The free ARM64 [paired comparison 38925110432](https://github.com/infocyph/Foundation/actions/runs/38925110432)
completed twice on unchanged source `99c8921`, using the same lock digest
`e6bcad0151476776a3d876d3a9948693197f940234de656df9d921cc104e8b4e`.
Both attempts failed the unchanged 2% RPM / 2.5% CV gate:

| Attempt | Pass | Stable regression | Inconclusive variance | Non-passing stable cases |
| --- | --- | --- | --- | --- |
| 1 | 8 | 1 | 3 | Runwire w1/c50 −2.705%, CV 0.666% |
| 2 | 6 | 2 | 4 | FPM w1/c10 −2.339%, CV 0.793%; Runwire w1/c10 −3.656%, CV 1.655% |

Passing cases from separate attempts are not combined. The repeated source did
not reproduce the first attempt's w1/c50 classification, but the complete gate
still fails. A pinned local Docker w1/c50 comparison measured +0.358% RPM, CV
1.687% / 2.029%, with valid responses; it is diagnostic rather than a substitute
for the hosted matrix. No performance defect is claimed fixed, no baseline is
reset, and no limit is relaxed. The serving implementation needs new exact-source
QA and host evidence after the native lifecycle issue is resolved. PR #20 remains
draft; no merge, tag or publication.

## Current Core candidate — 2026-10-16

Tested executable source: [`3d35605`](https://github.com/infocyph/Foundation/commit/3d356050bb87feeeb2861d5cb4bb13c400d798fe).
Runwire `^2.1.1` is required Core; the public catalog has three modules and eight
optional features. Shared intermediary contexts populate exact native DI inputs;
completed/cancelled shared requests are rejected before application callbacks.
This checkpoint updates documentation only and preserves the tested source IDs.

**Quality and correctness pass; sustained performance acceptance remains open.**
[Security & Standards 38917716824](https://github.com/infocyph/Foundation/actions/runs/38917716824)
passed PHP 8.4/8.5 stable/lowest QA 4/4, analysis 2/2, production consumers 4/4,
component benchmarks 2/2, clean install and release evidence. The conditional
Security Report was skipped and is not additional security certification.
Source `3d356050bb87feeeb2861d5cb4bb13c400d798fe` and tested merge commit
`04c4cc762cd109bfc980a7c030f76c26e90ea2b4` share Git tree
`10ad01cb183b6eba60ae2e5792e06e163d77f873`. The release-evidence archive digest is
`sha256:ed2419e31ee17510b5da80649df7f6875305440b02c1d7febb85438af1fa718c`.
Its lock and both paired attempts use SHA-256
`e6bcad0151476776a3d876d3a9948693197f940234de656df9d921cc104e8b4e`.

Local full detailed quality passed **769 tests / 29,638 assertions** and all 13
`composer ic:ci` checks passed. Focused native forwarding passed 31 tests /
1,112 assertions. Stable/lowest isolated production consumers, including twelve
selected-feature configurations per mode, passed without development packages.
The hosted manifest includes CacheLayer 4.0.1, DBLayer 6.1.1, Omnibus 3.0.1,
Runwire 2.1.1, InterMix 11.2 and Webrick 6.0.3.
[Ordinary native host smoke 38917716261](https://github.com/infocyph/Foundation/actions/runs/38917716261)
passed. Both minimal/native workloads cover twelve scenarios, with zero failed
responses and four shorter soak windows each.

### PR checks and the separate manual comparison

The ordinary `pull_request` event runs `native-host`; its `prepare-paired-host`,
`paired-host` and `compare-paired-host` jobs are intentionally **skipped** by the
workflow's `workflow_dispatch` conditions. Those skipped PR jobs are not passing
performance evidence. The actual sustained matrix ran separately as manually
dispatched [workflow 38917773579](https://github.com/infocyph/Foundation/actions/runs/38917773579)
on the exact source above, with immutable baseline `6274939c395f75e5cf777aad5d7439f10792758c`.

| Complete attempt | Passing cases | Inconclusive variability | Stable regression classification | Overall comparison |
| --- | ---: | ---: | ---: | --- |
| [1](https://github.com/infocyph/Foundation/actions/runs/38917773579/attempts/1) | 8/12 | 4/12 | 0/12 | Failed |
| [2, unchanged source](https://github.com/infocyph/Foundation/actions/runs/38917773579/attempts/2) | 10/12 | 2/12 | 0/12 | Failed |

Each attempt contains sixty measured trials per revision, all at least sixty
seconds after recorded thirty-second warm-up, plus four two-minute soak windows
per revision. All measured responses and all eight soak windows per attempt
passed correctness, with live CPU/RSS telemetry. Baseline/candidate share the
lock, fixture, tools and physical runner within each scenario. Passing samples
from different attempts are not combined. The 2% median successful-RPM and
2.5% maximum CV limits remain unchanged.

The second attempt's unresolved scenarios are:

| Scenario | Median RPM change | Maximum CV | Classification |
| --- | ---: | ---: | --- |
| Runwire, one worker, concurrency 50 | +0.382% | 3.011% (baseline) | Inconclusive variability |
| Runwire, four workers, concurrency 10 | -3.252% | 7.835% | Inconclusive variability |

The second attempt passes every FPM case, including four-worker/concurrency-50
at -1.110% RPM and 0.428% CV. An independent pinned Docker reproduction of that
case on PHP 8.5.11 measured 7,883.48 baseline versus 7,931.87 candidate median RPM
(+0.614%), CV 1.383% / 1.133%, zero failed responses. Its 708 frozen baseline
runtime/resource files match Git `6274939`; both variants use identical current
benchmark tools and the same lock. This is local diagnostic evidence, not hosted
acceptance or a claim that earlier candidates' regressions were fixed.

B7 throughput/stability and final sign-off remain open. The user confirmed this
is a public repository. The workflow now permits the standard free
`ubuntu-24.04` or `ubuntu-24.04-arm` runner for measured pairs; its run title
distinguishes manual comparison from ordinary PR smoke. An ARM64 comparison is
the next experiment, not an assumed stability fix or existing certification.
Both revisions of every pair still use the same runner, lock, fixture and tools;
limits remain unchanged. See [GitHub's standard public-runner specifications](https://docs.github.com/en/actions/reference/runners/github-hosted-runners).
No self-hosted runner was registered when inspected; larger runners are paid
even for public repositories and are not selected. PR #20 remains draft;
merge, tag, publication and Infbyte migration are not performed.

## Historical evidence before the Core correction

The SHAs, lock hashes, nine-feature counts and results below predate required
Core Runwire. They do not certify the current eight-feature contract. The prior
pre-Core repeat was cancelled at the user's request.

Checkpoint: 2026-10-16. Repository: `infocyph/Foundation`. PR: [#20](https://github.com/infocyph/Foundation/pull/20). Branch: `feature/foundation-3`.

**QA is green; sustained host acceptance remains open.** Executable candidate
[`60a7951`](https://github.com/infocyph/Foundation/commit/60a795133924fa3a06ad78820aa5543cf8978e81)
is verified against the current published native dependencies. The Foundation
release ledger accepts **32/37** criteria. Module B7 is **4/7 individually
QA-checked, 0/7 whole-batch accepted**; the module totals remain **46/49 engineering
checks, 42/49 batch accepted, 7/8 batches accepted**. PR #20 stays draft.

## Immutable source and quality evidence

[Security & Standards 38860725174](https://github.com/infocyph/Foundation/actions/runs/38860725174)
is **SUCCESS** on the exact candidate: PHP 8.4/8.5 × stable/lowest PHPForge QA 4/4,
analysis 2/2, isolated production consumers 4/4, component benchmarks 2/2, clean
install and release evidence. The conditional Security Report job is skipped;
it is not additional security certification. No test or detector was disabled.
The artifact records source `60a795133924fa3a06ad78820aa5543cf8978e81` and PR merge
CI SHA `85973b4e0498ee51558f7dc0b9af9e419987eaa1`; both have identical Git tree
`5ea2d0dcd905609673593a6e783d5cdbb7b0df37`. The release-evidence archive digest is
`sha256:40ab5993c7be3c23cd4872becbbc0816abeb22d28921568f4754f078184a7aa3`.
Its recorded PHP is 8.5.11, and its dependency-lock digest matches the paired
host lock recorded below.
Local full `composer ic:ci` and `composer ic:tests:details` pass. The minimum
compatible graph passed 746 tests / 28,853 assertions with deprecations fatal.

Published CacheLayer 4.0.1, DBLayer 6.1.1 and Omnibus 3.0.1 are used without local
package overrides. Optional transitive constraints reject verified PHP 8.4/8.5
incompatibilities and PKI Framework versions before security release 1.6.2.
Composer advisory output and published upstream advisory ranges were checked;
no selected current minimum/stable runtime version matched those advisories.
The abandoned `doctrine/annotations` package is a development-only PHPBench
transitive dependency, distinct from an advisory or network failure.

The native/security revalidation covers ordinary passed-context operations,
child-only cursor cancellation with reusable parent context, actual signed
WebAuthn/HOTP proof, independent SQLite/MySQL/PostgreSQL one-winner transitions,
rollback and revocation, manifest authentication before evaluation, and real
Composer publication/removal races. Production symbol ownership checks prohibit
competing library engines and retired mutation paths. The [release ledger](foundation-4.0-release-audit-and-plan.md)
names the active criterion evidence. PR review threads were last inspected at
63 total, zero unresolved; repeat this check before final readiness.

## Historical B7 acceptance gates before the Core correction

| Gate | Evidence required | Current result |
| --- | --- | --- |
| 1. Full quality and security | Exact-source PHPForge, analysis, formatting, references and configured security checks | **PASS**, candidate `60a7951`, run 38860725174 |
| 2. Production matrices | PHP 8.4/8.5 × stable/lowest, Core and selected native features under `--no-dev` | **PASS**, all four consumers |
| 3. Before/after throughput and module CLI | Comparable CLI, compiled startup and complete authenticated paired-host metrics | **OPEN**, final aggregate and soak evidence under evaluation |
| 4. ≤2% regression and stability | Five sustained trials per case, CV ≤2.5%, median successful-RPM regression ≤2%, bounded resources | **OPEN**, downloading measurements does not establish a passing comparison |
| 5. Concurrency and recovery | Composer/publication races, real database contention, lifecycle, cancellation and recovery | **PASS**, full exact-source suites and local/native host smoke |
| 6. Review and immutable provenance | No unresolved review threads, immutable source/toolchain/lock evidence, synchronized trackers | **PASS**, recheck final PR state before readiness |
| 7. Final notes and release decision | Current public API, publication metadata and notes reviewed, preceding gates accepted | **BLOCKED** by gates 3 and 4 |

## Approved paired host environment

The user selected GitHub Actions for the sustained comparison and Docker for
local diagnostics. [Workflow 38860730929](https://github.com/infocyph/Foundation/actions/runs/38860730929)
compares frozen baseline `6274939c395f75e5cf777aad5d7439f10792758c` with candidate
`60a795133924fa3a06ad78820aa5543cf8978e81`. Each FPM/Runwire × one/four workers ×
concurrency 1/10/50 pair runs sequentially on the same runner. Both revisions use
one Composer lock, the candidate benchmark tools, PHP/INI/extensions, and
isolated equivalent PostgreSQL and Redis datasets. Runner identity is recorded
per scenario; different matrix cases are not represented as one physical host.

The workload uses real bearer authentication, account/permission SQL reads,
authorized business data and a validated Redis cache hit. Every successful
response must pass status/body, actual query-count and cache-evidence checks.
Each revision records a separate **30-second warm-up**, then **five measured
repetitions of at least 60 seconds**. The four-worker/concurrency-10 jobs also
record **two 120-second soak windows per host/revision**, with continuous live
process-tree RSS/CPU telemetry, cancellation/error recovery and Runwire worker
replacement. First-ready-request and compile timing are separate diagnostics.

The comparator retains the existing **2.5% CV** and **2% median successful-RPM
regression** limits. It rejects missing or mismatched source/lock/tool/fixture,
runner identities, partial matrices, invalid responses and insufficient samples.
The merger requires both host soak summaries and both valid ≥120-second windows.
An unstable case remains inconclusive; a stable budget breach remains failed.
The approved test environment does not establish capacity for an unmeasured
production deployment.

Artifacts are `foundation4-paired-comparison` (merged baseline/candidate reports,
host comparison and CLI comparison), `foundation4-pair-*` (raw per-scenario
load/telemetry/startup/soak evidence) and `foundation4-paired-host-lock` (exact
Composer lock). Artifacts exclude generated applications and authorization files.
The shared lock SHA-256 is
`47721543a0f9438cfd0fa862b074c5c11953d320c1c9f14164bc4a9df9359e28`.

## CLI and local diagnostics

The paired workflow measures seven identical v2 command operations: list, show,
doctor, plan, install dry-run, remove dry-run and actual enable, including cold
and warm no-op measurements. `foundation4-module-cli-compare.php` checks their
shape, toolchain and distinct source identities. Its result is diagnostic and
cannot independently certify HTTP throughput. Compiled startup timing is
recorded by the actual host harness.

Local Docker diagnostics passed twelve FPM/Runwire scenarios, four shorter soak
windows, authenticated response checks, cancellation/recovery and worker
replacement. A separate four-worker Runwire probe passed the new recorded warm-up
boundary. Local results support correctness and measurement lifecycle; they are
not complete immutable-host throughput certification.

A targeted pinned-CPU Docker reproduction of FPM four-worker/concurrency-50 used
the same baseline/candidate sources, dependency lock and benchmark tools, a
30-second warm-up and five >=60-second trials. On PHP 8.5.11 it measured
8,600.52 baseline versus 8,445.13 candidate median RPM (**-1.807%**), with CV
**1.453% / 0.774%** and zero failed responses. The hosted -5.218% result was not
reproduced in this controlled local case; PHP differs from the hosted 8.4.26
measurement, so this is supporting evidence rather than hosted acceptance.

## Candidate 60a7951 — paired attempt 1

The full comparison completed with **4/12 passing, 7/12 inconclusive variance,
and 1/12 stable regression**. All twelve measurement jobs passed their response
checks; the comparison job correctly failed. The failing FPM four-worker/c50 case
measured 6,155.11 baseline versus 5,833.96 candidate successful RPM (-5.218%,
maximum CV 2.449%). This result has not been dismissed as noise or called fixed.
A complete repeat on unchanged source `60a7951` is in progress; samples from
different attempts will not be combined to manufacture acceptance.

| Scenario | Baseline RPM | Candidate RPM | Change | Maximum CV | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| fpm-w1-c1 | 2,211.47 | 2,249.41 | +1.716% | 2.852% | inconclusive variance |
| fpm-w1-c10 | 2,285.32 | 2,134.63 | -6.594% | 3.375% | inconclusive variance |
| fpm-w1-c50 | 2,132.94 | 2,099.62 | -1.562% | 1.412% | pass |
| fpm-w4-c1 | 2,152.76 | 2,164.98 | +0.568% | 1.006% | pass |
| fpm-w4-c10 | 7,302.05 | 7,878.17 | +7.890% | 5.547% | inconclusive variance |
| fpm-w4-c50 | 6,155.11 | 5,833.96 | -5.218% | 2.449% | regression |
| runwire-w1-c1 | 3,158.16 | 3,467.65 | +9.800% | 3.459% | inconclusive variance |
| runwire-w1-c10 | 4,199.87 | 4,225.37 | +0.607% | 3.103% | inconclusive variance |
| runwire-w1-c50 | 3,388.59 | 3,467.96 | +2.342% | 2.571% | inconclusive variance |
| runwire-w4-c1 | 3,314.43 | 3,272.12 | -1.277% | 0.556% | pass |
| runwire-w4-c10 | 11,649.17 | 11,295.15 | -3.039% | 22.600% | inconclusive variance |
| runwire-w4-c50 | 6,630.35 | 6,589.94 | -0.609% | 0.249% | pass |

Both baseline and candidate retain FPM and Runwire soak summaries, each with two
>=120-second correct-response windows and continuous live process-tree samples.
The candidate windows had no failed responses; their first-to-last RSS growth
was at most 0.25 MiB. The seven CLI operation comparisons and compiled startup
artifacts are present. These completeness/correctness results do not override
the throughput/variance failure.

## Earlier comparison history

[Run 38837335243](https://github.com/infocyph/Foundation/actions/runs/38837335243)
ran twice on unchanged candidate `95eccf9`. Attempt 1 passed 9/12 cases, with two
noisy cases and a stable one-worker Runwire/concurrency-50 result at -2.549%.
Attempt 2 passed 8/12, with four noisy cases; every stable case passed, including
the earlier failing case at -1.548% and CV 0.472%. No reproducible runtime defect
or performance fix was established. The measured warm-up/longer-window follow-up
does not relax budgets or discard earlier evidence.

Earlier dated 19/37 counts and physically dedicated host prerequisites in the
architecture history are superseded by the current native revalidation and the
user-approved paired GitHub environment. Final performance acceptance is still
required. Release notes remain an unpublished draft; merge, tag, publication and
Infbyte migration are separate operator actions.
