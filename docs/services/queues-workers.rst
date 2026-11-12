.. _queues-and-workers:

Queues and background workers
=============================

Omnibus provides native durable queues, failure storage, worker loops
and handler execution. Foundation composes the application worker
runtime and selected service dependencies without providing a second
queue engine.

Workers are explicit
--------------------

Installing ``execution.messaging`` does not start a daemon.
Run a selected worker through the application CLI and configure an
external supervisor when deployment requires continuous service.

.. code-block:: bash

   php infbyte worker:list
   php infbyte worker:run reports
   php infbyte queue:consume --transport=redis --queue=reports --limit=100 --visibility=60

The precise worker/queue names must match your application's
``messaging.workers`` and route/transport configuration.

Durable queue storage
---------------------

Foundation 4 can compose Omnibus's native DBLayer-backed transport,
failure store and workflow store. Durable messaging requires
``data.database`` and the configured schema when a DB transport is
selected. Explicit codecs are necessary for persisted message payloads;
never deserialize arbitrary PHP objects from untrusted queue rows.

.. code-block:: bash

   php infbyte module:schema:status execution.messaging
   php infbyte module:schema:install execution.messaging
   php infbyte queue:failed

Retries and ownership
---------------------

Omnibus owns receipt/acknowledgment, retries, release and failure-store
behavior. A cancelled or stale execution must not continue accepting
ownership of new work. Foundation must release scoped resources between
deliveries, and the process supervisor owns service restart and scaling.

Do not assume a partial processing success is transactional with a
database commit; design jobs to handle retries and idempotent effects.

Maintenance workers versus message workers
------------------------------------------

Application maintenance worker providers register in
``routes/workers.php``, while Omnibus message workers are configured
in ``messaging.workers``. They are separate execution mechanisms.

Before a breaking upgrade, quiesce workers, preserve durable queue and
failure data, verify codecs/schema, and test coherent rollback.

See `Foundation messaging
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/messaging.md>`_
and :doc:`events-jobs`.
