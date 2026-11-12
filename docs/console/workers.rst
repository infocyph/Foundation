.. _worker-lifecycle-guide:

Background worker lifecycle
===========================

Infbyte has **two distinct worker families**. Maintenance workers
implement the Foundation ``WorkerProvider`` contract; message
consumers and pools are managed by native **Omnibus**. Both use
Foundation's explicit worker application mode.

Choose the right worker
-----------------------

A maintenance worker performs application-owned repeated maintenance.
Configure it in ``routes/workers.php`` and implement
``Infocyph\Foundation\Worker\WorkerProvider``.
A queue worker consumes Omnibus messages configured under
``messaging.workers``; it needs the optional
``execution.messaging`` feature and the selected transport.

The worker runtime is neither HTTP nor CLI mode even when launched
from the ``infbyte`` CLI executable.

Run and inspect workers
-----------------------

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:status
   php infbyte worker:run reports
   php infbyte worker:restart reports

``reports`` is an **application-defined worker name**. Replace it
with one shown by ``worker:list``. A command may fail if the
named provider or optional message transport is not configured.

To consume a bounded set of Omnibus deliveries:

.. code-block:: bash

   php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60

That command requires the corresponding transport, queue, codecs and
failure-storage configuration. Do not assume the presence of Redis
implies a configured Omnibus worker.

Execute bounded work safely
---------------------------

The maintenance provider receives a ``WorkerRuntime``. It should
use ``$runtime->execute($handler)`` for each bounded item; this
creates a fresh execution scope while leaving only safe reusable
process infrastructure alive. Call ``heartbeat()`` during long
operations when appropriate, and honour restart signals.

A provider must never keep a previous request's principal,
transactions, browser sessions, mutable authentication state or
temporary upload paths in process-wide singleton properties.

Ownership and graceful restart
------------------------------

Workers do not hold locks unless configured to do so.
``singleton=true`` opts a maintenance worker into CacheLayer
ownership. A long-lived worker refreshes that lease and checks
generation tokens; lost ownership must stop execution.

Use these commands to request cooperative shutdown:

.. code-block:: bash

   php infbyte worker:restart
   php infbyte runtime:reload

These commands do **not** spawn new processes. Supervisor, systemd,
Docker or Kubernetes owns replacement, scaling and termination grace
periods. For Unix process-pool behavior, Omnibus owns the native
``WorkerPool`` and forks only after appropriate checks.

Deployment/rollback checklist
-----------------------------

1. Stop acceptance of new work for the retiring generation.
2. Allow bounded work to finish or cancel safely.
3. Verify that active reservations have valid receipts, and retained
   queue payload codecs are compatible with the next generation.
4. Switch the trusted generation and relaunch workers through the
   process supervisor.
5. Reconcile dead-letter/failure records and check for stale
   heartbeat entries.

A code rollback is not proof that old code can decode messages written
by new code. Rehearse the actual transport and retained-state schema.

See :doc:`../services/queues-workers` and
`Foundation operations
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/operations.md>`_.
