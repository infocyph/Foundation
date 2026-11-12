.. _application-commands:

Creating application commands
=============================

Use application commands for bounded tasks that can be invoked
by a developer, deployment job, scheduler or another process.
Foundation owns command definitions, CLI parsing, IO and execution
policy. Command registration is explicit, which makes the production
command graph deterministic.

1. Generate a command class
---------------------------

In a compatible Foundation 4 application:

.. code-block:: bash

   php infbyte create:command Reports/Daily

This creates a starting point; it does **not** register it for you.
Inspect and adapt the generated file rather than assuming the generator
guesses a command name or business behavior.

2. Implement the actual command contract
----------------------------------------

Application handlers implement
``Infocyph\Foundation\Command\CommandHandlerInterface``.
The interface requires a static ``define(CommandDefinition $command)``
and instance ``run(CommandContext $context): int``.

This complete PHP example is maintained in the docs repository and
syntax-checked in CI:

.. literalinclude:: ../examples/infbyte-report-command.php.txt
   :language: php
   :linenos:

``define()`` contributes **metadata**, including the CLI name;
``run()`` performs the requested operation. Here it only writes a
success message, so the example has no database or queue dependency.

The ``CommandContext`` supplies typed input, IO and an execution ID.
Returning ``0`` indicates success. Return an appropriate non-zero
status on a controlled failure, rather than swallowing exceptions
and always signalling success.

3. Register it in the application
---------------------------------

Inside ``routes/console.php``, the application returns a class list
or an explicit name-to-class map. For the example above:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       \App\Command\Reports\DailyCommand::class,
   ];

The **actual filename and PSR-4 mapping** must match the application's
class layout. The source scanner does not register all files in
``app/Command``. Application commands may not override system
commands with the same name.

Inspect registration before running it:

.. code-block:: bash

   php infbyte list
   php infbyte help reports:daily
   php infbyte reports:daily

4. Inputs, options and execution policy
---------------------------------------

The ``CommandDefinition`` API supports typed metadata for arguments,
flags, options, runtime selection and execution policy. ``ParsedInput``
exposes ``argument()``, ``option()``, ``flag()`` and
``values()``. It is preferable to parse with these contracts rather
than reading ``$argv`` or applying custom global option conventions.

For example, a command can register a required user identifier using
``$command->argument('account', required: true)``; its handler reads
``$context->input()->argument(0)``. An optional flag can be
declared with ``$command->option('dry-run')`` and read through
``$context->input()->flag('dry-run')``.

For potentially destructive commands, implement a dry-run path and
treat ``-n``/non-interactive mode deliberately. Never print secrets
through command output, including JSON and verbose diagnostics.

5. Performance and lifecycle
----------------------------

A command should load only the selected services it uses. Foundation
supports a separate CLI graph: no default web request objects or
route middleware are needed to send a report or inspect a database.

For long operations requiring process supervision, timeouts or locks,
use the existing command execution policy. Do not reimplement a
``pcntl_fork`` supervisor or create a second Foundation process manager.
Overlap locks use CacheLayer only when explicitly configured.

Development command metadata may be cached:

.. code-block:: bash

   php infbyte command:cache
   php infbyte command:clear

If command definitions change, rebuild the appropriate artifacts and
the production release before switching live processes.

Next: :doc:`scheduling` for recurring execution.
