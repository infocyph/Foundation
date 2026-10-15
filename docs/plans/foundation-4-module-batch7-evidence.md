# Foundation 4 — Module architecture Batch 7 certification evidence

Checkpoint: 2026-10-15. Repository: infocyph/Foundation. PR: [#20](https://github.com/infocyph/Foundation/pull/20). Branch: \`feature/foundation-3\`.

**Status: NOT fully certified (2026-10-15). B7 4/7 individually QA-checked, 0/7 fully accepted.** The B0–B6 module architecture is accepted; the last two architecture performance gates require matched, production-equivalent baseline/candidate measurements. Release-notes sign-off is blocked until those gates pass. Do not confuse repeatable CI smoke with a successful-RPM comparison, and do not merge, tag or publish on the strength of this file.

## Current source and automated evidence

- B6 immutable source: [6358b97](https://github.com/infocyph/Foundation/commit/6358b975a12add6d598e3e06e2724edd9a3cecfd); [PHPForge full matrix](https://github.com/infocyph/Foundation/actions/runs/38686271597) and [native FPM/Runwire smoke](https://github.com/infocyph/Foundation/actions/runs/38686270918) passed.
- Batch 7 implementation: [3d8370](https://github.com/infocyph/Foundation/commit/3d837009eaf8f4ed598dc94286c94259b6f8ba25) adds a fail-closed **diagnostic-only** v2 CLI microbenchmark comparator. [bcf6fbb](https://github.com/infocyph/Foundation/commit/bcf6fbb7107df284e294f9cb0711f0d12b844d55) prevents invalidating a trusted release on a no-op module enable/disable and tests repeated CLI operations.
- Latest executable [`8fa9fd4`](https://github.com/infocyph/Foundation/commit/8fa9fd48952dbfaa49e2258e5afa6a3264fa4658): [Security & Standards #38688337213](https://github.com/infocyph/Foundation/actions/runs/38688337213) **SUCCESS** with locked dependencies/release evidence archive SHA-256 `759532cc428d9ff44c46196f0faee89a68c8530f700059869188726b812c6a20`; [native HTTP host #38688336732](https://github.com/infocyph/Foundation/actions/runs/38688336732) **SUCCESS**. The host comparator rejects a same-source baseline/candidate comparison. All 52 inline PR review threads are resolved.
- Foundation 4 final docs parity checkpoint: [`592d43d`](https://github.com/infocyph/Foundation/commit/592d43d75ec61225c9668bd7be10826c2a54e9b3); [Security & Standards #38689963558](https://github.com/infocyph/Foundation/actions/runs/38689963558) **SUCCESS** (full 8.4/8.5 × stable/lowest QA matrix, PHPStan, consumers, benchmarks, release evidence) and [native HTTP host #38689962743](https://github.com/infocyph/Foundation/actions/runs/38689962743) **SUCCESS** on identical source. `docs/foundation-4-migration.md` was corrected to the qualified v2 CLI; the unpublished notes now specify the fixed 3-module/9-feature contract and conditional specialist dependencies; two executable documentation-parity cases prohibit retired CLI examples. **This is NOT sign-off:** host throughput gates 3/4 and dependent release decision 7 remain blocked; shared GitHub-runner smoke cannot replace same-hardware physically controlled evidence. 52/52 PR review threads resolved at this checkpoint.
- Re-run every QA and host job after the final implementation or evidence edit, using one immutable exact source SHA. Record CI SHA, dependency lock digest, PHP versions, extensions, benchmark artifacts, PR review state and final release-notes sign-off. A passing earlier SHA is not certification of a later SHA.
- Existing B6 automated release evidence captures \`manifest.env\`, \`dependencies.json\`, \`composer-lock.sha256\`, PHP extension inventory, module v2 CLI baseline and benchmark output.
- PR inline review audit (2026-10-15): 52 threads inspected; **zero unresolved** at inspection. Re-check on final source.

## Final module API and publication metadata audit — 2026-10-15

**Documentation review: COMPLETE. Release certification: BLOCKED.** Exact audit code and draft docs [`4de9034`](https://github.com/infocyph/Foundation/commit/4de9034c874190ffe6adbb7996dc0b6c2d4c8386); [Security & Standards #38722387329](https://github.com/infocyph/Foundation/actions/runs/38722387329) and [native HTTP host #38722386530](https://github.com/infocyph/Foundation/actions/runs/38722386530) are re-running for this source at review time. The previous completed v2 documentation baseline remains green, but earlier CI must not be mistaken for certification of newer sources.

| Audited component | Review result | Release implication |
| --- | --- | --- |
| Public module API and CLI examples | **Reviewed:** exactly `data`, `security`, `execution`, nine qualified IDs, `schema_version:2`; no executable retired aliases or implicit activation | Public v2 contract documented, no prior-major shim |
| Candidate release notes and migration guidance | **Reviewed:** breaking contract, conditional Epicrypt/OTP MFA, passkeys, DBLayer/durable Omnibus, native Webrick/InterMix/Runwire ownership | Candidate notes remain **unpublished draft** |
| Composer publication metadata | **Reviewed:** `infocyph/foundation`, MIT, `library`, `php:^8.4`, `composer-runtime-api:^2.0`, PSR-4 and `bin/infbyte`; Core packages in `require`, selected optional packages in `require-dev`/`suggest`, `opis/closure` dev-only, no hardcoded `version` | Exact final source tag and dependency lock must be provided only at publication time |
| Provenance and reviewed PR | **Reviewed:** immutable CI source, dependency/Composer lock evidence, 52 resolved PR inline threads at most recent inspection; no merge/tag/publication | Repeat final provenance review against the *approved source* |
| Dedicated matched-host performance | **UNVERIFIED:** different frozen baseline and candidate SHAs, identical hardware/toolchain/lock, five validated sustained repetitions per representative FPM/Runwire/workers/concurrency case, real auth/database/cache load, stable variance, ≤2% median successful-RPM regression | **Hard blocker for B7 gates 3 and 4** |
| Final release approval | **NOT GRANTED** until performance, exact-source QA and operator release metadata are complete | **B7 gate 7 remains unchecked; release notes stay draft** |

Executable parity is covered by `ModuleCommandDocumentationParityTest` and `ModuleExternalDependencyRoleTest`; the final metadata audit extends those tests without relaxing any release gate. Do not use the GitHub-hosted smoke RPM as matched-hardware sign-off.

## B7 acceptance gates

| Gate | Evidence required | Status |
| --- | --- | --- |
| 1. Full quality and security | Exact-source PHPForge 8.4/8.5 QA, PHPStan, formatting, references, duplicate/security checks and no unjustified skips | **PASS: exact-source QA 4/4, PHPStan 2/2, benchmarks 2/2** |
| 2. Production matrices | PHP 8.4/8.5 × lowest/stable, core and all nine selected specialists under \`composer --no-dev\` | **PASS: 4/4 `--no-dev` producer/consumer matrix** |
| 3. Before/after throughput and module CLI | Comparable CLI inspect/plan/activation/compiled boot metrics **and** real baseline/candidate matched-host HTTP successful RPM, latencies and telemetry | **BLOCKED: matched host evidence unavailable** |
| 4. ≤2% regression and stability | At least five sustained ≥30s correct-response measurements per FPM/Runwire/1-or-4-worker/1,10,50-concurrency scenario, ≤2.5% CV, bounded memory; independent real auth/database/cache profile | **BLOCKED: controlled host evidence unavailable** |
| 5. Concurrency and recovery | Selected lifecycle/Composer/config locking, partial repair, durable SQLite queue and schema retention, scoped session/principal, Runwire worker generation and cancellation tests | **PASS: exact-source suites and native host** |
| 6. Review and immutable provenance | No unresolved PR review threads; exact executable SHA, PHP/toolchain and dependency/Composer lock manifest; both trackers synchronized | **PASS: zero unresolved threads and immutable CI artifact; both trackers synchronized** |
| 7. Final notes and release decision | Module v2 docs, release metadata and upgrade notes reviewed; only accept ready after all previous gates | **BLOCKED by 3 and 4** |

The separate Foundation 4.0 release-audit plan remains **32/37** and has overlapping but distinct matched-hardware performance and release requirements. Neither tracker may claim release readiness while those are incomplete.

## Diagnostic CLI before/after comparison

On the same stable PHP/toolchain and matching v2 CLI fixture, run a frozen Foundation 4 v2 baseline checkout and the candidate checkout. Do not compare the historical 11-entry major-version contract as if its semantics were identical.

\`\`\`bash
FOUNDATION_MODULE_SOURCE_SHA="$(git rev-parse HEAD)" \
FOUNDATION_MODULE_BASELINE_OUTPUT="build/release-evidence/module-cli.json" \
php benchmarks/foundation4-module-cli-baseline.php

php benchmarks/foundation4-module-cli-compare.php \
  /path/to/v2-baseline/module-cli.json \
  /path/to/candidate/module-cli.json \
  build/release-evidence/module-cli-comparison.json
\`\`\`

The v2 CLI comparator checks source identity, six command operations, repeated cold/warm p50/p95 timing, PHP/toolchain and fixture shape. It **never** certifies host RPM, and does not stand in for actual enable or compiled-boot whole-host measurements. Include direct \`module:enable\` and immutable compiled-boot timing in the controlled application profile.

## Mandatory host comparison

Run baseline and candidate **on physically identical dedicated infrastructure**, with an identical Composer lock, PHP/INI/extensions, datasets, host OS, worker counts, OPcache, and load generator. Keep the same benchmark tooling revision. For each checkout:

\`\`\`bash
export FOUNDATION_CERTIFICATION_HOST_ID='physically-controlled-host-id'
export FOUNDATION_HOST_WORKLOAD_PROFILE='native-read'
export FOUNDATION_HOST_REPEATS=5
export FOUNDATION_HOST_REQUESTS=1000
export FOUNDATION_HOST_MIN_SECONDS=30
export FOUNDATION_HOST_SOAK_SECONDS=120
export FOUNDATION_HOST_RESULT_DIR="$PWD/build/foundation4-controlled"
bash benchmarks/foundation4-host-certify.sh
\`\`\`

The \`native-read\` smoke is still a small synthetic SQLite/file-cache/credential fixture, **not a full production-equivalent authenticated database/cache workload**. Repeat with a controlled real representative workload before certification. Use separate directories and source SHA for baseline and candidate, then compare:

\`\`\`bash
php benchmarks/foundation4-host-compare.php \
  /path/to/baseline/report.json \
  /path/to/candidate/report.json \
  build/foundation4-host-comparison.json
\`\`\`

The current PHP comparator requires a complete 12-case FPM/Runwire worker/concurrency matrix, five runs per case, 30-second sustained valid responses, matching physical host identity, PHP version, Composer lock, declared workload and request count; it rejects >2% median successful-RPM regression and >2.5% run variance. Attach comparison JSON, per-request p50/p95/p99, live CPU/RSS samples, query/cache metrics, worker replacement, long soak and cancellation recovery evidence. Missing/incomparable/unstable evidence is **inconclusive and blocking**, never a pass.

CI's shared GitHub-hosted native HTTP smoke uses three repetitions for minimal routes and two for its selected-native profile, so it **cannot** certify this host gate even when its workflow is green.

## Release boundary

Complete the two blocked performance gates on representative matched hardware; repeat exact-SHA CI after any change; re-review all PR findings; update both trackers and the draft release notes. Only then consider Batch 7 7/7 and the separate Foundation 4 release audit gates. Leave PR #20 draft; user owns merge/tag/publication. Infbyte is out of scope.
