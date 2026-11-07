# Authentication and authorization

Foundation owns application authentication composition. It combines storage,
cryptography, OTP, passkey, notification, cache, and identifier capabilities
without moving specialist package engines into Foundation.

## Activation and lifecycle

Authentication is not part of every Web request. Foundation activates auth
services only when application code resolves them or selected route middleware
requires them.

The `resolve-auth` middleware resolves configured principal sources. The
`auth`, `guest`, `verified`, `mfa`, `recent`, `role`, `permission`, and `policy`
aliases consume that request principal. The `web-auth` route preset applies
browser sessions and CSRF before principal resolution and authorization.

Current-principal state participates in the Foundation execution boundary and is
restored/cleared in `finally`, preventing persistent runtimes from carrying one
execution's principal into the next.

## Typed lazy services

`Application` remains a runtime/composition object rather than an auth facade.
Resolve the typed `AuthServices` gateway through DI:

```php
use Infocyph\Foundation\Auth\AuthServices;

$auth = $app->make(AuthServices::class);

$accounts = $auth->accounts();
$login = $auth->authenticator();
$sessions = $auth->sessions();
$tokens = $auth->tokens();
$mfa = $auth->mfa();
$passkeys = $auth->passkeys();
$authorizer = $auth->authorizer();
```

Resolving the gateway does not intentionally construct every authentication
capability. Additional accessors cover password reset/change/passwordless flows,
email verification, remember tokens, roles, permissions, delegation, devices,
impersonation, step-up checks, password services, and the application gate.

## Accounts and login

```php
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;

$auth = $app->make(AuthServices::class);
$hash = $auth->passwordHasher()->hash($plainPassword);
$created = $auth->accounts()->create($email, $hash);

$result = $auth->authenticator()->login(
    new LoginRequest($email, $plainPassword),
);
```

Authentication sessions are identity/security records used by login and
principal resolution. They are distinct from Foundation browser sessions,
which own cookie-backed application state, flash data, and CSRF tokens.

Foundation deliberately does not couple those two session domains. At an
application privilege boundary—successful login, impersonation start/stop,
account switch or another transition that changes the browser's authenticated
authority—the web application must explicitly call
`BrowserSession::regenerate()` before returning the response. Logout or a
transition that must discard browser state may use `invalidate()`. Rotation
checks current lock ownership before deleting the previous record, so a stale
request cannot remove newer session state.

## Email verification trust boundary

The HTTP-facing `AuthActions::requestEmailVerification()` only issues a
verification token to the account's stored email identity. A supplied `email`
field must match that stored address; for username-based accounts, the canonical
address may come from stored account `metadata.email`. Mismatches and missing
trusted destinations return `EmailVerificationStatus::INVALID` with
`email_destination_untrusted` and do not issue a token.

```php
use Infocyph\Foundation\Auth\Http\AuthActions;

$actions = $app->make(AuthActions::class);
$result = $actions->requestEmailVerification(['account_id' => $accountId]);

if ($result->failed()) {
    // Handle the invalid destination without exposing a token.
}
```

The domain-level `EmailVerificationManager::issue()` independently requires
the destination to match the target account's stored email identity. Its
constructor receives both the account write store and a separately bound
`AccountProviderInterface` for that authoritative lookup. Custom constructor
callers must pass the account provider after the account store. An arbitrary new
address is not a supported verification purpose: a privileged email-change
feature needs a separate authenticated update and pending-address contract,
not a `context.email` override. The TalkingBytes notifier independently checks
the `EMAIL_VERIFICATION_REQUESTED` notification's explicit destination against
the account's **current stored address** immediately before sending. Directly
constructing a notification with a different address, or an unknown account,
fails closed even if a caller bypasses the domain manager. The notifier
only treats an identifier as a stored email when it is a bare validated address;
a username formatted as `Display Name <address>` must not shadow the
canonical `metadata.email` field even though the underlying email library
also supports display-name mailboxes for normal message composition. This is
verification of an existing address, **not** authorization for an email-address
change.

During token redemption, the manager also requires a destination-conditional
`AccountStoreInterface::markVerifiedForEmail($accountId, $email, $verifiedAt)`
operation. Unlike the administrative `markVerified()` operation, this method
must atomically compare the currently persisted email destination at its write
boundary and return `false` without changing the account when it has changed.
The one-time token's `consumeAndApply()` and account mutation must participate
in the same transaction; a failed compare aborts consumption with
`email_destination_untrusted`. Built-in in-memory and DBLayer account stores
implement this contract. Custom stores implementing `AccountStoreInterface`
must implement the conditional operation with equivalent atomicity; a separate
read followed by unconditional account update is not sufficient. Database
adapters must preserve the original stored JSON representation when comparing
username-account email metadata, including differences across SQL dialects.

This low-level action does **not** authenticate or authorize the HTTP caller.
Applications must bind account identity to the authenticated principal and apply
appropriate rate limits. A privileged address-change flow needs a separate,
explicit account update contract; verifying a newly supplied arbitrary address
must not mark a different stored account address verified. Do not expose issued
token values directly to unauthenticated HTTP clients.

The HTTP-facing `AuthActions::requestEmailVerification()`,
`requestPasswordReset()` and `requestPasswordless()` return outcome records
with `token: null` even after successful delivery. This prevents accidental
JSON serialization of newly issued bearer tokens. The configured notifier still
delivers the token to its authoritative recipient; trusted domain code calling
`AuthServices::emailVerification()->issue()`, `passwordResets()->issue()`
or `passwordless()->issue()` retains the actual token for controlled workflows.
Never serialize those trusted-domain issuance results in unauthenticated HTTP
handlers. The action helpers do not themselves authenticate the caller; bind
account ownership and rate limits at the application route.

Passwordless login links are single-use. `PasswordlessManager::issue()` records
an expiring, domain-separated SHA3-256 token fingerprint before notifying the recipient; neither
the raw token nor its claims are stored in the redemption marker. `verify()`
first verifies the native token's signature, purpose and expiry, then atomically
pulls that marker. Only the winning redemption succeeds. Replayed tokens,
missing/evicted markers and tokens issued directly by the stateless token
adapter return `INVALID`; expired tokens retain the native `EXPIRED` result.
Invalid input does not consume another valid link, and storage errors propagate
without reporting successful issuance or redemption.

Use `AuthServices::passwordless()` for issuance and redemption. Direct
`PasswordlessManager` construction now requires the auth `TtlStoreInterface`
and matching `auth.passwordless_ttl` in addition to the token service and
notifier. Custom TTL stores must implement atomic `pull()` at their advertised
visibility. The default array store is process-local; PHP/FPM requests and
multiple workers need a shared persistent auth cache with native atomic
consumption. With `auth.drivers.cache=cache`, retain the existing shared-state
topology and `cache.default_counter` configuration requirements. Cache loss
invalidates outstanding links, so request a replacement. Links issued before
this change have no redemption marker and must also be reissued. These
contracts apply with or without a live Runwire context.

Audit metadata is descriptive, not an identity source. Auth managers do not promote
free-form request context to canonical audit session or device fields. Verified
record-owned token devices remain authoritative; unbound token-family revocation
cannot ascribe an account from `context.account_id`. Pass verified session and
device IDs explicitly when recording an auth audit event; caller-provided
`metadata.session_id` and `metadata.device_id` must not become canonical IDs.

Credential-family matching is conservative across casing, separators and
compound metadata names, including PIN/OTP and PKCE code verifiers. The explicit
safe identifier fields `token_id`, `token_type`, `credential_id` and
`authorization_id` remain descriptive identifiers rather than credentials.

## Authorization

The configured authorizer evaluates application gate/policy/permission/grant
state according to the registered auth services. Generic persistence remains in
its configured storage implementation.

```php
$permission = $auth->permissions()->create('invoice.read');
$auth->permissions()->assignToAccount($accountId, $permission->id);

$decision = $auth->authorizer()->can($principal, 'invoice.read');
```

Load required repository projections explicitly before authorization rather
than hiding database work in policy properties.

## Purpose-first optional modules

Authentication is part of Core. Select optional implementations explicitly:

```bash
php infbyte module:install security.mfa
php infbyte module:install security.crypto
php infbyte module:install security.passkey
```

OTP-driver MFA uses OTP 7.0.1 and requires the selected Epicrypt-backed
`security.crypto` feature. Passkeys require OTP 7.0.1 and WebAuthn 5.3.12 independently
of MFA. Install `data.database` for DBLayer persistence and
`execution.communication` for TalkingBytes delivery. CacheLayer is required Core
infrastructure. See [module selection](modules.md).

## Driver ownership

The final `auth.*` configuration selects application behavior while
infrastructure stays in its owning capability:

- `memory` storage requires no database;
- `database` storage uses DBLayer;
- process-local auth cache requires no CacheLayer;
- shared `cache` state uses CacheLayer;
- `native` passwords use PHP password APIs; `simple` tokens are development-only;
- `security` password/token behavior uses Epicrypt;
- `simple` MFA is development/testing-oriented;
- `otp` MFA uses the OTP package;
- passkeys may be disabled/memory-backed or use WebAuthn;
- collected notifications are local application behavior, while TalkingBytes
  delivery belongs to `communication`.

Foundation never constructs a second password, token, OTP, WebAuthn, cache,
database, or email transport engine merely to expose a framework-prefixed API.

## Security-backed passwords and tokens

When password or token drivers select `security`, Foundation applies application
auth policy and Epicrypt owns the underlying cryptographic implementation.

`security.password.*` selects Epicrypt password-hash options. JWT configuration
under `security.jwt.*` selects supported signing policy; secret/key material must
meet the requirements of the chosen algorithm and deployment.

See [Security boundaries](security.md).

## OTP-backed MFA

When `auth.drivers.mfa=otp`, Foundation owns factor/challenge persistence and
maps application state to OTP 7.0.1 primitives. OTP owns TOTP, HOTP, OCRA,
provisioning payloads, verification semantics, and recovery-code cryptography.

Replay/counter state is coordinated according to the OTP mode and Foundation's
configured durable state. Production validation checks that replay protection
has suitable state visibility and atomic coordination for the deployment
topology.

See [OTP-backed MFA](otp.md).

## Passkeys

When passkeys select WebAuthn, Foundation owns the application workflow and
credential persistence boundary while `web-auth/webauthn-lib` owns WebAuthn
protocol validation. Package presence alone does not activate passkey services.

## Notifications

Foundation authentication can emit application notifications through its
notification contracts. TalkingBytes remains the owner of inbound/outbound email
and transport mechanics.

Reusable email sender profiles live under `notifications.email.*` and are
activated only when communication-backed delivery is selected.

## Durable auth schema

When `auth.drivers.storage=database`, inspect/install the Foundation-owned auth
schema through its Core provisioning service inside a CLI execution:

```php
$app->execution()->run(static function () use ($app): void {
    $schema = $app->make(\Infocyph\Foundation\Database\AuthSchema\AuthSchemaInstaller::class);
    $schema->install();
    $readiness = $schema->readiness();
});
```

Core auth schema provisioning is separate from optional feature schema commands.

The schema includes accounts, authentication sessions, consumable verification
requests, remember/refresh tokens, MFA factors, passkey credentials,
authorization records, devices, audit records, and lockouts. MFA factors include
a scalar `revision` used for portable compare-and-swap updates. Passkeys retain
the full opaque credential ID and use a unique SHA3-256 `credential_id_hash` index
for portable, case-sensitive lookup. Configure DBLayer `security.max_param_bytes`
for bounded credential-record and audit JSON sizes; the native guard remains active.

Expired/revoked state can be pruned explicitly:

```bash
php infbyte auth:prune
php infbyte auth:prune --retention-hours=24
php infbyte auth:prune --connection=primary
```

## Production validation

```bash
php infbyte config:validate --production
php infbyte app:ready
```

Production validation rejects development-only/inadequate state combinations
and applies OTP replay-topology validation when OTP MFA is active.

## Verification

The Foundation suite covers credential/authorization flows, MFA/passkey replay
and persistence boundaries, OAuth rotation/revocation, browser-session fixation
rotation, shared-store contention, Fiber/persistent-runtime isolation and
secret-safe error output. The release evidence record identifies the exact
candidate workflow that qualified those source contracts.
