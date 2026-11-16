.. _advanced-authentication:

MFA, passkeys and federated identity in Infbyte
===============================================

Infbyte's Core authentication policy lives in **your application's
``config/auth.php``**, which ships with the Infbyte 4 candidate.
Optional native packages add mechanisms for OTP, passkeys and
cryptographic/token operations. Installing a library is not the
same as enabling a secure factor or complete login ceremony.

This chapter describes the **Foundation/Infbyte 4 development
candidate**. It is not a promise that a production-ready MFA
enrollment route, passkey UI or OAuth authorization server ships
in the Core starter.

Choose the actual security goal
-------------------------------

.. list-table::
   :header-rows: 1
   :widths: 28 37 35

   * - Goal
     - Selected feature
     - Native owner
   * - Stronger password/token cryptography
     - ``security.crypto``
     - Epicrypt
   * - TOTP/HOTP challenge and recovery
     - ``security.mfa``; crypto also required for selected OTP driver
     - OTP + Foundation factor state/policy
   * - WebAuthn/passkey ceremony
     - ``security.passkey``
     - Native WebAuthn package and Foundation account policy
   * - External OAuth/OIDC/PAT workflow
     - Explicit Core auth configuration and supported optional services
     - Foundation identity integration and native crypto/storage

Do not install every optional capability to support one
authentication method. A passkey selection does not
automatically enable the OTP MFA feature, even when their
native package dependency graphs overlap.

Start from the Infbyte auth configuration
-----------------------------------------

The Infbyte candidate ships **``my-app/config/auth.php``**
with driver choices:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'drivers' => [
           'storage' => env_string('AUTH_STORAGE', 'memory'),
           'cache' => env_string('AUTH_CACHE', 'array'),
           'passwords' => env_string('AUTH_PASSWORDS', 'native'),
           'tokens' => env_string('AUTH_TOKENS', 'simple'),
           'mfa' => env_string('AUTH_MFA', 'simple'),
           'passkey' => env_string('AUTH_PASSKEY', 'memory'),
       ],
   ];

This is an **illustrative subset**, not the complete
Infbyte config: the shipped file also contains
``token_secret_environment``, ``otp`` and
``webauthn`` settings. Keep the full original file.

The Core defaults are useful for basic local and
test scenarios but should not be mistaken for
cross-host persistent factor storage. A process-local
memory store cannot be the sole authority for
production passkeys, replay counters or recovery use.

Step 1 — plan and select OTP MFA
--------------------------------

From your Infbyte application root:

.. code-block:: bash

   php infbyte module:plan security.mfa
   php infbyte module:plan security.crypto
   php infbyte module:install security.mfa
   php infbyte module:install security.crypto
   php infbyte module:enable security.mfa
   php infbyte module:enable security.crypto
   php infbyte module:doctor security.mfa

Review the planner's declared feature dependencies and
package state before changing the active driver. Set
``AUTH_MFA=otp`` only when the relevant cryptographic
and durable factor/replay services are actually ready.

The OTP mechanism belongs to ``infocyph/otp``;
Foundation owns account linkage, enrollment,
challenge issuance, recovery and authorization.
A selected OTP driver does not install a working
out-of-band delivery service.

Step 2 — configure OTP lifetime and replay
------------------------------------------

The shipped ``config/auth.php`` has an ``otp`` section:

* ``otp.issuer`` identifies the application displayed by authenticator apps.
* ``otp.totp.algorithm``, ``digits``, ``period``
  and ``window`` select the accepted TOTP policy.
* ``otp.hotp.look_ahead`` bounds counter drift for HOTP.
* ``otp.recovery_codes.count`` and ``length`` control issued backups.
* ``otp.replay.store`` and ``ttl`` select replay protection state.

The candidate defaults to SHA-1, six TOTP digits,
a 30-second period and a one-step verification window
for compatibility with ordinary authenticator apps;
security still depends on secret entropy, challenge
binding, replay prevention, rate limits and account policy.

If using a multi-worker or PHP/FPM deployment, configure
a **shared atomic** replay backend. An ephemeral
``AUTH_CACHE=array`` is not a production replay store.
When the backend is unavailable, fail closed for
security-sensitive redemption rather than granting
access or silently accepting a second code.

Step 3 — use the typed factor service
-------------------------------------

The application's ``AuthServices`` gateway offers
``mfa()`` returning a native ``MfaManager``:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);
   $mfa = $auth->mfa();

   $result = $mfa->verifyChallenge(
       $challengeId,
       $submittedCode,
   );

The code above is a **request-context excerpt**.
``$challengeId`` and ``$submittedCode`` must come from
a server-created, time-bounded challenge and a validated
client response. You must authenticate the account and
bind the issued challenge to its intended account,
purpose and execution context before using this method.

The native manager also exposes ``enrollFactor()``,
``activateFactor()``, ``removeFactor()``,
``verifyRecoveryCode()`` and ``isSatisfied()``.

Factor enrollment and removal are privilege-changing
operations. Require an authenticated principal and
appropriate step-up proof; never trust an arbitrary
account identifier from the request body. A successful
factor change should produce auditable account
events and appropriate session policy.

Recovery codes must be one-time and stored/verifiable
using the selected security mechanism. Display them
only through a secure authenticated channel at issue
time, not repeatedly in account APIs or logs.

Step 4 — choose passkeys independently
--------------------------------------

For a WebAuthn application:

.. code-block:: bash

   php infbyte module:plan security.passkey
   php infbyte module:install security.passkey
   php infbyte module:enable security.passkey
   php infbyte module:doctor security.passkey

The selected feature brings in the native WebAuthn
support defined by the current Foundation catalog.
Do **not** additionally enable OTP MFA unless your
application actually offers it.

Choose the candidate's
``config/auth.php`` ``webauthn`` settings:

.. list-table::
   :header-rows: 1
   :widths: 34 33 33

   * - Config key
     - Meaning
     - Production rule
   * - ``rp_id``
     - Relying party identifier
     - Must match the expected website domain
   * - ``origin``
     - Allowed browser origin
     - Exact HTTPS origin for deployed UI
   * - ``rp_name``
     - Display name
     - Match public application identity
   * - ``user_verification``
     - Authenticator UV policy
     - Match account risk/assurance requirements
   * - ``resident_key``
     - Resident/discoverable credential preference
     - Match intended login UX
   * - ``attestation``
     - Attestation policy
     - Only collect what your threat model needs

Do not accept a WebAuthn assertion simply because
the browser returns a JSON object. Verify the signed
ceremony with the selected native library, challenge,
origin, RP ID, credential identity, sign count and
user-verification policy.

Step 5 — use the passkey manager correctly
------------------------------------------

After authenticating the account and establishing the
appropriate enrollment session, the native manager
can begin registration:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);

   $outcome = $auth->passkeys()->startRegistration(
       $verifiedAccountId,
   );

``$verifiedAccountId`` must be the authoritative
account identifier, not arbitrary browser input.
``startRegistration()`` produces an application
outcome, **not a complete browser-facing HTTP
response or a newly stored credential by itself**.

Completing the ceremony requires an actual validated
``PasskeyRegistrationResult`` from the native WebAuthn
verification flow, passed to ``finishRegistration()``.
Authentication similarly uses
``startAuthentication()`` and
``finishAuthentication()`` with a validated result.
Credential revocation uses ``revokeCredential()``.

Keep registration/authentication challenges short-lived,
single-use and bound to the intended account and
origin. Audit successful and rejected ceremonies.
Persistent workers must not retain an earlier
user's pending challenge or principal.

OAuth, OIDC and personal access tokens
--------------------------------------

Foundation has application-facing OAuth/OIDC/PAT
integration, but the selected deployment must
explicitly configure issuer, audience, client,
signing keys, scopes, persistence and revocation.
The mere presence of ``AuthServices::oauth()``
does not create a publicly deployable identity server.

Prefer narrow scopes and short lifetimes for
bearer tokens. Rotate signing keys through the
selected secure key storage; carefully bound any
verification overlap during rotation.

For concrete OAuth 2.1/OIDC behavior, use the
maintained Foundation technical guide and
the actual selected native crypto dependencies.
Do not copy example client secrets to source control.

Rate limits, step-up and incident response
------------------------------------------

A secure factor workflow needs more than code
verification:

* Apply rate limits and account lockouts to login,
  verification and recovery endpoints.
* Require step-up before factor removal, credential
  replacement or privileged account changes.
* Detect lost or reused challenges; never grant
  access because replay state is unavailable.
* Separate account auth sessions from browser
  cookie session storage and CSRF.
* Protect recovery codes, passkey IDs and OTP
  seeds as sensitive data.
* Ensure a compromised device can be revoked
  without disabling unrelated credential records.
* Revoke/rotate affected sessions and tokens
  after credible account compromise.

Test the real failures
----------------------

Test the selected Infbyte application, not just
the underlying native package:

1. Correct factor on the account/purpose it was issued for.
2. Wrong account, wrong challenge or wrong OTP code.
3. Already consumed challenge/recovery code.
4. Expired code or invalid timestamp window.
5. Store unavailable or lock lease lost across two workers.
6. Passkey origin/RP ID mismatch or invalid signature.
7. Credential revoked between initiation and completion.
8. Persistent process serving a different user afterward.

A fake backend can check the application's intended
calls; it does not prove the actual shared replay
store, WebAuthn/browser ceremony or production HTTPS
origin handling.

Common mistakes
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 34 37

   * - Symptom
     - Likely cause
     - First check
   * - OTP driver unavailable
     - Selected optional feature or dependency absent
     - ``module:doctor security.mfa`` and crypto
   * - Same OTP accepted twice
     - No shared atomic replay state
     - ``otp.replay.store`` and real backend
   * - Passkey registration cannot finish
     - RP/origin/challenge mismatch
     - ``auth.webauthn`` and signed ceremony
   * - Recovery succeeds after it was used
     - Non-atomic marker or stale state
     - Shared recovery store and transaction policy
   * - Works in one local PHP process only
     - Memory-backed factor state
     - Selected durable/shared auth backend
   * - Token accepted after logout
     - Revocation or audience/expiry policy missing
     - Token store, issuer and verifier contract

Read :doc:`authentication`,
:doc:`authorization`,
:doc:`sessions-csrf` and
:doc:`production`.
For deeper implementation contracts, see the
Foundation source technical references:

https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/otp.md

https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/oauth-2.1.md
