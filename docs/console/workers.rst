.. _worker-lifecycle-guide:

Run and supervise Infbyte background workers
============================================

Infbyte has **two worker families**. Maintenance workers implement
Foundation's ``WorkerProvider`` and are selected through
**``my-app/routes/workers.php``**. Omnibus message consumers use
the optional ``execution.messaging`` feature and
**``my-app/config/messaging.php``**. Both run in Foundation's
explicit worker mode, even when launched by the PHP CLI executable.

A fresh Core-only Infbyte 4 candidate ships
``routes/workers.php`` but registers no custom worker.
It also has no ``config/messaging.php`` until you
select the optional feature.

Build a maintenance WorkerProvider
----------------------------------

Create ``my-app/app/Workers/HeartbeatWorker.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Workers;

   use Infocyph\Foundation\Worker\WorkerProvider;
   use Infocyph\Foundation\Worker\WorkerRuntime;

   final readonly class HeartbeatWorker implements WorkerProvider
   {
       public function run(WorkerRuntime $runtime): int
       {
           if ($runtime->stopRequested()) {
               return 0;
           }

           $runtime->execute(
               static function (): void {
                   // Perform one bounded, idempotent unit of work.
               },
           );

           $runtime->heartbeat();

           return 0;
       }
   }

This is a **developer-created demonstration**, not a
preinstalled daemon. The ``WorkerProvider`` contract
is ``run(WorkerRuntime $runtime): int``.
A real maintenance worker needs application-specific
registration in ``routes/workers.php``, bounded loop
policy, and a supervising process.

``WorkerRuntime::execute()`` creates a fresh
application execution scope. If the host requests restart,
``heartbeat()`` can raise a
``WorkerRestartRequested`` exception; do not swallow
that signal and continue accepting new work.

Choose a message consumer only for messages
-------------------------------------------

To consume queued messages, first select the optional
messaging feature and publish the **Infbyte**
``config/messaging.php``:

.. code-block:: bash

   php infbyte module:plan execution.messaging
   php infbyte module:install execution.messaging
   php infbyte module:enable execution.messaging
   php infbyte module:doctor execution.messaging

The Omnibus message bus, transport, codecs, handler maps,
queue name, acknowledgment and failure store are all
explicit configuration choices. In-memory transport is not
durable, and selecting a Redis name does not install a server.

For an appropriately configured bounded consumer:

.. code-block:: bash

   php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60

Here ``redis`` and ``reports`` are **application-defined**.
For complete transport and codec configuration, read
:doc:`../services/queues-workers` and
:doc:`../tutorials/durable-queue`.

Inspect and start a registered worker
-------------------------------------

From the project root:

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:status
   php infbyte worker:run reports

``reports`` must be an actual configured worker.
Never assume a class is registered simply because it
exists under ``app/Workers/``. Route/worker registration
and selected runtime have to agree.

The host must decide whether a worker is one-shot,
bounded-count, time-limited or persistent. Do not
write an infinite busy loop that bypasses
``WorkerRuntime::execute()``, heartbeat checks,
backoff and graceful cancellation.

Signals and external supervision
--------------------------------

Foundation can signal cooperative stop/reload:

.. code-block:: bash

   php infbyte worker:restart
   php infbyte worker:restart reports
   php infbyte runtime:reload

These commands **do not launch replacement workers**.
An external supervisor such as systemd or the container
orchestrator owns process restart, scaling and termination
grace periods.

For distributed singleton workers, select CacheLayer
coordination and ownership policy explicitly.
A local test lock does not serialize multiple hosts.
Lost lease or stale generation ownership must stop
the old instance from publishing further work.

Bounded execution and retry safety
----------------------------------

* Make each unit idempotent or protect its durable
  effects with a unique business execution key.
* Close DBLayer transactions and file/socket handles
  at the end of each unit, including failure paths.
* Do not preserve an authenticated principal or
  browser session from another user's request.
* Refresh ownership during long operations, honor
  cancellation and stop requests.
* Never acknowledge Omnibus work until the selected
  handler has reached the correct transport
  completion point.
* For a rolling upgrade, preserve queue codec and
  retained-data compatibility until old workers drain.

Test the lifecycle deliberately
-------------------------------

A useful acceptance sequence is:

1. Complete one successful bounded unit.
2. Fail the next unit after acquiring a DB/lock resource.
3. Confirm another unit receives fresh scope state.
4. Request restart while work is in flight.
5. Verify the old generation stops accepting new work,
   and the external supervisor launches a new generation.

Check the actual worker host and queue receipt/lease
semantics. A TestKit fake proves the intended call,
not production durability or multi-worker safety.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Symptom
     - Likely cause
     - First check
   * - Worker not listed
     - Missing maintenance registration or messaging profile
     - ``routes/workers.php`` / ``config/messaging.php``
   * - Messages disappear on restart
     - Volatile ``memory`` transport
     - Native transport and durable store
   * - Multiple workers perform same singleton job
     - Lock not distributed or lease lost
     - CacheLayer provider, ownership and generation
   * - Worker stops and never returns
     - No external supervisor restart
     - Process orchestration and restart policy
   * - Old generation handles new payload
     - Worker drain/reload incomplete
     - Source SHA and codec compatibility
   * - Persistent memory grows indefinitely
     - Escaped scope references or unbounded payloads
     - Per-unit resource release and memory limit

Next: :doc:`../services/queues-workers`,
:doc:`../advanced/runtime-modes`,
:doc:`../testing/persistent-runtimes` and
:doc:`../deployment/operations`.
