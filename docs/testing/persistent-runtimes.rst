.. _persistent-runtime-testing:

Test persistent hosts and execution isolation
=============================================

An Infbyte HTTP endpoint can pass under one-off PHP/FPM requests
yet fail when a persistent Runwire worker handles many unrelated
users. A long-running worker can also fail after cancellation,
stale-generation reload or lost distributed coordination.

**Do not treat one TestKit request as full persistent-runtime
verification.** In-process tests are useful for application
behavior; actual host lifecycle, sockets and resource ownership
need real host tests.

What must be isolated?
----------------------

At the end of each request or job, no later execution should
inherit the previous one's:

* current principal, authentication, browser session or CSRF token;
* DBLayer transaction, sticky read, deadline or reserved pool lease;
* uploaded temporary file or open stream;
* outbound request cookies, auth headers or mutable client state;
* queue reservation/acknowledgment or old generation ownership;
* pending cancellation, worker execution ID or response emitter.

The host may reuse safe infrastructure (compiled definitions,
immutable config, connection pools under controlled leases).
The **mutable execution state** must be scoped through
Webrick/InterMix and cleaned before the next request/job.

Test a sequence, not a singleton request
----------------------------------------

Use a **single running worker** to execute:

1. A successful request that sets scoped user/session data.
2. A failing request that opens a transaction or lock.
3. A separate principal's successful request, still in the
   same worker process.
4. A long-running operation receiving cancellation/reload.
5. A fresh request after the worker is replaced.

After each step assert that the next unit starts with clean
principal/session/transaction state, correct response headers
and a valid matching generation identity.

In the Infbyte candidate, ``GET /api/health``
is a useful low-cost control route. It is not an
authentication isolation test on its own: add application-owned
protected routes and selected middleware before asserting
cross-principal behavior.

Application-level smoke
-----------------------

For a booted **web** application in a TestKit context:

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

   $testing->http()->get('/route-that-does-not-exist')
       ->assertStatus(404);

This tests native Webrick route handling without a network
socket. It does **not** prove a real Runwire process survived
a cancellation or that FPM forwarded all FastCGI headers.

Production host verification
----------------------------

Build a trusted generation and run against the actual
selected host:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

For FPM, use an external reverse proxy with the project's
``public/index.php`` and independent trusted manifest
identity. For native Runwire, start the correct supported
driver and record its installed extension/host version.

Record source SHA, dependency graph, host, number of workers,
concurrency, request sequence, response/error counts,
latency distribution, memory growth and process restarts.

Inject the failures you expect in production
--------------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 31 35 34

   * - Fault
     - Correct behavior
     - Measure
   * - Session lease expires before save
     - Stale owner does not overwrite newer data
     - Lock provider and user state
   * - DB transaction throws
     - Connection is rolled back/sanitized
     - Next request has clean transaction
   * - Queue worker dies before ACK
     - Reservation recovered or retried per transport policy
     - Receipt, visibility and idempotency
   * - New generation activates
     - Old worker drains and stops new acceptance
     - Generation token and supervisor restart
   * - Trusted manifest digest mismatch
     - Production fails closed
     - No dynamic fallback compile
   * - Remote backend disappears
     - Failure visible and correctly cleaned up
     - Resource handle and subsequent success

Borrowed native runtime context is **not** permission to
create a second Runwire loop or fabricate request IDs.
Pass the actual host-provided objects to native library
bindings where appropriate.

Performance tests are a separate qualification
-----------------------------------------------

Measure throughput/latency under the *same* routes, workload,
network, cache state, selected capabilities and security policy.
Warm-up and cold-start measurements should be labeled distinctly.
Do not disable correctness checks to make a performance
benchmark look green.

Foundation's P6 source/host acceptance matrix and retained-state
rehearsal remain **independent release gates**. A green docs CI
or short host smoke cannot replace sustained stress,
soak, cancellation/race or rollback verification.

Next: :doc:`../architecture/request-lifecycle`,
:doc:`../advanced/runtime-modes`,
:doc:`../console/workers` and
:doc:`../deployment/releases`.
