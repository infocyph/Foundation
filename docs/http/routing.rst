.. _routing-guide:

HTTP routing in an Infbyte application
======================================

Infbyte keeps HTTP route declarations in its **application** ``routes/``
directory. Webrick owns routing and HTTP semantics; Foundation composes
and compiles selected routes for the web runtime.

Where route files live
----------------------

The actual Infbyte 4 candidate includes:

.. code-block:: text

   my-app/
     config/router.php
     routes/api.php
     app/Http/Controllers/SystemController.php

``config/router.php`` explicitly lists ``api.php`` under ``files``.
A new PHP file under ``routes/`` is **not** auto-discovered.

The real candidate file ``routes/api.php`` contains:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));
   $router->get('/json', SystemController::json(...), 'json');

These routes are registered using native Webrick ``Registrar``,
not a Laravel facade.

Create a basic route
--------------------

Add a route under the existing registrations:

.. code-block:: php

   $router->get(
       '/api/welcome',
       static fn (): array => ['message' => 'Welcome to Infbyte'],
       'welcome',
   );

This is an **application-added** example, not a preinstalled
route. The ``welcome`` string assigns a route name.

.. code-block:: bash

   php infbyte route:list

For a larger handler, register an application-owned controller
method rather than placing business logic inside a large closure.

HTTP methods and semantics
--------------------------

The native registrar supports GET, POST, PUT, PATCH, DELETE, HEAD
and OPTIONS registrations. Choose the method that matches
the operation:

.. code-block:: php

   $router->get('/api/status', static fn (): array => ['ok' => true]);
   $router->post('/api/accepted', static fn (): array => ['accepted' => true]);

Neither route ships with Infbyte by default. A real write
endpoint must validate input, authorize its principal when
necessary, and define retry/idempotency behavior.

Use ``get`` for safe reads, not state-changing operations.
Browsers, intermediaries and crawlers can retry, prefetch or
cache read requests.

Parameters and constraints
--------------------------

Webrick supports typed route constraints:

.. code-block:: php

   $router->get(
       '/api/items/{id:int}',
       static fn (int $id): array => ['item_id' => $id],
       'items.show',
   );

The ``:int`` segment guards route matching. It does not
authorize access to that ID or prove an item exists in
a database. Webrick also supports ``:uuid``, ``:slug``,
``:hex`` and custom patterns; consult the current
native routing guide for detailed syntax.

For overlapping paths, register a static path such as
``/api/items/new`` before the broad dynamic path.
Keep names unique to avoid confusing URL generation.

Route options and middleware
----------------------------

The third argument may be a plain name or an options
array. A contextual example with an application callable:

.. code-block:: php

   $router->get('/api/private', $handler, [
       'as' => 'private.index',
       'middleware' => ['auth'],
   ]);

Here ``$handler`` must be defined by the application,
and the selected auth alias/principal policy must
actually exist. This is **not** a complete preinstalled
private route.

For browser routes requiring sessions and CSRF,
Foundation's selected ``web`` and ``web-auth`` middleware
presets provide ordered policy. Avoid adding those to
stateless health endpoints that don't need them.

Route groups, domain scoping, signed URLs and
resource-route registration are native Webrick features.
Advanced signatures and middleware alias setup belong to
the owning native manual:

https://github.com/infocyph/Webrick/blob/main/docs/guides/routing.rst

Application controllers
-----------------------

For a controller under
``my-app/app/Http/Controllers/SystemController.php``,
register an explicit callable:

.. code-block:: php

   $router->get('/api/health', SystemController::health(...));

Webrick injects only parameters requested by the handler.
A handler with no request argument need not allocate
request-dependent application services.

See :doc:`controllers` and
:doc:`requests-responses` for HTTP input/output.

Development and production
--------------------------

From the Infbyte project root:

.. code-block:: bash

   php infbyte route:list
   php infbyte serve --dry-run --json=1

Production compiles the enabled route files into
Webrick's immutable matcher inside the verified
``my-app/storage/releases/`` generation:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

Changing ``routes/api.php`` will not automatically
update a previously activated production generation.
Do not manually edit generated release artifacts;
build/promote a new verified generation.

Foundation 4 does not expose a parallel
Laravel-style ``route:cache`` command. Its
``optimize`` operation creates the applicable
native artifacts for the selected runtimes.

Test the route
--------------

A configured Foundation web test application can
use native Webrick TestKit integration:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

The health route exists in the actual Infbyte 4
candidate. The ``$app`` object must be a booted
web application with the candidate root/route configuration.
See :doc:`../testing/http` for a full test-file example.

Test unknown paths, wrong methods, invalid parameters,
authorization failures and persistent-request cleanup.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 27 37 36

   * - Problem
     - Likely cause
     - Check
   * - New route returns 404
     - Route file absent from configured list
     - ``config/router.php`` and ``route:list``
   * - Wrong handler selected
     - Overlapping paths or broad constraint
     - Route order and parameter patterns
   * - 403/419 on browser route
     - Auth or CSRF policy
     - Selected middleware and session state
   * - Works in development only
     - Stale compiled generation or host discrepancy
     - Release SHA and actual host adapter
   * - Slow health endpoint
     - Eager service/provider or global middleware
     - Inspect selected runtime graph

Continue with :doc:`controllers`,
:doc:`middleware` and :doc:`../start/first-application`.
