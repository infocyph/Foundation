.. _first-application:

Build your first Infbyte API application
========================================

This walkthrough uses the **actual Infbyte 4 candidate** as the
starting point. It develops a small JSON endpoint, explains
where each file belongs, tests it through the real Foundation
HTTP integration and describes what changes for production.

**Current status:** Foundation 4 and Infbyte 4 are development
candidates, not published stable releases. The public Infbyte
``main`` starter uses Foundation 3. Use
:doc:`foundation4-candidate` for compatibility notes.

Before you begin
----------------

You need PHP 8.4+ on a 64-bit platform, Composer 2, and a
checkout of the candidate application:

.. code-block:: bash

   git clone --branch feature/infbyte-4 --single-branch https://github.com/infocyph/Infbyte.git my-app
   cd my-app
   composer update --no-interaction --prefer-dist
   php infbyte app:install
   php infbyte module:list

Why the Git checkout? The current published
``composer create-project infocyph/infbyte`` command still
installs the Foundation 3 application. The development
branch currently follows a moving Foundation branch;
record the installed commit before diagnosing a regression.

Infbyte initially selects no optional DBLayer, validation
or queue feature. A small health endpoint remains possible
without any of them.

Step 1 — inspect the existing route
------------------------------------

Open **``my-app/routes/api.php``**. The candidate
already registers a Webrick route:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));
   $router->get('/json', SystemController::json(...), 'json');

No Laravel-style ``Route::`` facade is necessary in this
application route file. Webrick supplies the native
``Registrar`` handed to the selected route source.

Open **``my-app/config/router.php``**. Its ``files``
list contains ``api.php``, so that route file is part
of the configured application. Adding another PHP file
under ``routes/`` does not make it active automatically.

Step 2 — inspect the application controller
--------------------------------------------

Open **``my-app/app/Http/Controllers/SystemController.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Controllers;

   final readonly class SystemController
   {
       public static function health(): array
       {
           return ['status' => 'ok'];
       }

       public static function json(): array
       {
           return ['memory' => memory_get_usage(true)];
       }
   }

This is an application class. It doesn't extend a Foundation
controller base class and doesn't implement a database model.

Webrick accepts the handler's array result and emits
the appropriate response through its native execution layer.
The simple route does not need session, authentication,
database, container-heavy work or input validation.

Step 3 — add your own endpoint
-------------------------------

Extend the same application controller with a simple
method (shown here as the new method only):

.. code-block:: php

   public static function welcome(): array
   {
       return [
           'message' => 'Welcome to Infbyte',
           'framework' => 'Infbyte',
       ];
   }

Then register **one more route** in
``my-app/routes/api.php``:

.. code-block:: php

   $router->get('/api/welcome', SystemController::welcome(...), 'welcome');

The string ``welcome`` gives the route a name.
This is a source-level example for a method you add:
the default candidate does **not** already ship
``/api/welcome``.

Run the route inspector:

.. code-block:: bash

   php infbyte route:list

If the route is missing, verify that the modified file is
still selected by ``config/router.php`` and that the
installed Foundation source is compatible.

Step 4 — start a suitable development host
-------------------------------------------

Inspect host selection without opening a listener:

.. code-block:: bash

   php infbyte serve --dry-run --json=1

For the selected available Runwire driver, start the
development server:

.. code-block:: bash

   php infbyte serve

The precise port and selected driver come from the
current CLI/host configuration; do not assume that
all PHP installations have the same native extensions.
You can use ``--host`` and ``--port`` options where
supported. Check ``php infbyte help serve``.

On another shell, request your endpoint with curl
using the host and port shown by the running server:

.. code-block:: bash

   curl -i http://127.0.0.1:8000/api/health
   curl -i http://127.0.0.1:8000/api/welcome

The built-in health route returns JSON equivalent to
``{"status":"ok"}``. Your new route returns a
``message`` and ``framework`` field. The server address
shown here is illustrative: use the actual listener output.

Step 5 — test the same route without a network listener
--------------------------------------------------------

Infbyte owns the ``tests/`` directory, while Foundation's
TestKit uses native Webrick request/response behavior.

Create **``my-app/tests/Feature/WelcomeEndpointTest.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;
   use Infocyph\Foundation\Testing\TestKit;

   it('serves the welcome endpoint', function (): void {
       $app = Foundation::web([
           'base_path' => dirname(__DIR__, 2),
           '_config_cache' => false,
           'app' => [
               'env' => 'testing',
               'capabilities' => [],
           ],
       ])->boot();

       $testing = $app->make(TestKit::class);

       $testing->http()->get('/api/welcome')
           ->assertStatus(200)
           ->assertJson([
               'message' => 'Welcome to Infbyte',
               'framework' => 'Infbyte',
           ]);
   });

This test assumes you actually added ``welcome()``
and registered ``/api/welcome`` as shown above.
It does not claim the route ships in Infbyte by default.
The existing candidate CI already exercises
``/api/health`` using the same TestKit style.

The HTTP test uses the in-process web graph:
it validates route dispatch, middleware selection
and response behavior, **not** FPM/TLS/proxy behavior.
For production host verification, use the separate
native-host smoke suite.

Step 6 — verify errors, not only success
----------------------------------------

A helpful API test suite checks:

* ``GET /api/health`` succeeds without optional services.
* ``GET /api/welcome`` returns exactly the intended fields.
* An unregistered route produces the configured 404 result.
* A disallowed HTTP method produces the configured 405 result.
* A route requiring input rejects missing or malformed input
  before mutation (when you add such a route).
* A failed request cannot leave principal or transaction
  state available to the next request in the same process.

This example has no authorization requirement. If you later
add user-specific data, authenticate and authorize the
principal before reading or modifying that user's resource.

Step 7 — understand the request lifecycle
------------------------------------------

For the two simple endpoints:

.. code-block:: text

   HTTP host
     -> Foundation's selected Web application
     -> compiled Webrick router (routes/api.php at build time)
     -> only selected middleware
     -> App\Http\Controllers\SystemController
     -> Webrick response mapping and emission

Foundation composes the application and runtime lifecycle.
Webrick owns HTTP routing, request parsing and response
semantics. InterMix owns service resolution. Runwire owns
native host lifecycle where the selected driver needs it.

There is no benefit in opening a database connection
for a fixed two-field health JSON response.

Step 8 — build an immutable candidate release
----------------------------------------------

For deployment planning, validate the selected
application before creating a Foundation generation:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

The source of routes is **``my-app/routes/api.php``**;
the production router uses compiled artifacts stored under
``my-app/storage/releases/``. Those files are
**generated** and should never be edited manually.

The deployment service must provide a trusted manifest
digest independently from the writable release directory.
``public/index.php`` validates it before handling
requests under ordinary PHP/FPM. Do not bypass the
trust check merely because a development route works.

When to use a different approach
--------------------------------

* If your handler needs client data, accept Webrick
  ``Request`` and validate at the boundary; see
  :doc:`../tutorials/validate-an-api-request`.
* If the response is an application representation,
  consider Foundation ``JsonResource``; see
  :doc:`../tutorials/json-api-responses`.
* If work should outlive the request, explicitly
  select Omnibus and a real transport; see
  :doc:`../tutorials/durable-queue`.
* If you need durable SQL, enable DBLayer rather
  than assuming models appear automatically;
  see :doc:`../tutorials/database-migrations`.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 27 38 35

   * - Problem
     - Typical reason
     - First check
   * - New endpoint returns 404
     - Route missing or source not selected
     - ``config/router.php`` and ``route:list``
   * - Controller cannot be loaded
     - Wrong ``App\`` namespace or Composer autoload
     - Project file location and autoload map
   * - Local endpoint works but production doesn't
     - Stale/invalid compiled release or wrong host
     - Release SHA, web server and deploy settings
   * - Test sees old response
     - Test fixture reused cached route graph
     - Rebuild selected graph and isolate runtime
   * - Unexpected service connection
     - Global middleware/eager provider dependency
     - Route selections and provider graph
   * - Test passes but real HTTP host fails
     - Different adapter, proxy headers or TLS policy
     - Run a real host acceptance test

Next steps
----------

Continue with :doc:`../http/routing`,
:doc:`../http/controllers`,
:doc:`../testing/http` and
:doc:`../architecture/request-lifecycle`.
