.. _sessions-csrf:

Browser sessions and CSRF in Infbyte
====================================

Browser sessions carry **cookie-backed application state**, for example a
shopping-cart identifier or one-time status message. They are not the
same as authentication sessions, which represent account login and token
lifecycle. Infbyte Core composes browser sessions only when a selected
Webrick route needs them.

This guide targets the **Infbyte 4 development candidate**. The default
Core-only Infbyte project has ``config/auth.php``, but it does **not**
ship a separate ``config/session.php``. Foundation provides Core
session defaults until an application customizes them.

Choose middleware only where needed
-----------------------------------

Use the selected Foundation middleware aliases:

* ``session`` enters a request-scoped browser session.
* ``csrf`` checks the state-changing browser request and **must
  follow** session initialization.
* ``web`` expands to ``session``, then ``csrf``.
* ``web-auth`` expands to ``session``, ``csrf``,
  ``resolve-auth`` and ``auth`` in that order.

A stateless JSON status route should not instantiate a browser
session merely because the framework supports it. A browser
application using cookie authentication needs the matching
session and CSRF policy on its state-changing requests.

These aliases must be selected through the actual
application/Webrick route configuration. Do not add them
unconditionally to all routes.

Customize the application session configuration
-----------------------------------------------

When your Infbyte app needs a different store or cookie policy,
place the configuration in **``my-app/config/session.php``**.
This Core template is stored in Foundation's installed package
for reference and can be copied into your app before editing:

.. code-block:: bash

   cp vendor/infocyph/foundation/resources/config/session.php config/session.php

This command must run from your **Infbyte project root**
after Composer installs Foundation. It creates an
application-owned config file; do not edit the copy
under ``vendor/`` or the Foundation repository.

The installed template defaults to:

.. list-table::
   :header-rows: 1
   :widths: 34 28 38

   * - Setting in ``config/session.php``
     - Default
     - Why it matters
   * - ``driver``
     - ``file``
     - Local persistent browser data
   * - ``lifetime``
     - 7,200 seconds
     - Idle expiration
   * - ``max_payload_bytes``
     - 65,536 bytes
     - Bounded session data
   * - ``cookie.secure``
     - ``true``
     - HTTPS-only browser cookie
   * - ``cookie.http_only``
     - ``true``
     - Prevent client JavaScript reading the cookie
   * - ``cookie.same_site``
     - ``Lax``
     - Restrict cross-site attachment
   * - ``lock.enabled``
     - ``false``
     - Opt-in concurrent mutation coordination
   * - ``csrf.header``
     - ``X-CSRF-Token``
     - Header accepted for unsafe requests
   * - ``csrf.field``
     - ``_token``
     - Parsed form/body field accepted

This is a map of **actual Foundation Core defaults**,
not proof that a new Infbyte checkout already has
``config/session.php``. Choose production values
according to the browser origin, TLS and host topology.

Write scoped browser session data
---------------------------------

The native ``BrowserSession`` supports
``put()``, ``get()``, ``has()``, ``forget()``,
``flash()``, ``oldInput()`` and ``csrfToken()``.

An application-owned controller can accept it
**only on a session-enabled request**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Controllers;

   use Infocyph\Foundation\Session\BrowserSession;
   use Infocyph\Webrick\Response\Response;

   final readonly class CartController
   {
       public static function add(BrowserSession $session): Response
       {
           $session->put('cart_id', 'cart-42');
           $session->flash('notice', 'Item added.');

           return Response::json([
               'cart_id' => $session->get('cart_id'),
           ]);
       }
   }

This is a **developer-created** class, not a built-in route.
You must register a Webrick route with session policy and
check the resulting request scope. Do not assume that a
plain stateless ``GET /api/health`` can resolve a browser
session service.

A flash value is available to the following request.
It is not long-term account state and should not store
unbounded or sensitive objects.

Rotate session identity on privilege changes
--------------------------------------------

A successful login, account switch or impersonation changes the
browser's security boundary. In that selected request, call:

.. code-block:: php

   $session->regenerate();

When explicitly discarding browser state:

.. code-block:: php

   $session->invalidate();

``regenerate()`` rotates the session identifier, and
``invalidate()`` discards state. Choose the correct
operation for the user-visible workflow.

Do not cache the active session, principal or
request object in a static property or process
singleton on persistent Runwire workers.

CSRF token and origin policy
----------------------------

The ``csrf`` middleware checks unsafe HTTP methods after the
session has loaded. Its default token inputs are
``X-CSRF-Token`` or the parsed body field ``_token``.
It does **not** accept the CSRF token from a query
string or cookie.

A same-origin browser frontend can obtain a token from
a trusted, session-enabled response and include it
with its state-changing request:

.. code-block:: text

   POST /checkout HTTP/1.1
   Cookie: foundation_session=<browser-managed-value>
   X-CSRF-Token: <session-bound-token>

The cookie and token are placeholders. Never print
actual tokens or session cookies into logs or
documentation examples.

A mismatch produces HTTP **419**, with no
token material returned and no-store cache policy.
When origin checking is enabled, an Origin header
that disagrees with the configured/derived expected
origin is rejected.

For production behind a reverse proxy, configure
Webrick's trusted proxy boundaries correctly
before using forwarded host/protocol values for
origin derivation. An explicit trusted
``session.csrf.origin`` is also supported.

Choose a store for the real deployment
--------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 24 36 40

   * - Driver
     - Best suited to
     - Important limit
   * - ``array``
     - One-process isolated tests
     - Not persistent across workers
   * - ``file``
     - Single-host local persistence
     - Not automatically shared between machines
   * - ``cache``
     - Shared CacheLayer store
     - Requires correct shared backend/TTL policy
   * - ``database``
     - DBLayer-backed session records
     - Requires ``data.database`` and Core schema

With ``session.driver=database``, use the native
``SessionDatabaseSchema`` typed installer/readiness
contract during CLI/deployment preparation.
An optional module's schema-sync command does
not automatically replace Core session provisioning.

For a selected cache-backed session, choose a
working shared CacheLayer store. A configured
``redis`` name does not create a Redis server
or prove that locks are atomic across hosts.

Concurrent requests and lock ownership
--------------------------------------

Two browser requests may mutate the same session
at once. Without locks, the selected store has
its native last-write-wins semantics.

``session.lock.enabled=true`` selects CacheLayer
lock coordination. The configured wait and lease
must be finite; a valid incoming session is locked
only when first accessed. A lost lease rejects
mutation instead of writing stale session data.
Lock release runs on both successful and failed
dispatch paths.

A process-local lock must not be described as
cross-host coordination. If a lease can expire
before a bounded backend write completes,
the application needs an appropriate ownership
and recovery policy.

Session middleware finalizes session data before
Webrick emits the response body. Do not keep a
mutable session and attempt to write it from a
deferred streaming response producer.

Prune expired persistent records outside HTTP
---------------------------------------------

File and database sessions require explicit
bounded expiry cleanup:

.. code-block:: bash

   php infbyte session:prune --limit=1000

Run this through a scheduled, supervised CLI
operation. It should not scan the session store
on every incoming request.

Testing and production checks
-----------------------------

Verify at least:

* A session-enabled route persists a small JSON-safe value.
* A plain stateless route does not emit an unnecessary cookie.
* ``csrf`` rejects missing/invalid tokens with HTTP 419.
* Login or privilege change rotates the session identifier.
* Shared-store concurrent updates do not silently lose ownership.
* A store failure remains a failure, not an empty successful session.
* Persistent workers cannot carry one browser's state into another.

Use :doc:`../testing/http` to exercise the selected
Webrick route and middleware in-process, then test
real browser cookie attributes and reverse-proxy
behavior through the actual host.

Common errors
-------------

.. list-table::
   :header-rows: 1
   :widths: 30 35 35

   * - Symptom
     - Likely cause
     - First check
   * - ``config/session.php`` absent
     - Core defaults not overridden
     - Copy template only if customizing
   * - Cookie not sent
     - Secure flag on HTTP or wrong domain
     - Actual scheme, domain and cookie policy
   * - CSRF always returns 419
     - Missing/old token or origin mismatch
     - Header/body field and trusted proxy
   * - Session resets across hosts
     - Machine-local file/array store
     - Selected shared session store
   * - Mutations disappear under concurrency
     - No shared lock or expired lease
     - CacheLayer lock config and lease
   * - Late streaming session mutation fails
     - Session already finalized
     - Complete mutation before response return

Continue with :doc:`authentication`,
:doc:`../http/middleware`,
:doc:`../tutorials/secure-browser-session` and
:doc:`../deployment/hosting`.
