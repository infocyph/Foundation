.. _routing-guide:

Routing
=======

Infbyte uses Webrick's native router. Routes are written in explicitly
configured application files; Foundation composes the route graph and the
production compiler records its immutable execution plan.

Start with a route
------------------

The current Infbyte skeleton uses ``routes/api.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

The router source is included through the application's ``router.files``
configuration; neither controller directory scanning nor implicit route
file discovery is performed.

HTTP methods and constraints
----------------------------

Webrick supports GET, POST, PUT, PATCH, DELETE and other explicit
registrations, named routes, groups, parameter constraints and
domain-scoped routing. These are **native Webrick features**, not a
second Foundation router API.

For advanced patterns such as ``{id:int}``, route groups, named routes,
signed URL generation and matcher selection, consult the
`Webrick routing manual
<https://github.com/infocyph/Webrick/blob/main/docs/guides/routing.rst>`_.

Inspect and deploy routes
-------------------------

.. code-block:: bash

   php infbyte route:list
   php infbyte optimize
   php infbyte optimize:report

The latter two Foundation 4 commands compile and inspect the trusted
application generation; there are no independent Foundation
``route:cache`` or ``route:clear`` commands. Production consumes
Webrick's compiled routing artifacts and does not reload source routes
on each request.

Useful practices
----------------

* Declare only the route sources an application needs.
* Put more specific route patterns before overlapping dynamic patterns.
* Select middleware on the routes that actually require it.
* Test 404, 405 and signed-URL behavior through the chosen host.
* Build a new trusted release after changing route definitions.

Continue with :doc:`controllers` and :doc:`middleware`.
