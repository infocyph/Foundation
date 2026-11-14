.. _notifications-guide:

Notifications and email delivery in Infbyte
===========================================

Notifications let application code inform users about
events or security actions. **Foundation Core** owns
the application notification contracts and policy;
**TalkingBytes** owns actual email transport mechanics
for the selected sender.

An Infbyte 4 candidate already ships
**``my-app/config/notifications.php``**.
You do **not** need to install a fictitious
``notifications`` module to edit this file.

When to use notifications
-------------------------

Notifications may be used for account verification,
password reset, an account security warning, a
completed background operation or a business
status change.

Identify first whether the message is
**security-critical**, whether delivery must be
durable, and whether the recipient is trustworthy.
A successful in-process notification dispatch
is not necessarily evidence of actual delivery.

Step 1 — inspect the shipped application policy
------------------------------------------------

Open ``my-app/config/notifications.php``.
The current Infbyte candidate contains these
top-level keys:

.. list-table::
   :header-rows: 1
   :widths: 29 38 33

   * - Key
     - Purpose
     - Ownership
   * - ``channels``
     - Application-defined named channels
     - Foundation channel contract and your app
   * - ``auth``
     - Security notice recipient/sender/criticality policy
     - Foundation authentication/notification integration
   * - ``email``
     - Default sender, sender profiles, transport definitions
     - TalkingBytes native email engine

``channels`` initially contains no custom channels.
``email.senders`` includes named ``default`` and
``auth`` sender profiles. The default configured
transport for both is ``null``.

The ``null`` transport discards mail. It is
useful in certain development/test paths but
is **not** production email delivery.

Step 2 — select a real email transport
--------------------------------------

The candidate's transport descriptors include
``null``, ``fake``, ``log``,
``mail``, ``sendmail``, ``smtp``
and ``spool``. Select one appropriate to
your environment; a transport name alone
doesn't make its backend available.

For a production SMTP transport the shipped
``config/notifications.php`` uses environment
keys such as:

.. code-block:: text

   NOTIFICATIONS_EMAIL_TRANSPORT=smtp
   NOTIFICATIONS_EMAIL_SMTP_HOST=mail.example.test
   NOTIFICATIONS_EMAIL_SMTP_PORT=587
   NOTIFICATIONS_EMAIL_SMTP_SECURITY=starttls-required
   NOTIFICATIONS_EMAIL_SMTP_USERNAME=service-user

**These are example values, not real credentials.**
Keep the SMTP password in trusted runtime secret
configuration using the existing
``NOTIFICATIONS_EMAIL_SMTP_PASSWORD`` key.
Do not commit a password in this document, an
application source file or test fixture.

Make sure your chosen sender profile points
to the transport you actually configured.
An auth notice can use the distinct
``NOTIFICATIONS_AUTH_TRANSPORT`` setting.

Step 3 — configure sender identity
----------------------------------

``notifications.email.default_sender`` picks
the sender profile for ordinary messages.
``notifications.email.default_from`` supplies
a fallback sender when a message has no From.

``notifications.auth.sender`` defaults to
the ``auth`` profile. It can be configured
separately from the generic sender so
authentication messages have deliberate
rate/identity/transport policy.

A production From address must be verified
for the sending domain; test delivery with
the actual SMTP server and DKIM/SPF/DMARC
policy rather than relying only on an
in-process fake.

Step 4 — create an application notification
-------------------------------------------

In a compatible Infbyte 4 app, Foundation's
CLI can generate starting classes:

.. code-block:: bash

   php infbyte create:notification PasswordChanged
   php infbyte create:notification-channel Sms
   php infbyte create:mail Welcome

Those generators create **application files**.
They don't silently register a channel, select
a transport or activate an unrelated module.

Define channel classes through the selected
Foundation ``NotificationChannel`` contract
and register them in **your application's**
``config/notifications.php``. The framework
owns execution/dispatch semantics; a custom
service owns the actual business delivery
integration.

Step 5 — protect authentication notifications
---------------------------------------------

For password reset, passwordless login and
email verification, obtain the recipient
from the authoritative account identity
resolved by the application. A submitted
arbitrary email value is not proof that
the requester controls that account.

Security notices should:

1. Use the selected trusted auth sender.
2. Enforce replay and token lifetimes through
   the actual Foundation auth contracts.
3. Avoid bearer credentials in public API
   responses, logs and analytics.
4. Record non-sensitive delivery outcome
   and bounded retry identifiers.
5. Fail closed when policy requires delivery
   and no trusted transport is configured.

``auth.fail_silently`` affects failure handling,
but it must not be used to hide a mandatory
security delivery failure simply to make
a flow appear successful.

Step 6 — retry and durable delivery
-----------------------------------

The shipped sender profiles support configured
retry, fallback, rate limiting and DKIM policy.
Retry counts/intervals require careful selection.

A network timeout might occur after a mail
server has accepted a message. Retrying can
produce duplicates. Use stable notification
identifiers or application idempotency policy
for effects that must be once-only.

For asynchronous delivery, the application
may select Omnibus for durable work and
explicitly register the job/handler and
transport. Do not claim that configuring
SMTP automatically starts a queue worker.

Step 7 — test notifications correctly
-------------------------------------

Foundation's TestKit supports the configured
native fake email transport for selected
application tests. A fake is appropriate for
testing which message was constructed and
which recipient/channel was chosen.

It does **not** prove real SMTP connectivity,
DKIM signatures, recipient acceptance,
mailbox delivery, bounce handling or
persistent worker restart behavior.

Test these cases:

* Default development ``null`` behavior is explicit.
* Selected sender and recipient match account identity.
* Transport failure is surfaced as required by policy.
* Retry cannot perform duplicate security operations.
* Mail templates escape untrusted user data.
* Critical messages aren't silently dropped.
* A real staging delivery uses the intended TLS
  and authentication policy.

Step 8 — production and privacy notes
-------------------------------------

Use appropriate notification template size
and attachment limits. Avoid putting complete
customer records or access tokens into
email subjects and operational logs.

Rate limiting should prevent account enumeration
and abuse. Protect outbound credentials and
keep raw email/transcript collection disabled
unless the application has an explicit
security-reviewed diagnostic policy.

For persistent Runwire hosts, don't retain
a previous user's message/recipient in a
long-lived mutable sender singleton.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 36 35

   * - Symptom
     - Likely cause
     - What to check
   * - No mail arrives
     - ``null``/``fake``/``log`` transport
     - Actual ``config/notifications.php`` sender
   * - Auth email goes to wrong sender
     - ``auth.sender`` differs from general default
     - Selected ``auth`` profile and transport
   * - SMTP rejected
     - Credentials, TLS, domain or port policy
     - Native TalkingBytes transport diagnostics
   * - Duplicate messages
     - Retry without stable identifier
     - Idempotency/acknowledgment boundary
   * - Generator output not dispatched
     - Channel/handler not registered
     - Application's configured channels

Related
-------

Read :doc:`communication`,
:doc:`../security/authentication`,
:doc:`../reference/configuration` and
the native TalkingBytes project:

https://github.com/infocyph/TalkingBytes
