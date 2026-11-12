.. _production-security:

Production security checklist
=============================

Foundation 4 brings application authentication, web sessions,
authorization and runtime composition together, but specialist
cryptographic, validation, transport and storage mechanisms still
belong to their native owners. Secure deployment depends on
**correct selections and boundaries**, not only installed packages.

Identity and secrets
--------------------

Keep ``APP_DEBUG`` disabled in production. Use controlled
process environment and secret files for credentials. The
``secret:generate`` command can create application secret material
without printing it to stdout:

.. code-block:: bash

   php infbyte secret:generate

When ``security.crypto`` is selected, environment-file protection
can use an external key file:

.. code-block:: bash

   php infbyte env:encrypt --key-file=/secure/env.key

Do not embed the encryption key into the ``.env`` file that it
protects. Do not pass literal secrets on a command line where shell
history or process tables can expose them. Permission and access
controls on the key file remain deployment responsibilities.

HTTP authentication and CSRF
----------------------------

Resolve a principal only on routes requiring one. Foundation's
``web-auth`` preset orders browser session, CSRF, principal
resolution and authentication policy.

Rotate browser session identifiers after login, impersonation,
account switch and similar privilege changes; avoid leaking
session IDs through URLs or logging. Separate browser session
data from authentication state, passwordless replay and
persistent token/refresh records.

Passwordless redemption needs atomic shared replay state across
PHP/FPM processes or workers. Lost replay state invalidates the
issued token; never silently treat the missing marker as success.

Validation and response safety
------------------------------

Use ReqShield for input validation and sanitization, but do not
mistake sanitization for authorization. Verify uploaded files
through Pathwise's strict untrusted-data policy. Serve private
resources using access checks, bounded downloads and appropriate
cache-control policy rather than exposing arbitrary storage paths.

Keep HTTP server exception reporting separate from the client
error renderer. Production responses should not reveal traces,
database credentials, internal file paths or raw request bodies.

Network and protocol checks
---------------------------

With TalkingBytes, maintain TLS peer and host verification.
Webhook receivers must verify signed metadata and body and
use atomic replay claims. For native Runwire serving, choose
supported TLS/HTTP protocol settings and test the actual host.

Trust forwarded host/protocol/IP headers only from known proxies.
Enforce body limits before expensive parsing or application work.

Runtime isolation
-----------------

Long-lived workers need clean InterMix execution scopes: principals,
sessions, transactions, request-specific cache and temporary
resources cannot survive into unrelated work. Run cancellation,
error and restart tests on each supported host mode you actually
deploy.

A running process must only load a generation matching the
externally trusted Foundation manifest and exact vendor graph.
Disable source fallback rather than weakening trust checks.

Prepare for incidents
---------------------

* Record the generation identity, installed dependency refs,
  selected feature topology and actual runtime mode.
* Rotate compromised tokens/keys through the owning security API;
  maintain bounded verification overlap only when safe.
* Stop affected workers and revoke active credentials when needed.
* Preserve useful redacted logs and recovery snapshots.
* Test rollback together with database, queue, session and
  crypto compatibility, not only a code pointer change.

See :doc:`authentication`, :doc:`sessions-csrf`,
:doc:`../deployment/operations` and
`Foundation security boundaries
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/security.md>`_.
