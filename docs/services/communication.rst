.. _communication-guide:

HTTP clients, webhooks and email in Infbyte
===========================================

Infbyte uses **TalkingBytes** as the native outbound HTTP,
webhook, email and gRPC engine. Foundation's optional
``execution.communication`` feature selects application
profiles and provides the integration policy.

**Where to configure it:** ``my-app/config/communication.php``,
published into your Infbyte application after selecting
the optional feature. It is not shipped with a new
Core-only Infbyte 4 candidate.

Choose the communication feature
--------------------------------

Run from the Infbyte application root:

.. code-block:: bash

   php infbyte module:plan execution.communication
   php infbyte module:install execution.communication
   php infbyte module:enable execution.communication
   php infbyte module:doctor execution.communication

The feature is not active merely because its Composer
dependency exists. Review the generated
``config/communication.php`` before contacting live
external services. Only select the capabilities that
your actual runtime needs.

Typical communication use cases
--------------------------------

* Requesting data from a trusted third-party API.
* Sending signed webhooks after a business operation.
* Verifying webhook callbacks, replay markers and
  authorized senders.
* Sending account/security notices through configured
  email sender profiles.
* Invoking or serving gRPC endpoints when the correct
  native transport/host has been selected.

TalkingBytes owns the protocol implementation.
Foundation does **not** introduce a second HTTP
client facade or SMTP protocol stack.

Step 1 — configure an outbound HTTP profile
--------------------------------------------

The selected application
``config/communication.php`` can define named
outbound HTTP profiles, including policy for
timeouts, allowed endpoints, retries, transport
and secure headers. Follow the generated file's
schema instead of inventing unsupported keys.

Select a profile from a service already running
inside the composed Infbyte application:

.. code-block:: php

   use Infocyph\Foundation\Communication\CommunicationProfiles;

   $profiles = $app->make(CommunicationProfiles::class);
   $client = $profiles->http('billing');

``billing`` is a developer-defined profile
name that must be configured first. Calling
``$profiles->http()`` without a name selects
the application default when defined.

The return type is native
``Infocyph\TalkingBytes\Http\HttpClient``;
the typed client owns request/response behavior.

Step 2 — perform a bounded request
----------------------------------

From a correctly configured application service:

.. code-block:: php

   $response = $client->get('https://api.example.com/health');

This is a **contextual snippet**. ``$client``
comes from the selected profile, and the URL
is illustrative. It does not represent a live
Infbyte-provided billing integration.

When calling a third-party service, enforce
connection and overall request timeouts, TLS
peer/host verification, redirect boundaries
and network-address allowlists as appropriate.
Do not allow an untrusted user to supply an
arbitrary URL that can reach internal network
or cloud metadata endpoints.

For production readiness, verify explicit
failure behavior: timeout, connection refused,
wrong TLS certificate, wrong HTTP status,
malformed JSON and rate-limited responses.

Step 3 — protect credentials and request data
---------------------------------------------

Keep API keys, bearer tokens and client
credentials in trusted application environment
or secret-management services. Never hard-code
them in ``config/communication.php`` or
log authorization headers by default.

Avoid sharing mutable per-user headers,
cookies or authentication state across
unrelated persistent Runwire executions.
Use the intended request-scope boundary and
a transport whose lifetime is well-defined.

Step 4 — send a webhook
-----------------------

A webhook is an outbound delivery to a
partner-supplied endpoint. TalkingBytes owns
the native ``WebhookSender`` signature,
transport and retry behavior. Foundation
selects a configured sender profile:

.. code-block:: php

   $sender = $profiles->webhookSender('partner');

The ``partner`` profile is application-defined;
its signing key, destination, timeout, retry
and payload policy must be reviewed.

Include stable event/operation identifiers in
business payloads so the receiving service
can handle retries idempotently. A network
timeout after delivery is **not** proof that
the receiver did not perform the operation.

Step 5 — receive and verify webhooks
------------------------------------

A Webrick inbound route is an application
entrypoint; a TalkingBytes verifier owns
cryptographic signature/version/timestamp
and delivery binding.

Before accepting a callback:

1. Authenticate/verify the configured sender.
2. Enforce payload, signature and age limits.
3. Check a **shared atomic** replay marker
   where the selected protocol requires one.
4. Validate the payload's schema/semantics.
5. Apply domain authorization and idempotency.
6. Acknowledge only after the intended
   persistence/dispatch boundary is defined.

A process-local cache is not a distributed
webhook replay defense. Do not implement
read-then-set replay checking outside the
selected atomic CacheLayer owner.

Step 6 — email belongs in notification policy
---------------------------------------------

Infbyte ships **``config/notifications.php``**
as Core configuration. The selected mail sender
profile and outbound transport are defined there.
The default ``null`` transport does **not**
send real email.

The optional communication feature supplies
additional TalkingBytes client/protocol integration;
do not copy email settings to an invented
``config/mail.php`` without a real application
contract supporting it.

For authentication notices, configure a
trusted delivery transport, validate the
selected sender and prevent raw token URLs
from appearing in logs or public JSON output.

Read :doc:`notifications` for practical
email/notification configuration.

Step 7 — gRPC and persistent workers
------------------------------------

``CommunicationProfiles`` also provides
native gRPC client/server and webhook
integration seams. gRPC is not automatically
listening just because the feature is enabled.
It requires an eligible selected host and
explicit handler/transport setup.

Do not create long-lived callbacks that retain
a previous request's authorization or context.
Bound connection/concurrency resources and
test cancellation and host recycling.

Step 8 — testing and production checks
--------------------------------------

In focused application tests, use the native
TalkingBytes fake HTTP/notification transport
selected by ``TestKit``. A fake proves that
the application **attempted** the request,
not that the network/TLS/remote service
works in production.

Run integration tests for success, timeout,
4xx/5xx, invalid signature, replay, duplicate
delivery, request cancellation, and follow-up
requests in the same persistent process.

For a production service, record error rates,
latency, retry counts and redacted request
identifiers. Retries must have bounded attempts
and idempotency policy; increasing retries
without limits can amplify an outage.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Symptom
     - Likely cause
     - Safe check
   * - ``config/communication.php`` missing
     - Optional feature not installed/published
     - ``module:doctor execution.communication``
   * - Named profile unavailable
     - Profile never configured or capability disabled
     - Selected application config and feature topology
   * - TLS request fails
     - Certificate/hostname or proxy mismatch
     - Native client verification/host policy
   * - Webhook accepted twice
     - Missing atomic replay state or idempotency
     - Shared CacheLayer and delivery identifiers
   * - Email never arrives
     - Default null transport or wrong sender profile
     - ``config/notifications.php`` delivery settings
   * - Worker leaks request cookies
     - Mutable client retained in a process singleton
     - Per-operation client/request scope

Further reading
---------------

See :doc:`../reference/configuration`,
:doc:`../testing/fakes`,
:doc:`../security/production` and
TalkingBytes' native documentation:

https://github.com/infocyph/TalkingBytes
