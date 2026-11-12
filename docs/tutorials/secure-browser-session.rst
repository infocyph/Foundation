.. _tutorial-browser-session:

Tutorial: secure a browser session
==================================

A browser request can carry **application session state** and an
**authenticated principal**, but these are different concepts.
This walkthrough explains how to enable only the required middleware,
select shared state, protect writes with CSRF and rotate the session
when authority changes.

The guide describes **Foundation 4 development behavior**. The public
Infbyte application on ``main`` still targets Foundation 3.

Know the two session systems
----------------------------

Foundation's authentication session represents login/security state,
token families and remembered-device records. A ``BrowserSession``
represents cookie-backed application data such as a cart, flash notice
or CSRF token. An application can use one without pretending the two
stores are interchangeable.

A public JSON health endpoint needs neither. A browser page with a cart
may use browser sessions but no authenticated principal. An account page
typically needs both.

Step 1 — select middleware intentionally
----------------------------------------

The Foundation 4 predefined route-selected middleware presets are:

.. list-table::
   :header-rows: 1
   :widths: 24 40 36

   * - Preset/alias
     - Ordered expansion
     - Typical use
   * - ``session``
     - Browser session only
     - Flash data, anonymous cart
   * - ``web``
     - ``session, csrf``
     - Browser writes/forms
   * - ``web-auth``
     - ``session, csrf, resolve-auth, auth``
     - Authenticated browser account pages
   * - ``auth``
     - Principal authorization check
     - Route with independently resolved principal

Order is important. CSRF token validation requires session state first,
and authorization checks require resolved principal state. Adding all
these operations globally would make even a trivial stateless route
resolve resources that it does not need.

Registration of middleware on Webrick routes is governed by
the native Webrick route options; use
:doc:`../http/middleware` for the integration boundary.

Step 2 — use the session object inside a request
------------------------------------------------

Where the selected browser middleware is active, a Webrick handler can
accept ``BrowserSession`` through request-scope DI. The following
is a complete illustrative handler function, not an assertion that
this route is preinstalled in Infbyte:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Session\BrowserSession;
   use Infocyph\Webrick\Response\Response;

   function cartSummary(BrowserSession $session): Response
   {
       $session->put('cart_id', 'cart-42');
       $session->flash('notice', 'Item added.');

       return Response::json([
           'csrf_token' => $session->csrfToken(),
       ]);
   }

The session is request-scoped. It should not be stored in a static
property, shared singleton, or background job and used after the
response has completed.

A flash value lives for the next request. A CSRF token is an
anti-forgery proof, not a replacement for login or authorization.

Step 3 — choose production storage
----------------------------------

Foundation Core supports four browser-session drivers:

.. list-table::
   :header-rows: 1
   :widths: 18 37 45

   * - Driver
     - Reason to choose
     - Production consideration
   * - ``array``
     - Fast isolated unit tests
     - Process-local; never use for multi-request shared state
   * - ``file``
     - Simple local persistence
     - File permissions, cleanup and host-affinity matter
   * - ``cache``
     - Shared CacheLayer-backed state
     - Select durable backend and atomic coordination
   * - ``database``
     - DBLayer-backed persistence
     - Requires ``data.database`` and Core schema provisioning

Configuring a database session driver is not accomplished by enabling
every ``data`` feature. Select only the needed
``data.database`` capability, provision its schema in a controlled
CLI/deploy step, and verify the actual backend.

Session storage and authorization replay coordination must be visible
to all eligible web workers. A local array store may make a smoke test
pass while authentication disappears when traffic hits another process.

Step 4 — rotate on privilege change
-----------------------------------

After successful login, impersonation, account switch or another
change of authority, call ``regenerate()`` on the active browser
session *before* sending a response. For a logout or transition that
must discard browser state, ``invalidate()`` can start a fresh
session.

Why: retaining the old browser identifier across a privilege change
can permit session fixation or continued use by an old holder.
Foundation's rotation checks lock ownership before deleting the old
record; if ownership has been lost, the request must not report a
successful mutation.

This browser rotation is separate from creation/revocation of an
authentication session or refresh token family.

Step 5 — protect state-changing requests
----------------------------------------

Foundation's CSRF middleware checks state-changing browser requests
against the active session token. It does **not** accept a token
from the query string or a cookie as proof. The configured header
or parsed body field is the supported input channel.

Expected failure is a **419** response with
``Cache-Control: no-store`` and no secret token material.

When origin checking is enabled, ensure the Webrick trusted-proxy
configuration reflects the real external scheme and host. Otherwise
a correctly issued browser form may fail an origin comparison after
TLS termination, or a proxy may supply spoofed origin information.

Step 6 — handle concurrency and expiry
--------------------------------------

Session locks are CacheLayer coordination, not an implicit process
mutex. Configure a shared atomic lock backend when multiple workers
might modify the same session. Leases must be finite; losing ownership
rejects the mutation rather than allowing a stale request to
overwrite newer data.

For large file/database stores, perform expiry cleanup outside the
HTTP hot path:

.. code-block:: bash

   php infbyte session:prune --limit=1000

The command's delete count is bounded; file stores may still inspect
their directory to find candidates. Schedule cleanup appropriate to
actual backend volume and operational budget.

Streaming responses require special care: the session is finalized
before Webrick emits the response body. Do **not** lazily mutate session
state from a stream producer after the handler has returned.

Step 7 — test the negative cases
--------------------------------

An HTTP test should verify all these conditions, not just a successful
page load:

1. Public health routes do not create session cookies.
2. Browser writes without CSRF proof are rejected.
3. A valid token succeeds and is not reflected in errors.
4. Session identifiers rotate after login and privilege changes.
5. A failed backend write does not silently succeed.
6. A lost lock prevents stale overwrites.
7. A follow-up request in the same persistent host cannot see a
   prior principal or session object.
8. Logout/revocation prevents use of invalidated authority.

These checks require a compatible application route and actual
configured store; the docs do not claim to have run them against
an unpublished Infbyte 4 starter.

Related guides
--------------

Continue with :doc:`../security/sessions-csrf`,
:doc:`../security/authentication`,
:doc:`../testing/http` and the
`Foundation browser-session contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/browser-sessions.md>`_.
