# Foundation 3 Release-Readiness Evidence

Foundation 3 aggregate release readiness closed after all lower-library utilization passes.

## Final aggregate matrix

Reference implementation/tracker head: `3eaa7b87ba1051c96c0fd1450c66043e4380b169`  
PHPForge Security & Standards: **run #1482 — PASS**

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

Foundation 3 release readiness is complete before InfByte migration. InfByte is a consumer/skeleton and must adopt the finalized Foundation 3 migration contract; consumer drift does not reopen Foundation runtime ownership or restore retired Foundation 2 paths.
