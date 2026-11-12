.. _first-application:

Build your first API endpoint
=============================

This tutorial follows source from the **currently published Infbyte 3
application skeleton**. A separate, unpublished Foundation 4 candidate
exists on Infbyte PR #11; follow :doc:`foundation4-candidate` for the
candidate's actual route registration and development installation.
The published version below is not a Foundation 4 release tutorial.

Install and start
-----------------

.. code-block:: bash

   composer create-project infocyph/infbyte my-app
   cd my-app
   php infbyte serve

The starter already contains a small health endpoint without requiring
database, queue or authentication modules.

Read the controller
-------------------

The current ``app/Http/Controllers/SystemController.php`` contains:

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

The application owns this class. Webrick, not a Foundation controller
facade, owns HTTP route execution.

Read the route
--------------

The existing ``routes/api.php`` registers a native Webrick callable:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

Its ``config/router.php`` explicitly includes ``api.php``. Check
registered routes with:

.. code-block:: bash

   php infbyte route:list

With the local server running, visit
``http://127.0.0.1:8000/api/health`` to inspect the response.

What happens next?
------------------

Webrick selects the route and handles native HTTP semantics. Foundation
provides the application graph and policy. Simple routes do not need
to resolve an auth store, session, or database.

During production deployment route definitions are compiled into a
trusted release. Changing route source without rebuilding that release
does not automatically change the active production router.

Next, read :doc:`../architecture/service-providers` and
:doc:`../architecture/request-lifecycle`. The example above has been
matched against the current starter source, but the current Foundation 4 candidate's **latest** dependency graph has
not been requalified by this documentation batch.
