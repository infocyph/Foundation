.. _controllers-guide:

Controllers
===========

Controllers are application classes whose methods can serve as native
Webrick route handlers. Infbyte does not impose a second controller
base class or require every controller to inject an HTTP request.

The existing starter controller
-------------------------------

The published Infbyte skeleton includes a class with this handler:

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
   }

Its route references the callable through
``SystemController::health(...)`` in ``routes/api.php``. This
example is sourced from **Infbyte 3** and is not an end-to-end
Foundation 4 starter test.

Generate a controller
---------------------

Foundation 4's CLI supports:

.. code-block:: bash

   php infbyte create:controller Admin/User

Generators provide starting files; they do not automatically register
controllers or routes. Add the handler to an explicitly listed route
file yourself. Keep request validation and authorization at the
appropriate boundaries, and delegate business logic to testable services
resolved through native DI.

When to accept a Request object
-------------------------------

Webrick only constructs/injects the request when the handler or middleware
needs it. Controllers can depend on
``Infocyph\Webrick\Request\Request`` for headers, input, uploads
and other HTTP-specific data.

Avoid process-static current-request state on persistent hosts.
Request-local dependencies should use InterMix's scoped lifetimes.

Continue with :doc:`requests-responses` and
:doc:`../architecture/service-providers`.
