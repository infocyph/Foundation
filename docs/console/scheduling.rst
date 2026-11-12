.. _scheduling-guide:

Scheduling recurring work
=========================

The scheduler starts **bounded command executions** at configured times.
It is not a message broker or a replacement process supervisor. The
Foundation 4 scheduler uses its own application mode, and schedule
definitions live in the application-owned ``routes/schedule.php``.

1. Register a schedule
----------------------

The file returns a callable that receives
``Infocyph\Foundation\Scheduling\Schedule``.

This complete example is CI syntax-checked:

.. literalinclude:: ../examples/infbyte-schedule.php
   :language: php
   :linenos:

It schedules an application-defined ``reports:daily`` command every
day at 02:00 UTC and gives the entry a **stable identity**. The command
must already exist in ``routes/console.php``. No new command class is
generated merely by adding a schedule.

The methods ``dailyAt()``, ``timezone()``, ``key()``,
``onOneServer()`` and ``withoutOverlap()`` are defined on
Foundation's ``ScheduledCommand`` contract.

2. Inspect and test scheduling
------------------------------

.. code-block:: bash

   php infbyte schedule:list
   php infbyte schedule:test reports.daily
   php infbyte schedule:run

``schedule:test`` resolves a stable key first. For schedules sharing
the same command string, use unique keys: matching only by text would
make run history and tests ambiguous.

``schedule:run`` evaluates due entries and should be triggered
according to the deployment scheduler. Alternatively, an appropriate
long-running schedule service can use:

.. code-block:: bash

   php infbyte schedule:work --sleep=60

Do not run multiple uncoordinated scheduler processes unless the
application's locking and single-server policy are configured and
tested.

3. Locking and overlap
----------------------

``withoutOverlap()`` protects against a second execution of the same
scheduled item. ``onOneServer()`` coordinates across multiple
eligible scheduler hosts. The underlying coordination is CacheLayer,
not a hidden global process lock.

For a distributed deployment, select an actual shared, atomic
coordination backend. Process-local storage cannot enforce a
single-server guarantee across hosts.

Lease and wait durations must be finite and valid. During supervised
commands, Foundation refreshes ownership. If a scheduler loses the
lease, the active child is stopped rather than continuing unowned.

4. Schedule metadata and deployment
-----------------------------------

.. code-block:: bash

   php infbyte schedule:cache
   php infbyte schedule:clear
   php infbyte schedule:interrupt

Schedule metadata caching is an optimization; the generated production
application still requires a complete trusted release. The
``schedule:interrupt`` command sets a generation token that asks
running scheduler processes to stop cooperatively. It does not start
replacement processes.

An external systemd, cron or container supervisor must manage the
actual process. Use a supervised runtime appropriate to the deployed
host and ensure that repeated invocations do not overlap unintentionally.

5. Common failure scenarios
---------------------------

* **Nothing runs:** verify the application's timezone, cron expression,
  executable command registration, and the actual scheduler trigger.
* **Duplicate runs:** verify shared lock state, key identity and number
  of scheduler instances; do not rely on a local cache across hosts.
* **Command execution fails after deployment:** rebuild command and
  scheduler metadata for the active generation. Check the process's
  trusted release identity and configured capabilities.
* **Work stops after a reload signal:** expected cooperative shutdown;
  ensure the supervisor starts the replacement worker.

See :doc:`workers` for persistent processes and the
`Foundation scheduler contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/console.md>`_.
