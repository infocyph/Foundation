
# Foundation 4.0 — Native host performance certification

This procedure certifies **Foundation 4 only**. It is not a legacy-version compatibility test. The routine PR host job runs a minimal-JSON smoke and a selected-owner native-read smoke on shared GitHub hardware; neither is a production-equivalent throughput certification.

## Paired GitHub qualification

Use GitHub Actions for the paired comparison and Docker for local diagnostics. Before PR merge, an authorized maintainer can apply the `foundation4-certify-source` label to the same-repository PR; the next PR head update runs **only the source-only** full paired matrix via Security & Standards' reusable workflow call against the immutable Foundation 4 baseline. It uses the exact PR head and ARM runner. The independent-lock upgrade mode still requires separate attested inputs and qualification. Labeling is an execution request, not a passing verdict.
The [manual workflow](../.github/workflows/foundation4-host-certification.yml) supports a full `authenticated-read`
PostgreSQL/Redis profile, distinct immutable baseline/candidate sources, one
resolved dependency lock and benchmark revision, and the same runner for each
pair. It records the workflow attempt and VM boot identity per matrix case;
this proves paired VM reuse, not dedicated physical infrastructure or production
capacity. Other matrix cases may use different runners.

The twelve cases cover FPM/Runwire, one/four workers and concurrency 1/10/50.
Each revision has a recorded 60-second warm-up followed by five >=120-second
trials. Two independent complete batches run all twelve cases; the confirmation
batch reverses baseline/candidate order. Four-worker/concurrency-10 jobs include
two >=120-second soak windows per host/revision and batch, continuous live CPU/RSS,
response/query/cache checks and recovery. The controller and load generator use
CPU 0, PostgreSQL/Redis use CPU 1, and the server uses CPU 2 or CPUs 2–3.
The allocation requires four reported cores without SMT siblings; standard x64
workers reporting two cores with SMT are rejected before measurement. The default
manual runner is `ubuntu-24.04-arm`.

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
source attribution result. Record both immutable locks and both comparisons
with the PR. No benchmark certification is claimed until the two primary and
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

Compare evidence using the candidate's current tooling:

```bash
php benchmarks/foundation4-host-compare.php \
  /path/to/baseline/report.json \
  /path/to/candidate/report.json \
  build/foundation4-host-comparison.json
```

The comparator fails closed unless both reports contain the complete matching host/concurrency/worker matrix, matching declared physical host identity, PHP version, workload profile and sample configuration. Source-only comparisons require an identical dependency-lock SHA-256; upgrade comparisons require different locks and a reviewed exact dependency delta. Each case must contain at least five response-correct sustained observations of at least 120 seconds. It recalculates successful RPM from completed requests and elapsed time; rejects missing telemetry and internal inconsistencies; and refuses unstable evidence with RPM coefficient of variation greater than 2.5%. For every comparable stable case, the candidate median successful RPM must be at least **98%** of the baseline. A high-variance result is *inconclusive*, not a pass.

## Release acceptance

The comparison command alone does not certify a release. Perform dedicated production-equivalent workload profiles for authenticated/authorization requests, actual selected database reads/writes, cache hits/misses and service interactions, using valid fixture data and the real native library owners. Record cache hit rates, query counts, connections, worker-generation churn, errors and timeouts. Use a bounded longer persistent-host soak for memory growth, failure recovery, cancellations, recycle and shutdown; capture live peak and steady-state RSS, CPU and p50/p95/p99 and note client saturation.

Treat the release as blocked if any selected workload is untested, unstable, materially regresses, or has unbounded resource growth. **Do not relax the 2% budget** or substitute the short shared-runner smoke for matched sustained certification.

After final changes, repeat all required PHP 8.4/8.5 lowest/stable, production `--no-dev`, compiler, release trust, selected specialist and host workloads against the *same immutable Foundation source and resolved dependency set*. Record exact SHA, workflow URL and artifact hashes with the release pull request. Infbyte application publication follows Foundation publication; merging, tags and publication remain operator actions.

## Frozen-lock source replay

Preparation normally resolves current dependencies. To repeat a reviewed source-only graph, provide `replay_run_id` and `replay_lock_sha256` together. The workflow downloads `foundation4-paired-host-locks` from that run in this repository, verifies both captured lock files against the reviewed digest before Composer installation, then uses `composer install`. Missing, malformed or mismatched evidence stops the run; it never resolves a replacement graph. Replay is intentionally restricted to source-only mode.

`candidate_ref` selects an immutable measured source SHA independently of the workflow's tooling revision. The same selected tools and input fixture are overlaid into both source checkouts. Reports record the actual measured source identities and the shared tooling SHA. Keep the workflow dispatch ref stable while collecting evidence.

Example:

```bash
gh workflow run foundation4-host-certification.yml \
  --repo infocyph/Foundation --ref feature/foundation-3 \
  -f candidate_ref=cd4fba90d6e415bbe7af04400c59ff5374be1cac \
  -f baseline_ref=c220759fcbd6654fead843599712a5b79685f02d \
  -f comparison_mode=source-only -f host_runner=ubuntu-24.04-arm \
  -f replay_run_id=43217583759 \
  -f replay_lock_sha256=fcfff7349f7e19bfd76687ec94184ba508ac573abff84b5c2ccd9cc61444f793
```

For the unresolved Runwire w1/c50 signal, add `-f diagnostic_case=runwire-w1-c50` to run both order-reversed batches for that case. This mode is explicitly non-certifying: it publishes `foundation4-diagnostic-pair-*` artifacts with a diagnostic-only scope marker, and does not invoke the complete-matrix qualification job. A successful diagnostic run cannot close P6. Ordinary PR certification and the default manual mode still require all 24 pairs and both comparison verdicts.

Each measured trial now records diagnostic snapshots immediately before and after its timed load: CPU counters including steal time, pressure totals, cgroup throttling and I/O counters, available CPU frequencies, PostgreSQL activity, Redis activity, and selected Docker service image IDs/resource gauges. These probes execute outside the load window. Unavailable host counters are explicitly marked; credentials and DSNs are not included in output. Use matching before/after deltas to distinguish application work from host/backend contention. Diagnostic snapshots do not change acceptance calculations or budgets.

## Current qualification evidence

Both sustained performance certifications remain open. The [completed source-only run #43217583759](https://github.com/infocyph/Foundation/actions/runs/43217583759) compared candidate `cd4fba90d6e415bbe7af04400c59ff5374be1cac` with Foundation 4 baseline `c220759fcbd6654fead843599712a5b79685f02d`. All 24 measurement jobs and 240 measured trials completed with zero failed requests. Primary classified 7 pass / 4 high-variance / 1 regression; confirmation classified 9 pass / 3 high-variance / 0 regressions. Both acceptance jobs failed.

The earlier failed scenarios passed both fresh batches: FPM w1/c50 +0.626%/-1.003%, Runwire w4/c1 +0.887%/-1.728%. A new primary Runwire w1/c50 signal (-8.318%, CV 1.316%) was not established by the noisy confirmation (+1.969%, CV 2.788%). Its cause remains unresolved. A Composer HTTP 429 error cleared on retry before the final confirmation comparison. No runtime repair or performance pass is claimed.

[Primary artifact 13947562197](https://github.com/infocyph/Foundation/actions/runs/43217583759/artifacts/13947562197) and [confirmation artifact 13947492490](https://github.com/infocyph/Foundation/actions/runs/43217583759/artifacts/13947492490) retain the full reports and trial evidence. Both sources used lock `fcfff7349f7e19bfd76687ec94184ba508ac573abff84b5c2ccd9cc61444f793`. Compared with [earlier run #43172457224](https://github.com/infocyph/Foundation/actions/runs/43172457224), lock `28ca535ff6f08595a48613384f874a5192dee335188247129d007fd2cb63c263`, only development psalm/plugin-laravel changed 4.19.1 to 4.19.3; all other package references and production entries were unchanged. This fresh run is not an exact old-lock repeat. Earlier primary/confirmation counts were 4/7/1 and 7/4/1 (pass/variance/regression); their two stable negative cases were -2.520% and -2.560%. Keep both attempts' evidence.

Before release, freeze the final source SHA and qualify PHP 8.4/8.5 latest/lowest quality checks, specialist operations, native hosts, the actual Composer archive and isolated production consumers. Source-only and independent-lock dependency-upgrade comparisons are separate requirements; neither substitutes for the other. Merge, tagging, package publication and downstream Infbyte deployment remain manual release decisions.
