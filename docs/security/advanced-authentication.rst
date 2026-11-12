.. _advanced-authentication:

MFA, passkeys and OAuth
=======================

Foundation 4 includes optional multi-factor and federated authentication
capabilities. Their algorithms and protocol engines are implemented by
specialist packages rather than copied into Foundation.

MFA and passkeys
----------------

TOTP/HOTP/OCRA and related factor algorithms belong to
``infocyph/otp``. Foundation owns application enrollment,
authorization, recovery, account relationships and policy.

In a Foundation 4-compatible application:

.. code-block:: bash

   php infbyte module:plan security.mfa
   php infbyte module:install security.mfa
   php infbyte module:install security.crypto

MFA and passkeys are independently selectable; WebAuthn passkeys use
``security.passkey`` and the additional
``web-auth/webauthn-lib`` dependency. Do not install passkey-only
dependencies for OTP-only applications. AOTP also requires
``ext-sodium``.

Replay and state security
-------------------------

Replay-safe OTP challenges, passkey ceremonies and recovery operations
require configured durable state. The deployment must enforce
authenticity, bounded lifetime, atomic consumption and loss-of-state
failure behavior. For persistent hosts, do not cache a caller's factor
state in a process-wide singleton.

OAuth 2.1, OpenID Connect and PATs
----------------------------------

Foundation's opt-in OAuth capability is disabled unless application
configuration explicitly enables it. Foundation owns application/HTTP
integration; Epicrypt owns protocol-neutral token and cryptographic
services. This is not permission to expose an authorization server
without client, key, revocation and persistence configuration.

Operators should validate issuer, audience, scopes, signing-key
rotation and token replay policy before publication.

Read the maintained technical guides for
`OTP and passkeys
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/otp.md>`_
and `OAuth 2.1/OIDC
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/oauth-2.1.md>`_.

See :doc:`authentication` and :doc:`authorization`.
