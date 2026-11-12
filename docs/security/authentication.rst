.. _authentication-guide:

Authentication
==============

Foundation 4 owns application authentication composition: accounts,
login, sessions, tokens, MFA, notifications and policy. The underlying
cryptographic and storage engines remain the responsibilities of
Epicrypt, DBLayer, CacheLayer and OTP as selected by the application.

Authentication is lazy
----------------------

An unauthenticated public route does not automatically resolve an
authentication provider. The selected ``resolve-auth`` middleware
resolves the configured principal sources. The ``auth``,
``guest``, ``verified`` and ``recent`` middleware aliases
consume that principal only when selected.

Use the typed service gateway
-----------------------------

When the current application runtime is available (for example, in an
application service that has received ``Application $app``), resolve
the Foundation 4 services through DI:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);
   $accounts = $auth->accounts();
   $login = $auth->authenticator();
   $sessions = $auth->sessions();
   $tokens = $auth->tokens();

The ``AuthServices`` gateway exposes configured services lazily;
this is not a global ``Auth`` facade. A selected password/token
driver may require ``security.crypto`` and persistent storage.

Passwordless and email verification
-----------------------------------

Passwordless links require an atomic, single-use redemption marker.
In PHP/FPM or multi-worker deployments, use shared persistent state
with atomic consumption rather than a process-local array.
Loss of replay state invalidates outstanding links.

Email verification must target an account's authoritative stored
email; do not accept an arbitrary new address as proof of ownership.
Bind account identity and rate limits at application routes.

Browser sessions are different
------------------------------

Authentication sessions track security/account state; browser sessions
own cookie-backed application state and CSRF. When login or another
privilege transition succeeds, the application must rotate the browser
session ID before returning the response.

Review the `Foundation authentication contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/authentication.md>`_
for production, password, token, reset and replay requirements.

Continue with :doc:`authorization` and :doc:`sessions-csrf`.
