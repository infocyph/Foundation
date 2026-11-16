.. _development-server:

Run an Infbyte development server
=================================

This guide describes the **Infbyte 4 development candidate**,
not a published stable 4.x release. The public Infbyte
``main`` branch still targets Foundation 3. If you have
not cloned the candidate, start with
:doc:`foundation4-candidate`.

What runs when you serve an Infbyte application?
------------------------------------------------

Your project's ``infbyte`` CLI executable delegates to
Foundation's ``CommandDispatcher``, which selects the
runtime and Runwire host policy. Webrick owns HTTP
routing and response output. The application itself
does not implement another web server.

A development server is useful for a fast local feedback
loop. Production deployment is a **different** operation,
with a trusted generated release and supervised processes.

Step 1 — prepare the application
--------------------------------

Work from the **Infbyte project root**, not the Foundation
package checkout:

.. code-block:: bash

   git clone --branch feature/infbyte-4 --single-branch https://github.com/infocyph/Infbyte.git my-app
   cd my-app
   composer update --no-interaction --prefer-dist
   php infbyte app:install
   php infbyte config:validate
   php infbyte module:list

The development Composer manifest currently requires
the moving Foundation ``dev-feature/foundation-3``
branch. Record the resolved source SHA in bug reports
because an older CI result may target a different API.

The initial application is Core-only. It includes
``config/app.php``, ``config/router.php`` and
``routes/api.php``; no database or queue feature
is required to serve the sample health endpoint.

Step 2 — inspect runtime availability
-------------------------------------

Check the installed application CLI:

.. code-block:: bash

   php infbyte --version
   php infbyte help serve
   php infbyte serve --dry-run --json=1

``serve --dry-run`` reports host selection without
opening a listener. This matters when PHP extensions
or native host drivers differ across developer machines.

Foundation delegates to Runwire's host selection
instead of embedding a second event loop. The
default automatic selection chooses an available
driver, not a universally guaranteed Swoole or
native transport.

Step 3 — start the selected local host
--------------------------------------

For a supported available driver:

.. code-block:: bash

   php infbyte serve

To request a specific address/port when the installed
CLI supports these flags:

.. code-block:: bash

   php infbyte serve --host=127.0.0.1 --port=8080

Explicit native drivers can require extensions.
For example, ``--runtime=swoole`` is only usable
when that host is actually installed and eligible;
selecting it does not install the Swoole extension.

Read the running process output for its bound address.
Do not assume that the sample port below matches
every user's host configuration.

Step 4 — request the actual sample route
----------------------------------------

The candidate's selected ``config/router.php``
includes ``routes/api.php``, which defines:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

The controller belongs to
``my-app/app/Http/Controllers/SystemController.php``.
Its ``health()`` method returns
``['status' => 'ok']``.

With a listener on port 8080, issue:

.. code-block:: bash

   curl -i http://127.0.0.1:8080/api/health

Expect the JSON payload equivalent to:

.. code-block:: json

   {"status":"ok"}

You can inspect registration with:

.. code-block:: bash

   php infbyte route:list

If the route returns 404, check whether the requested
method/path matches an active route and whether
``config/router.php`` selects the right PHP source.

Step 5 — understand what should reload
---------------------------------------

A route, provider, capability or configuration change
may require restarting the selected host or rebuilding
a compiled generation. A long-lived process does not
always re-read changed PHP sources on every request.

Development ArrayKit config-cache operations are:

.. code-block:: bash

   php infbyte config:cache
   php infbyte config:clear

These concern generated development metadata, not
the production immutable release. Do not use a
missing configuration cache as an excuse to bypass
validation or generate source inside
``storage/releases/`` manually.

Step 6 — use ordinary nginx/PHP-FPM correctly
---------------------------------------------

An Infbyte application can also run under an ordinary
web server using PHP-FPM:

* Set the web server document root to **``my-app/public/``**.
* Forward PHP requests to the actual
  **``my-app/public/index.php``** entrypoint.
* Build/promote a trusted Foundation release first.
* Supply the external trusted release root and manifest
  digest in the process/host launch environment.
* Configure FastCGI variables, trusted proxy headers
  and TLS termination for your actual deployment.

You do **not** need ``php infbyte serve`` running
inside a production PHP/FPM deployment: FPM and
the web server already own the HTTP socket/process.

For a candidate production build:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

Never let a public HTTP request generate a new
unreviewed route/provider release as fallback.
``public/index.php`` is designed to fail closed
when the trust identity is unavailable or mismatched.

Step 7 — test in-process and on a real host
-------------------------------------------

For a fast request/response feedback loop,
Foundation's TestKit can exercise the configured
Webrick HTTP kernel without a live socket:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

The ``$app`` variable is a booted Infbyte test
application, not an arbitrary CLI graph. The
full test fixture is in :doc:`../testing/http`.

A passed TestKit test does **not** prove actual
FPM, proxy, TLS, Runwire concurrency, worker
recycling or externally trusted manifest behavior.
Complete an independent host smoke test for
those concerns before publishing a release.

Common development problems
---------------------------

.. list-table::
   :header-rows: 1
   :widths: 27 37 36

   * - Symptom
     - Likely cause
     - First safe check
   * - Selected runtime unavailable
     - Missing extension or unsupported host driver
     - ``serve --dry-run`` and PHP extensions
   * - CLI cannot bootstrap
     - Missing Composer packages or stale config
     - ``composer update``, ``config:validate``
   * - Route returns 404
     - Source not included or wrong method
     - ``config/router.php``, ``route:list``
   * - Server serves stale handler code
     - Long-lived compiled graph
     - Restart/rebuild selected development host
   * - PHP/FPM returns 500 without content
     - No trusted release root/digest or FPM misconfig
     - Deployment metadata and sanitized logs
   * - Health works but auth disappears
     - Test-local store used on multiple workers
     - Selected shared session/auth coordination
   * - Benchmarks differ unexpectedly
     - Different native host or dependency graph
     - Record host, installed SHA and concurrency

Security and deployment boundary
--------------------------------

Do not expose a development server with debug diagnostics
and default test/memory credentials to untrusted traffic.
The default ``AUTH_STORAGE=memory`` and
``AUTH_CACHE=array`` settings are for simple
development, not automatically persistent multi-host
authentication.

An actual deployment requires an app-specific
security policy, production configuration, native host
qualification and coherent data/session/queue rollback.

Next: :doc:`first-application`,
:doc:`../deployment/hosting` and
:doc:`../tutorials/release-and-rollback`.
