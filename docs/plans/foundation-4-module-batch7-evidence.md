# Foundation 4 — Module architecture Batch 7 certification evidence

## Controller isolation correction — 2026-10-18

The user selected Foundation-first finalization, without merge, tag or
publication. Infbyte's required stable-dependency gate remains deferred until
Foundation publication; no PHPForge candidate-policy change is made.

A real execution of the current paired workflow step reproduces a measurement
control gap: the benchmark controller and telemetry children inherit the full
runner CPU mask, despite separate server/load/backend CPU assignments. They can
therefore compete with measured server or backend processes. This finding does
not establish the complete cause of the historical variance failures.

The paired workflow now starts the controller on the existing load CPU. Native
server commands retain their explicit server CPU mask; the controller, sampler
and ordinary child processes inherit CPU 0. The host environment artifact records
the actual controller CPU set. A regression executes the actual workflow step
for both variants and checks the real controller and child affinity through
Linux process metadata; it fails before the correction and passes afterwards.
Focused workflow/transport/report/comparison coverage passes **30 tests / 144
assertions**. The complete detailed suite passes **806 tests / 29,918
assertions**, with all configured quality checks passing. Actionlint, shell
syntax, current direct-dependency and advisory checks pass; the required runtime
constraint guard also passes. The common dependency lock is unchanged.

Runtime source, libraries, workload, warm-up, measured duration, repetition count,
soaks and the <=2% regression / <=2.5% CV budgets are unchanged. Both complete
independent matrices must be collected again before accepting this correction
as performance qualification. Earlier variance failures below remain historical
evidence; no passing case is selected across attempts and no claim of resolved
variance is made from the affinity regression alone. Ledger: **35/37**.

## Previous final-source QA and release handoff — 2026-10-18

Frozen framework, benchmark and workflow source is
`f64cc61e9f89b8f8663b4c21220c344a04354eef`. Both
[Security & Standards](https://github.com/infocyph/Foundation/actions/runs/39091597274)
and [native hosting / PHP 8.4–8.5 Swoole/OpenSwoole](https://github.com/infocyph/Foundation/actions/runs/39091597067)
finish **SUCCESS**. Local mandatory hooks and all thirteen PHPForge checks pass;
focused transport/report/workflow coverage passes 29 tests / 138 assertions.
The earlier 801-test detailed run precedes four additional regression cases;
that historical count is not presented as a final-source count.

Release artifact `12123154586` has digest
`sha256:15dc63c4ba570978624ec21c960433c7b2aab614d2e50de41b7a5544479a2829`.
Its manifest identifies source `f64cc61`, tested merge
`0003d1c26036926cf1e00993a25a2884b868feff`, PHP 8.5.11 and reusable
PHPForge workflow `fdec64cf4460f13116eb0e2f3405acadd3e84377`.
The source and tested merge share tree
`54e6aba7d735becf707c68cfcd0247bf4b856cf7`; the dependency lock remains
`b557fba7662dba32601c3840f69f7ac394f253d729246fcba08db44e26067cb9`.
Composer reports no advisories and no outdated direct dependencies;
`doctrine/annotations` is an abandoned development-only dependency.

[Infbyte acceptance attempt 3](https://github.com/infocyph/Infbyte/actions/runs/39090393036/attempts/3)
finishes **SUCCESS, 13/13 jobs**, at application source
`a8e908cb677f95fe50de5e7f2dfd0c8843d331bf`. Its actual PHP 8.5 Composer log
records Foundation `f64cc61`; the local application uses the same source and
passes all thirteen quality checks. A documentation-only update at
`ba7d48519bbba5087636849f8e0957354069e76c` also passes
[all thirteen current-head jobs](https://github.com/infocyph/Infbyte/actions/runs/39094315924).
Infbyte's checked, unapplied stable
Composer/README patch and release handoff are committed. Real published
Foundation `^4.0` resolution and stable/lowest application qualification remain
post-publication conditions; no alias or local repository simulates publication.

Both package archives have been inspected: Foundation excludes development,
tests, plans and CI files; Infbyte excludes internal QA and plans, including its
cutover patch. Composer metadata, runtime/development dependency roles, CLI
entrypoint, namespace and tag-derived version identity have been reviewed.
Release notes remain an unpublished draft pending the operator's release decision.

The final performance run is
[39091593840](https://github.com/infocyph/Foundation/actions/runs/39091593840).
It uses the same frozen dependency lock and full baseline `6274939`, and
independently enforces two complete twelve-case matrices on standard ARM
GitHub workers. The user explicitly retained standard workers; no paid runner,
organization upgrade or billing change is authorized or performed. Guest CPU
pinning and reported non-SMT topology do not establish physical-host exclusivity.
Budgets remain <=2% median RPM regression and <=2.5% CV. No incomplete or
selected collection of passing cases closes the full performance gate.
Superseded runs `39090356659` and `39091030858` were cancelled for the telemetry
and PHP-version capture corrections and are not qualification results.

## Complete final performance verdict — 2026-10-18

[Run 39091593840](https://github.com/infocyph/Foundation/actions/runs/39091593840)
finishes with all **24 measurement jobs successful**, while both complete
aggregate enforcement jobs **fail the unchanged variability gate**:
**primary 7 pass / 5 inconclusive; confirmation 7 pass / 5 inconclusive**.
There are no stable measured regressions. Inconclusive cases do not establish
performance equivalence and are not accepted by substituting passing cases
from the other batch.

Independent verification of all raw and merged reports confirms **240 measured
trials of at least 120 seconds**, **sixteen additional soak windows of at least
120 seconds**, zero failed measured responses, exact authentication/query/cache
accounting, live process-tree RSS samples, measured server CPU and per-worker
response counts. All 24 case pairs have distinct runner identities, while each
pair matches hardware, PHP, frozen dependency lock and tool/fixture identity.
Maximum measured load-generator CPU is **2.849%**. Complete native comparison
is recomputed independently from the downloaded merged reports with the same
2% regression and 2.5% CV limits.

| Scenario | Primary RPM change | Primary max CV | Primary verdict | Confirmation RPM change | Confirmation max CV | Confirmation verdict |
| --- | ---: | ---: | --- | ---: | ---: | --- |
| fpm-w1-c1 | +7.056% | 6.037% | Inconclusive | +9.069% | 2.335% | Pass |
| fpm-w1-c10 | +6.307% | 1.418% | Pass | +7.503% | 2.899% | Inconclusive |
| fpm-w1-c50 | +13.851% | 1.469% | Pass | +13.605% | 3.768% | Inconclusive |
| fpm-w4-c1 | +7.703% | 2.016% | Pass | +8.857% | 1.172% | Pass |
| fpm-w4-c10 | -1.117% | 4.579% | Inconclusive | +6.790% | 1.445% | Pass |
| fpm-w4-c50 | +7.582% | 0.904% | Pass | -3.071% | 3.098% | Inconclusive |
| runwire-w1-c1 | -1.981% | 3.736% | Inconclusive | -0.553% | 0.415% | Pass |
| runwire-w1-c10 | +5.378% | 4.258% | Inconclusive | +0.616% | 1.164% | Pass |
| runwire-w1-c50 | -1.210% | 1.769% | Pass | +7.001% | 4.049% | Inconclusive |
| runwire-w4-c1 | +3.447% | 1.966% | Pass | -1.079% | 0.633% | Pass |
| runwire-w4-c10 | +0.823% | 1.713% | Pass | -3.371% | 5.346% | Inconclusive |
| runwire-w4-c50 | +1.797% | 5.191% | Inconclusive | -0.530% | 2.183% | Pass |

The reproduced production FPM w4/c1 regression is resolved and independently
confirmed: both complete batches now pass (+7.703% and +8.857%). The connection
warm-up correction also prevents the previously observed single-worker bursts
in these native multi-worker samples. Residual variability remains even with
balanced request distribution and an unsaturated load generator; these facts
do not prove the complete cause of the remaining variation or justify a new
runtime change, workload change, discarded trial or relaxed threshold.

Actual native logs in both batches verify contained HTTP failure recovery,
client cancellation, supervisor-owned worker replacement and both post-replacement
soak windows for each revision. Memory in the second native soak window levels
off after replacement-worker warm-up. Direct/FPM and PHP 8.4/8.5
Swoole/OpenSwoole acceptance is green separately. This closes functional host
and bounded-soak acceptance, **not** stable throughput qualification.

Primary aggregate artifact **12124536227**, digest
`sha256:50de7704392742b6a015db176569b81f87599a1089069730a9c1b1aa6d8ba87f`;
confirmation aggregate artifact **12124407008**, digest
`sha256:c3ac16c36e9d9c196f601859ddb33f62d6c621ad1db89fd1eba43d1df98c06b8`.
Both contain their own complete merged baseline/candidate reports and verdict;
original raw per-case artifacts remain on the same run. The exact failing gate
is `php benchmarks/foundation4-host-merge.php build/paired-input build/paired-comparison/comparison.json`,
which rejects full-matrix acceptance with `Matched host comparison failed; inspect variance and regression evidence.`
No library defect is inferred solely from a high-CV baseline or candidate.

The Foundation release ledger is **35/37** after functional hosting/soak and
reviewed notes/artifact/publication metadata. Two criteria remain open:
stable complete-matrix performance and the dependent requirement that every
required release gate pass. Infbyte's candidate is green; genuine stable/lowest
published-package qualification follows Foundation publication. The user's
standard-worker decision remains in force. Both PRs remain draft, with no merge,
tag, publication, paid runner or billing change.

## Connection setup reproduction and correction — 2026-10-18

The slow native four-worker samples below are reproducible without Foundation:
a native Runwire fixture with CPU-bound requests, four workers and two server
CPUs sometimes accepts the initial client connection burst entirely in one
worker. Ten burst trials at each concurrency 10/50 have RPM CV 27.336% / 26.503%
and include single-worker samples. No native-library defect is inferred from
that connection arrival pattern.

The common load harness now opens fresh HTTP/1.1 connections at 5 ms intervals,
validates one complete response on each, retains the bounded native cURL
connection cache and starts the measurement clock only afterwards. Keep-alive,
server accept batching, native worker/event-loop ownership, application workload,
required measured responses and performance budgets are unchanged. Warm-up
responses are excluded from measured RPM, query/cache counts and latency; invalid
warm-up responses fail collection. Raw and merged reports retain successful
response counts by actual worker PID, connection warm-up evidence and new
connection counts during measurement. The common fixture identity becomes
`foundation-auth-postgres-redis-v2-warm-connections`; both revisions use it.
Redis prefixes also use the variant's isolated database identifier, preventing
baseline/candidate fixture cache keys from overlapping on their shared Redis
service. The seeded data, response and authentication/authorization work remain
equivalent.

The controlled local ramp trials use all four workers in all 20 samples;
CV is 0.717% / 1.025%. These are setup diagnostics, not application acceptance
or a production RPM claim. Real-HTTP regressions reproduce the missing warm
connection boundary before the change. Authenticated and minimal-response paths
verify connection reuse and exact measured counts; hostile warm-up and measured
response evidence remains rejected.
The focused transport/comparison/release guard suite passes 25 tests / 106
assertions, and the detailed full suite passes 801 tests / 29,880 assertions.

The permanent manual host workflow now carries the tested CPU allocation from
the diagnostic workflow into ordinary release qualification: load CPU 0,
backends CPU 1 and server CPUs 2 / 2,3. It refuses reported SMT/shared-core
allocations. Independent **primary and confirmation full matrices** each use
five 120-second trials after 60-second server warm-up, retain both 120-second
soak windows per four-worker host and enforce the original <=2% regression /
<=2.5% CV limits. Confirmation reverses baseline/candidate order. Each batch
downloads only its own twelve artifacts and independently runs the unchanged
complete-matrix comparator. No green collection job substitutes for enforcement.
Local and hosted checks on the final source must pass before any open release
criterion is closed; earlier immutable measurements below remain historical.

Final telemetry review also reproduces a separate reporting defect: the old
`peak_process_cpu_percent` reads `ps pcpu`, a lifetime utilization gauge, rather
than CPU used inside the sample window. Server samples now retain cumulative
CPU seconds; reports calculate window utilization from counter/time deltas and
label the original lifetime peak explicitly. Counter resolution is one second;
a single-sample short diagnostic reports window CPU as unavailable, not zero.
The load generator separately records its own user/system CPU consumed within
the measured interval using `getrusage`, allowing direct saturation checks.
Two report regressions fail against the old implementation and pass after the
correction. The earlier dispatched `f9cbda9` performance run is superseded and
cancelled; it must not be counted as final qualification.
Both new subprocess suites run with `E_ALL` and reject unexpected stderr. This
also reproduces and removes the PHP 8.4/8.5 implicit CSV-escape deprecation in
the numeric telemetry reader by declaring its escape behavior explicitly.

## Previous-source hosted verification — 2026-10-18

Executable candidate `d2954021adf346aa84ffcff1c95d41678deb2a8d` passes
[Security & Standards](https://github.com/infocyph/Foundation/actions/runs/39055130400)
and [native hosting plus PHP 8.4/8.5 Swoole/OpenSwoole serving](https://github.com/infocyph/Foundation/actions/runs/39055129960).
[Infbyte candidate acceptance, attempt 2](https://github.com/infocyph/Infbyte/actions/runs/39048507633/attempts/2)
passes all 13 jobs at application source `fcc2824`; Composer's job logs confirm
Foundation `d295402`. Infbyte's local installation is refreshed to that same
Foundation source; its focused lifecycle, release-entrypoint and module
integration suite passes 13 tests / 86 assertions; its full suite passes
51 tests / 395 assertions with local socket capability access. Composer reports no security
advisories; abandoned development-only `doctrine/annotations` remains reported.

Foundation QA artifact `12108919105` has digest
`sha256:cbae3e13bfc0cc1ee1d353e89f452d88c4a43bf71ce3fe9cb429633f553bf3f9`.
It records source `d295402` and tested merge
`0a6b51da61747a2691168a7d932f54b478820dd7`, sharing source tree
`4afcb8ce53956cbd02b50a6fd5a9b0d349dc2796`.

The [complete CPU-controlled ARM comparison](https://github.com/infocyph/Foundation/actions/runs/39055428140)
finishes with **7 pass, 0 stable regressions, 5 inconclusive cases**. All 13
measurement jobs succeed; the aggregate acceptance job correctly fails.
The comparison uses frozen baseline `6274939c395f75e5cf777aad5d7439f10792758c`,
candidate `d295402`, common tooling `60372849940ec5bdddc83095c278660855f5be66`
and dependency lock
`b557fba7662dba32601c3840f69f7ac394f253d729246fcba08db44e26067cb9`.
Every primary pair shares a runner, PHP version and dependency/tool identity.
All 120 primary trials and 10 separate confirmation trials meet the five-trial,
60-second measurement and 30-second warm-up requirements, with validated
authentication, database queries, cache hits and live process-tree RSS samples.
All eight additional soak windows run for at least 120 seconds. Failed responses
are zero throughout; these correctness results do not close throughput acceptance.

| Scenario | Median successful-RPM change | Maximum CV | Verdict |
| --- | ---: | ---: | --- |
| FPM w1/c1 | +13.026% | 1.543% | Pass |
| FPM w1/c10 | +9.818% | 0.547% | Pass |
| FPM w1/c50 | +7.540% | 1.220% | Pass |
| FPM w4/c1 | +6.742% | 2.582% | Inconclusive |
| FPM w4/c10 | +1.924% | 1.928% | Pass |
| FPM w4/c50 | +7.575% | 2.107% | Pass |
| Runwire w1/c1 | -0.624% | 1.193% | Pass |
| Runwire w1/c10 | -1.794% | 3.481% | Inconclusive |
| Runwire w1/c50 | +1.522% | 3.093% | Inconclusive |
| Runwire w4/c1 | +0.968% | 0.671% | Pass |
| Runwire w4/c10 | +7.085% | 15.125% | Inconclusive |
| Runwire w4/c50 | -1.819% | 15.555% | Inconclusive |

The independent FPM w4/c1 confirmation is also **inconclusive**: +0.847% median
RPM, baseline CV 1.873% and candidate CV 6.778%. Its green measurement job
only confirms successful collection and response validation. It does not enforce
the performance budget and must not be represented as performance acceptance or
substituted for a primary case. Stable FPM cases improve after graph reuse;
the remaining noisy FPM case has not yet received stable independent confirmation.

Worker identities in raw successful-response telemetry establish an additional
source of variation: baseline Runwire w4/c10 trial 2 sends all 4,518 responses
to one worker (4,508.92 RPM); candidate trial 3 sends all 4,149 to one worker
(4,139.55 RPM). Candidate w4/c50 trial 5 similarly sends all 4,233 responses to
one worker (4,183.50 RPM), while trials using several workers exceed 6,400 RPM.
The recorded worker counts sum to successful responses in every measured trial.
This establishes uneven request distribution, not its complete cause or a
Foundation regression. A bounded local native-Runwire probe compares existing
accept-batch settings 32 and 1 over ten connection bursts at each concurrency
10/50; both remain uneven, so no listener-policy change or workaround is adopted.
Runwire retains ownership of connection acceptance and worker scheduling.

Primary aggregate artifact `12110210969` has digest
`sha256:847c7af5fc2ac86b411fbb8f81898fd507c4c3141312e6d7dca02eaa9d0b4477`.
Independent confirmation artifact `12109209784` has digest
`sha256:c9df39a40d64bc8a7110cd9173fb3327e9f65734057af1c4fd44be8e058831b3`.
The original per-case raw artifacts remain attached to the same run. Preserve
all results together; do not merge passing cases across runs or relax the
2% regression / 2.5% CV budgets. The ledger remains **33/37**. Release blockers
remain stable complete-matrix performance, independent confirmation and final
sign-off; Infbyte's genuine stable/lowest resolution follows Foundation publication.

## Production graph reuse correction — 2026-10-17

The unchanged FPM w4/c1 candidate reproduces its regression independently:
-2.831% RPM (baseline/candidate CV 0.343% / 1.551%) in
[attempt 3](https://github.com/infocyph/Foundation/actions/runs/39047995755/attempts/3),
and -3.124% (CV 0.531% / 0.641%) in the
[candidate-first control](https://github.com/infocyph/Foundation/actions/runs/39052678007).
Order alone does not explain this case. FPM w1/c10 does not reproduce its earlier
slowdown: -0.545%, CV 1.312% / 1.334%. Targeted runs remain diagnostics, not a
fresh complete-matrix pass.

[Matched Xdebug profiles](https://github.com/infocyph/Foundation/actions/runs/39052363971)
capture 24 requests per revision and identify graph composition/validation as
the dominant common boot cost. Instrumented medians are 112.701 / 112.784 ms;
these are diagnostic timings, not sustained acceptance. Source review and two
regression cases reproduce a separate deterministic inefficiency: each provider
contributes twice during a production web load. Production now prepares its
graph before InterMix freezes it, then reuses that graph for the native
production loader and its compiled-identity checks. This removes the second
composition pass while retaining Core inputs and native specialist providers.

The focused web-release suite passes 12 tests / 113 assertions; the full suite
passes 796 tests / 29,842 assertions. Final quality hooks, exact-source hosted
QA/serving and Infbyte integration pass; complete paired performance acceptance
remains open as recorded above. Earlier `9fbd8cd` quality and `3f295de`
performance results below precede this runtime change.

## Coordinated Foundation / Infbyte release preparation — 2026-10-17

Infbyte's current application candidate is `fcc2824` on `feature/infbyte-4`;
its [13-job candidate acceptance](https://github.com/infocyph/Infbyte/actions/runs/39048507633)
is green. Foundation `cdefa10` also passes
[quality and production consumers](https://github.com/infocyph/Foundation/actions/runs/39048504642)
and [native serving](https://github.com/infocyph/Foundation/actions/runs/39048504621).
Both workspaces resolve the same current
Core library versions and source references, including published Runwire 2.1.2.
Refreshed Composer checks report no outdated direct requirements and no security
advisories; `doctrine/annotations` remains an abandoned development-tool dependency.

The pending tag-release wiring exposed an independent release-order defect:
tag pushes could skip quality checks while invoking PHPForge publication.
Regression tests reproduce the missing prerequisite and pass with the corrected
job graph. Foundation publication now waits for PHPForge QA, production
consumers, release constraints/audit/evidence, and the existing native-host
workflow, reused through `workflow_call` only on tags. Normal PR host checks
retain their existing ownership. No native mechanism or production API is added.
Infbyte publication similarly waits for stable-only Foundation validation and
its published Core/DBLayer consumers. Its candidate checks accept the declared
development candidate or an actual stable 4.x identity, rejecting other majors,
prereleases, development aliases and stable-mode VCS overrides. Metadata fixtures
are unit checks, not evidence of a published Foundation 4 package.

Local detailed suites pass 794 Foundation tests and 51 Infbyte tests. Final
commit/hosted evidence is recorded in the corresponding PRs after the final
checks; these counts alone do not close release acceptance.

The previous x86 runner reported four CPUs across two cores with two threads
per core; three pinned workload roles therefore could not occupy independent
reported cores. The [ARM comparison 39047995755](https://github.com/infocyph/Foundation/actions/runs/39047995755)
checks for four CPUs without reported sibling threads before measuring. It uses
diagnostic tooling `7140c1a`, unchanged executable candidate `3f295de`, frozen
baseline `6274939`, the same dependency lock and all original workload/budget
requirements. This tests an environment limitation; it is not a runtime fix or
a performance acceptance claim. Preserve the complete result, including failures.

The complete ARM result is **6 pass, 2 measured regressions, 4 inconclusive**.
FPM w1/c10 measures -8.037% (maximum CV 2.422%); FPM w4/c1 measures
-2.550% (maximum CV 1.740%). All 120 measured trials and eight soak windows
complete without failed responses. Aggregate artifact `12106763398` has digest
`sha256:9b4850baa342fe283174b5f2bf7f20b91a8efa583c7750d3759ece100d74297a`.
An unchanged-revision GitHub rerun will investigate reproducibility; neither
runner selection nor a mixture of successful cases closes the release gate.

The comparator now enforces the plan's full 60-second minimum for every measured
trial and at least 30 seconds of warm-up in both reports. Previously it accepted
30-second trials and matching absent/short warm-up values. Regression cases
reproduce the incorrect acceptance before this correction. Replaying both complete
CPU-controlled reports with the stricter comparator leaves their case verdicts
unchanged: their actual measurements already satisfy these duration requirements.
The executable framework, frozen baseline and performance budgets are unchanged.

Final release order remains Foundation performance/sign-off, operator-controlled
Foundation 4 publication, then Infbyte's stable `^4.0` manifest without a VCS
override and genuine stable/lowest production resolution. Neither a unit fixture
nor a candidate branch substitutes for that published dependency gate.

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
pass. Current-source [Security & Standards 39007156463](https://github.com/infocyph/Foundation/actions/runs/39007156463)
and [native/hosted serving 39007156290](https://github.com/infocyph/Foundation/actions/runs/39007156290)
are green, including all four PHP 8.4/8.5 Swoole/OpenSwoole serving lanes and
stable/lowest production consumers. Sustained performance acceptance remains open.
The frozen baseline and 2% RPM / 2.5% CV limits remain unchanged. Keep PR #20
draft; merging, tagging, publication and Infbyte changes remain separate.

> Earlier dated checkpoints below retain their original dependency/source evidence.

## Completed controlled comparison — 2026-10-17

Executable candidate `3f295de1a2a5a6287d016de8afe0903a0907e675` and tested
PR merge `15f9ea99db73ba90b1750914dfcbfe61168d021e` share tree
`f42bdab5795187637319e349e5334edd72a3f6bc`. QA and every paired variant use
lock SHA-256 `b557fba7662dba32601c3840f69f7ac394f253d729246fcba08db44e26067cb9`.
The QA release-evidence archive digest is
`sha256:ef6c691a5524db8db1287363ca803385146d7ed3ae20a2ab0edabc797925d82e`.

The [complete CPU-controlled comparison 39010682913](https://github.com/infocyph/Foundation/actions/runs/39010682913)
uses diagnostic tooling `42657367badbc42ad61eb115802e5837765d4344`, while
explicitly checking out unchanged application candidate `3f295de` and frozen
baseline `6274939c395f75e5cf777aad5d7439f10792758c`. Server/load-generator/backend
CPU allocation is recorded per pair; both variants use the same allocation.
The original comparator and 2% RPM / 2.5% CV budgets are unchanged.

**Complete verdict: 8 pass, 4 inconclusive variance, 0 stable regressions.**
All twelve host/worker/concurrency cases per variant have five measured trials of at
least 60 seconds after a separate 30-second warm-up: 120 measured trials and
eight additional 120-second soak windows in total, all with zero failed
responses. Telemetry and response evidence are complete; performance acceptance
is not. Three FPM failures already exceed the variance limit in the baseline.

| Inconclusive case | Candidate median RPM change | Baseline CV | Candidate CV |
| --- | --- | --- | --- |
| FPM w1/c1 | +3.742% | 4.582% | 1.884% |
| FPM w1/c10 | +0.190% | 3.505% | 1.406% |
| FPM w1/c50 | +1.125% | 3.592% | 2.415% |
| Runwire w4/c10 | +5.529% | 1.704% | 9.620% |

The aggregate artifact is `foundation4-affinity-comparison`, ID `12092548761`,
digest `sha256:b90db39d67b4c60be057bbc0a0d9a9757bdef7823dd5ba0fcec096f426cc48ea`.
It includes the complete baseline/candidate reports and strict comparator result.
The previous ordinary-runner attempts also failed the complete gate; their
stable regressions did not recur as stable regressions here. No reproducible
performance defect is claimed fixed. Passing cases from separate attempts are
not combined. Stable full-matrix evidence remains a release prerequisite;
repeating shared-runner measurements without addressing variability is not a fix.

Core integration requires native application/DI/request/task wiring, shared
identities, cancellation and owner-correct cleanup, rather than package presence
alone. Foundation composes InterMix, Webrick, CacheLayer, Runwire, ArrayKit and
UID without reproducing their mechanisms. A fresh unchanged-source recheck
passes **59 tests / 1,367 assertions**, covering native provider/factory ownership,
cache identity, isolated execution, direct/intermediary/borrowed contexts,
coroutines, cancellation and exception restoration.

Remaining pre-merge gates are stable sustained performance evidence and final
documentation/release sign-off. The current catalog is three modules/eight
optional features. PR #20 remains draft; no merge, tag or publication is claimed.

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
