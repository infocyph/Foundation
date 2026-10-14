# Foundation 4.0 — Native host performance certification

This procedure certifies **Foundation 4 only**. It is not a legacy-version compatibility test. The routine PR host job runs a minimal-JSON smoke and a selected-owner native-read smoke on shared GitHub hardware; neither is a production-equivalent throughput certification.

## Measurements already automated

`benchmarks/foundation4-host-certify.sh` compiles a trusted Foundation Webrick/InterMix generation and launches real Nginx/PHP-FPM and native Runwire listeners. It validates each HTTP/1.1 status and response body, tests concurrency 1/10/50 with one/four workers, samples process-tree CPU and RSS during load, and exercises failed-request recovery, client cancellation, and prefork worker replacement.

The ordinary GitHub job checks 36 minimal-route samples (three repetitions), then a separate `native-read` profile with real DBLayer SQLite selects, CacheLayer file-cache reads and credential digest verification (two repetitions). Both profiles exercise FPM/Runwire and two 30-second four-worker soak windows, with contained error, cancellation and Runwire worker-replacement checks. The native fixture uses a low-work-factor testing hash and a small deterministic dataset; **it is not a production authentication, authorization or distributed database workload**. All outputs are artifacts, not controlled baselines.

For the selected-owner profile, use `FOUNDATION_HOST_WORKLOAD_PROFILE=native-read` instead of `json-minimal`. The comparator rejects comparisons between different profile identities. [Native host #38552807316](https://github.com/infocyph/Foundation/actions/runs/38552807316) passed on source `8af4e1e0` with zero response-validation errors in both selected-native soak windows. Shared-runner comparisons and these fixture sizes remain insufficient to approve the 2% release budget.

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

## Additional evidence required for Batch 6

The comparison command does not, by itself, certify Batch 6. Perform dedicated production-equivalent workload profiles for authenticated/authorization requests, actual selected database reads/writes, cache hits/misses and service interactions, using valid fixture data and the real native library owners. Record cache hit rates, query counts, connections, worker-generation churn, errors and timeouts. Use a bounded longer persistent-host soak for memory growth, failure recovery, cancellations, recycle and shutdown; capture live peak and steady-state RSS, CPU and p50/p95/p99 and note client saturation.

Treat the release as blocked if any selected workload is untested, unstable, materially regresses, or has unbounded resource growth. **Do not relax the 2% budget** or substitute the short shared-runner smoke for matched sustained certification.

After final changes, repeat all required PHP 8.4/8.5 lowest/stable, production `--no-dev`, compiler, release trust, selected specialist and host workloads against the *same immutable Foundation source and resolved dependency set*. Record exact SHA, workflow URL and artifact hashes in the [Foundation 4 plan](plans/foundation-4.0-release-audit-and-plan.md). Infbyte application work, merging, tags and publication are outside this plan.
