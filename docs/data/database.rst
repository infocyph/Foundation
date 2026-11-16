.. _database-guide:

Database connections, migrations and queries
============================================

Infbyte provides application configuration and a CLI for
the optional **DBLayer** integration. DBLayer itself owns
SQL connections, query builders, transactions, schema
operations, read replicas and migration execution.

**Where you configure it:** ``my-app/config/database.php``
after selecting ``data.database``. A fresh Core-only
Infbyte 4 candidate does **not** ship this file.

When a database is appropriate
------------------------------

Select DBLayer when your application needs
durable relational data, SQL transactions,
migration history or a database-backed
selected integration.

A fixed JSON health route doesn't need DBLayer
merely because the framework supports SQL.

Step 1 — activate the feature
-----------------------------

From the Infbyte application project root:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

``module:install`` selects the package and
can publish its configuration. ``module:enable``
records explicit activation; the application
capability topology must also be consistent.

The result is **``my-app/config/database.php``**,
not an editable file under Foundation's
``resources/config/`` directory.

Step 2 — choose a connection
----------------------------

Open the published application ``config/database.php``.
It defines:

* ``default`` — the connection name for operations
  that don't specify one.
* ``connections`` — named driver/server descriptors.
* ``pool`` — optional persistent-host pooling policy.
* ``query_cache`` — optional native DBLayer query caching.
* ``migrations.classes`` — explicit migration class list.
* ``seeders`` — explicit seed class list.

The current template supports connection names for
SQLite, MySQL, MariaDB, PostgreSQL and MSSQL,
subject to installed PDO drivers and actual
server/version capabilities.

The default connection is selected from
``DB_CONNECTION``, falling back to ``sqlite``
in the framework template. Don't assume that
a default SQLite test validates SQL syntax,
collation and locking on a PostgreSQL/MySQL host.

Connection credentials should be injected through
trusted process/deployment configuration, not
hard-coded into a committed PHP source file.

Step 3 — work through the native connection
-------------------------------------------

In a composed application service:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;

   $db = $app->make(Connection::class);

``Connection`` is a **native DBLayer type**, not
an Eloquent Active Record model. Use the owner's
query builder, execution, transaction and schema
contracts directly; Foundation does not invent
a replacement ``Model`` or ``DB`` facade.

The application should define its own repositories
or services around business operations. Keep SQL
bound parameters separate from untrusted input,
and validate/authorize before mutations.

Step 4 — create and register migrations
---------------------------------------

Generate a starting class:

.. code-block:: bash

   php infbyte create:migration CreateInvoices
   php infbyte create:seeder Production

Generators do not implicitly register source files.
After reviewing the generated class and its
native DBLayer ``Migration`` contract, add the
application class to the explicit manifest in
**``my-app/config/database.php``**:

.. code-block:: php

   'migrations' => [
       'classes' => [
           App\Database\Migration\CreateInvoicesMigration::class,
       ],
   ],

This is a configuration **fragment**: the class
must actually exist, and the complete application
config should not be replaced with this excerpt.

The source location for a generated migration is
the path shown by the actual CLI generator.
Do not assume a Laravel ``database/migrations``
directory is scanned or that all migrations are
auto-discovered.

Step 5 — inspect and apply
--------------------------

Before changing schema:

.. code-block:: bash

   php infbyte migrate:status
   php infbyte migrate --pretend

The preview is useful for checking native SQL
and intended connection. It does not prove
production lock budgets or data compatibility.

After approval and a recoverable snapshot:

.. code-block:: bash

   php infbyte migrate
   php infbyte migrate:status
   php infbyte db:seed

``migrate:rollback`` supports explicit batches
when part of a rehearsed rollback procedure.
Destructive ``migrate:fresh``, ``migrate:reset``,
``migrate:refresh`` and ``db:wipe`` are **not**
generic production repair commands. Their
``--force`` requirement does not make them safe.

Step 6 — inspect actual database state
--------------------------------------

The Infbyte CLI provides the Foundation 4
application integration for DBLayer inspection:

.. code-block:: bash

   php infbyte db:show
   php infbyte db:table invoices
   php infbyte db:monitor
   php infbyte db:monitor --section=queries --seconds=10

Only run against an actual configured eligible
connection. Monitoring/inspection output can
contain sensitive schema or operational metadata;
use appropriate credentials and log redaction.

Step 7 — separate Core and optional schemas
-------------------------------------------

CacheLayer is Core and has its own database-cache
schema commands:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install

Optional enabled modules expose appropriate
schema lifecycle through the module CLI:

.. code-block:: bash

   php infbyte module:schema:status data.database
   php infbyte module:schema:sync

Core authentication and browser-session state
have typed schema owners; don't assume every
table is created by DBLayer's application
migration manifest.

Step 8 — use transactions responsibly
--------------------------------------

DBLayer owns actual SQL transaction semantics.
A transaction should hold a DB connection only
for the bounded unit of work. Don't issue
arbitrary network requests or long upload
scans while retaining row locks.

For persistent workers, ensure each operation
returns its connection/transaction state to
the selected execution scope, even when a
handler throws or is cancelled. A transaction
from one user must not remain visible to
the next request or queue message.

Database pooling is **opt-in** through the
selected application's ``database.pool`` policy.
Enable it only after representative throughput,
connection-limit and cleanup qualification.

Step 9 — verify deployment and rollback
---------------------------------------

For a schema-changing release:

1. Record the exact Infbyte/Foundation/DBLayer
   installed source and dependency graph.
2. Snapshot the actual selected SQL database.
3. Inspect native migration output and plan
   concurrent worker compatibility.
4. Prefer expand/contract changes where old
   and new worker generations overlap.
5. Apply migrations outside HTTP hot paths.
6. Deploy a new verified Foundation generation.
7. Exercise representative reads/writes and
   check migration and worker statuses.
8. Test recovery of retained SQL and message
   data, not merely a code pointer rollback.

If an older deployment cannot read newer rows,
reverting its PHP source alone is not a complete
rollback.

Testing checklist
-----------------

* Migration creates the intended schema on each
  supported SQL dialect used by your app.
* Re-running registered migrations is repeat-safe.
* A failed transaction rolls back correctly.
* Seeders do not duplicate unique production state.
* A wrong connection name fails with a useful error.
* Unauthorized user IDs cannot access another
  tenant's records.
* Connection/pool leases do not leak after exceptions
  in a persistent Runwire host.
* Prepared statement/parameter binding rules
  prevent untrusted SQL construction.

For a complete step-by-step migration example,
see :doc:`../tutorials/database-migrations`.
For native query/transaction APIs, consult DBLayer:

https://github.com/infocyph/DBLayer

See also :doc:`../reference/configuration`,
:doc:`cache` and
:doc:`../testing/database`.
