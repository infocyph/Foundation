.. _first-route:

===========================
Your first HTTP route
===========================

Infbyte keeps application routes in explicitly configured PHP files rather
than discovering every controller in the project.

The current **Foundation 3** Infbyte skeleton includes a route file at
`routes/api.php`. Its existing API health route illustrates the native
Webrick registration model:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

Here `SystemController` is application-owned, while `Registrar` belongs
to Webrick. The route file must be explicitly listed in the application
router configuration. This is an illustration of the current starter, **not**
a tested complete Foundation 4 example.

To inspect configured routes in a supported application:

.. code-block:: bash

   php infbyte route:list

For Foundation 4, verify the selected HTTP runtime, application route
configuration and compiled-release behavior before upgrading the skeleton.
Refer to :doc:`../reference/technical-guides` for current branch details.
