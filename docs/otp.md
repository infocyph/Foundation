# OTP-backed MFA

Foundation composes `infocyph/otp` into application MFA without copying OTP
algorithms or replay logic into the framework.

## Ownership

Foundation owns:

- account MFA factors and challenge lifecycle;
- enrollment policy and application labels;
- durable factor persistence;
- challenge satisfaction, notifications, and audit events;
- mapping OTP verification results into Foundation MFA results.

OTP owns:

- TOTP, HOTP, and OCRA algorithms;
- Base32 secret generation and validation;
- provisioning URIs and enrollment payloads;
- verification windows and drift results;
- recovery-code generation, normalization, keyed hashing, and consumption
  semantics;
- CacheLayer-backed replay protection.

Select the driver with:

```php
'auth' => [
    'drivers' => [
        'mfa' => 'otp',
    ],
],
```

Install the optional package with:

```bash
php infbyte module:install otp
```

The module targets `infocyph/otp ^6.0`.

## Configuration

Foundation's OTP application policy lives under `auth.otp`:

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

A null replay store selects `cache.default`. An explicit value names a
`cache.stores.*` entry.

Foundation validates this application policy when the OTP provider activates.
OTP continues to validate algorithm availability, Base32 secrets, OCRA suites,
verification inputs, and replay-store security capabilities.

## Replay state

Replay state has two different ownership paths because the algorithms have
different state models.

### TOTP

TOTP verification passes Foundation's selected
`AuthenticationStateCacheInterface` directly to OTP 6.0. OTP performs the
atomic timestep claim and rejects replayed codes.

The selected cache must satisfy OTP's authentication-state contract:

- authoritative direct backend;
- fail closed;
- payload integrity enabled;
- coordinated lock capability.

Foundation does not duplicate those checks or implement a second OTP replay
protocol.

### HOTP

HOTP's authoritative state is the factor's persisted counter. OTP finds the
matching counter and returns `nextCounter`; Foundation atomically commits that
next counter through `MfaFactorCompareAndSwapStoreInterface`.

A concurrent verifier that loses the compare-and-swap is treated as a replay.
There is therefore no second CacheLayer counter for HOTP.

### OCRA

Counter-bearing OCRA uses the same persisted-factor CAS model as HOTP. The
counter is authoritative and does not expire.

Counterless OCRA uses OTP's CacheLayer replay protection. Time-based
counterless suites may use the configured Foundation drift window; replay TTL
must remain long enough for OTP to cover the accepted time window.

Counter-bearing suites use exact-time verification so their successful result
continues to carry the next durable counter.

## Provisioning

`OtpProvisioningService` is a narrow Foundation adapter around OTP's native
enrollment payloads.

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

Each method returns:

- the native OTP `EnrollmentPayload`;
- canonical Foundation factor metadata suitable for `MfaManager::enrollFactor`.

`provision()` remains the default TOTP convenience entry point.

Foundation persists OCRA secrets as canonical Base32 strings, not raw binary
keys.

## Recovery codes

Recovery codes use OTP's native `RecoveryCodes` service. OTP owns:

- random code generation;
- formatting and normalization;
- entropy requirements;
- keyed SHA-256 digests;
- single-use consumption results.

Foundation supplies `OtpRecoveryCodeStore`, an implementation of OTP's native
`RecoveryCodeStoreInterface` backed by the existing MFA factor store.

Only keyed digests are persisted. Plain recovery codes are returned once to the
enrollment caller and are never written to the factor store.

The hidden persistence record:

- uses factor type `recovery_code`;
- is disabled;
- cannot be enrolled, activated, removed, or challenged as a normal factor;
- uses atomic create/update/consume operations through the MFA factor CAS
  contract.

Recovery codes are accepted only while the account still has an enabled real
MFA factor.

Regenerating recovery codes atomically replaces the previous batch.

The recovery-code HMAC key is domain-separated from `AUTH_TOKEN_SECRET`.
Rotating the application token secret therefore deliberately invalidates all
previous recovery codes; regenerate recovery codes after such a rotation.

## Persistence and production

The official memory MFA store supports CAS but is process-local and remains a
development choice. Foundation's production auth policy requires durable
storage; the DBLayer MFA store implements the same CAS contract across
workers/processes.

Every `MfaFactor` carries a non-negative `revision`. New factors start at zero;
Foundation mutations such as activation or metadata/counter updates advance it
by exactly one. The CAS contract is therefore:

- create only when the factor ID is absent and the new revision is `0`;
- update only when the persisted `id + revision` still match the expected
  factor and the replacement carries `expected revision + 1`.

DBLayer uses that scalar revision as the synchronization token. JSON metadata is
payload only and is never compared in SQL, which keeps the CAS path portable
across MySQL, MariaDB, PostgreSQL, SQL Server, and SQLite.

Fresh Foundation auth schemas create the `mfa_factors.revision` column with a
zero default. Existing auth schemas receive it through the dedicated
`20260822000000_foundation_auth_mfa_revision` migration. `auth:schema:status`
reports the schema as incomplete until the required revision column exists.

This prevents concurrent activation, removal, counter advancement, or recovery
state changes from overwriting one another.

Custom MFA factor stores used with the OTP driver must implement
`MfaFactorCompareAndSwapStoreInterface` and honor the same revision semantics.

## Persistent workers

The Foundation OTP integration keeps no request-, account-, or challenge-state
inside singleton service properties. OTP services are immutable/stateless;
durable mutable state lives in:

- the MFA factor store for HOTP/OCRA counters and recovery-code digests;
- CacheLayer for TOTP and counterless OCRA replay state;
- Foundation's normal challenge/satisfaction stores for application MFA
  lifecycle.

This makes the integration safe for persistent Web, Worker, CLI, and Scheduler
runtimes when the selected backing stores themselves are appropriate for those
runtimes.

## Direct OTP use

Foundation does not globally construct `TOTP`, `HOTP`, or `OCRA` because each
instance requires factor-specific secret material. Applications needing OTP
outside Foundation authentication should instantiate those native OTP classes
directly.

Foundation only exposes the configured native `RecoveryCodes` service and its
store contract through lazy OTP-provider activation because those objects are
application-level services rather than per-factor algorithm instances.
