.. _request-lifecycle:

From HTTP request to Infbyte response
=====================================

An Infbyte HTTP request crosses **three distinct systems**:
the host handles incoming connections, **Foundation** composes
the application and selected policy, and **Webrick** owns
routing, middleware, request/response semantics and the
single response writer.

No separate Foundation routing facade or HTTP emitter
is introduced.

Step 1 — configure the route sources
------------------------------------

In the Infbyte 4 candidate, ``my-app/config/router.php``
explicitly selects ``api.php``. The file
``my-app/routes/api.php`` registers the real
Core health endpoint using the Webrick Registrar:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));

The controller is an Infbyte application class at
``app/Http/Controllers/SystemController.php``;
it returns ``['status' => 'ok']``.
No route or controller is auto-discovered simply because
a PHP file exists in a directory.

Step 2 — choose an HTTP host
----------------------------

The candidate can use an eligible development/native Runwire
host or ordinary PHP/FPM with a configured Webrick SAPI adapter.
The **application mode** is web in both cases. Runwire being
installed does not mean it is always the active HTTP listener.

The selected host adapts the request and supplies it to Webrick.
Runwire owns native event-loop admission/deadlines when used.
Webrick retains one request/response execution path.

Step 3 — load a trusted production generation
---------------------------------------------

The actual ``my-app/public/index.php`` includes
a trusted release bootstrap:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Release\FoundationReleaseBootstrap;

   require dirname(__DIR__) . '/vendor/autoload.php';

   $config = ['base_path' => dirname(__DIR__)];
   $release = FoundationReleaseBootstrap::fromEnvironment($config)
       ?? throw new RuntimeException('No trusted release configured.');

   $release->web($config)->server->handle();

This is an **abbreviated but API-accurate** version of the
candidate's entrypoint. The external deployment supplies the
expected trusted manifest digest. A digest only obtained from
the same writable generated directory is *not* independent trust.

On a trust mismatch, production fails rather than silently
building a new mutable route/container graph on the web request.

Step 4 — match and authorize the route
--------------------------------------

Webrick finds the selected compiled route, applies route
constraints and enters selected middleware. Foundation
provides aliases/presets for auth and browser sessions.

A stateless health route does **not** need a browser session
or database connection. A protected cookie route may select
``web-auth``, whose order is:

.. code-block:: text

   session -> csrf -> resolve-auth -> auth

First match an acceptable method/path, then establish the
needed principal, enforce resource-specific authorization
and validate untrusted values. A typed route parameter is
not proof that the caller may access the referenced record.

Step 5 — invoke the handler and write once
------------------------------------------

Webrick invokes the registered callable. It can receive a
Webrick ``Request``, a constrained route parameter or
an eligible scoped dependency when needed. A handler can
return a supported representation or a native immutable
``Response``; Webrick owns final HTTP normalization,
headers, streaming policy and emission.

For application-owned JSON APIs, JsonDispatch and
``JsonResource`` can contribute a selected envelope,
but they do not replace the Webrick transport layer.
The default ``/api/health`` route is a bare JSON
object, not a universal JsonDispatch ``data`` wrapper.

Step 6 — leave the execution scope
----------------------------------

After completion, Webrick and Foundation finalize
request-scoped resources. A session, auth principal,
DBLayer transaction, request-specific HTTP client or
temporary file handle cannot safely be retained in a
global singleton for the next unrelated request.

When a response is streamed later, **do not mutate a
finalized browser session**. Release scope-owned
resources as required by their native owner even
when the handler throws or is cancelled.

Troubleshooting matrix
----------------------

.. list-table::
   :header-rows: 1
   :widths: 29 36 35

   * - Problem
     - Likely stage
     - Inspect
   * - 404 on new path
     - Route file absent or matcher stale
     - ``config/router.php`` and ``route:list``
   * - Wrong method returns 405
     - Route method constraint
     - Registrar method and client request
   * - Unauthorized 401/403
     - Principal resolution or resource policy
     - Selected middleware and auth backend
   * - Browser 419
     - Session/CSRF token/origin
     - Same-origin request and trusted proxy
   * - Stale production handler
     - Old immutable release active
     - ``optimize:report`` and manifest SHA
   * - User state leaks across requests
     - Invalid InterMix lifetime
     - Per-execution test under real host

To follow the complete request as an application developer:
:doc:`../start/first-application`,
:doc:`../http/routing`,
:doc:`../http/middleware`,
:doc:`../http/requests-responses` and
:doc:`../testing/http`.
