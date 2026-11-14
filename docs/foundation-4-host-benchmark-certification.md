
# Foundation 4.0 — Native host performance certification

This procedure certifies **Foundation 4 only**. It is not a legacy-version compatibility test. The routine PR host job runs a minimal-JSON smoke and a selected-owner native-read smoke on shared GitHub hardware; neither is a production-equivalent throughput certification.

## Paired GitHub qualification

Routine PR acceptance requires the configured quality, security, production-consumer,
documentation and native-host checks. Run sustained throughput qualification separately
through the manual workflow on the immutable candidate and reviewed dependency locks.
This keeps an unstable shared-runner comparison from blocking routine PR acceptance;
it does not turn failed performance evidence into a passing release qualification.

Use GitHub Actions for the paired comparison and Docker for local diagnostics. An authorized maintainer can explicitly opt a same-repository PR back into blocking source qualification by applying the `foundation4-certify-source` label; the next PR head update runs **only the source-only** full paired matrix via Security & Standards' reusable workflow call against the immutable Foundation 4 baseline. It uses the exact PR head and ARM runner. Leave this label off routine PRs when qualification is being managed separately. Historical independent-lock upgrade benchmarking is optional for this release; when requested, it requires separate attested inputs and qualification. Labeling is an execution request, not a passing verdict.
The [manual workflow](../.github/workflows/foundation4-host-certification.yml) supports a full `authenticated-read`
PostgreSQL/Redis profile, distinct immutable baseline/candidate sources, one
resolved dependency lock and benchmark revision, and the same runner for each
pair. It records the workflow attempt and VM boot identity per matrix case;
this proves paired VM reuse, not dedicated physical infrastructure or production
capacity. Other matrix cases may use different runners.

Each pair generates its authentication, MFA-protection and recovery-code secrets
once before either source runs, preserving caller-supplied values. Both children
inherit those exact inputs. Schema 3 reports record a SHA-256 fingerprint of the
three length-prefixed inputs in every scenario, without exposing their values.
The comparator rejects missing, malformed or different fingerprints. Separate
scenario runners may use different secrets; their baseline/candidate pair must
match. Local authenticated comparisons must likewise export the same three
variables (`FOUNDATION_HOST_AUTH_SECRET`, `FOUNDATION_HOST_MFA_SECRET` and
`AUTH_OTP_RECOVERY_HMAC_KEY`) before invoking either source.

Schema 2 reports did not attest these inputs. Retain and replay those historical
reports with their frozen tooling; do not relabel them as schema 3 or treat them
as matched-input qualification.

The twelve cases cover FPM/Runwire, one/four workers and concurrency 1/10/50.
Each revision has a recorded 300-second warm-up followed by five >=300-second
trials. These windows do not establish host stability or make an unstable
comparison acceptable. Two independent
complete batches run all twelve cases; the confirmation
batch reverses baseline/candidate order. Four-worker/concurrency-10 jobs include
two >=120-second soak windows per host/revision and batch, continuous live CPU/RSS,
response/query/cache checks and recovery. The controller and load generator use
CPU 0, PostgreSQL/Redis use CPU 1, and the server uses CPU 2 or CPUs 2–3.
The allocation requires four reported cores without SMT siblings; standard x64
workers reporting two cores with SMT are rejected before measurement. The default
manual runner is `ubuntu-24.04-arm`.

Native-host and paired jobs share one prerequisite installer. Repository setup
retries recognized transient Launchpad transport errors and timeouts up to three
times, with a 90-second command deadline and five-second termination grace per
attempt. Other repository errors fail immediately; exhausted retries remain a
failed job before any measurements. Package-manager calls have a 180-second
deadline and bounded network acquisition retries. Package repositories and
signature checks are unchanged.

The paired job allows 85 minutes for both revisions, including their warm-ups,
trials, diagnostics and soak windows, within a 95-minute job limit.
Both complete batches must pass the unchanged 2.5% CV and 2% median successful-RPM
regression limits. Passing cases from different batches or attempts are never
combined. Unstable or failed comparisons cannot be certified by the environment
approval.
Record current verdicts, exact source/dependency identities and artifact hashes
in the release pull request. Preserve failed and inconclusive evidence.

The reference profile enables OPcache and disables JIT explicitly for both
revisions and the load generator. The harness records and validates the actual
FPM and Runwire process settings before load; inherited JIT settings cannot
silently change this profile. Compare a separately qualified JIT-enabled profile
only when representative evidence justifies it.

The dedicated-host procedure below remains available for controlled reproduction
and deployment-specific capacity validation. Routine PR smoke and local Docker
results remain diagnostic.

## Separate source-only and dependency-upgrade verdicts

Historical whole-upgrade benchmarking is optional for Foundation 4. The last 2.x release, `2.1.1`, is the maintainer-selected historical reference if that additional comparison is performed. Its source, dependency lock provenance and compatible workload need independent qualification; a reconstructed lock must remain labelled as reconstructed. This optional comparison does not change configured CI budgets or turn incomplete measurements into a performance certification.

The manual paired-host workflow accepts **two distinct modes**. The default
`source-only` mode uses the same resolved Composer lock in both checkouts and
still rejects any differing lock SHA-256. The `dependency-upgrade` mode resolves
independent baseline and candidate locks, keeping the same benchmark tooling,
workload fixture, PHP/INI, services, host CPU allocation and baseline/candidate
run order. It does **not** rewrite the old stack to use the candidate lock.

For an upgrade run, commit a reviewed JSON policy into the candidate source and
provide its relative path through `upgrade_allowlist_path`. Its format is:

```json
{
  "format": 1,
  "allowed_changes": {
    "infocyph/runwire": {
      "before": {
        "version": "EXACT_BASELINE_VERSION",
        "source_ref": "EXACT_BASELINE_SOURCE_SHA",
        "dist_ref": "EXACT_BASELINE_DIST_SHA"
      },
      "after": {
        "version": "EXACT_CANDIDATE_VERSION",
        "source_ref": "EXACT_CANDIDATE_SOURCE_SHA",
        "dist_ref": "EXACT_CANDIDATE_DIST_SHA"
      }
    }
  }
}
```

Replace these placeholders with the exact Composer lock identities for **every
changed package**, including dev/tooling dependencies, and `null` where a source
or dist reference is absent. A package addition/removal uses `null` as the entire
`before`/`after` identity. The prepare job derives both lock manifests from
actual Composer files and refuses any missing, unexpected or mismatched change
*before* launching the expensive 24-job matrix. The final report separately
labels `comparison_kind: dependency_upgrade` and lists the changed package
names. Identical locks, tool/fixture drift, invalid load evidence, unfinished
soaks, unstable throughput or a >2% successful-RPM regression still block a
verdict.

This distinction matters: a source-only pass cannot validate the old-to-new
dependency cutover, and a whole-upgrade pass cannot substitute for a same-lock
source attribution result. Record both immutable locks and any requested upgrade
comparison with the PR. No benchmark certification is claimed until the two primary and
confirmation matrices actually finish with acceptable evidence.


## Measurements already automated

`benchmarks/foundation4-host-certify.sh` compiles a trusted Foundation Webrick/InterMix generation and launches real Nginx/PHP-FPM and native Runwire listeners. It validates each HTTP/1.1 status and response body, tests concurrency 1/10/50 with one/four workers, samples process-tree CPU and RSS during load, and exercises failed-request recovery, client cancellation, and prefork worker replacement.

The ordinary GitHub job checks 36 minimal-route samples (three repetitions), then a separate `native-read` profile with real DBLayer SQLite selects, CacheLayer file-cache reads and credential digest verification (two repetitions). Both profiles exercise FPM/Runwire and two 30-second four-worker soak windows, with contained error, cancellation and Runwire worker-replacement checks. The native fixture uses a low-work-factor testing hash and a small deterministic dataset; **it is not a production authentication, authorization or distributed database workload**. All outputs are artifacts, not controlled baselines.

For the selected-owner profile, use `FOUNDATION_HOST_WORKLOAD_PROFILE=native-read` instead of `json-minimal`. The comparator rejects comparisons between different profile identities. Shared-runner smoke fixtures remain insufficient to approve the 2% release budget.

## Controlled sustained comparison

Run the **same version of benchmark tooling** on a dedicated, stable host with two separately checked-out Foundation 4 sources: a known-good frozen baseline and the proposed release candidate. For source attribution use an identical resolved Composer lock; for the reviewed whole-upgrade comparison use independently resolved, attested locks. Never mix verdict types. Do not modify working trees during measurement. Keep the PHP version/extensions and INI, services, datasets, routes, OPcache settings and OS configuration identical. Disable competing workloads and verify that the load generator can saturate the host without becoming the bottleneck.

For each checkout, install the selected services, then run (using a different result directory for each):

```bash
export FOUNDATION_CERTIFICATION_HOST_ID='dedicated-host-identifier'
export FOUNDATION_HOST_WORKLOAD_PROFILE='json-minimal'
export FOUNDATION_HOST_REPEATS=5
export FOUNDATION_HOST_REQUESTS=1000
export FOUNDATION_HOST_MIN_SECONDS=30
export FOUNDATION_HOST_RESULT_DIR="$PWD/build/foundation4-host-cert"

bash benchmarks/foundation4-host-certify.sh
```

The 30-second minimum is a **lower bound**, not sufficient proof of a longer production-like soak. A 5×30-second profile comparison remains diagnostic if the application only serves `/json`. Use multiple measured trials on the same machine and repeat the run on another controlled window to establish stability. Do not use a GitHub ephemeral runner identifier as an attestation of physically matched hardware.

Compare evidence using the exact tooling revision that collected it:

```bash
php benchmarks/foundation4-host-compare.php \
  /path/to/baseline/report.json \
  /path/to/candidate/report.json \
  build/foundation4-host-comparison.json
```

The comparator fails closed unless both reports contain the complete matching host/concurrency/worker matrix, matching declared physical host identity, PHP version, workload profile, immutable benchmark-tooling SHA, workload fixture identity and sample configuration. Both source-only and upgrade comparisons reject missing, invalid or differing tooling/fixture identities. Source-only comparisons require an identical dependency-lock SHA-256; upgrade comparisons require different locks and a reviewed exact dependency delta. Each case must contain at least five response-correct sustained observations of at least 120 seconds. It recalculates successful RPM from completed requests and elapsed time; rejects missing telemetry and internal inconsistencies; and refuses unstable evidence with RPM coefficient of variation greater than 2.5%. For every comparable stable case, the candidate median successful RPM must be at least **98%** of the baseline. A high-variance result is *inconclusive*, not a pass.

## Release acceptance

The comparison command alone does not certify a release. Perform dedicated production-equivalent workload profiles for authenticated/authorization requests, actual selected database reads/writes, cache hits/misses and service interactions, using valid fixture data and the real native library owners. Record cache hit rates, query counts, connections, worker-generation churn, errors and timeouts. Use a bounded longer persistent-host soak for memory growth, failure recovery, cancellations, recycle and shutdown; capture live peak and steady-state RSS, CPU and p50/p95/p99 and note client saturation.

Treat the release as blocked if any selected workload is untested, unstable, materially regresses, or has unbounded resource growth. **Do not relax the 2% budget** or substitute the short shared-runner smoke for matched sustained certification.

After final changes, repeat all required PHP 8.4/8.5 lowest/stable, production `--no-dev`, compiler, release trust, selected specialist and host workloads against the *same immutable Foundation source and resolved dependency set*. Record exact SHA, workflow URL and artifact hashes with the release pull request. Infbyte application publication follows Foundation publication; merging, tags and publication remain operator actions.

## Frozen-lock source replay

Preparation normally resolves current dependencies. To repeat a reviewed source-only graph, provide `replay_run_id` and `replay_lock_sha256` together. The workflow downloads `foundation4-paired-host-locks` from that run in this repository, verifies both captured lock files against the reviewed digest before Composer installation, then uses `composer install`. Missing, malformed or mismatched evidence stops the run; it never resolves a replacement graph. Replay is intentionally restricted to source-only mode.

`candidate_ref` selects an immutable measured source SHA independently of the workflow's tooling revision. The same selected tools and input fixture are overlaid into both source checkouts. Reports record the actual measured source identities and the shared tooling SHA. Keep the workflow dispatch ref stable while collecting evidence.

Set `FOUNDATION_REPLAY_RUN_ID` and `FOUNDATION_REPLAY_LOCK_SHA256` from the
reviewed lock receipt in the release pull request before replaying the current
commit:

```bash
gh workflow run foundation4-host-certification.yml \
  --repo infocyph/Foundation --ref feature/foundation-3 \
  -f candidate_ref="$(git rev-parse HEAD)" \
  -f baseline_ref=c220759fcbd6654fead843599712a5b79685f02d \
  -f comparison_mode=source-only -f host_runner=ubuntu-24.04-arm \
  -f replay_run_id="${FOUNDATION_REPLAY_RUN_ID:?Set the reviewed workflow run ID}" \
  -f replay_lock_sha256="${FOUNDATION_REPLAY_LOCK_SHA256:?Set the reviewed lock SHA-256}"
```

For the unresolved Runwire w1/c50 signal, add `-f diagnostic_case=runwire-w1-c50` to run both order-reversed batches for that case. The `hosts-w4-c1` selection runs **only** FPM and Runwire at four workers/concurrency one, each in primary and reversed-order confirmation batches. Both selections are explicitly **non-certifying**: they publish `foundation4-diagnostic-pair-*` artifacts with diagnostic-only scope markers and do not invoke full-matrix acceptance. A successful diagnostic run cannot close the full performance qualification. Ordinary PR certification and the default manual mode still require all 24 pairs and both comparison verdicts.

To investigate w4/c1 measurement stability without moving the frozen Foundation candidate, a branch-limited `push` workflow at `.github/workflows/foundation4-w4c1-control.yml` invokes the existing reusable certification workflow with `diagnostic_case=hosts-w4-c1`. Its only push trigger is a change to that diagnostic caller on `diagnostics/foundation4-w4c1-controls`. The workflow records diagnostics, **never** full-matrix acceptance. This avoids relying on `workflow_dispatch`, which requires the workflow file to exist on the repository default branch.

The caller freezes baseline `d6ea8a2a33194396d3dfd93089449aab3653ab81`, candidate `3fcebbfe06165a7363ea9e21ef55831469041fb3`, the reviewed lock artifact from run `43570637823`, and lock SHA-256 `47714e7847c611f6cd8cef4caa45ac9ffae71188c93b7424d9daa867866f4ec7`. These revisions are runtime-identical; the diagnostic branch supplies shared tooling only. It deliberately requires an explicit committed update to the branch-scoped caller to rerun, rather than silently retrying an unchanged full qualification.

The two source revisions have identical tracked runtime/resource/bin files; the diagnostic branch supplies only shared benchmark tooling. Examine each host's baseline/candidate median successful RPM, five-trial CV, load CPU, worker distribution, source identities, fixture fingerprints, before/after host and backend counters, and both execution orders. Any apparent >2% difference or >2.5% CV with identical runtime is a measurement-calibration failure, not an attributable code regression. Primary and confirmation cases execute on separate GitHub VMs; same-VM baseline/candidate pairing does **not** establish stable hardware across jobs or attempts. Repeat focused controls across independent windows or move them to controlled infrastructure if variance persists. Never combine passing controls from different attempts or present them as certification.


Each measured trial now records diagnostic snapshots immediately before and after its timed load: CPU counters including steal time, pressure totals, cgroup throttling and I/O counters, available CPU frequencies, PostgreSQL activity, Redis activity, and selected Docker service image IDs/resource gauges. These probes execute outside the load window. Unavailable host counters are explicitly marked; credentials and DSNs are not included in output. Use matching before/after deltas to distinguish application work from host/backend contention. Diagnostic snapshots do not change acceptance calculations or budgets.

### Frozen source-identical host control result — November 14, 2026

Control run [#43589969910](https://github.com/infocyph/Foundation/actions/runs/43589969910) compared frozen revision d6ea8a2a against 3fcebbfe with unchanged runtime files, reviewed Composer lock and identical authentication inputs within each physical job. The source-identical controls completed forty sustained 300-second trials without failed HTTP requests. The full-matrix certification run was intentionally not invoked.

| Host | Primary delta / max CV | Confirmation delta / max CV | Interpretation |
| --- | --- | --- | --- |
| FPM w4/c1 | **−4.587% / 4.019%** | +1.318% / 2.165% | **Unstable identical-code control** |
| Runwire w4/c1 | +0.650% / 1.443% | −1.950% / 1.101% | Earlier −3.35% regression not reproduced |

The two separate GitHub VMs differed in same-source baseline throughput by **−9.366% FPM** and **+6.716% Runwire**. Their combined recorded CPU-pressure SOME time across all forty trials was **750.5 seconds**. Recorded CPU steal, cgroup throttling, Redis misses/rejections and PostgreSQL deadlocks/conflicts were zero; the CPU-frequency probes were unavailable (80 snapshots). CPU scheduling pressure is observed, but its origin is not established; the earlier zero-CPU-pressure claim was incorrect.

The strict diagnostic evidence workflow [#43596773359](https://github.com/infocyph/Foundation/actions/runs/43596773359) fetched the actual artifacts, reconstructed medians/CVs and diagnostics, and **failed calibration as designed**, uploading a JSON and Markdown report. This does not weaken the **2% regression / 2.5% variance** budgets or imply an application source defect. **Source release performance qualification remains OPEN.**

A second branch-isolated trial changes only the host family to the standard public-repository x64 ubuntu-24.04 runner, preserving the two immutable source revisions, Composer lock and all other workload parameters. Evidence between x64 and ARM is never combined into one certification result. If x64 also fails source-identical controls, require a controlled dedicated four-physical-core host with repeatable performance across execution windows before proceeding with both complete matrices and soaks. A fresh GitHub VM alone is not hardware-stability evidence.

### Dedicated runner prerequisite for release performance certification

The alternative [x64 diagnostic run #43597250946](https://github.com/infocyph/Foundation/actions/runs/43597250946) stopped during host prerequisites: **CPU 0 reported SMT siblings 0–1**. All four paired jobs rejected this topology before benchmarks began. This is an intentional rejection, not evidence of a PHP/FPM/Runwire regression. Do **not** bypass that guard or reuse the failed run as a throughput comparison.

The paired-host workflow now accepts an opt-in custom GitHub Actions label **foundation4-perf-dedicated**. Its use requires an actual available repository or organization runner with:

- Four isolated physical CPU cores available as CPUs 0–3; each of /sys/devices/system/cpu/cpuN/topology/thread_siblings_list must equal N (SMT disabled or topology otherwise fully isolated). The load controller stays on CPU 0, PostgreSQL/Redis on CPU 1, application on CPUs 2–3.
- Linux, Docker service-container support, writable benchmark workspace, installation privileges for the existing reviewed prerequisites, and enough RAM/disk for real PostgreSQL, Redis and PHP 8.4.
- Stable CPU and power behavior with no competing workloads. Record host hardware identity, CPU pressure, frequency telemetry if available, RPM CV, resource bounds, and the full authentication and dependency fingerprints.
- Repeatable results **between batches and across separate windows**, not merely a newly provisioned VM. Make source-identical FPM and Runwire w4/c1 controls consistent in both execution orders before interpreting any source-level regression.

The diagnostics caller **already selects** `foundation4-perf-dedicated`; do not change that label or substitute hosted ARM/x64 merely to obtain a passing job. Once a physical runner meeting the topology, isolation and stability requirements is attached to the repository, make the explicitly gated source-identical calibration request described below. Keep the immutable baseline/candidate SHA and lock replay unchanged. Review the complete evidence and both orders before requesting either full 12-scenario matrix. Never merge, tag or publish from a diagnostic-only green result.

**Workflow syntax QA (November 14):** [run #43600577345](https://github.com/infocyph/Foundation/actions/runs/43600577345) passed the pinned `actionlint` validation of all five performance workflows and the 15-feature-test / 165-assertion guard suite. Only ShellCheck SC2016 informational notices from deliberately single-quoted inline PHP scripts are excluded; all other workflow syntax and shell findings remain active.

### Staged dedicated-runner certification: fail closed, source pinned

The diagnostic branch now has a **two-commit opt-in** for a real, installed runner labelled `foundation4-perf-dedicated`, with strict branch-scoped GitHub Actions workflows. **Neither workflow runs from an ordinary push:** both gate on an exact commit message. Keep source and lock SHA values frozen.

1. Once the dedicated runner is truly registered and isolated, change only `.github/workflows/foundation4-w4c1-control.yml` (for example its request comment) and commit with the exact subject `perf: qualify dedicated controls`. The controls caller runs FPM/Runwire w4/c1 both orders, authenticates every request and immediately verifies the trial evidence against the **2% absolute drift / 2.5% CV** calibration requirements. A run fails and uploads the diagnostic artifact if the hosts remain unstable.
2. After that workflow **succeeds**, change only `.github/workflows/foundation4-dedicated-certify.yml` and commit with exact subject `perf: certify Foundation 4`. The second workflow uses GitHub Actions' current-run API to locate the most recent controls run, verifies that run succeeded, downloads its calibration receipt, checks the exact immutable baseline/candidate/tooling/Composer identities and **all four consistent scenarios**, and only then invokes the existing complete source-only matrix with required soaks on the same dedicated runner label.
3. Full qualification still requires both comparison jobs to pass the unchanged **2% RPM regression** and **2.5% trial CV** limits, plus actual workloads, responses, backend evidence and resource/soak checks. A stable control is **not** a certification result. Do not merge, tag, publish or close the release blocker unless full qualification and normal QA pass on the **final exact source SHA**.

The dedicated label is a prerequisite backed by real physical capacity, not a setting that magically provisions a runner. A queued job or unavailable label does not qualify hardware. The two workflows cannot automatically create the host, and neither weakens CPU-topology checks or marks a failed release gate green.

## Historical qualification evidence

These receipts describe earlier attempts, not qualification of the current release candidate. Current source identities and complete comparison verdicts belong in the release pull request. The historical independent-lock comparison is optional. The [completed source-only run #43217583759](https://github.com/infocyph/Foundation/actions/runs/43217583759) compared candidate `cd4fba90d6e415bbe7af04400c59ff5374be1cac` with Foundation 4 baseline `c220759fcbd6654fead843599712a5b79685f02d`. All 24 measurement jobs and 240 measured trials completed with zero failed requests. Primary classified 7 pass / 4 high-variance / 1 regression; confirmation classified 9 pass / 3 high-variance / 0 regressions. Both acceptance jobs failed.

The earlier failed scenarios passed both fresh batches: FPM w1/c50 +0.626%/-1.003%, Runwire w4/c1 +0.887%/-1.728%. A new primary Runwire w1/c50 signal (-8.318%, CV 1.316%) was not established by the noisy confirmation (+1.969%, CV 2.788%). A Composer HTTP 429 error cleared on retry before the final confirmation comparison. No runtime repair or performance pass is claimed.

The [frozen-lock diagnostic run #43237331915](https://github.com/infocyph/Foundation/actions/runs/43237331915) subsequently repeated only Runwire w1/c50 with those exact measured sources and lock, using shared tooling `6f9a34e2e689d6b0155cc78157ca5c349efb955b`. Primary measured +1.813% median successful RPM but remained inconclusive (baseline CV 3.205%); reversed-order confirmation measured +2.991% with maximum CV 1.800%. All 20 sustained trials completed with zero failed requests. Captured counters showed no CPU steal, PostgreSQL deadlocks/conflicts/temp spill, or Redis rejected connections/evictions/cache misses. The earlier negative signal was not reproduced; its original cause is not established. These explicitly diagnostic-only artifacts cover one case and cannot certify either complete performance matrix or a later source revision.

[Primary artifact 13947562197](https://github.com/infocyph/Foundation/actions/runs/43217583759/artifacts/13947562197) and [confirmation artifact 13947492490](https://github.com/infocyph/Foundation/actions/runs/43217583759/artifacts/13947492490) retain the full reports and trial evidence. Both sources used lock `fcfff7349f7e19bfd76687ec94184ba508ac573abff84b5c2ccd9cc61444f793`. Compared with [earlier run #43172457224](https://github.com/infocyph/Foundation/actions/runs/43172457224), lock `28ca535ff6f08595a48613384f874a5192dee335188247129d007fd2cb63c263`, only development psalm/plugin-laravel changed 4.19.1 to 4.19.3; all other package references and production entries were unchanged. This fresh run is not an exact old-lock repeat. Earlier primary/confirmation counts were 4/7/1 and 7/4/1 (pass/variance/regression); their two stable negative cases were -2.520% and -2.560%. Keep both attempts' evidence.

Before release, freeze the final source SHA and qualify PHP 8.4/8.5 latest/lowest quality checks, specialist operations, native hosts, the actual Composer archive and isolated production consumers. Source-only and independent-lock dependency-upgrade comparisons provide separate verdicts; neither substitutes for the other. Historical dependency-upgrade benchmarking is optional for this release. Merge, tagging, package publication and downstream Infbyte deployment remain manual release decisions.
