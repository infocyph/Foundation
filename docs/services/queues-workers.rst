.. _queues-and-workers:

Queues and background workers in Infbyte
========================================

A background job should continue under a **defined**
delivery and failure policy after the HTTP response
has finished. Infbyte composes **Omnibus** for this;
it does not implement a second queue engine.

**Application configuration** belongs in
``my-app/config/messaging.php`` after selecting
``execution.messaging``. A new Core-only Infbyte
candidate does not ship this file.

Understand the three execution concepts
---------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 28 35 37

   * - Concept
     - Owned by
     - What it does
   * - ``routes/workers.php``
     - Infbyte application + Foundation
     - Declares maintenance worker providers
   * - ``messaging.workers``
     - Infbyte config + Omnibus
     - Defines named message-consumer workers
   * - Selected queue transport
     - Native Omnibus
     - Stores/reserves/acks/releases messages

An application maintenance worker is not
automatically an Omnibus message consumer.
A registered message route does not start a
daemon; an external process supervisor is
needed for continuously running background
workers in deployment.

Step 1 — enable messaging
-------------------------

From your Infbyte project:

.. code-block:: bash

   php infbyte module:plan execution.messaging
   php infbyte module:install execution.messaging
   php infbyte module:enable execution.messaging
   php infbyte module:doctor execution.messaging

Inspect ``config/messaging.php`` after
publication. Configure message routing,
handler mappings, selected transport,
consumer/worker names and codec policies.

Step 2 — select a transport
---------------------------

The owning Omnibus transport determines
how deliveries behave:

.. list-table::
   :header-rows: 1
   :widths: 23 33 44

   * - Transport
     - Typical use
     - Important constraint
   * - ``sync``
     - Immediate deterministic dispatch
     - Not an asynchronous durable queue
   * - ``memory``
     - Local development/in-process tests
     - Does not survive process termination
   * - Redis-backed transport
     - Shared queue consumers
     - Must configure native Redis and visibility policy
   * - DBLayer-backed transport
     - SQL durable messaging
     - Requires ``data.database``, schema and codecs

Do not infer that installing Redis or DBLayer
automatically enables a corresponding Omnibus
receiver. The selected runtime must have the
actual transport and application configuration.

Step 3 — route and handle a message
-----------------------------------

Generate a starting point:

.. code-block:: bash

   php infbyte create:job GenerateReport
   php infbyte create:handler GenerateReport

Register application-defined classes in
``config/messaging.php``. A contextual fragment:

.. code-block:: php

   'routes' => [
       App\Jobs\GenerateReportJob::class => [
           'transport' => 'redis',
           'queue' => 'reports',
           'delay_seconds' => 0.0,
       ],
   ],
   'handlers' => [
       App\Jobs\GenerateReportJob::class =>
           App\Messaging\Handlers\GenerateReportHandler::class,
   ],

The ``App\...`` classes are examples that
**you** must create. ``redis`` and ``reports``
must name an eligible configured transport
and queue; the fragment does not create them.
Do not replace the whole application config
with this excerpt.

For the synchronous/default path and event
listeners, read :doc:`events-jobs`.

Step 4 — configure a named worker
---------------------------------

The ``messaging.workers`` section maps a
worker name to selected transport/queue,
concurrency, visibility/backoff,
resource bounds and lifecycle policy.

A contextual worker profile:

.. code-block:: php

   'workers' => [
       'reports' => [
           'transport' => 'redis',
           'queue' => 'reports',
           'prefetch' => 4,
           'visibility_seconds' => 60.0,
           'max_messages' => 1000,
           'max_runtime_seconds' => 3600.0,
       ],
   ],

The actual application must provide all
required native settings for its backend.
``reports`` is **not** included by the
default Core-only skeleton.

List and run eligible workers with the
current Foundation 4 CLI:

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:run reports
   php infbyte worker:status reports

A supervisor/systemd/container orchestrator
must restart the worker after an intended
exit or crash. Foundation signals worker
control but does not spawn process managers
out of nothing.

Step 5 — run a bounded consumer first
-------------------------------------

Before starting indefinite service, test a
bounded receive operation on the actual
configured transport:

.. code-block:: bash

   php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60
   php infbyte queue:monitor --transport=redis --queue=reports

The command is only applicable if that
native transport/queue exists. Visibility
is a delivery lease, **not** proof that a
handler can never execute twice.

A late/failed acknowledgement can produce
another delivery. Plan all non-idempotent
effects accordingly.

Step 6 — enable durable SQL messaging
-------------------------------------

Omnibus's DBLayer-backed durable transport
requires the optional ``data.database``
feature as well as ``execution.messaging``,
a real SQL connection, selected message
codecs and applicable native schema.

.. code-block:: bash

   php infbyte module:schema:status execution.messaging
   php infbyte module:schema:install execution.messaging

Failure storage must have a deliberate policy:
a database-backed failure store is suitable
for retained operational history, while an
in-memory store intentionally loses records
after the process exits.

Never accept arbitrary payload-provided
PHP classes or native PHP object deserialization.
Omnibus's explicit allowed codecs and bounded
JSON serializer must own persistent message
formats.

Step 7 — inspect and retry failures
-----------------------------------

When a message becomes terminally failed:

.. code-block:: bash

   php infbyte queue:failed --limit=100
   php infbyte queue:failed:show <id>
   php infbyte queue:retry <id>
   php infbyte queue:prune-failed --hours=168

Replace ``<id>`` with the actual failure
identifier. Treat retry as a **business
replay**, not proof that the first execution
made no side effects.

``queue:flush --force`` affects the
failed-message store, not the live queue.
It is destructive and should not be used
as a generic recovery command.

Step 8 — coordinate deployment generations
-------------------------------------------

When the application changes a handler,
codec, database schema or persisted payload:

1. Record the exact Infbyte, Foundation and
   Omnibus installed source versions.
2. Check whether old/new workers can read
   the same message and retained records.
3. Quiesce affected consumers when required.
4. Snapshot the appropriate durable queue,
   failure store and relevant database rows.
5. Build a new trusted Foundation generation.
6. Signal a cooperative worker restart.
7. Let the external supervisor launch new workers.
8. Test a bounded delivery, failure and retry.
9. Resume normal capacity only after
   confirming compatibility.

The relevant Foundation control commands:

.. code-block:: bash

   php infbyte runtime:reload
   php infbyte worker:restart
   php infbyte worker:restart reports

A stale, cancelled or superseded worker must
not continue claiming work after losing its
generation/lease ownership.

Step 9 — test all lifecycle paths
---------------------------------

A useful test sequence under one selected
persistent host:

* Message dispatch and successful acknowledgement.
* Handler exception and a recorded retry/failure.
* Visibility expiry or lost ownership, if backend supports it.
* Cancellation while holding a scoped resource.
* A new successful message in the same process.
* Verification that prior principal, DB transaction,
  temporary upload and response context have gone.

Recording senders from TestKit are useful
for fast application tests, but they cannot
prove durable reservation/ack behavior.
Use actual backend acceptance separately.

Performance and resource selection
----------------------------------

Configure bounded prefetch, maximum messages,
maximum runtime, memory growth and backoff
based on representative processing time and
actual backend capacity.

A queue that silently retries a failed network
request thousands of times can amplify outages.
Use explicit retry budgets, dead-letter/failure
records, observability and idempotency.

Production monitoring should record backlog,
time-in-queue, retries, terminal failures,
worker restarts, actual backend connection
pressure and generation identity.

Where to go next
----------------

For a full durable delivery/rollback tutorial,
read :doc:`../tutorials/durable-queue`,
:doc:`../console/workers` and
:doc:`events-jobs`.

For native transport and worker mechanics:
https://github.com/infocyph/Omnibus
