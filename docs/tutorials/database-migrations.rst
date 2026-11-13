.. _tutorial-database-migrations:

Tutorial: migrate and seed a database
=====================================

Use this procedure when an Infbyte application needs durable SQL tables,
versioned schema changes and reproducible seed data. It separates
**Foundation's application registration** from **DBLayer's database
engine** and distinguishes optional feature schema from Core state.

These are **Foundation 4 development contracts**. The public Infbyte
application skeleton still depends on Foundation 3; run the commands
below only in a compatible Foundation 4 application.

Decide what you need
--------------------

Before installing a database feature, decide whether the application
uses SQL queries, needs Foundation's built-in authentication/browser
session tables, or uses an optional DBLayer-backed transport.

* For application queries, migrations and seeding, select
  ``data.database`` (DBLayer).
* Core auth and browser session schemas use separate typed installers,
  not arbitrary optional-module schema discovery.
* CacheLayer's own database-backed stores use Core cache schema
  commands, not a duplicate database module migration.
* An Omnibus durable database queue additionally requires
  ``execution.messaging`` and the matching Omnibus schema.

One application can use only the subset it needs. Avoid enabling all
packages just because a future feature might require them.

Step 1 — select and configure DBLayer
-------------------------------------

Inspect the explicit Foundation 4 module plan:

.. code-block:: bash

   php infbyte module:list
   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

Review **``config/database.php`` in the Infbyte application project** after
the selected DBLayer feature publishes it. The Core-only Infbyte candidate
does not ship this file; the Foundation package's internal
``resources/config/database.php`` is the template source. Inspect connection
names and the actual target database. Do not assume that
a local SQLite connection implies identical semantics to a
PostgreSQL or MySQL production connection.

Choose a connection by application configuration and use the native
``Infocyph\DBLayer\Connection\Connection`` DI type in services.
Foundation does not ship Active Record models or an Eloquent API.
DBLayer owns queries, transaction boundaries, schema grammar,
replica policy, pooling and telemetry.

Step 2 — create a migration class
---------------------------------

Generate a starting point with:

.. code-block:: bash

   php infbyte create:migration CreateInvoices

The CLI only creates application source. It does **not** scan the
directory and register every migration class.

The generated implementation should follow the native
``Infocyph\DBLayer\Migration\Migration`` contract and use DBLayer's
schema grammar. Inspect the generated class before changing it,
especially the required method signatures and supported SQL dialects.
Do not substitute Laravel-style schema helpers.

Step 3 — register migrations explicitly
---------------------------------------

The current Foundation 4 database configuration supports
``database.migrations.classes`` and
``database.seeders`` manifests. This standalone PHP example
illustrates the *relevant subset* of ``config/database.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'default' => 'sqlite',
       'migrations' => [
           'classes' => [
               App\Database\Migration\CreateInvoicesMigration::class,
           ],
           'table' => 'migrations',
           'lock_store' => null,
           'lock_wait_seconds' => 10.0,
           'lock_lease_seconds' => 300.0,
       ],
       'seeders' => [
           App\Database\Seeder\ProductionSeeder::class,
       ],
   ];

The migration and seeder classes are application-defined, not built
into Foundation. Your actual config must also contain suitable
connection credentials and driver settings; do not replace the
entire generated config with this abbreviated example.

Use fixed class names for production manifests. Runtime directory
scanning would make the generated release depend on mutable source
layout and would hide which migrations are truly deployable.

Step 4 — inspect before applying
--------------------------------

Use the selected real connection and inspect the pending migration:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte migrate:status
   php infbyte migrate --pretend
   php infbyte migrate --connection=reporting --pretend

The last command only applies if your application has a connection
named ``reporting``. DBLayer's native migration runner owns
the preview SQL and bindings; Foundation only renders the result.

Check indexes, nullability, unique constraints, data preservation,
lock requirements and SQL-dialect-specific behavior before promotion.
A successful pretend run is **not** proof that a migration will fit
production lock budgets or retain all existing data.

Step 5 — apply a controlled migration
-------------------------------------

For a non-destructive, reviewed migration:

.. code-block:: bash

   php infbyte migrate
   php infbyte migrate:status

When a configured migration lock store is present, Foundation selects
CacheLayer coordination while the underlying DBLayer migration runner
performs migration checkpoints. For a multi-instance deployment,
a process-local lock is insufficient to serialize migrations across hosts.

Do not run ``migrate:reset``, ``migrate:refresh`` or
``migrate:fresh`` as an ordinary deployment remedy. They are
destructive and may require ``--force`` even when non-interactive.
Use a rehearsed data migration and rollback plan instead.

Step 6 — seed intentionally
---------------------------

Generate a seeder and register its class in the explicit
``database.seeders`` manifest:

.. code-block:: bash

   php infbyte create:seeder Production
   php infbyte db:seed
   php infbyte db:seed --connection=reporting

``db:seed`` is transactional by default in the documented
Foundation 4 command contract; the explicit
``--no-transaction`` option disables this behavior. Keep seeders
repeat-safe where deployment or test replays can invoke them twice.

Do not seed real customer identities or authentication secrets from
a generic example. Treat production seed data as a controlled input
with change history and appropriate access restrictions.

Step 7 — provision Core and optional schemas
--------------------------------------------

The application may separately need Core session/auth schema and
optional module schemas.

**Core:** install/readiness methods for auth/browser-session state
belong to typed Foundation services; they execute through the
selected CLI execution scope. CacheLayer database store schemas
are managed through:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install

**Optional:** installed and enabled modules expose their selected
schema policy through:

.. code-block:: bash

   php infbyte module:schema:status data.database
   php infbyte module:schema:sync

Not every feature has an applicable schema. Always inspect the
feature's actual schema/readiness contract rather than expecting
every module to create tables.

Step 8 — verify retained state and rollback
-------------------------------------------

For a breaking release, capture the exact source revision,
Composer-installed dependency graph, migration IDs, database
schema and a recoverable snapshot of all selected durable stores.

Prefer **expand/contract** migrations when old and new web/worker
generations may coexist: add compatible structures first, update
writers and readers, then remove old structures only when all
old processes have drained.

A code-only rollback cannot guarantee that an older binary can read
new rows, message payloads, credential records or encryption key
formats. A credible rollback plan covers both application code
and associated retained data.

In production verification, exercise representative queries with
real configured connections, simulate a failed migration, confirm
transaction recovery and ensure persistent-worker request/job scopes
do not reuse an open transaction from prior work.

Troubleshooting map
-------------------

.. list-table::
   :header-rows: 1
   :widths: 29 34 37

   * - Symptom
     - Likely cause
     - First safe check
   * - Unknown migration class
     - Missing Composer autoload or manifest entry
     - Check class name and ``database.migrations.classes``
   * - Migration never runs
     - Wrong connection or manifest not rebuilt
     - Inspect status, application config and release SHA
   * - Duplicate migration across hosts
     - No shared lock or overlapping deployment job
     - Check configured CacheLayer coordination
   * - Session table missing
     - Core schema not provisioned
     - Use typed SessionDatabaseSchema readiness
   * - Schema exists but app still fails
     - Different connection or package/schema mismatch
     - Verify deployed vendor graph and target DB
   * - Rollback fails after new data writes
     - Incompatible retained state
     - Stop writers and restore coherent code/data point

Related documentation
---------------------

Read :doc:`../data/database`, :doc:`../data/cache`,
:doc:`../services/queues-workers` and the
:doc:`../data/database` application integration guide.
For queries, schema builders and transactions, use
`DBLayer's own documentation <https://github.com/infocyph/DBLayer>`_.
