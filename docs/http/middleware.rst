.. _middleware-guide:

HTTP middleware
===============

Middleware runs before and after a selected Webrick handler. In Infbyte,
the default rule is **use middleware only where a route needs it**.

What middleware can do
----------------------

Webrick owns global and route-level middleware compilation, ordering,
request limits, negotiation, CORS, compression, cache validators,
telemetry and other protocol concerns.

Foundation composes selected application policy such as authentication,
browser sessions, CSRF, authorization and validation into the native
Webrick execution graph.

For a browser route requiring authenticated session state, the current
Foundation 4 **web-auth** preset expands to:

.. code-block:: text

   session -> csrf -> resolve-auth -> auth

A stateless API endpoint should not automatically pay for that stack.

Order is part of the security contract
--------------------------------------

* Load browser session state before performing CSRF verification.
* Resolve the principal before enforcing authentication or role policy.
* Apply request/body limits before expensive parsing or application work.
* Preserve Webrick's post-handler compression and representation policy.

Webrick's normal middleware ordering is pre-global, route-selected
middleware, handler, then post-global unwind. Native middleware must be
registered before production route compilation.

Common mistakes
---------------

Do not add authentication to all health endpoints, attempt to sanitize
all input globally as a security substitute for validation, or retain a
request object in a process-global singleton. A selected store that is
not activated by the route should remain cold.

For real aliases, middleware groups and native HTTP behavior, see the
`Webrick middleware manual
<https://github.com/infocyph/Webrick/blob/main/docs/middleware/index.rst>`_
and :doc:`../security/sessions-csrf`.
