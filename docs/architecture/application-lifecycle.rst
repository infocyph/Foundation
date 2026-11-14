.. _app-lifecycle:

Application composition and lifecycle
=====================================

An Infbyte application is composed in stages, not re-created from scratch
for every operation. **Foundation** owns the application lifecycle and
selection policy; **InterMix** owns service definitions/DI; **Webrick**
owns HTTP routing and middleware; the selected native libraries own
their domain operations.

Your application starts at **``my-app/``**. The Foundation package
may provide templates and built-in services, but normal application
edits belong in ``my-app/config/``, ``my-app/app/``,
``my-app/routes/`` and ``my-app/bootstrap/``.

Four modes, one intentional choice
----------------------------------

Foundation exposes four explicit modes:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;

   $web = Foundation::web(['base_path' => $root]);
   $cli = Foundation::cli(['base_path' => $root]);
   $worker = Foundation::worker(['base_path' => $root]);
   $scheduler = Foundation::scheduler(['base_path' => $root]);

This is an **API illustration**, with ``$root`` supplied by your
application. You should **not** instantiate all four in one request.
The Infbyte ``bootstrap/app.php`` returns a web application:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;

   return Foundation::web([
       'base_path' => dirname(__DIR__),
   ]);

The ``infbyte`` executable delegates to
``CommandDispatcher::project()`` for CLI operations.
Workers and schedulers select their corresponding modes.
Do **not** infer mode solely from PHP SAPI: a worker and
scheduler commonly run under the CLI SAPI.

From settings to an executable application
------------------------------------------

A development/build operation works conceptually as follows:

.. code-block:: text

   Infbyte bootstrap/app.php + app configuration
            |
   Foundation defaults and selected application capabilities
            |
   Infbyte bootstrap/providers.php + built-in providers
            |
   InterMix native container and selected runtime graph
            |
   Webrick routes / commands / worker or schedule definitions
            |
   Booted application (development), or compiled verified generation

The actual ``bootstrap/providers.php`` file has groups
``common``, ``web``, ``cli``, ``worker`` and
``scheduler``. A provider that only registers a web-specific
service belongs under ``web``; place a service used in all modes
under ``common`` when its lifecycle is safe there.

Use :doc:`service-providers` for the full
``ServiceProvider`` / ``ContainerBuilder`` registration
pattern. Merely adding a class under ``app/`` does not
automatically register a provider or service.

Modules and capabilities are different decisions
------------------------------------------------

The Core-only Infbyte candidate ships
``config/modules.php`` with ``features=[]`` and reads
``APP_CAPABILITIES`` in ``config/app.php``.
A library's mere presence in ``vendor/`` does not make
its optional feature available to every runtime.

For example:

.. code-block:: bash

   php infbyte module:plan data.database
   php infbyte module:install data.database
   php infbyte module:enable data.database
   php infbyte module:doctor data.database

This selects an optional DBLayer integration and can publish
**``my-app/config/database.php``**. The application
must also allow the necessary runtime capability.
Avoid enabling every package or eagerly constructing
unneeded sessions, database connections and queues.

Execution scope and resource ownership
--------------------------------------

The web runtime enters/exits request scopes through Webrick and
InterMix only where needed. Non-web modes use
``foundation.cli``, ``foundation.worker`` and
``foundation.scheduler`` execution scopes.

A scoped database connection, current principal, browser
session or request-dependent HTTP client belongs to one
execution. An application singleton cannot safely retain
those values across users on a persistent host.

A correct workflow releases resources on **both** success and
failure. A cancelled job may not continue using stale
leases or acting as the active generation.

Build and activate production once
----------------------------------

A Foundation 4 production build compiles the selected
configuration, routes, DI services and mode artifacts
into an immutable release under **``my-app/storage/releases/``**.
It validates a manifest and source/dependency fingerprints,
then promotes a verified generation.

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

A process loading the generation must receive an
**independently trusted** manifest SHA-256. An
artifact mismatch must fail closed; do not rebuild
mutable PHP routes at request time or load a
partially written generation.

This is why development changes under ``routes/api.php``
or ``bootstrap/providers.php`` don't alter an already
activated production graph until a new generation
is built and promoted.

Troubleshoot by stage
---------------------

.. list-table::
   :header-rows: 1
   :widths: 28 36 36

   * - Symptom
     - Likely stage
     - Check
   * - Service cannot be resolved
     - Provider not selected/registered
     - ``bootstrap/providers.php`` and InterMix binding
   * - Route missing after deploy
     - Compiled route selection stale
     - ``config/router.php`` and active release
   * - Unnecessary service creation
     - Wrong provider lifetime or over-broad capability
     - Runtime graph and explicit dependencies
   * - Request state leaks
     - Process singleton retained scoped service
     - Scope exit / persistent-worker tests
   * - Production bootstrap rejects build
     - Manifest/vendor identity mismatch
     - External trust anchor and installed SHA

See :doc:`request-lifecycle`,
:doc:`../start/directory-structure`,
:doc:`../advanced/modules` and
:doc:`../deployment/releases`.
