.. _directory-structure:

Understanding the Infbyte application layout
============================================

An Infbyte application is the **project you work inside**. This
chapter describes the actual ``feature/infbyte-4`` candidate
layout, not the internal layout of ``infocyph/foundation``.
The Foundation source lives separately under Composer's
``vendor/infocyph/foundation/`` in a resolved project.

The public Infbyte ``main`` branch is still Foundation 3.
The Foundation 4 skeleton is a development candidate until
released; see :doc:`foundation4-candidate`.

Project tree
------------

An Infbyte 4 candidate starts with this **application-owned**
structure (simplified; generated caches and dependency files
are not listed exhaustively):

.. code-block:: text

   my-app/
   ├── app/
   │   └── Http/
   │       └── Controllers/
   │           └── SystemController.php
   ├── bootstrap/
   │   ├── app.php
   │   ├── providers.php
   │   └── cache/                 # generated development metadata
   ├── config/
   │   ├── app.php
   │   ├── auth.php
   │   ├── cache.php
   │   ├── modules.php
   │   ├── notifications.php
   │   └── router.php
   ├── public/
   │   └── index.php
   ├── routes/
   │   ├── api.php
   │   ├── console.php
   │   ├── schedule.php
   │   └── workers.php
   ├── storage/
   │   ├── cache/                 # application runtime data
   │   ├── logs/
   │   ├── releases/              # generated trusted generations
   │   ├── sessions/
   │   └── uploads/
   ├── tests/
   │   ├── Feature/
   │   └── Unit/
   ├── .env.example
   ├── composer.json
   ├── deploy.sh
   └── infbyte

Some ``storage/`` subdirectories are created during application
installation or deployment. Their absence in a clean checkout
is not evidence of a malformed skeleton. Do not commit actual
session state, generated releases, secrets or dependencies.

Where to put new application code
---------------------------------

.. list-table::
   :header-rows: 1
   :widths: 36 31 33

   * - You want to...
     - Location in **your Infbyte project**
     - Who owns behavior
   * - Implement an HTTP controller
     - ``app/Http/Controllers/``
     - Your application; Webrick executes the route
   * - Register an HTTP endpoint
     - ``routes/api.php`` or another explicitly selected route file
     - Infbyte route declaration; Webrick router
   * - Change application identity/capabilities
     - ``config/app.php``
     - Infbyte policy; Foundation composition
   * - Activate an optional module
     - ``config/modules.php``
     - Foundation module selector
   * - Configure a database connection
     - ``config/database.php`` **after** choosing DBLayer
     - DBLayer integration, not Core-only default
   * - Configure mail delivery
     - ``config/notifications.php``
     - Application policy; TalkingBytes transport
   * - Register a console command
     - ``routes/console.php``
     - Foundation CLI runtime
   * - Register a scheduled command
     - ``routes/schedule.php``
     - Foundation scheduler
   * - Register a maintenance worker
     - ``routes/workers.php``
     - Foundation worker routing; distinct from Omnibus queues
   * - Add a service provider
     - Your class under ``app/``, then ``bootstrap/providers.php``
     - Application author; InterMix service graph
   * - Write tests
     - ``tests/Feature/`` or ``tests/Unit/``
     - Pest/selected test tools

Do **not** create a top-level ``resources/config/`` inside an
Infbyte app to copy Foundation examples. The framework's
``resources/config/`` directory is its **template source**.
When a selected feature publishes configuration, edit the resulting
file in **``my-app/config/``**.

Bootstrapping and entrypoints
-----------------------------

The candidate's ``bootstrap/app.php`` explicitly creates the
web application using the Foundation factory:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;

   return Foundation::web([
       'base_path' => dirname(__DIR__),
   ]);

``bootstrap/providers.php`` declares ``common``, ``web``,
``cli``, ``worker`` and ``scheduler`` provider groups.
A provider is application-owned; only groups required by
the selected runtime are composed. Do not register every
service eagerly just because it is available in Composer.

``public/index.php`` is the **production HTTP entrypoint**.
It requires a trusted immutable Foundation release and delegates
execution to Webrick. It is not a duplicate HTTP parser,
router or response emitter.

``infbyte`` at the project root is the application command
entrypoint. It forwards to Foundation's ``CommandDispatcher``,
rather than implementing a parallel console framework.

HTTP routes are explicit
------------------------

``config/router.php`` selects ``api.php`` by default,
and the actual registered application route is:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

The matching controller belongs in
``app/Http/Controllers/SystemController.php``.
Creating another PHP controller class **does not register
its route automatically**. Use native Webrick route
registration and check:

.. code-block:: bash

   php infbyte route:list

See :doc:`../http/routing` and
:doc:`first-application` for route execution.

Feature-owned configuration
---------------------------

An Infbyte 4 candidate intentionally starts **Core-only**.
``config/modules.php`` has ``features: []``. Do not expect
``config/database.php``, ``config/filesystem.php``,
``config/messaging.php`` or ``config/communication.php``
to exist until those integrations are selected and their
configuration is published.

For example:

.. code-block:: bash

   php infbyte module:plan data.filesystem
   php infbyte module:install data.filesystem
   php infbyte module:enable data.filesystem
   php infbyte module:doctor data.filesystem

After appropriate publication, the Pathwise application's disk
configuration belongs at ``config/filesystem.php``.

**A caution about packages:** Installing ``infocyph/pathwise``
alone does not declare an enabled Foundation filesystem feature.
The package, selected feature, application policy and native
backend must agree.

Generated and persistent directories
------------------------------------

``bootstrap/cache/`` holds development/build cache products,
including ArrayKit config and command metadata. These are not
committed application source files.

``storage/releases/`` holds **generated trusted release
generations**. Each generation includes selected web, CLI,
worker and scheduler artifacts with a verified manifest
identity. Never edit those artifacts manually or teach a
developer to author their own PHP file inside a generated
generation directory.

``storage/cache/``, ``storage/sessions/``, ``storage/logs/``
and ``storage/uploads/`` hold selected runtime state.
Their contents depend on the actual cache/session/storage
drivers in use; a distributed production deployment should
not accidentally rely on a local worker file as shared truth.

Ordinary PHP/FPM hosting serves the app from ``public/``.
Don't point the web server document root to ``my-app/``,
``storage/``, ``config/`` or ``vendor/``.

Custom application extensions
-----------------------------

The application autoload root ``App\`` maps to ``app/``.
You may organize services, commands, jobs or providers there
when your application needs them. Those directories are
**not created or scanned automatically** just because
they appear in a guide.

Code-generation commands create starting files but still
require explicit registration in the appropriate route,
service provider or module configuration.

Application roots and package roots
-----------------------------------

.. list-table::
   :header-rows: 1
   :widths: 35 33 32

   * - Example
     - Correct use
     - Incorrect assumption
   * - ``my-app/config/database.php``
     - Developer-controlled DBLayer connection config
     - Exists in every new Core-only app
   * - ``my-app/routes/api.php``
     - Selected Webrick registration source
     - Every file under ``routes/`` is scanned
   * - ``my-app/bootstrap/providers.php``
     - Application provider declarations
     - Defines Foundation's internal providers
   * - ``my-app/storage/releases/``
     - Generated immutable release artifacts
     - A directory for handwritten runtime PHP code
   * - ``vendor/infocyph/foundation/resources/config/``
     - Framework-provided template source (read-only reference)
     - Where the application keeps its live config

Next steps
----------

For a detailed task-oriented configuration procedure, see
:doc:`configuration`. For optional feature installation,
use :doc:`../tutorials/choose-capabilities`.
For production release ownership and safe rollback,
see :doc:`../tutorials/release-and-rollback`.
