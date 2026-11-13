.. _cli-overview:

Using the Infbyte command line
==============================

The ``infbyte`` executable is the application's entry point for
development, maintenance, commands, scheduling, workers, and deployment.
It delegates to Foundation's ``CommandDispatcher``; there is no parallel
Artisan-like kernel. This chapter targets the **Foundation 4 development
contract**, not the current published Infbyte 3 command catalog.

Discover commands before using them
-----------------------------------

Run these commands from a compatible application's root:

.. code-block:: bash

   php infbyte list
   php infbyte help
   php infbyte about
   php infbyte env:show
   php infbyte route:list
   php infbyte config:show router.matcher

Listing, help and version metadata use a bootless preflight where possible.
The application does not construct the complete web graph merely to print
help. This is useful for diagnosing problems before connecting to any
database or message broker.

To see machine-readable output from commands that support it:

.. code-block:: bash

   php infbyte about --json
   php infbyte config:validate --json

When automating deployments, parse documented command output rather than
scraping human-readable tables. A machine-readable schema's fields can
change across major versions; record the framework version with generated
reports.

Global options
--------------

The following switches are parsed by Foundation:

.. list-table::
   :header-rows: 1
   :widths: 22 78

   * - Option
     - Behavior
   * - ``-q``, ``--quiet``
     - Suppress routine informational output.
   * - ``--silent``
     - Suppress output, including profiling.
   * - ``-v``, ``-vv``, ``-vvv``
     - Increase verbosity for diagnostics.
   * - ``--json``
     - Select structured output where supported.
   * - ``--profile``
     - Report command-level profile to stderr, without corrupting stdout.
   * - ``--env=<name>``
     - Select an execution environment explicitly.
   * - ``-n``, ``--no-interaction``
     - Disable prompts for automation.

For isolated commands executed via ``ProcessRunner``, Foundation
carries the execution identity into the child process. The parent
reports command-level profiling once; the child does not duplicate it.

Commands by task
----------------

.. rubric:: Application inspection

.. code-block:: bash

   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte app:ready

These commands answer different questions. Ordinary validation checks
configuration shape. ``--production`` applies production policy.
``app:ready`` also checks selected dependency/schema/storage readiness.
None of them is a substitute for a live HTTP health check.

.. rubric:: Building generated artifacts

.. code-block:: bash

   php infbyte config:cache
   php infbyte command:cache
   php infbyte schedule:cache
   php infbyte optimize
   php infbyte optimize:report

The first three are development/deployment metadata optimizations.
``optimize`` publishes a coordinated immutable application generation.
There is no independent Foundation ``route:cache``; Webrick's compiled
route output belongs to the release generation.

.. rubric:: Operational controls

.. code-block:: bash

   php infbyte maintenance:status
   php infbyte worker:list
   php infbyte schedule:list
   php infbyte execution:list

Some commands contact activated services; do not assume they are free
or safe to run from every HTTP worker. Run operational commands from
a controlled CLI or deployment context.

What happens when a command runs?
---------------------------------

Foundation selects the **CLI runtime**, composes the command metadata,
parses arguments/options and invokes the registered handler. Commands
requiring timeouts, memory limits or isolation use supervised child
execution. Resources scoped to a command are released on exit, including
failure paths.

Long-running work belongs to the worker or scheduler runtime instead
of an indefinitely running web controller.

Where to go next
----------------

Read :doc:`commands` to register an application command,
:doc:`scheduling` to run it on a schedule, and
:doc:`workers` for continuous processes.
Use :doc:`../reference/cli` for the maintained command catalog
and :doc:`../reference/console-testing-api` for typed CLI contracts.
