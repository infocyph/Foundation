.. _controllers-guide:

Controllers in Infbyte
======================

A controller is **an application-owned PHP class** whose method is
registered with Webrick as an HTTP handler. Infbyte does not require
a base Controller class, Laravel facade, ORM, or automatic discovery.

Where to create a controller
----------------------------

In an Infbyte 4 candidate project:

.. code-block:: text

   my-app/
     app/Http/Controllers/SystemController.php
     routes/api.php
     config/router.php

The candidate already provides ``SystemController`` and registers
its two native Webrick routes. You can add classes under
``app/Http/Controllers/`` using the Composer ``App\``
PSR-4 autoload namespace.

Understand the default handler
------------------------------

The actual ``SystemController`` defines:

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

The route registration is:

.. code-block:: php

   $router->get('/api/health', SystemController::health(...));

The controller returns an array, and the selected Webrick
HTTP layer converts the handler result to a response. A small
health handler does not need to resolve the container, database
or request body.

Create a controller that reads a request
----------------------------------------

Suppose you want to echo an optional search query.
Create ``my-app/app/Http/Controllers/SearchController.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Controllers;

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   final readonly class SearchController
   {
       public static function search(Request $request): Response
       {
           return Response::json([
               'query' => $request->query('q', ''),
           ]);
       }
   }

Then add the handler registration to
``my-app/routes/api.php``:

.. code-block:: php

   use App\Http\Controllers\SearchController;

   $router->get('/api/search', SearchController::search(...));

This endpoint is **developer-created** and is not part of
the default candidate. The ``Request`` dependency belongs
to Webrick, not Foundation.

Try:

.. code-block:: bash

   php infbyte route:list
   curl -i 'http://127.0.0.1:8000/api/search?q=docs'

The URL assumes a server listening on that address.
Use the host/port actually selected by the CLI.

Explicit responses and HTTP status
----------------------------------

Webrick has native immutable response factories. Use a
typed response when you must set status or headers:

.. code-block:: php

   use Infocyph\Webrick\Response\Response;

   $response = Response::json(['saved' => true], 201)
       ->withHeader('Cache-Control', 'no-store');

A standalone snippet is not a complete route. Inside
an application controller, return that response object.

For application-specific structured envelopes and
JSON resources, use the optional Foundation
``JsonDispatchResponseFactory`` and
``JsonResource`` integration—not a parallel response
emitter. Webrick remains responsible for the HTTP
wire representation.

How to organize controller logic
--------------------------------

Keep controllers thin:

1. Let Webrick match a route and provide only requested
   HTTP parameters.
2. Resolve authentication/authorization when the route
   actually requires it.
3. Validate untrusted input with the configured ReqShield
   feature if needed.
4. Delegate database/queue/storage work to typed
   application services using the selected native owner.
5. Return an application resource or native Webrick
   response.
6. Test both the successful and rejected paths.

Don't store the current request, principal or an open
DBLayer transaction in a static property. Persistent
Runwire workers can process unrelated users in one host
process; request-scoped services must leave that scope.

Generator and registration lifecycle
------------------------------------

A compatible Foundation 4 CLI exposes:

.. code-block:: bash

   php infbyte create:controller Admin/User

It generates a starting file; it does **not** register
routes automatically. Review the emitted class and
include its handler in a configured route file yourself.

For a controller needing an injected service, use the
supported Webrick handler and InterMix DI contract.
Do not invent constructor injection semantics that the
selected handler resolver hasn't registered.

Testing the existing controller
-------------------------------

The actual candidate ``/api/health`` can be tested
using Foundation's native HTTP test client:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

A success assertion alone does not test input validation,
authorization, 404/405 handling, or failures after
database writes. Add those tests for each new business
route and then run an actual host smoke test before release.

Common errors
-------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Symptom
     - Likely issue
     - Action
   * - Class cannot be loaded
     - Namespace/path mismatch
     - Confirm ``App\`` PSR-4 autoload location
   * - Handler never called
     - Missing Webrick route registration
     - Check ``routes/api.php`` and ``route:list``
   * - Request state leaks
     - Static/process-wide mutable dependency
     - Use appropriate InterMix request scope
   * - Untrusted client field reaches SQL
     - Validation skipped or raw input reused
     - Validate once and use typed output
   * - Endpoint works only in development
     - Old compiled route or host difference
     - Inspect release manifest and real host

Read :doc:`routing`,
:doc:`requests-responses`,
:doc:`../tutorials/validate-an-api-request` and
:doc:`../testing/http`.
