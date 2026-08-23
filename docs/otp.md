# OTP-backed MFA

Foundation composes `infocyph/otp ^6.0` into application MFA without copying
OTP algorithms or replay mechanics into the framework.

OTP is an implementation inside Foundation's canonical `auth` module; there is
no standalone public OTP module.

## Ownership

Foundation owns application concerns:

- account MFA-factor/challenge lifecycle;
- enrollment policy/application labels;
- factor persistence and CAS integration;
- challenge satisfaction, notification, and audit mapping;
- mapping OTP verification results into Foundation MFA results.

OTP owns specialist behavior:

- TOTP, HOTP, and OCRA algorithms;
- Base32 secret generation/validation;
- provisioning URIs/enrollment payloads;
- verification windows/drift results;
- recovery-code generation, normalization, keyed hashing, and consumption
  semantics;
- native replay-protection integration.

Select OTP MFA with:

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

The auth module also contains the optional WebAuthn package. Runtime readiness is
implementation-specific: OTP MFA requires OTP, not WebAuthn, unless passkeys are
also configured.

## Configuration

Foundation OTP application policy lives under `auth.otp`:

```php
'otp' => [
    'issuer' => 'Foundation',
    'hotp' => [
        'look_ahead' => 5,
    ],
    'totp' => [
        'algorithm' => 'sha1',
        'digits' => 6,
        'period' => 30,
        'secret_bytes' => 20,
        'window' => 1,
    ],
    'recovery_codes' => [
        'count' => 10,
        'length' => 12,
    ],
    'replay' => [
        'store' => null,
        'ttl' => 90,
    ],
],
```

A null replay store selects `cache.default`; an explicit value names a
`cache.stores.*` entry.

Foundation validates application ranges and deployment policy. OTP remains the
owner of algorithm availability, Base32/OCRA input validation, verification
semantics, and native replay primitives.

## Replay/state models

OTP modes have different authoritative state, so Foundation does not force them
into one generic counter/replay abstraction.

### TOTP

Foundation passes the selected CacheLayer authentication-state store to OTP.
OTP performs the atomic timestep claim and rejects replayed codes.

Production configuration must provide state visibility and coordination suitable
for the deployment topology. `config:validate --production` and `app:ready`
check Foundation's application/deployment policy around that store.

### HOTP

HOTP's authoritative state is the persisted factor counter. OTP returns the
matching/next counter; Foundation commits the transition atomically through
`MfaFactorCompareAndSwapStoreInterface`.

A verifier that loses the compare-and-swap cannot overwrite newer counter state.
Foundation does not maintain a second CacheLayer counter for HOTP.

### OCRA

Counter-bearing OCRA uses the same durable factor-CAS model as HOTP.
Counterless OCRA uses OTP replay protection; time-based accepted windows require
replay TTL sufficient for the accepted time range.

## Provisioning

`OtpProvisioningService` is Foundation's narrow application adapter over OTP's
native enrollment payloads:

```php
use Infocyph\Foundation\Auth\Adapter\Otp\OtpProvisioningService;

$otp = $app->make(OtpProvisioningService::class);

$totp = $otp->provisionTotp('account-id', 'user@example.com', withQrSvg: true);
$hotp = $otp->provisionHotp('account-id', 'user@example.com');
$ocra = $otp->provisionOcra(
    'account-id',
    'OCRA-1:HOTP-SHA256-6:QN08-T1M',
    'user@example.com',
);
```

The adapter returns native OTP enrollment information plus Foundation factor
metadata suitable for the application MFA workflow. Foundation persists OCRA
secrets in its canonical encoded form rather than creating another OTP secret
format.

## Recovery codes

Recovery-code cryptography stays in OTP. Foundation supplies
`OtpRecoveryCodeStore`, an implementation of OTP's native recovery-code storage
contract backed by the Foundation MFA factor store.

Only digests are persisted. Plain recovery codes are returned to the enrollment
caller and must not be written into application persistence/logging.

Recovery-code state uses the same factor CAS boundary so regeneration and
single-use consumption cannot silently overwrite concurrent factor updates.

## Durable factor CAS

Every `MfaFactor` carries a non-negative scalar `revision`. The portable CAS
contract is:

- create only when the factor ID is absent and the new revision is zero;
- update only when persisted `id + revision` match the expected factor;
- replacement carries exactly the next revision.

DBLayer uses that scalar revision as synchronization state. JSON metadata remains
payload and is never the SQL CAS token, keeping the persistence model portable
across supported SQL drivers.

When durable auth storage is selected, inspect/install the current Foundation
auth schema through the canonical schema commands:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
```

`module:schema:sync` also provisions it when current configuration requires the
auth schema. Readiness reports a missing required MFA revision column as not
ready.

Custom factor stores used with OTP must honor the same
`MfaFactorCompareAndSwapStoreInterface` semantics.

## Persistent runtimes

Foundation's OTP composition does not keep account/challenge mutable state in
singleton service properties. Mutable state lives in explicit stores:

- factor persistence for HOTP/OCRA counters and recovery-code state;
- CacheLayer for TOTP/counterless-OCRA replay claims;
- Foundation challenge/satisfaction stores for application MFA lifecycle.

This is the required model for reusable Web/Worker/CLI/Scheduler application
instances: execution-specific state remains scoped or durable, not ambient
process state.

## Direct OTP use

Applications needing OTP outside Foundation authentication should use OTP's
native `TOTP`, `HOTP`, `OCRA`, provisioning, and recovery-code APIs directly.
Foundation does not add a generic OTP facade or duplicate those algorithms.

## Production checks

Use:

```bash
php infbyte config:validate --production
php infbyte app:ready
```

These validate Foundation application/deployment policy around OTP state in
addition to OTP's own specialist input/algorithm validation. Full concurrency,
backend, persistent-worker, and failure-path verification remains part of the
dedicated release matrix rather than an implied documentation guarantee.
