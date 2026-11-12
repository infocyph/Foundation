.. _persistent-runtime-testing:

Testing long-lived runtimes
===========================

A persistent host reuses process infrastructure across multiple
requests or work items. A test that passes for one request under
plain FPM can still fail because another request inherits mutable
state, an expired lock, or a stale compiled generation.

Verify several executions in one process
----------------------------------------

Use a single selected runtime and execute a sequence that includes:

1. A successful request/job setting request-local values.
2. A failing or cancelled request/job that opens external state.
3. Another successful request/job in the same worker process.
4. A shutdown/reload signal while a longer unit is still active.

After every step assert that:

* no previous principal, CSRF/browser session or authenticated account
  remains available to the next execution;
* transactions and resource handles are released or rolled back;
* a stale generation cannot accept new work after a newer generation
  becomes active;
* cancellation is propagated through native Runwire/Omnibus callbacks;
* a completed request does not emit a second response or leak a
  previous response body into the next one.

Scope and borrowed context
--------------------------

A web request scope is owned by Webrick. CLI/worker/scheduler scopes
are owned by Foundation through InterMix. A borrowed Runwire request
or coroutine context must be forwarded **as received**, not recreated
just to make a library think it is running under a persistent host.

Avoid testing a synthetic "persistent" flag that bypasses actual
host lifecycle behavior. Test the selected host adapter, resource
ownership and cancellation policy in integration workflows.

Load and performance tests are different
----------------------------------------

Measure latency, throughput, memory and worker utilization without
changing the production graph merely to make a benchmark pass.
Run multiple trials under a fixed source SHA, dependency lock,
host mode and workload. Attribute differences to actual code
changes before proposing optimizations.

The Foundation 4 source qualification has additional sustained
matrices and acceptance budgets. Those remain release gates;
they are **not** certified by these documentation examples
or a quick application HTTP test.

Useful failure injections
-------------------------

* Invalid/expired authentication marker under shared storage.
* Session lock expiration between read and commit.
* Queue reservation whose process is cancelled before ACK.
* Missing or stale generated container/router manifest.
* Worker restart signal during a long scoped operation.
* Backend loss in a failure path followed by a new execution.

See :doc:`../architecture/request-lifecycle`,
:doc:`../console/workers`, and
`Foundation runtime hosting
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/runtime-hosting.md>`_.
