.. _sessions-csrf:

Browser sessions and CSRF
=========================

Browser sessions carry cookie-backed application state. They are
distinct from authentication sessions used for token, device and
login-history records.

Activate sessions only where needed
-----------------------------------

The following Foundation 4 middleware presets are route-selected:

* ``web`` expands to ``session``, then ``csrf``.
* ``web-auth`` expands to ``session``, ``csrf``,
  ``resolve-auth``, then ``auth``.

A stateless API does not need a browser session just because
Foundation supports one.

Use a scoped browser session
----------------------------

In a Webrick request handler with scoped dependency injection:

.. code-block:: php

   use Infocyph\Foundation\Session\BrowserSession;
   use Infocyph\Webrick\Response\Response;

   function addToCart(BrowserSession $session): Response
   {
       $session->put('cart_id', 'cart-42');
       $session->flash('notice', 'Item added.');

       return Response::json(['csrf_token' => $session->csrfToken()]);
   }

This is application-session behavior. Do not keep a ``BrowserSession``
instance in a static variable or singleton across HTTP requests.

Choose an appropriate store
---------------------------

Foundation Core supports ``array`` (tests/ephemeral),
``file`` (local persistence), ``cache`` (shared CacheLayer), and
``database`` (DBLayer via ``data.database``) session drivers.
Shared deployments must use a suitable durable/consistent store.

Session-locking behavior uses selected CacheLayer coordination, without
an unrelated implicit file-lock fallback.

Session fixation and CSRF
-------------------------

After successful login, impersonation, account switch or other privilege
change, rotate the browser session ID using ``regenerate()``;
``invalidate()`` discards browser state when required. The
``csrf`` middleware must execute *after* ``session``.

Expired sessions and revoked locks must not silently persist success.
Foundation attempts cleanup on success and failure while preserving
the original application error.

Use bounded maintenance outside request traffic:

.. code-block:: bash

   php infbyte session:prune --limit=1000

Read the `browser session implementation guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/browser-sessions.md>`_
and :doc:`authentication`.

For a production-minded walkthrough of storage selection, privilege rotation,
CSRF, locking and persistent-host cleanup, see :doc:`../tutorials/secure-browser-session`.
