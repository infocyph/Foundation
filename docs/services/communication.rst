.. _communication-guide:

HTTP clients, webhooks and email
================================

Foundation selects named application communication profiles, while
**TalkingBytes** owns HTTP clients, webhook signatures and transport,
email protocols, and gRPC mechanics.

Select the communication feature
--------------------------------

In a Foundation 4-compatible application:

.. code-block:: bash

   php infbyte module:plan execution.communication
   php infbyte module:install execution.communication

This publishes selected application communication settings. Package
presence alone does not imply an active client or network connection.

Make an outbound HTTP request
-----------------------------

When the application's configured HTTP profile is active, resolve
the native TalkingBytes client:

.. code-block:: php

   use Infocyph\TalkingBytes\Http\HttpClient;

   $http = $app->make(HttpClient::class);
   $result = $http->get('https://api.example.com/health');

To use a named profile:

.. code-block:: php

   use Infocyph\Foundation\Communication\CommunicationProfiles;

   $profiles = $app->make(CommunicationProfiles::class);
   $billing = $profiles->http('billing');

The ``billing`` profile is an **application-defined name** that must
be configured. Foundation does not wrap native TalkingBytes request or
response types in a replacement facade.

Webhooks
--------

TalkingBytes owns signing, verification, timestamp/delivery binding,
replay protection and transport. Foundation can compose CacheLayer-backed
atomic replay state. Do not accept inbound webhooks on an untrusted
secret, bypass signature/version checks, or implement replay prevention
as a non-atomic read-then-write operation.

Email and gRPC
--------------

TalkingBytes owns SMTP, MIME, DKIM, inbound mail, webhooks and gRPC
protocol engines. Foundation selects sender/transport profiles and
integrates application/auth notifications.

Production HTTP clients should retain TLS peer and host verification.
Do not share mutable request-specific cookies or circuit-breaker state
across unrelated persistent-host executions.

See `TalkingBytes <https://github.com/infocyph/TalkingBytes>`_
and `Foundation communication
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/communication.md>`_.
