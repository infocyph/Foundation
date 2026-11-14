.. _authentication-guide:

Authentication in an Infbyte application
========================================

**Authentication** establishes who a caller is. **Authorization**
decides what that identified caller may do. In Infbyte,
Foundation Core composes account management, login,
tokens, sessions, notification policy and principal
resolution; selected native packages own storage,
cryptography and additional security factors.

A fresh Infbyte 4 candidate already has
**``my-app/config/auth.php``** as Core configuration.
It is not an optional installable ``auth`` module.

This guide describes the **unreleased Infbyte 4
development candidate**. A published stable
Foundation 4 consumer is not yet available.

When authentication belongs in the route
----------------------------------------

A public health endpoint can be stateless and
anonymous. A route returning a user's invoices
must establish an identity, then enforce
resource-specific permission before reading
private records.

Don't enable auth/session/DB middleware
globally on every public endpoint simply
because the framework supports them.

Step 1 — inspect auth drivers in Infbyte
----------------------------------------

Open ``my-app/config/auth.php``. The candidate
defines ``drivers`` with these defaults:

.. list-table::
   :header-rows: 1
   :widths: 29 30 41

   * - Driver selector
     - Default
     - What to consider
   * - ``storage``
     - ``memory``
     - Persistent accounts need real storage
   * - ``cache``
     - ``array``
     - Shared coordination/replay for multi-worker auth
   * - ``passwords``
     - ``native``
     - Chosen hash/verifier policy
   * - ``tokens``
     - ``simple``
     - Key/secret lifetime, revocation, replay
   * - ``mfa``
     - ``simple``
     - OTP requires selected optional features
   * - ``notifications``
     - ``collect``
     - Real mail requires a trusted transport
   * - ``passkey``
     - ``memory``
     - WebAuthn requires passkey feature and policy

The defaults make a Core-only starter
lightweight. They do **not** imply a production
multi-host authentication setup with persistent
accounts, durable session state or delivered
email verification.

Step 2 — understand optional prerequisites
------------------------------------------

The selected auth security topology can need:

* ``data.database`` / DBLayer for persistent
  SQL account data.
* ``security.crypto`` / Epicrypt for selected
  cryptographic password/token mechanisms.
* ``security.mfa`` / OTP plus selected
  ``security.crypto`` for OTP-backed MFA.
* ``security.passkey`` / WebAuthn for
  passkey registration and assertions.
* ``execution.communication`` / TalkingBytes
  for selected outbound communication flows.

Keep Core and optional features separate.
Installing a native dependency alone does not
activate a Foundation feature.

For OTP-backed MFA, for example:

.. code-block:: bash

   php infbyte module:plan security.crypto
   php infbyte module:plan security.mfa
   php infbyte module:install security.crypto
   php infbyte module:enable security.crypto
   php infbyte module:install security.mfa
   php infbyte module:enable security.mfa

Only change ``AUTH_MFA=otp`` after the
required packages, selected feature policy
and replay storage are ready. Inspect plans
for additional app-specific prerequisites.

Step 3 — use the typed auth gateway
-----------------------------------

From an application service with a composed
Foundation ``Application $app``:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);
   $accounts = $auth->accounts();
   $login = $auth->authenticator();
   $sessions = $auth->sessions();
   $tokens = $auth->tokens();

``AuthServices`` exposes native typed services
and is resolved lazily. It is **not** a global
Laravel ``Auth`` facade. The exact account
record/driver storage API is owned by the
selected Foundation auth contract.

Other accessors include ``principals()``,
``authorizer()``, ``gate()``,
``roles()``, ``permissions()``,
``mfa()``, ``passkeys()``,
``passwordResets()``, ``passwordless()``,
``emailVerification()`` and ``lockouts()``.
Only call the relevant service when the
route/task needs that capability.

Step 4 — select authentication middleware
-----------------------------------------

Foundation's route-selected auth policy
distinguishes resolving a principal from
requiring one:

* ``resolve-auth`` examines configured
  principal sources for the current request.
* ``auth`` rejects an unauthenticated caller.
* ``guest``, ``verified`` and ``recent``
  apply additional identity policies where
  configured.
* ``web-auth`` expands to ordered
  browser ``session``, ``csrf``,
  ``resolve-auth`` and ``auth`` middleware.

A stateless token-based API and a browser
session-based account page need different
configuration. The browser flow requires
session state before CSRF validation, and
principal resolution before authorization.

Use Webrick's route options and the selected
Foundation alias registration; don't invent
application global middleware or assume
``auth`` by itself populates a principal.

Step 5 — distinguish browser and auth sessions
----------------------------------------------

Foundation's authentication ``sessions()``
service tracks security/account state.
``BrowserSession`` is a separate request-scoped
cookie-backed application session.

After login, impersonation, account switch
or another change of authority, rotate
the active browser session ID with
``BrowserSession::regenerate()``.
For logout/discarded browser state, use
the selected invalidation contract.

This protects against retaining the same
browser identifier across privilege changes.
Auth token-family revocation and browser
cookie rotation are different operations.

See :doc:`sessions-csrf` and
:doc:`../tutorials/secure-browser-session`.

Step 6 — password, reset and verification
-----------------------------------------

Password-reset and passwordless links
require **atomic single-use consumption**
with an appropriate shared durable store.
A process-local array marker can be lost
when PHP workers restart or requests
land on different hosts.

Email verification must target the
authoritative account email, not an
arbitrary address supplied in a public
request. Rate-limit and audit sensitive
operations without exposing whether a
specific email/account exists.

In ``my-app/config/notifications.php``
the default email transport is ``null``.
To deliver real reset/verification notices,
configure a trusted sender and transport
instead of assuming a notification API
implies successful delivery.

Step 7 — preserve secrets and observability
--------------------------------------------

The candidate's ``config/auth.php`` uses
``token_secret_environment`` to read
``AUTH_TOKEN_SECRET`` from runtime
environment/secret storage. Never commit
its value, serialize it into compiled
release config or print it in diagnostic
HTTP output.

Account lockouts and audit entries should
limit credential spraying while preserving
reviewable, redacted identity/activity
metadata. The runtime must not leak a
previous principal in a persistent worker.

Step 8 — test authentication boundaries
---------------------------------------

Meaningful tests cover:

1. Unauthenticated request rejected on protected route.
2. Authorized principal allowed by explicit permission policy.
3. Authenticated but unauthorized principal denied.
4. Browser session ID rotates on privilege changes.
5. Revoked token/session cannot be reused.
6. Passwordless/reset token is accepted at most once.
7. Replay marker survives selected process/host boundaries.
8. Failed storage/transport obeys fail-closed policy.
9. Request A's principal is absent from request B
   in the same long-lived host process.
10. Secrets and account enumeration clues never
    appear in error envelopes or logs.

A test-only memory driver verifies application
behavior but cannot qualify production DB/cache
durability. Repeat selected tests on the
actual backend and deployed host mode.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 35 37

   * - Symptom
     - Likely cause
     - Safe check
   * - Login vanishes after restart
     - Memory account/session driver
     - ``config/auth.php`` and real durable store
   * - CSRF failure after login
     - Middleware ordering or stale browser token
     - ``web-auth`` session/CSRF chain
   * - MFA replay accepted
     - No shared atomic replay state
     - Selected cache, MFA and crypto policy
   * - Reset mail not delivered
     - Default null notification transport
     - ``config/notifications.php``
   * - Wrong principal on another request
     - Scope leak or shared mutable singleton
     - Persistent-host execution lifecycle
   * - Basic health route slowed
     - Eager auth/backend initialization
     - Middleware and provider graph selection

Further reading
---------------

Read :doc:`authorization`,
:doc:`sessions-csrf`,
:doc:`../services/notifications`,
:doc:`../tutorials/secure-browser-session` and
the :doc:`advanced-authentication` and
:doc:`../reference/http-security-api` guides.
