.. _performance-guide:

Performance and scalability
===========================

Infbyte's architecture is optimized to avoid unnecessary work,
especially on high-throughput HTTP endpoints and long-lived workers.
Performance should be measured against **real application behavior**
rather than inferred from available packages or benchmark headlines.

Prefer a minimal execution graph
--------------------------------

A public health endpoint generally needs neither authentication
sessions nor a database. Keep route middleware selected and activate
optional services only when the handler requires them.

For a web route, Webrick can avoid materializing a full
``Request`` object and an InterMix request scope if no selected
handler/middleware needs them. A persistent host may reuse safe
singletons while request-local data lives only in scopes.

Avoid:

* globally resolving all optional modules on every request;
* constructing outbound HTTP clients, DB pools or queues inside
  unrelated provider boot hooks;
* reading environment files or scanning routes in the production hot path;
* creating a new host/event loop inside ordinary synchronous PHP;
* using shared mutable state without scope isolation.

Compiled production artifacts
-----------------------------

Foundation 4 compiles a trusted generation containing a normalized
configuration snapshot and native Webrick/InterMix artifacts for all
four application modes. Production does not reconstruct a mutable
development container on each execution.

Development config caching has two ArrayKit-owned forms:
``sharded`` with an exact-leaf index, and ``single``
with one exported configuration file.

Neither configuration-cache form is a second router compiler or a
replacement for trusted release artifacts. Use the right
measurement surface for each optimization.

Choose the host based on evidence
---------------------------------

Native Runwire serving, ordinary PHP/FPM, RoadRunner,
FrankenPHP and explicit Swoole/OpenSwoole adapters have different
performance and lifecycle properties. A host supporting concurrency
is not a reason to run extra workers on an unsuitable platform.

Before changing worker counts or protocol settings:

1. Record the exact application source SHA and resolved dependencies.
2. Keep routes, middleware, data topology, payloads and response sizes
   identical across comparisons.
3. Warm caches consistently and separate cold-start from steady-state
   behavior.
4. Record latency distribution, throughput, memory, errors,
   cancellation/restarts and CPU utilization.
5. Repeat trials and compare variance; do not cherry-pick the fastest run.
6. Check security and correctness under the same workload after tuning.

Foundation release qualification is stricter
--------------------------------------------

Foundation PR #20 uses explicit sustained comparison gates;
its current candidate requires a full FPM/Runwire,
1/4-worker, concurrency 1/10/50 matrix, two batches and
multiple sustained trials, plus soak/recovery and bounded
median regression/variance acceptance criteria.

A focused short diagnostic, local Sphinx build or benchmark smoke
is **not release certification**. Keep failed and inconclusive
evidence when investigating performance regressions.

For the exact, source-controlled acceptance budget, see
`Foundation 4 host benchmark certification
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-host-benchmark-certification.md>`_.

Scale safely
------------

The first scaling improvement is usually to avoid unnecessary
work and choose correct service lifetimes. Only then consider
additional workers, queue transports, coordination stores and
host-specific concurrency. Verify distributed locks and session
storage across nodes; local in-memory stores do not magically
become distributed because worker count increased.

See :doc:`runtime-modes` and
:doc:`../testing/persistent-runtimes`.
