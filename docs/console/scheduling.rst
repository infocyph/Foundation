.. _scheduling-guide:

Schedule recurring tasks in Infbyte
===================================

A schedule describes **when** an application command should execute.
Infbyte owns its application schedule source at
**``my-app/routes/schedule.php``**. Foundation provides the
scheduler contract and selected runtime graph; an external
cron/systemd/container service decides when to invoke it.

A schedule is not a durable queue or a substitute
for a process supervisor. This guide describes the
Infbyte 4 **development candidate**.

Start with the actual application schedule
------------------------------------------

The Infbyte 4 candidate has
``routes/schedule.php`` with an empty
``Schedule`` callback:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Scheduling\Schedule;

   return static function (Schedule $schedule): void {
       // Register schedules when the application needs them.
   };

Nothing is scheduled automatically in a fresh
Core-only app. Before adding an entry, ensure
the corresponding command is registered in
**``my-app/routes/console.php``**.

Step 1 — register a command first
---------------------------------

Create an application command such as
``App\Command\Reports\DailyCommand`` following
:doc:`commands`. Its source and public name
must agree with the command registry.

This complete example is maintained as
a PHP 8.4 syntax-checked documentation file:

.. literalinclude:: ../examples/infbyte-report-command.php.txt
   :language: php
   :linenos:

The example returns success but does no
database processing. Your real handler should
be bounded, idempotent and able to report a
controlled failure exit status.

Step 2 — add one scheduled entry
--------------------------------

Edit **``my-app/routes/schedule.php``** to
register the command. The following complete
source is also syntax checked in docs CI:

.. literalinclude:: ../examples/infbyte-schedule.php.txt
   :language: php
   :linenos:

The schedule runs ``reports:daily`` at 02:00
in the UTC timezone, assigns the stable
identity ``reports.daily``, and selects
single-server/overlap protection.

The scheduled entry's **key** is important
when two entries run the same command with
different arguments or timing.

Step 3 — choose an appropriate recurrence
-----------------------------------------

Foundation's native ``ScheduledCommand`` supports:

.. list-table::
   :header-rows: 1
   :widths: 42 58

   * - Method
     - Meaning
   * - ``everyMinute()``
     - One-minute cadence
   * - ``hourly()``
     - Each hour
   * - ``dailyAt('02:00')``
     - Daily at a local clock time
   * - ``cron(string $expression)``
     - Explicit cron expression
   * - ``timezone('UTC')``
     - Timezone for due-check
   * - ``key('reports.daily')``
     - Stable entry identity
   * - ``arguments([...])``
     - CLI arguments supplied to the command
   * - ``timeout(float $seconds)``
     - Maximum time policy
   * - ``memoryLimit(int $megabytes)``
     - Memory boundary policy

Choose a timezone explicitly when a task
must run at a specific civil time. Consider
daylight-saving transitions: a wall-clock
time can be skipped or repeated. For strict
once-per-day business semantics, use a
stable business-period identifier and
idempotency record, not only a cron match.

Don't schedule expensive maintenance every
minute just because the scheduler permits it.

Step 4 — inspect and exercise the entry
---------------------------------------

From the Infbyte project root:

.. code-block:: bash

   php infbyte schedule:list
   php infbyte schedule:test reports.daily
   php infbyte schedule:run

``schedule:test`` selects the stable entry
key and verifies the registered command
through the selected runtime. ``schedule:run``
evaluates due entries.

For a continuously supervised scheduler,
the CLI may run:

.. code-block:: bash

   php infbyte schedule:work --sleep=60

Do not run the loop unmanaged as a
background shell process and assume
it restarts after a host reboot. Configure
a real process supervisor when selecting
a long-running scheduling service.

Step 5 — protect overlapping runs
----------------------------------

Two independent scheduler processes can
both see the same entry as due. The native
``ScheduledCommand`` supports:

* ``withoutOverlap()`` to keep a second
  instance of **this entry** from running
  while the first holds its lease;
* ``onOneServer()`` to select one owner
  among eligible scheduler hosts.

They rely on CacheLayer coordination,
not a magic lock shared between servers.

You can configure bounded lease and
wait durations:

.. code-block:: php

   $schedule->command('reports:daily')
       ->key('reports.daily')
       ->everyMinute()
       ->onOneServer(true, 300.0, 0.0)
       ->withoutOverlap(true, 300.0, 0.0);

This is a **registration excerpt** to place
inside the schedule callback; it requires
a working shared atomic coordination
backend if multiple hosts run schedulers.

A host-local memory store cannot provide
cross-host exclusion. A lost lease must
not permit a stale process to continue
committing unowned work.

For a financial or other high-value task,
add an application-level idempotency key
and durable record even when a scheduler
lock is selected.

Step 6 — separate schedule and queue work
-----------------------------------------

A scheduler starts a **command** at a due
time. If that command should enqueue
durable asynchronous tasks, explicitly
select ``execution.messaging`` and dispatch
through the configured native Omnibus bus.

Scheduling alone does not start an Omnibus
message consumer or persist a message
through a process restart. A separate
supervised message worker handles
queued deliveries.

For short maintenance operations, a
scheduled CLI command may be enough.
For large or retryable work, dispatch
small idempotent messages with safe
codecs and a real durable transport.

Step 7 — update production artifacts safely
--------------------------------------------

Schedule metadata can be cached during
development:

.. code-block:: bash

   php infbyte schedule:cache
   php infbyte schedule:clear

A trusted Foundation release still
must compile the matching scheduler,
CLI and dependency graph:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

For a controlled rolling replacement,
the application offers:

.. code-block:: bash

   php infbyte schedule:interrupt

This requests cooperative interruption
of old scheduler loops. It does **not**
start a replacement daemon. The external
supervisor must start the new generation
with the appropriate trusted manifest
identity.

Step 8 — test recurrence and failure behavior
---------------------------------------------

Verify more than the happy path:

1. Scheduled key resolves the intended command.
2. Recurrence/timezone works at a boundary
   (including daylight-saving transitions
   if your timezone observes them).
3. Required CLI inputs are supplied correctly.
4. No due entry runs outside its expected window.
5. Two concurrent hosts cannot both own a
   single-server entry.
6. A lost lock lease stops unowned work.
7. A command failure propagates to diagnostics
   rather than being reported as success.
8. A rolling restart does not duplicate a
   side effect across old/new generations.

A frozen application clock is useful for
unit tests only if the scheduler's actual
time source observes that clock. Verify
the native due-check source when writing
time-control fixtures.

Common failures
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 35 37

   * - Symptom
     - Likely cause
     - First check
   * - Entry absent from list
     - Schedule callback not active
     - ``routes/schedule.php`` and selected graph
   * - Scheduled command is unknown
     - Command not registered
     - ``routes/console.php`` and command list
   * - Nothing executes when due
     - Supervisor not invoking run/work
     - Host trigger, timezone, due clock
   * - Duplicate work after multiple hosts start
     - Process-local lock or unsafe idempotency
     - Shared CacheLayer store and lease/fencing
   * - New schedule ignored after deployment
     - Old compiled generation active
     - Trusted release and schedule metadata
   * - Worker stops after interrupt
     - Expected cooperative shutdown
     - External supervisor/replacement process
   * - Scheduled job never reaches consumer
     - No durable Omnibus transport/worker
     - ``config/messaging.php`` and receiver

Next: :doc:`commands`,
:doc:`workers`,
:doc:`../services/queues-workers`,
:doc:`../data/cache` and
:doc:`../deployment/releases`.
