
# Foundation 4.0 — Native host performance certification

This procedure certifies **Foundation 4 only**. It is not a legacy-version compatibility test. The routine PR host job runs a minimal-JSON smoke and a selected-owner native-read smoke on shared GitHub hardware; neither is a production-equivalent throughput certification.

## Paired GitHub qualification

Use GitHub Actions for the paired comparison and Docker for local diagnostics.
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

## Measurements already automated

`benchmarks/foundation4-host-certify.sh` compiles a trusted Foundation Webrick/InterMix generation and launches real Nginx/PHP-FPM and native Runwire listeners. It validates each HTTP/1.1 status and response body, tests concurrency 1/10/50 with one/four workers, samples process-tree CPU and RSS during load, and exercises failed-request recovery, client cancellation, and prefork worker replacement.

The ordinary GitHub job checks 36 minimal-route samples (three repetitions), then a separate `native-read` profile with real DBLayer SQLite selects, CacheLayer file-cache reads and credential digest verification (two repetitions). Both profiles exercise FPM/Runwire and two 30-second four-worker soak windows, with contained error, cancellation and Runwire worker-replacement checks. The native fixture uses a low-work-factor testing hash and a small deterministic dataset; **it is not a production authentication, authorization or distributed database workload**. All outputs are artifacts, not controlled baselines.

For the selected-owner profile, use `FOUNDATION_HOST_WORKLOAD_PROFILE=native-read` instead of `json-minimal`. The comparator rejects comparisons between different profile identities. Shared-runner smoke fixtures remain insufficient to approve the 2% release budget.

## Controlled sustained comparison

Run the **same version of benchmark tooling**, on a dedicated, stable host using two separately checked-out Foundation 4 candidate sources: a known-good frozen 4.0 baseline and the proposed release candidate. Do not modify working trees during measurement. Reuse an identical resolved Composer lock, PHP version/extensions and INI, services, datasets, routes, OPcache settings, and OS configuration. Disable competing workloads and verify that the load generator can saturate the host without becoming the bottleneck.

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

The comparator fails closed unless both reports contain the complete matching host/concurrency/worker matrix, matching declared physical host identity, PHP version, dependency-lock SHA-256, workload profile and sample configuration. Each case must contain at least five response-correct sustained observations of at least 30 seconds. It recalculates successful RPM from completed requests and elapsed time; rejects missing telemetry and internal inconsistencies; and refuses unstable evidence with RPM coefficient of variation greater than 2.5%. For every comparable stable case, the candidate median successful RPM must be at least **98%** of the baseline. A high-variance result is *inconclusive*, not a pass.

## Release acceptance

The comparison command alone does not certify a release. Perform dedicated production-equivalent workload profiles for authenticated/authorization requests, actual selected database reads/writes, cache hits/misses and service interactions, using valid fixture data and the real native library owners. Record cache hit rates, query counts, connections, worker-generation churn, errors and timeouts. Use a bounded longer persistent-host soak for memory growth, failure recovery, cancellations, recycle and shutdown; capture live peak and steady-state RSS, CPU and p50/p95/p99 and note client saturation.

Treat the release as blocked if any selected workload is untested, unstable, materially regresses, or has unbounded resource growth. **Do not relax the 2% budget** or substitute the short shared-runner smoke for matched sustained certification.

After final changes, repeat all required PHP 8.4/8.5 lowest/stable, production `--no-dev`, compiler, release trust, selected specialist and host workloads against the *same immutable Foundation source and resolved dependency set*. Record exact SHA, workflow URL and artifact hashes with the release pull request. Infbyte application publication follows Foundation publication; merging, tags and publication remain operator actions.
