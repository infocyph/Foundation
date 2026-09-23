# OTP-backed MFA and passkeys

Foundation composes `infocyph/otp ^6.1` into application authentication without
copying OTP algorithms, replay mechanics, or WebAuthn ceremony behavior into the
framework.

OTP is an implementation inside Foundation's canonical `auth` module; there is
no standalone public OTP module.

## Ownership

Foundation owns application concerns:

- account-to-factor and account-to-passkey relationships;
- enrollment, activation, removal, revocation, lockout, and recovery policy;
- durable factor/passkey persistence and revision compare-and-swap;
- challenge satisfaction, notification, audit, and session authorization;
- mapping OTP results into Foundation authentication results.

OTP 6.1 owns specialist behavior:

- TOTP, HOTP, OCRA, AOTP, GridOTP, and legacy MobileOTP algorithms;
- OTP verification windows/drift results and replay primitives;
- recovery-code generation, normalization, keyed hashing, and consumption;
- Passkey/WebAuthn option creation, serializer/validator wiring, ceremony state,
  origin/RP-ID validation, replay detection, and authoritative WebAuthn
  `CredentialRecord` updates.

Foundation does not maintain another WebAuthn validator/runtime above OTP.
`web-auth/webauthn-lib` remains the optional specialist dependency used by OTP
when the WebAuthn passkey driver is selected.

## Selecting OTP MFA

```php
'auth' => [
    'drivers' => [
        'mfa' => 'otp',
    ],
],
```

Install the `auth` capability when the optional implementation is not already
available:

```bash
php infbyte module:install auth
```

OTP MFA and WebAuthn passkeys are independently selectable. TOTP/HOTP/OCRA do
not require `web-auth/webauthn-lib`; AOTP requires `ext-sodium`.

## Authentication-state storage

Stateful OTP modes and passkey ceremonies require a CacheLayer store implementing
`AuthenticationStateCacheInterface`. Foundation resolves the configured store
and fails closed when that capability is unavailable.

For OTP replay/challenge state:

```php
'auth' => [
    'otp' => [
        'replay' => [
            'store' => 'auth-state',
        ],
    ],
],
```

For passkey ceremony state:

```php
'auth' => [
    'passkey' => [
        'state' => [
            'store' => 'auth-state',
        ],
    ],
],
```

A null store selection falls back to `cache.default`. Production storage must be
fail-closed, integrity protected, and coordinated for the deployment topology.

## TOTP

TOTP remains Foundation's default OTP enrollment workflow. OTP generates and
validates the secret/provisioning payload and atomically advances replay state in
the selected authentication-state cache.

```php
$otp = $app->make(Infocyph\Foundation\Auth\Otp\OtpManager::class);

$enrollment = $otp->beginEnrollment(
    accountId: 'account-1',
    label: 'user@example.com',
    withQrSvg: true,
);
```

A disabled factor is created first. `completeEnrollment()` verifies the initial
OTP through OTP's native replay-safe verifier and atomically activates the
persisted factor revision.

## HOTP and OCRA

HOTP's authoritative state is the persisted factor counter. OTP returns the
matching/next counter; Foundation commits the transition through
`MfaFactorCompareAndSwapStoreInterface`.

Counter-bearing OCRA uses the same durable CAS model. Counterless/time-based OCRA
uses OTP's CacheLayer replay protection instead. Foundation does not maintain a
second counter or replay implementation.

## AOTP

AOTP is an Ed25519 challenge/response factor owned cryptographically by OTP.
Foundation stores **only the public key**. The private key must be generated and
held by the user's device/application and must never be submitted to Foundation.

```php
use Infocyph\OTP\AOTP;

// Device-side provisioning code:
$keyPair = AOTP::generateKeyPair();

// Send only $keyPair->publicKey to the Foundation application.
$enrollment = $otp->enrollAotp(
    accountId: 'account-1',
    publicKey: $keyPair->publicKey,
    audience: 'example.com',
);
```

Foundation can issue an enrollment challenge with
`issueAotpEnrollmentChallenge()`. The device signs that native OTP challenge
with `AOTP::respond()`, then Foundation verifies it through
`completeAotpEnrollment()` before activation.

For normal application MFA, `issueAotpChallenge()` embeds OTP's native
`AotpChallenge` in Foundation's existing application challenge envelope. The
response submitted to `MfaManager::verifyChallenge()` is the JSON representation
of OTP's `AotpResponse`.

## GridOTP

GridOTP is exposed deliberately rather than treated as another TOTP variant.
Foundation generates the GridOTP secret through OTP, persists the authoritative
factor state, and returns the secret only in `GridOtpEnrollmentResult` so the
caller can provision it once.

```php
$enrollment = $otp->enrollGridOtp('account-1');
$secret = $enrollment->secret; // sensitive provisioning output
```

The generic enrollment/audit context redacts that secret. Activation uses
`issueGridEnrollmentChallenge()` plus `completeGridEnrollment()`. Normal MFA uses
`issueGridChallenge()` and Foundation's existing challenge verification path.
OTP owns the grid mapping, challenge positions, attempt budget, expiration, and
replay state.

## MobileOTP

MobileOTP is exposed **only as legacy compatibility**. New deployments should
prefer TOTP, AOTP, passkeys, or another current factor.

```php
$enrollment = $otp->importLegacyMobileOtp(
    accountId: 'account-1',
    secret: $legacySecret,
    pin: $legacyPin,
);
```

Imported factors are explicitly persisted with `legacy=true`. Their secret/PIN
are redacted from generic enrollment and audit context. OTP still owns the
legacy algorithm and replay-window verification.

## Recovery codes

Recovery-code cryptography stays in OTP. Foundation supplies
`OtpRecoveryCodeStore`, backed by the Foundation MFA factor store.

Only digests are persisted. Plain recovery codes are returned once to the
enrollment caller and must not be written into application persistence/logging.
Regeneration and consumption use the same factor revision/CAS boundary.

## Durable factor CAS

Every `MfaFactor` carries a non-negative scalar `revision`. Creation and
activation now use the atomic `MfaFactorCompareAndSwapStoreInterface` contract:

- create only when the factor ID is absent and revision is zero;
- update only when persisted `id + revision` match the expected factor;
- replacement advances the revision exactly once.

HOTP/counter-OCRA counter transitions use the same rule. A stale verifier cannot
overwrite newer factor state.

Sensitive factor metadata is persisted only where required for verification; it
is redacted from enrollment/audit context. Protection of durable symmetric MFA
secrets at rest is completed by Foundation's Epicrypt integration policy in
plan point 26.10 rather than by inventing encryption inside the OTP adapter.

## Passkey / WebAuthn

Select OTP-backed passkeys with:

```php
'auth' => [
    'drivers' => [
        'passkey' => 'webauthn',
    ],
    'webauthn' => [
        'rp_id' => 'example.com',
        'origin' => 'https://example.com',
        'challenge_ttl' => 300,
        'allow_subdomains' => false,
    ],
],
```

Foundation intentionally exposes only application/deployment inputs OTP needs:
RP ID, trusted origin, ceremony TTL, and optional subdomain policy. User
verification, discoverable registration, supported public-key algorithms,
attestation behavior, serializer setup, and WebAuthn validators stay OTP-owned.

Registration uses trusted Foundation account data and an opaque stable user
handle. OTP stores the complete ceremony options server-side; the browser never
supplies authoritative ceremony state back to Foundation.

After registration OTP returns a serialized WebAuthn `CredentialRecord`.
Foundation persists that value in the dedicated `credential_record` column.
After **every successful assertion**, OTP returns the updated authoritative
record and Foundation replaces it atomically only when the stored passkey
revision still equals the revision that was verified. A concurrent/stale
assertion therefore cannot overwrite newer authenticator state.

Passkey private keys never enter Foundation or OTP server storage; they remain in
the platform authenticator/security key.

## Auth schema

When durable auth storage is selected, inspect/install the current schema through
the canonical commands:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
```

The passkey schema includes independent `revision` and `credential_record`
columns. Existing installs receive additive migrations and readiness reports
missing columns as not ready.

## Persistent runtimes

Foundation's OTP composition keeps execution-specific mutable state out of
singletons:

- factor persistence owns HOTP/OCRA counters and durable MFA metadata;
- CacheLayer owns replay/challenge/ceremony state;
- Foundation challenge/satisfaction stores own application MFA lifecycle;
- DBLayer CAS owns durable stale-write rejection.

This model applies across reusable Web, Worker, CLI, and Scheduler application
instances.

## Direct OTP use

Applications needing OTP outside Foundation authentication should use OTP's
native `TOTP`, `HOTP`, `OCRA`, `AOTP`, `GridOTP`, `MobileOTP`, `Passkey`,
provisioning, and recovery-code APIs directly. Foundation does not add a generic
OTP facade or duplicate those specialist algorithms.

## Production checks

Use:

```bash
php infbyte config:validate --production
php infbyte app:ready
```

These validate Foundation application/deployment policy around OTP and passkey
state. OTP remains responsible for specialist input, algorithm, ceremony, and
cryptographic correctness.
