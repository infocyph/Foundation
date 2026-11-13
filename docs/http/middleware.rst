.. _middleware-guide:

HTTP middleware in an Infbyte application
=========================================

Middleware controls the work before and after a
Webrick route handler. Use it for explicit
HTTP concerns such as authentication, CSRF,
request limits, content negotiation and
response policies—not as a substitute for
domain validation or authorization.

**Application location:** declare route policy
in ``my-app/routes/api.php`` or another
file selected by ``my-app/config/router.php``.
The middleware implementations and ordering
remain native Webrick/Foundation contracts.

Why middleware selection matters
--------------------------------

A simple public health request needs a
route lookup and response. It should not
resolve a database account, browser session,
CSRF token and SMTP transport merely
because those features are installed.

Select only middleware required by that
route, then test both the positive and
negative execution paths.

Step 1 — understand the request flow
-------------------------------------

In the configured Infbyte web runtime:

.. code-block:: text

   HTTP host
      -> Webrick pre-global middleware
      -> route matcher
      -> selected route middleware
      -> application handler
      -> response/post-handler unwind
      -> Webrick response writer

The order and the source of middleware
registration affect security and
performance. Webrick owns native
request parsing, global HTTP middleware
and response emission; Foundation adds
the selected application policy.

A production route graph is compiled
into the trusted Foundation generation.
Changing a route's middleware list
requires rebuilding that generation.

Step 2 — choose a route's middleware
------------------------------------

The native Webrick ``Registrar`` accepts
an options array as a route's third
argument. For an application-defined
handler and selected alias:

.. code-block:: php

   $router->get('/api/private', $handler, [
       'as' => 'private.index',
       'middleware' => ['auth'],
   ]);

``$handler`` must be an actual configured
application callable. The ``auth`` alias
also requires appropriate principal
resolution; do not copy this fragment
as a complete working private route.

For a public route, use the existing
health registration without unnecessary
application-security middleware:

.. code-block:: php

   $router->get('/api/health', SystemController::health(...));

This native sample is present in
Infbyte 4's ``routes/api.php``.

Step 3 — select the browser presets
-----------------------------------

Foundation 4 defines convenient
route-selected middleware presets:

.. list-table::
   :header-rows: 1
   :widths: 27 38 35

   * - Selection
     - Execution order
     - Appropriate task
   * - ``session``
     - Browser session
     - Anonymous cart or flash state
   * - ``web``
     - ``session``, then ``csrf``
     - State-changing browser forms
   * - ``web-auth``
     - ``session``, ``csrf``, ``resolve-auth``, ``auth``
     - Authenticated browser pages
   * - ``auth``
     - Principal required
     - Route whose principal has already been resolved

CSRF needs session state first.
Auth policy needs the current principal
resolved before enforcement. Selecting
``auth`` without the actual identity
source can produce a denial or a
misconfigured security boundary.

The default Core-only Infbyte skeleton
does not enable a browser session on
its ``/api/health`` route.

Step 4 — distinguish global and route policy
---------------------------------------------

Webrick owns pre-global and post-global
HTTP layers: request/body limits,
gateway hardening, error handling,
content negotiation, CORS, compression,
cache validators and telemetry.

A global pre-handler limit is suitable
when every request needs the protection.
Authentication/authorization is usually
better selected on the routes that
actually require it.

Don't copy every available middleware
into each route. Overlapping response
compression, validators or CORS rules
can introduce inconsistent headers
and unnecessary work.

Step 5 — validate after parsing input
-------------------------------------

An HTTP middleware may normalize or
reject a request before application
work, but it does **not** replace
typed per-operation validation.

For an application write endpoint:

1. Webrick enforces safe body/route policy.
2. Selected auth middleware resolves identity.
3. The application authorizes the operation.
4. ReqShield validates and types incoming values.
5. The handler/service performs bounded work.
6. Webrick emits a native response.

``FormRequest``/ReqShield is selected
through ``security.validation``.
Do not enable optional database-backed
validation for every trivial route.

Read :doc:`validation` for the
application's validator API.

Step 6 — handle errors and responses
------------------------------------

An application can return a native
Webrick ``Response`` or a supported
handler result. Error handling belongs
to the HTTP execution boundary; the
selected JSON envelope is an
application-level representation policy.

Do not serialize raw exception messages
or stack traces into public error
responses. Preserve meaningful HTTP
status codes unless the application
explicitly selected an alternate
JsonDispatch status policy.

Response headers are immutable:
``withHeader()`` returns a changed
response rather than modifying an
already-emitted global object.

Step 7 — secure persistent runtimes
-----------------------------------

A persistent Runwire host can execute
requests from different principals.
Never store Webrick ``Request``,
``BrowserSession``, current principal
or live database transaction in a
process-global mutable singleton.

Webrick and Foundation own their
respective request/application cleanup
boundaries. A thrown exception or
cancelled request still needs to
release scoped resources before
the next execution.

Middleware that starts an external
network request needs timeout,
cancellation and response limits;
an uncapped API call in global
middleware penalizes every route.

Step 8 — test the pipeline
--------------------------

From a configured Infbyte web test
application, Foundation TestKit
can issue native Webrick requests:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

That endpoint is shipped in the
candidate. For your own protected
route, test anonymous denial,
authorized success, wrong role,
CSRF rejection and transport errors.

Then test two unrelated requests
through **one persistent host**,
including a failure in between.
Verify that the last request
cannot see the earlier principal
or session.

Debugging the selected chain
----------------------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Symptom
     - Likely cause
     - Safe check
   * - Public route opens SQL
     - Broad auth/backend middleware
     - Route and global middleware selection
   * - Browser write returns 419
     - CSRF/token/session ordering
     - ``web`` or ``web-auth`` policy
   * - Protected route always denies
     - Principal not resolved first
     - ``resolve-auth`` and store configuration
   * - Response headers conflict
     - Duplicate post-global processing
     - Webrick CORS/negotiation/compression policy
   * - New middleware not applied in production
     - Old compiled release generation
     - Current manifest and ``optimize:report``
   * - Principal leaks between calls
     - Scoped state captured in persistent singleton
     - Execution-scope cleanup tests

Next steps
----------

See :doc:`routing`,
:doc:`requests-responses`,
:doc:`../security/sessions-csrf` and the
native Webrick middleware reference:

https://github.com/infocyph/Webrick/blob/main/docs/middleware/index.rst
