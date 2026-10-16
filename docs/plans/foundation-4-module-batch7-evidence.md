> **Core correction, 2026-10-16:** Runwire is now a required production/Core integration and `execution.runtime` is removed. The catalog has three modules/eight optional features. All immutable SHAs, lock hashes, nine-feature counts and performance results below predate this change and are retained as historical evidence. The previous host repeat was cancelled at the user's request; its partial results cannot certify the new contract. Local full quality, native/compiled/no-dev and twelve-case Docker correctness checks pass for the correction; fresh hosted QA and sustained comparison remain required before current acceptance.

# Foundation 4 — Module architecture Batch 7 certification evidence

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
