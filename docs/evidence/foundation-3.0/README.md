# Foundation 3.0 release evidence

This index identifies the release-qualification sources and retained CI evidence
for Foundation 3.0. It intentionally contains no credentials, service passwords,
private keys or other deployment secrets.

## Immutable verification identities

- Foundation workflow: `.github/workflows/security-standards.yml`.
- Reusable PHPForge workflow revision:
  `fdec64cf4460f13116eb0e2f3405acadd3e84377`.
- Foundation development tooling requirement remains
  `infocyph/phpforge: dev-main@dev`; pinning the reusable workflow does not
  weaken or replace the project QA dependency.
- PHP matrix: 8.4 and 8.5.
- Dependency matrix: prefer-stable and prefer-lowest.
- Required integration services: MySQL, PostgreSQL, SQLite, Redis, Valkey and
  Memcached.
- Required host extensions include mbstring, PDO plus MySQL/PostgreSQL/SQLite
  drivers, Redis and Memcached.
- Skipped tests are release failures (`fail_on_skipped_tests=true`).

## Required workflow evidence

A qualifying current-source run must pass all of the following:

1. PHPForge QA on PHP 8.4/8.5 with prefer-stable and prefer-lowest, including
   phpprobe syntax/reference/duplicate/comment policy, Pest, Pint, PHPCS, Deptrac
   and Rector.
2. PHPStan/Psalm/composer-audit analysis on PHP 8.4 and PHP 8.5.
3. PHPForge production clean install.
4. Foundation disposable production consumers on PHP 8.4/8.5 ×
   stable/lowest. The minimal consumer installs with `--no-dev`, runs
   `vendor/bin/infbyte list`, and proves PHPForge/specialist packages do not
   leak into the production graph. The optional consumer explicitly adds DBLayer
   and verifies `module:show database --json`.
5. Both release benchmark jobs.
6. The post-gate `Release evidence` job, which uploads
   `foundation-release-evidence-<source-sha>` containing:
   - source SHA, PHP version and pinned PHPForge workflow revision;
   - sorted PHP extension inventory;
   - locked Composer dependency inventory;
   - Pest test inventory and inventory-line count.

The evidence artifact is retained for 30 days. Large logs and benchmark output
remain CI artifacts; this committed index records the contract and locations
rather than copying volatile logs into the repository.

## Runtime and performance evidence

- [Foundation 3.0 runtime support](../../foundation-3-runtime-support.md) is the
  authoritative certified/not-certified host statement.
- [Runtime hosting](../../runtime-hosting.md) documents the Webrick/Runwire
  ownership boundary without treating upstream adapter availability as
  Foundation certification.
- `PersistentRuntimeSoakTest` exercises 1,000 alternating successful/failing
  persistent execution scopes and verifies scoped objects are collectable.
- `RepresentativeBenchmark` records repeated validated workload results with
  RPM, p50/p95/p99 latency and memory metrics.
- `phase9-http-attribution.php` compares standalone Webrick and Foundation in
  separate PHP processes.
- [F7 point-2 measurements](../f7-point-2-measurements.json) and
  [F7 point-3 regression](../f7-point-3-regression.json) retain matching
  environment-fingerprint performance evidence.
- DBLayer, Omnibus and other specialist integration benchmarks remain
  attribution evidence; no broader application throughput claim is inferred
  from them.

## Deployment/security evidence

The release suite covers immutable staged publication, read-only application
source, trusted manifest/config loading, dependency identity, failed-stage
recovery, source isolation, generated runtime replacement, draining-generation
lease protection, generated-secret isolation and active/fallback signing-key
rotation.

Authentication/session evidence includes credential and authorization lifecycle
tests, persistent principal/session cleanup, durable cross-process OAuth
revocation, shared session-lock contention, stale-owner rejection, fail-closed
distributed auth policy, secret-safe output, and multi-process Redis
replay/CAS/auth lockout-counter atomicity.

## Final-candidate rule

Do not tag Foundation 3.0 from historical green evidence. The exact final
implementation/dependency revision must have a successful current-source
Security & Standards run with the required jobs above. Documentation-only
evidence/index commits may follow that implementation revision, but their own
workflow must also remain green before tagging.
