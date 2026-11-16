.. _database-testing:

Test Infbyte database behavior safely
=====================================

Use **native DBLayer integration tests** when an application depends on
real SQL, transactions, constraints, migrations or connection lifecycle.
Foundation's ``TestKit::database()`` selects a
``DatabaseTestManager`` only when the optional
``data.database`` feature is configured.

A new Core-only Infbyte candidate has neither an active DBLayer
feature nor ``config/database.php``. The database test examples
below need an **application-owned isolated test connection**.

Prepare an isolated test database
---------------------------------

From the **Infbyte project root**:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

The feature publishes ``config/database.php`` into **your
Infbyte application**, not into Foundation. Configure a separate
temporary/test connection with test-only credentials and
permissions. Never run ``refresh()`` or destructive
migration commands against the developer's shared schema or
production database.

TestKit's database helper
-------------------------

In a booted and appropriately configured test application:

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);
   $database = $testing->database();

   $database->transaction(static function (): void {
       // Perform DBLayer queries using the selected test application scope.
       // All work on the selected connection is rolled back at exit.
   });

``DatabaseTestManager::transaction()`` accepts a
**zero-argument callback** and an optional connection name.
It calls ``begin()``, then ``rollback()``
in ``finally`` so an exception still unwinds the
selected test connection's transaction levels.

The helper is **not** an in-memory database emulator.
Your selected DBLayer driver and schema must exist.
It also cannot undo an external SMTP send or work committed
on another independent connection.

Methods and safety contracts
----------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Test helper
     - Contract
   * - ``begin(?string $connection)``
     - Starts one selected DBLayer transaction.
   * - ``rollback(?string $connection)``
     - Rolls back remaining managed transaction levels.
   * - ``transaction(callable $test, ?string $connection)``
     - Runs callback and always rolls back afterwards.
   * - ``refresh(?string $connection)``
     - **Destructively** refreshes through the configured migration runner.
   * - ``TestKit::database()``
     - Resolves the manager from an enabled DBLayer integration.

For queries, inject the execution-owned native
``Infocyph\DBLayer\Connection\Connection``
in the application service being tested; do not register a
per-request mutable connection into a process-static facade merely
to make the test convenient.

Test a real query and rollback
------------------------------

Assuming the isolated test schema already contains
``accounts(id, email, active)``:

.. code-block:: php

   $database->transaction(static function () use ($app): void {
       $connection = $app->make(
           \Infocyph\DBLayer\Connection\Connection::class,
       );

       $connection->table('accounts')->insert([
           'email' => 'test@example.test',
           'active' => 1,
       ]);

       $rows = $connection->table('accounts')
           ->where('email', '=', 'test@example.test')
           ->get();

       if (count($rows) !== 1) {
           throw new \RuntimeException('Test insert was not visible.');
       }
   });

The code is a **contextual integration test excerpt**;
``$app`` must be a booted application with a valid
DBLayer execution scope, and the table must have been
created in that isolated schema. The rollback helper
controls the configured connection, so all work that
must be rolled back must use **that same connection**.

For a destructive schema rehearsal, create a fresh disposable
database and register the migration classes under
``config/database.php`` before invoking
``refresh()``. A migration passing under SQLite does not
prove its MySQL/PostgreSQL schema grammar is equivalent.

Negative-path tests
-------------------

Test an exception inside a transaction and verify no data
remains afterward. Exercise unique constraints, absent
relationships, invalid identifiers and unauthorized
tenant access. Then run another execution and verify
there is no leaked transaction, sticky-read flag,
timeout or stale connection lease.

For a write that queues a job or sends email, separately
verify the external side effect: rolling back DBLayer
cannot undo an independently delivered message.

Production confidence and retained data
---------------------------------------

A test on a disposable empty schema is not a retained-state
upgrade rehearsal. Before major releases, run migrations against
a representative **snapshot** of production-like data,
exercise old/new reader compatibility, and verify a coherent
rollback across code, SQL, messages and encryption state.

Keep the test database credentials and connection URI out of
CI logs and do not allow arbitrary test code to choose
the production connection.

Next: :doc:`../data/database`,
:doc:`../tutorials/database-migrations`,
:doc:`http` and
:doc:`../deployment/releases`.
