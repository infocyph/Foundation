.. _database-testing:

Database integration tests
==========================

Use native DBLayer integration tests when code depends on real queries,
transactions, schema grammar, constraints or retained data. Foundation
adds an application-level ``DatabaseTestManager`` through
``TestKit::database()`` when the ``data.database`` feature
is installed/configured.

Use an isolated connection
--------------------------

Never run database tests against a production database or a
shared developer schema. Configure a dedicated test connection,
preferably short-lived, with permissions limited to test data.

In an existing Foundation test runtime:

.. code-block:: php

   $database = $testing->database();

   $database->transaction(function (): void {
       // Use configured DBLayer services in this test scope.
   });

The method starts a DBLayer transaction on the selected connection
and rolls it back in ``finally``, even if the callback throws.
This is a contextual example: ``$testing`` must have been resolved
from a configured application and the test setup supplies the
appropriate DBLayer connection.

Available helper operations
---------------------------

``DatabaseTestManager`` exposes:

* ``begin(?string $connection)`` to start a transaction;
* ``rollback(?string $connection)`` to unwind open transaction levels;
* ``transaction(callable $test, ?string $connection)`` for scoped
  test work with automatic rollback;
* ``refresh(?string $connection)`` to run the configured migration
  manager's destructive refresh operation.

Do not use ``refresh()`` on a shared or production connection.
The helper delegates to the actual DBLayer migration runner; it is
not a schema emulator.

When transaction rollback is not enough
---------------------------------------

Transactions cannot roll back effects made through independent
connections, external message transports, email, filesystem storage
or other committed processes. For these tests, isolate and clean
the corresponding resources deliberately, including when the test
fails early.

An integration test should cover a failing transaction, verify the
post-failure database state, and ensure the next execution can
proceed without inheriting an open transaction.

Production-schema confidence
----------------------------

Running test migrations proves only the tested schema/driver.
For upgrades, separately rehearse the original persisted data,
the exact DBLayer version, schema and the new Foundation generation.
A code rollback alone does not restore the prior data format.

Read :doc:`../data/database` and
`Foundation retained-data rehearsal
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-retained-data-rehearsal.md>`_.
