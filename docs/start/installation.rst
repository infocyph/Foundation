.. _installation:

Installation and first application check
========================================

Use **PHP 8.4 or newer**, **Composer 2** and the extensions required by your
selected capabilities. This manual covers Infbyte applications using Foundation 4.

Create an application
---------------------

After the matching Infbyte 4 starter is published, create a project with:

.. code-block:: bash

   composer create-project infocyph/infbyte my-app '^4.0'
   cd my-app
   php infbyte --version

Before that publication, use the compatible development starter instead:

.. code-block:: bash

   git clone --branch feature/infbyte-4 --single-branch https://github.com/infocyph/Infbyte.git my-app
   cd my-app
   composer update --no-interaction --prefer-dist
   php infbyte --version

The development branch is not a stable release. Record its resolved Foundation
source identity when reporting issues. Do not deploy an unresolved candidate as
if it were a published stable package.

The Foundation library is separately available as ``infocyph/foundation``;
requiring it alone does not create the Infbyte application directory structure.

Initialize application state safely
-----------------------------------

From **``my-app/``**, after Composer has installed dependencies:

.. code-block:: bash

   php infbyte app:install
   php infbyte config:validate
   php infbyte app:ready
   php infbyte route:list

``app:install`` may create local application directories or
secrets. Review its output, protect secret material and exclude local
credentials from Git. Application readiness depends on the capabilities
and environment you selected; a development readiness result is not a
production trust or host-performance certification.

Check the real files
--------------------

The Core-only starter includes:

.. list-table::
   :header-rows: 1
   :widths: 39 61

   * - **Infbyte path**
     - Why it matters
   * - ``bootstrap/app.php``
     - Selects ``Foundation::web`` with this project's base path.
   * - ``bootstrap/providers.php``
     - Groups application providers as common/web/cli/worker/scheduler.
   * - ``config/app.php``
     - Application identity, environment, capabilities and development cache.
   * - ``config/router.php``
     - Lists the actual route sources (``api.php`` by default).
   * - ``routes/api.php``
     - Registers ``GET /api/health`` and ``GET /json`` with Webrick.
   * - ``public/index.php``
     - Production web entrypoint requiring a trusted Foundation generation.
   * - ``infbyte``
     - Executable selecting Foundation's command dispatcher.

Optional configuration such as ``config/database.php``,
``config/filesystem.php``, ``config/messaging.php`` or
``config/communication.php`` is **not shipped** in this Core-only
starter. Selecting the relevant module can publish it into **your
Infbyte project**.

Run a low-cost route/host check
-------------------------------

Inspect the native route table and the selected development host:

.. code-block:: bash

   php infbyte route:list
   php infbyte serve --dry-run --json=1

A dry run does **not** bind an HTTP port. When the machine has a
supported eligible development driver, start the actual listener:

.. code-block:: bash

   php infbyte serve

In another terminal, request the bound host/port reported by the server.
For a listener explicitly configured on 127.0.0.1:8080:

.. code-block:: bash

   curl -i http://127.0.0.1:8080/api/health

Expect a JSON object containing ``"status":"ok"``. The port is
illustrative. The matching ``SystemController`` class is an
**Infbyte application class**, under ``app/Http/Controllers/``.

If something fails
------------------

.. list-table::
   :header-rows: 1
   :widths: 30 35 35

   * - Problem
     - Typical reason
     - Safe first check
   * - CLI cannot find autoload
     - Composer install incomplete
     - Run ``composer update`` in the actual Infbyte root
   * - Missing optional config file
     - Feature not installed/published
     - ``php infbyte module:plan <qualified-feature>``
   * - Route returns 404
     - Wrong method or route file selection
     - ``config/router.php``, ``route:list``
   * - Native server cannot start
     - Driver/extension unavailable
     - ``serve --dry-run --json=1``
   * - Runtime/library methods differ
     - Installed version differs from the guide
     - Record Composer installed ref and matching SHA
   * - PHP/FPM rejects production bootstrap
     - No independently trusted generated release
     - ``optimize:report`` and trusted manifest identity

A production deployment is **not** simply ``php infbyte serve`` in
a background shell. It requires a verified generation, selected host,
process supervisor, real dependencies and an independent trust anchor.
See :doc:`../deployment/hosting` and
:doc:`../deployment/releases`.

Next: :doc:`directory-structure`,
:doc:`configuration`,
:doc:`development-server` and :doc:`first-application`.
