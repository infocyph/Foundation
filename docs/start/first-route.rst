.. _first-route:

Your first Infbyte HTTP route
=============================

This short tutorial introduces route registration in the
**Infbyte 4 development candidate**. For a complete
controller/test/deployment walkthrough, see
:doc:`first-application`.

Step 1 — find the application route file
----------------------------------------

Open ``my-app/routes/api.php``. The existing candidate
registers the built-in health endpoint:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

``Registrar`` comes from Webrick. ``SystemController``
belongs to your Infbyte application under
``app/Http/Controllers/``.

Step 2 — register a new GET handler
-----------------------------------

Add the following call to the same route file:

.. code-block:: php

   $router->get(
       '/api/hello',
       static fn (): array => ['message' => 'Hello from Infbyte'],
   );

This is a **new route you create**, not an existing starter
route. The callable returns a JSON-mappable array.

Step 3 — check route selection
------------------------------

The candidate's ``config/router.php`` includes
``'files' => ['api.php']``. If you instead create
``routes/web.php``, you must explicitly select it
in that configuration or Webrick will not discover it.

Run from the Infbyte project root:

.. code-block:: bash

   php infbyte route:list
   php infbyte serve --dry-run --json=1

The latter command inspects host selection without
binding a listener. A real server requires an available
Runwire host or an ordinary configured PHP/FPM path.

Step 4 — issue a request
------------------------

With your selected development host running:

.. code-block:: bash

   curl -i http://127.0.0.1:8000/api/hello

The address is illustrative; use the listener shown
by the running application. The response should
contain the ``message`` field.

Step 5 — grow the route safely
------------------------------

When a handler needs real business logic:

* Move behavior into an application controller or service.
* Accept Webrick ``Request`` only when needed.
* Choose the correct HTTP method.
* Validate untrusted data and authorize protected resources.
* Apply middleware per-route, not to every health endpoint.
* Write an HTTP integration test that includes failure paths.
* Rebuild a trusted production generation after changing
  route source.

Webrick also supports constrained parameters, route
names, grouping, domain scoping and signed URLs. They
are documented in :doc:`../http/routing` and
the native Webrick routing manual.

The Infbyte 4 candidate remains unpublished; do not
claim these development snippets prove a stable
``composer create-project`` consumer.

Next: :doc:`first-application`,
:doc:`../http/controllers` and
:doc:`../testing/http`.
