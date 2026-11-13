.. _application-commands:

Create and run commands in an Infbyte application
=================================================

Infbyte commands are **application-owned PHP handlers**, registered in
``my-app/routes/console.php`` and executed through the selected
Foundation CLI graph. Foundation supplies command parsing, metadata,
IO, execution scope and lifecycle. There is no Laravel Artisan
kernel or automatic scan of ``app/Command/`` classes.

The Infbyte 4 candidate starts with an empty command map. This page
uses a real syntax-checked example and shows **how to make it active**.

Step 1 — find your application command registry
-----------------------------------------------

The actual candidate contains **``routes/console.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       // Add application command classes or name-to-class pairs.
   ];

When adding a command, keep this file in **the Infbyte project**,
not the Foundation repository. Command classes can live under
``my-app/app/Command/`` and use the Composer ``App\`` namespace.

The CLI itself is the project-root ``infbyte`` script.
Inspect the installed command catalog:

.. code-block:: bash

   php infbyte list
   php infbyte help
   php infbyte --version

Core metadata/help handling is designed to avoid an unnecessary
web graph. Only selected command services should be initialized.

Step 2 — generate or create your handler
----------------------------------------

A compatible CLI can scaffold a command:

.. code-block:: bash

   php infbyte create:command Reports/Daily

The generator creates an application source starting point;
it does **not** select a command name, register a handler or
make a database connection for you. Inspect the generated file.

The complete example below represents
``my-app/app/Command/Reports/DailyCommand.php``.
It is already PHP 8.4 syntax-checked in documentation CI:

.. literalinclude:: ../examples/infbyte-report-command.php.txt
   :language: php
   :linenos:

The class implements
``Infocyph\Foundation\Command\CommandHandlerInterface``
with exactly two required methods:

* ``define(CommandDefinition $command): void`` describes the
  command name, help text and input/execution policy.
* ``run(CommandContext $context): int`` performs work and returns
  a process exit status: zero for success, nonzero for failure.

The example uses ``$context->io()->success()`` and returns zero;
it does not send a real report or schedule a future task.

Step 3 — register the command explicitly
----------------------------------------

Update **``my-app/routes/console.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'reports:daily' => \App\Command\Reports\DailyCommand::class,
   ];

The name must agree with ``DailyCommand::define()``.
Avoid using the same name as a system command.
The class must be autoloadable and compatible with
the selected CLI runtime.

Validate the registration:

.. code-block:: bash

   php infbyte list
   php infbyte help reports:daily
   php infbyte reports:daily

If the command is unknown, inspect the registry, class namespace,
Composer autoload map and the active command metadata cache.

Step 4 — declare arguments and options
--------------------------------------

The actual ``CommandDefinition`` API offers:

.. list-table::
   :header-rows: 1
   :widths: 42 58

   * - Method
     - Meaning
   * - ``name(string $name)``
     - Public command name, such as ``reports:daily``
   * - ``description(string $text)``
     - Help/usage description
   * - ``group(string $group)``
     - List/help grouping
   * - ``alias(string $name)``
     - Additional name for the same command
   * - ``argument(string $name, string $description = '', bool $required = false, bool $variadic = false)``
     - Ordered positional input
   * - ``option(string $name, string $description = '', ?string $short = null, bool $acceptsValue = false, bool $multiple = false, bool $negatable = false)``
     - Named flag/value option
   * - ``runtime(RuntimeMode $runtime)``
     - Explicit allowed execution graph
   * - ``scope(bool $scoped = true)``
     - Whether the command needs an execution scope
   * - ``execution(CommandExecutionPolicy $policy)``
     - Bounds/overlap/execution controls

The signature excerpts are from the current Foundation 4
source. A command with input can add definitions inside
``define()``. For example:

.. code-block:: php

   $command
       ->argument('account', 'Account identifier', required: true)
       ->option('dry-run', 'Show proposed changes');

Then in its ``run()`` body, read:

.. code-block:: php

   $accountId = $context->input()->argument(0);
   $dryRun = $context->input()->flag('dry-run');

``$accountId`` is nullable at the type level even when
the parser enforces a required argument. Validate it before
using it as an account identifier, and authorize any
state-changing action on that account.

``CommandContext::input()`` returns typed
``ParsedInput``; it also exposes
``option()``, ``hasOption()``, ``values()``
and ``verbosity()``. Prefer those APIs to raw ``$argv``
parsing or a second CLI input parser.

Step 5 — decide what belongs in a command
-----------------------------------------

Use a command for bounded maintenance, deployment,
ad hoc reporting or one explicit task.

* For a quick report, run synchronously in the selected CLI runtime.
* For recurring tasks, register the command with the
  Foundation scheduler in ``routes/schedule.php``.
* For durable background work, select Omnibus
  ``execution.messaging`` and a real broker/worker profile.
* For a long-lived maintenance process, use the
  separate Foundation worker-provider contract.

Do not implement a bespoke ``pcntl_fork`` daemon
inside a command simply because it needs to run periodically.

Run the selected services lazily. A Core-only
command should not create a browser session or
a database pool when it only prints a status.

Step 6 — avoid unsafe CLI side effects
--------------------------------------

For commands that modify persistent state:

* Prefer a dry-run option and explicit confirmation policy.
* Check the intended environment, target and authorization.
* Keep output redacted: do not print passwords, token material
  or entire production config trees.
* Bound memory, time, retries and page/batch size.
* Use a shared lock when executing on multiple hosts.
* Design a safe interrupt/rollback path for partial work.
* Return nonzero on controlled failure so automation can detect it.

A schedule may retry a failed command, and a supervisor may restart it.
Make repeated executions idempotent or explicitly compensating.

Step 7 — cached metadata and trusted production graphs
------------------------------------------------------

The development CLI can cache command metadata:

.. code-block:: bash

   php infbyte command:cache
   php infbyte command:clear

This is not a production release. A trusted generation
compiles the CLI graph with the matching dependencies:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

Changing ``routes/console.php`` or a command class requires
an appropriate rebuild and activation. A running scheduler
will not necessarily observe a changed command until
its selected generation is replaced.

Testing the command
-------------------

A useful application command test checks:

1. Registered name appears in the actual CLI catalog.
2. Help/usage and required arguments are correct.
3. A dry-run does not mutate selected database state.
4. Success returns code zero, failure nonzero.
5. Missing permissions or an unavailable optional
   backend fail clearly.
6. Repeated execution does not duplicate side effects.
7. The compiled CLI graph resolves the required
   service and rejects invalid release identity.

When the command uses DBLayer, test actual transaction
rollback and resource cleanup in the selected CLI scope.
When it dispatches Omnibus, test both a recording sender
and the real durable transport behavior separately.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 35 37

   * - Symptom
     - Likely cause
     - First check
   * - ``reports:daily`` is unknown
     - Not registered in application registry
     - ``routes/console.php`` and ``list``
   * - Class cannot be loaded
     - Namespace/path/autoload mismatch
     - ``App\`` PSR-4 mapping
   * - Options ignored
     - Incorrect metadata or parser usage
     - ``define()`` and ``ParsedInput``
   * - Command works in a web request only
     - Wrong runtime-specific dependency
     - ``bootstrap/providers.php`` groups
   * - Production runs old code
     - Cached/compiled generation not updated
     - Command cache and trusted release
   * - Duplicate writes occur
     - Retry or overlap without coordination
     - Idempotency and native lock policy

Next: :doc:`scheduling`,
:doc:`workers`,
:doc:`../architecture/service-providers` and
:doc:`../testing/overview`.
