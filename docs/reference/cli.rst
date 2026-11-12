.. _cli-reference:

Command reference
=================

This is a task-oriented **Foundation 4 development** command reference
for compatible Infbyte applications. All commands use the root
``php infbyte`` executable. The currently published Infbyte
3 skeleton may expose different module names and flags.

Inspect
-------

.. code-block:: bash

   php infbyte list
   php infbyte help
   php infbyte about
   php infbyte env:show
   php infbyte config:show router.matcher
   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte route:list
   php infbyte app:ready

``app:ready`` checks selected runtime/dependency and schema
readiness; it is not a public liveness probe.

Manage optional modules
-----------------------

.. code-block:: bash

   php infbyte module:list
   php infbyte module:show data
   php infbyte module:doctor data.database
   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:config:publish data.database
   php infbyte module:schema:status data.database
   php infbyte module:schema:sync

Groups such as ``data``, ``security`` and ``execution``
are read-only grouping concepts unless individual features are
selected. A bare group does not install every sibling feature.

Generate code
-------------

.. code-block:: bash

   php infbyte create:controller Admin/User
   php infbyte create:command Reports/Daily
   php infbyte create:request StoreUser
   php infbyte create:rule ValidVatNumber
   php infbyte create:resource Account
   php infbyte create:job GenerateReport
   php infbyte create:handler GenerateReport
   php infbyte create:worker Metrics
   php infbyte create:migration CreateAccounts
   php infbyte create:seeder Production

Generators create starting points without automatically adding
new routes, commands or providers to the active graph.

Schedule and background work
----------------------------

.. code-block:: bash

   php infbyte schedule:list
   php infbyte schedule:run
   php infbyte schedule:test reports.daily
   php infbyte schedule:work --sleep=60
   php infbyte schedule:interrupt
   php infbyte worker:list
   php infbyte worker:status
   php infbyte worker:restart
   php infbyte queue:failed

The argument ``reports.daily`` is an application-defined
schedule key. Workers and queues require registered definitions and
selected native transports.

Configuration and generated release
-----------------------------------

.. code-block:: bash

   php infbyte config:cache
   php infbyte config:clear
   php infbyte command:cache
   php infbyte command:clear
   php infbyte schedule:cache
   php infbyte schedule:clear
   php infbyte optimize
   php infbyte optimize:report
   php infbyte optimize:clear

Do not confuse an individual source metadata cache with the trusted
production generation. Never delete artifacts that a running
process still needs without a controlled release activation.

Operations
----------

.. code-block:: bash

   php infbyte maintenance:status
   php infbyte maintenance:enable --retry=60
   php infbyte maintenance:disable
   php infbyte runtime:reload
   php infbyte worker:restart
   php infbyte execution:list
   php infbyte secret:generate

The exact option set is available through
``php infbyte help <command>``. For an authoritative,
feature-specific list refer to the
`Foundation CLI guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/console.md>`_
and `operations guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/operations.md>`_.

Global CLI controls
-------------------

.. code-block:: text

   -q, --quiet
   --silent
   -v, -vv, -vvv
   --profile
   --json
   --env=<environment>
   -n, --no-interaction

Use machine-readable output for automation where a command supports
it. Keep secrets out of command arguments, environment dumps and
public logs.
