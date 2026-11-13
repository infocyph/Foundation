.. _tutorial-durable-queue:

Tutorial: operate a durable message queue
=========================================

Use this walkthrough when an application has work that must
survive a web request, be retried after a transient failure,
and be inspected operationally. It focuses on
**Foundation 4 + Omnibus 3.0.3** integration without inventing
a second Foundation queue engine.

A background queue needs more than a ``dispatch()`` call:
the sender, codec, broker, handler, failure store, worker,
data persistence and process supervisor must agree on the same
configuration and runtime generation.

What is owned by whom?
----------------------

* **Omnibus** owns events, envelopes, routes, serializers,
  transports, acknowledgment, retry and failure semantics,
  worker loops and durable stores.
* **Foundation** owns application registration, selected
  ``execution.messaging`` capability, DI composition,
  application execution scope, CLI integration and generation
  reload/stop policy.
* **DBLayer** owns SQL connectivity, transactions and
  schema grammar when a database-backed transport is selected.
* **CacheLayer** supplies coordination where the selected
  application policy calls for atomic locks or counters.
* An **external supervisor** manages daemon process
  replacement, scaling and restart.

Nothing starts a worker merely because ``infocyph/omnibus``
is installed.

Step 1 — select messaging and storage
-------------------------------------

For a compatible Foundation 4 application:

.. code-block:: bash

   php infbyte module:plan execution.messaging
   php infbyte module:install execution.messaging
   php infbyte module:doctor execution.messaging

Choose a transport according to your durability goal:

.. list-table::
   :header-rows: 1
   :widths: 20 33 47

   * - Transport class
     - Typical use
     - Durability consequence
   * - In-memory test transport
     - Unit tests, same-process workflows
     - Not durable across restarts
   * - Redis-backed transport
     - Multi-worker queue delivery
     - Requires configured shared Redis and visibility policy
   * - DBLayer-backed transport
     - Selected SQL durable message store
     - Requires ``data.database`` and Omnibus native schema

For a durable SQL-backed queue, select ``data.database``
as well and verify both modules' backend readiness.
The transport name in your application configuration must match
an actually configured Omnibus transport.

Step 2 — register an explicit message and handler
-------------------------------------------------

Generate an application-owned message and handler starting point:

.. code-block:: bash

   php infbyte create:job GenerateReport
   php infbyte create:handler GenerateReport
   php infbyte create:job-middleware AuditJob

Foundation does not scan arbitrary classes and auto-register a
message handler. In ``config/messaging.php``, the relevant
registration is structured like this:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'handlers' => [
           App\Jobs\GenerateReportJob::class =>
               App\Messaging\Handlers\GenerateReportHandler::class,
       ],
       'handler_middleware' => [],
       'job_middleware' => [
           App\Jobs\Middleware\AuditJobMiddleware::class,
       ],
       'routes' => [
           App\Jobs\GenerateReportJob::class => [
               'transport' => 'redis',
               'queue' => 'reports',
               'delay_seconds' => 0.0,
           ],
       ],
   ];

The referenced ``App\...`` classes must exist and implement the
current Foundation/Omnibus contracts. This is an illustrative subset,
not a replacement for complete application messaging settings.
The ``redis`` transport is also an example; the actual transport
must be declared and reachable.

If the selected message extends Foundation's application ``Job``
contract, its ``job_middleware`` is entered inside Omnibus's
more general handler middleware. Generic Omnibus messages do not
automatically run Foundation job-specific middleware.

Step 3 — dispatch through the native bus
----------------------------------------

Use the native Omnibus ``MessageBus`` service from a composed
application context:

.. code-block:: php

   use Infocyph\Omnibus\MessageBus;

   $bus = $app->make(MessageBus::class);

   $bus->dispatch(new App\Jobs\GenerateReportJob($reportId));

Here ``GenerateReportJob`` and ``$reportId`` belong to
the application. The example does not imply a generated job is
complete business logic. Do not include process resources or
unserializable objects in a payload intended for durable transport.

A durable queue needs an explicit allow-list of Omnibus
``MessageCodec`` implementations. Unknown codec aliases must
fail closed. Never deserialize arbitrary PHP objects or infer class
names from untrusted persisted messages.

Step 4 — prepare native queue schema
------------------------------------

For database-backed durable messaging:

.. code-block:: bash

   php infbyte module:schema:status execution.messaging
   php infbyte module:schema:install execution.messaging
   php infbyte module:schema:sync

A database-backed queue requires appropriate Omnibus tables,
message-codec configuration and a failure-store decision
(``database`` versus intentionally volatile ``memory``).
The native Omnibus database transport, workflow store and failure
store must use a coherent database connection for durable semantics.

Don't create queue tables inside the HTTP request that first
dispatches a message.

Step 5 — run one bounded consumer
---------------------------------

Before starting a daemon, prove one bounded receive path:

.. code-block:: bash

   php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60
   php infbyte queue:monitor --transport=redis --queue=reports

The Redis transport/queue options above are valid **only if configured**
and supported by the chosen native backend. A bounded consumer
runs in the Foundation Worker runtime and cleans its execution
scope between successfully decoded deliveries.

For a persistent profile, configure a named worker under
``messaging.workers`` with bounded message/runtime limits:

.. code-block:: php

   'workers' => [
       'reports' => [
           'transport' => 'redis',
           'queue' => 'reports',
           'prefetch' => 4,
           'visibility_seconds' => 60.0,
           'max_messages' => 1000,
           'max_runtime_seconds' => 3600.0,
           'pool' => ['enabled' => false],
       ],
   ],

This is a **contextual configuration fragment**; full worker
configuration may include idle backoff, memory growth and signal
policy. Inspect the owning Foundation/Omnibus reference before
introducing extra worker options.

Then:

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:run reports
   php infbyte worker:status reports

The name ``reports`` is defined by your application.
The external supervisor, not Foundation, restarts processes
after graceful generation changes or crashes.

Step 6 — inspect terminal failures safely
-----------------------------------------

When work cannot be completed, investigate the actual Omnibus
failure record rather than assuming the queue is empty:

.. code-block:: bash

   php infbyte queue:failed --limit=100
   php infbyte queue:failed:show <id>
   php infbyte queue:retry <id>
   php infbyte queue:prune-failed --hours=168

``queue:flush --force`` clears **failed-message storage**;
it does not purge the live queue. Avoid it as a generic incident
response. Retain enough failure history for reproduction and
reconciliation.

Retries must be safe: an HTTP notification, financial mutation
or third-party side effect might already have occurred before
a worker was interrupted. Use operation identifiers and
idempotency policy at the actual side-effect owner.
A visibility timeout is not a transactional guarantee.

Step 7 — coordinate generation changes
---------------------------------------

For a rolling deployment:

1. Record the exact Foundation release, Omnibus version,
   codec allow-list and selected message schema.
2. Stop accepting incompatible new message formats.
3. Quiesce/drain affected workers and snapshot durable
   queue/failure/workflow state before a breaking upgrade.
4. Build and verify the new immutable Foundation generation.
5. Signal workers to restart or reload cooperatively.
6. Let the external supervisor spawn replacement processes.
7. Consume one bounded test message and verify acknowledgment,
   retry and failure behavior with the actual transport.
8. Resume normal concurrency only when both old and new
   readers are known to understand retained data.

Foundation's generation signals include:

.. code-block:: bash

   php infbyte runtime:reload
   php infbyte worker:restart
   php infbyte worker:restart reports

Omnibus owns graceful message-loop cessation. Do not let stale,
cancelled or superseded workers continue claiming new jobs.

Step 8 — protect persistent-process boundaries
-----------------------------------------------

Request-local authentication principals, ORM/DB transactions,
coroutine context, temporary uploads, and mutable HTTP clients
must not survive between messages in the same worker.
Foundation enters/cleans an execution scope for messages;
Omnibus still owns transport resources and acknowledgment.

A useful integration sequence is:

* successful delivery,
* handler failure followed by retry,
* cancellation or lease loss,
* successful delivery in the **same host process**.

Assert that the final message cannot observe the earlier
execution's principal, transaction, completion state or
temporary resources.

Common failures and safe checks
-------------------------------

.. list-table::
   :header-rows: 1
   :widths: 29 37 34

   * - Problem
     - Likely cause
     - First action
   * - No messages consumed
     - Wrong transport, queue, worker or generation
     - Inspect worker profile and queue monitor
   * - Codec error
     - Missing allow-list or payload version mismatch
     - Inspect native codec config and retained payload type
   * - Duplicate side effect
     - Retry after partial handler completion
     - Check idempotency and acknowledgment boundary
   * - Worker not restarted
     - No external supervisor or unread generation token
     - Inspect worker status and process manager
   * - Database failure store missing
     - Unprovisioned schema or wrong connection
     - Check module schema status and DBLayer config
   * - Stale worker still writes
     - Lost ownership/cancellation not observed
     - Stop affected process and verify native lease checks

Further reading
---------------

See :doc:`../services/events-jobs`,
:doc:`../services/queues-workers`,
:doc:`../console/workers`,
:doc:`../testing/persistent-runtimes`, and the
:doc:`../services/queues-workers` application integration guide.
