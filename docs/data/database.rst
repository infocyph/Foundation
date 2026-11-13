.. _database-guide:

Databases and migrations
========================

Infbyte uses the native **DBLayer** connection, query, transaction and
schema APIs. Foundation 4 adds application connection selection,
migration/seed registration, CLI commands and runtime cleanup policy.

Select the database feature
---------------------------

In a compatible Foundation 4 application:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

This installs the selected DBLayer integration and publishes its
application configuration when needed. Do not assume that installing
a package automatically activates every database capability.

Work with connections
---------------------

Application services may type-hint
``Infocyph\DBLayer\Connection\Connection`` to obtain the selected
default connection. Use the native DBLayer query, transaction,
replica and schema APIs directly. Foundation does not provide
an Active Record ORM or a parallel database facade.

After the selected feature publishes it, edit **``config/database.php`` in
your Infbyte project root** for connection names, migration classes and seeders.
A fresh Core-only Infbyte 4 application does not yet have this file; Foundation's
``resources/config/database.php`` is framework template source only. Never
embed database credentials into source code.

Register and run migrations
---------------------------

Generate a migration starting point:

.. code-block:: bash

   php infbyte create:migration CreateAccounts
   php infbyte create:seeder Production

Then register the classes explicitly under
``database.migrations.classes`` and ``database.seeders``.
Foundation does not scan migration directories.

Useful commands include:

.. code-block:: bash

   php infbyte migrate:status
   php infbyte migrate --pretend
   php infbyte migrate
   php infbyte db:seed

The native DBLayer migration runner owns SQL generation and execution.
A destructive command such as ``migrate:fresh`` requires deliberate
authorization; do not run it against production merely to repair a
schema mismatch.

Core versus optional schemas
----------------------------

Core auth and browser session schemas have dedicated typed installers,
while an enabled optional feature can be provisioned through
``module:schema:sync``. CacheLayer database-backed schema helpers
remain a core capability:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install
   php infbyte module:schema:sync

Schema deployment belongs to a controlled build/deploy or CLI operation,
not the hot path of each HTTP request.

Read the `Foundation database guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/database.md>`_
and `DBLayer <https://github.com/infocyph/DBLayer>`_ for native APIs.

For a guided development-to-deployment sequence with migration manifests,
seeding, Core schemas and rollback, see :doc:`../tutorials/database-migrations`.
