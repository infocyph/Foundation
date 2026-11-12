.. _notifications-guide:

Application notifications
=========================

Notifications are a Foundation **Core application contract**, while
transport engines belong to selected specialist packages such as
TalkingBytes. An application can compose notification channels without
activating every possible communication transport.

Core versus optional transport
------------------------------

Foundation Core owns notification selection, dispatch contracts and
integration with account/security events. TalkingBytes handles actual
email transport mechanics when ``execution.communication`` is selected.

A custom notification channel should use the existing Foundation
channel registry/dispatcher extension seam. Foundation intentionally
does not expose a second generic ``NotificationManager`` facade.

Generate application starting points
------------------------------------

.. code-block:: bash

   php infbyte create:notification PasswordChanged
   php infbyte create:notification-channel Sms
   php infbyte create:mail Welcome

Generators create files and do not silently register transports.
The application must select and configure the channels it will use.

Security-sensitive notifications
--------------------------------

For password resets, email verification and passwordless login,
always derive the recipient from an authoritative stored account
identity, not arbitrary request data. Do not place bearer tokens in
unauthenticated JSON responses or logs; deliver through the trusted
configured notification channel.

Production configuration must select a real transport where an
authentication email must reach the recipient. A logging or fake
transport is not equivalent to successful delivery.

See `Foundation authentication
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/authentication.md>`_,
`communication
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/communication.md>`_,
and :doc:`communication`.
