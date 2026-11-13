.. _production-security:

Production security for an Infbyte application
==============================================

Security in Infbyte starts with **application configuration and selected
execution policy**. Foundation composes the runtime. Webrick, InterMix,
CacheLayer, DBLayer, Epicrypt, ReqShield, Pathwise, TalkingBytes, Omnibus
and Runwire retain their own native responsibilities.

This checklist targets the **unpublished Infbyte 4 development
candidate**. The Core-only starter is not a production security
certification and must not be represented as one.

Step 1 — verify what your application actually installs
-------------------------------------------------------

Before selecting security controls, record the package graph and
qualifying commit. The published Infbyte ``main`` branch is still
Foundation 3; the development candidate on ``feature/infbyte-4``
currently resolves a moving Foundation branch.

From **your Infbyte project root**:

.. code-block:: bash

   php infbyte --version
   php infbyte module:list
   php infbyte config:validate --production
   php infbyte app:ready

Review the actual six Core application config files:
``config/app.php``, ``config/auth.php``,
``config/cache.php``, ``config/modules.php``,
``config/notifications.php`` and
``config/router.php``. Do not point developers
to Foundation's ``resources/config/`` as if those
templates were live application settings.

Make every additional module intentional: optional
features publish files such as ``config/validation.php``,
``config/security.php`` or ``config/database.php``
**into the Infbyte project** when selected.

Step 2 — keep debug and credentials out of HTTP
-----------------------------------------------

The candidate's ``config/app.php`` selects environment
and debug settings from ``APP_ENV`` and ``APP_DEBUG``.
For production set the actual process environment to
``APP_ENV=production`` and ``APP_DEBUG=false``.

Configure secrets through a trusted process/environment
or protected secret storage. The application CLI offers
a secret generator:

.. code-block:: bash

   php infbyte secret:generate

When selecting ``security.crypto``, encrypted environment
material may use a separately protected key file:

.. code-block:: bash

   php infbyte env:encrypt --key-file=/secure/env.key

Neither command authorizes storing the decrypting key inside
the file it protects. Avoid literal secrets in shell history,
CI logs, application responses and committed repository files.

Review key rotation, revocation, and compatibility windows
with Epicrypt and the selected authentication driver.
Old encrypted retained data must remain readable or be
rehearsed through a data migration before a rollback.

Step 3 — separate public and authenticated routes
-------------------------------------------------

In **``my-app/routes/api.php``**, the shipped
``GET /api/health`` handler returns a small status
representation without selecting browser auth or
database state.

A protected application route should select
principal resolution and authentication only when
needed. Browser cookie routes typically select the
``web-auth`` middleware preset:

.. code-block:: text

   session -> csrf -> resolve-auth -> auth

A stateless service/API route may use a different
token-authentication policy. Do not force browser
cookie/session/CSRF work onto every health and
stateless JSON request.

Authentication establishes **who** the principal is;
authorization decides **whether** they can act on
the requested resource. A valid route parameter,
email address or signed-in session is not
authorization to access another account's record.

Step 4 — protect browser sessions and token state
-------------------------------------------------

A fresh Infbyte candidate ships ``config/auth.php``.
It does **not** ship a customized
``config/session.php``; Foundation Core supplies
browser-session defaults until the application overrides
them. The session configuration template can be placed
at ``my-app/config/session.php`` when needed.

Use HTTPS-only and HttpOnly cookies, choose an
appropriate SameSite policy and validate CSRF tokens
on state-changing cookie-backed routes. Rotate the
browser session ID after login, impersonation,
account switching and other privilege transitions.

For multi-host deployments use an appropriately
shared/atomic session, replay and lock backend.
A default ``AUTH_STORAGE=memory`` or
``AUTH_CACHE=array`` is not automatically durable
account or recovery state.

OTP, passwordless recovery, refresh tokens and
passkey ceremonies need single-use markers and
correctly scoped expiration. A missing replay
record or unavailable backend must never be
interpreted as successful authorization.

Step 5 — validate untrusted inputs and outputs
-----------------------------------------------

When necessary, select ReqShield through
``security.validation`` and publish the actual
``my-app/config/validation.php``. Use
``FormRequest::validated()`` or one validation
result per request to obtain typed values before
passing input into DBLayer or another native
service.

Validation and sanitization do not replace
resource authorization or output escaping.
For a state-changing route, verify permissions
for the exact record and tenant independently
of field formats.

Set body and upload size limits before expensive
parsing. With Pathwise, use the strict
untrusted-data upload profile and deliberate
scanner policy. Never expose an arbitrary
``storage/uploads/`` path or trust a browser
filename/extension as permission to serve
a private file.

Return safe client errors through Webrick and
the selected JsonDispatch policy. Do not
send stack traces, database credentials,
raw request bodies or sensitive token values.

Step 6 — preserve network trust
-------------------------------

TalkingBytes HTTP profiles must verify TLS peer
and host identities in production. For arbitrary
user-influenced target URLs, also enforce destination,
redirect, DNS and peer identity restrictions;
TLS verification alone is not SSRF protection.

Inbound webhooks need verified signatures,
timestamp/age bounds and atomic replay claims
stored in a shared backend when workers are
distributed. Do not trust a request because it
contains a plausible delivery header.

At the HTTP edge, use explicit trusted proxy
configuration before relying on forwarded
scheme, host or client IP. Terminate TLS
at a deliberately trusted endpoint.

If deploying FPM, the web server's document
root must be **``my-app/public/``**,
not ``my-app/``, ``config/`` or
``storage/``. Only the public entrypoint
should be reachable over HTTP.

Step 7 — activate only trusted generated releases
-------------------------------------------------

Production ``public/index.php`` loads the
``FoundationReleaseBootstrap`` contract and
requires an independently supplied trusted
generation manifest identity.

From a controlled deployment environment:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

An immutable generation contains compiled
web, CLI, worker and scheduler artifacts
under **``my-app/storage/releases/``**.
Those are build products, not application
source files to edit by hand.

A checksum read exclusively from the same
writable generation directory is **not**
an independent trust anchor. Supply the
verified digest and matching installed
Composer graph through the deployment process.

Never bypass a manifest mismatch by enabling
unverified runtime source compilation.
Reject activation until the source, vendor,
config and trusted digest agree.

Step 8 — prove persistent execution isolation
----------------------------------------------

A Runwire process can handle multiple requests/jobs.
InterMix request/job scopes must release mutable state
such as principals, browser sessions, DBLayer transactions,
selected HTTP clients and temporary files.

Exercise a successful request, a rejected request,
and another successful request in the **same**
process. Verify the second/third execution cannot
inherit authentication state or transaction handles
from earlier work.

Use bounded worker lifetimes, memory-growth limits,
restarts and host-specific cancellation handling.
A crashed or stale generation must not continue
accepting ownership of newly assigned work.

Omnibus message retries need idempotent effects.
Queue acknowledgments are not atomic with every
external email or database operation unless
the selected native mechanism explicitly provides
that guarantee.

Step 9 — rehearse code and retained-data rollback
-------------------------------------------------

A code pointer alone cannot reverse database migrations
or queue/credential/session format changes. Rehearse
the combination of:

* exact git/source and Composer package graph;
* selected capability and immutable release manifest;
* durable database schema and snapshot;
* queue, failure store, session and replay state;
* encryption/key version compatibility;
* web and worker generation activation/drain sequence.

Prefer compatible expand/contract changes when old
and new generations can coexist. Stop affected writers
before restoring a prior durable-state snapshot.
Test the former build against the retained data
instead of assuming it can read newer formats.

Step 10 — test the deployment, not only PHP classes
---------------------------------------------------

A green in-process TestKit run does not verify a live
TLS listener, proxy normalization, FPM FastCGI variables,
native Runwire cancellation, distributed replay state,
or actual email/webhook delivery.

Run representative host acceptance checks for the
real deployment mode and selected optional features,
including negative paths and fail-closed behavior.

Documentation validation and source-level public API
checks are **not sufficient** to approve Foundation
4.0 or Infbyte 4.0 release. The independent P6
performance, retained-state, security and published
dependency acceptance gates still apply.

Production troubleshooting
--------------------------

.. list-table::
   :header-rows: 1
   :widths: 28 34 38

   * - Symptom
     - Likely cause
     - First verification
   * - Production bootstrap refuses to start
     - Missing or mismatched trusted release identity
     - External manifest digest and installed dependency SHA
   * - Session works on only one host
     - Local file/array state
     - Shared session and lock backend
   * - Valid-looking OTP works twice
     - Missing atomic replay claim
     - OTP selected store and actual concurrent tests
   * - Outbound HTTP fails after enabling TLS
     - Certificate chain or target host mismatch
     - Trust store, expected host and DNS/peer identity
   * - Old worker still runs after deployment
     - Generation ownership/drain incomplete
     - Worker restart, supervisor and loaded generation
   * - Rollback cannot read account data
     - Retained schema/crypto version changed
     - Restore coherent data/key/code revision
   * - Client sees internal stack trace
     - Debug mode or error boundary misconfigured
     - ``APP_DEBUG`` and Webrick error renderer

Related guides
--------------

For practical Infbyte implementation details, read
:doc:`authentication`,
:doc:`authorization`,
:doc:`sessions-csrf`,
:doc:`advanced-authentication`,
:doc:`../http/validation`,
:doc:`../deployment/releases` and
:doc:`../tutorials/release-and-rollback`.
