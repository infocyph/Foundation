# Foundation 3 Release-Readiness Evidence

Foundation 3 aggregate release readiness closed after all lower-library utilization passes.

## Final aggregate matrix

Reference final implementation head: `e76d08ed3492006b389b3b5972f3bb5f19b74937`  
PHPForge Security & Standards: **run #1489 — PASS**

The run passed:

- PHP 8.4 prefer-lowest QA;
- PHP 8.4 prefer-stable QA;
- PHP 8.5 prefer-lowest QA;
- PHP 8.5 prefer-stable QA;
- PHP 8.4 PHPStan + Psalm security analysis + Composer audit;
- PHP 8.5 PHPStan + Psalm security analysis + Composer audit;
- clean production install;
- PHP 8.4 `benchmark:release`;
- PHP 8.5 `benchmark:release`.

The workflow enabled `fail_on_skipped_tests=true`, so an unexpected skipped Pest test is release-blocking.

## Runtime closure coverage

The final suite keeps aggregate coverage for:

- optional capability coldness and package absence;
- execution/Fiber/persistent-runtime cleanup and isolation;
- authentication, OAuth/OIDC/PAT state isolation;
- database/session transaction and execution state cleanup;
- filesystem and validation trust/lifetime boundaries;
- Omnibus worker/message execution ownership;
- TalkingBytes HTTP/email/webhook/gRPC lifetime and secret boundaries;
- immutable Foundation release compilation across Web, CLI, Worker and Scheduler;
- trusted manifest/config loading and tamper rejection;
- generation-aware worker replacement;
- source-file isolation after release compilation.

## Benchmark ownership

`composer benchmark:release` executes Foundation attribution benchmarks for:

- UID;
- CacheLayer;
- DBLayer;
- Epicrypt;
- Omnibus;
- OTP;
- Pathwise;
- ReqShield;
- TalkingBytes.

Foundation benchmarks measure bridge/policy overhead against native lower-library operations. Specialist-owned protocol, transport, crypto, queue, filesystem and parser microbenchmarks remain in their owning libraries rather than being duplicated here.

## Consumer handoff

Foundation 3 release readiness is complete. InfByte consumer work is intentionally deferred until after the Foundation 3 release. The existing InfByte migration work remains a separate consumer concern and must adopt the finalized lifecycle without reopening Foundation runtime ownership or restoring retired Foundation 2 paths.


## Final consumer-boundary findings

The InfByte handoff audit surfaced and Foundation closed three final consumer-boundary defects before release:

- core `app:install` generates `AUTH_TOKEN_SECRET` from PHP's OS CSPRNG and no longer requires optional Epicrypt merely to install a lean application;
- explicit `app.capabilities` production validation/readiness ignores inactive optional auth/cache policy while preserving strict auth checks when `auth` is selected;
- `module:install/show/schema:*` stay execution-scoped when a database connection may be used, but do not synthetically require the database capability before the module/schema manager determines applicability.

These corrections are included in final green run #1489.
