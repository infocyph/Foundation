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

`Application::auth()` returns the typed `AuthServices` gateway. Resolving the
gateway does not construct every authentication capability:

```php
$auth = $app->auth();

$accounts = $auth->accounts();
$login = $auth->authenticator();
$sessions = $auth->sessions();
$tokens = $auth->tokens();
$mfa = $auth->mfa();
$passkeys = $auth->passkeys();
$authorizer = $auth->authorizer();
```

Additional accessors cover password reset/change/passwordless flows, email
verification, remember tokens, roles, permissions, delegation, devices,
impersonation, step-up checks, password services, and the application gate. One
accessor does not intentionally resolve unrelated siblings.

## Accounts and login

```php
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;

$auth = $app->auth();
$hash = $auth->passwordHasher()->hash($plainPassword);
$created = $auth->accounts()->create($email, $hash);

$result = $auth->authenticator()->login(
    new LoginRequest($email, $plainPassword),
);
```

Authentication sessions are identity/security records used by login and
principal resolution. They are distinct from Foundation browser sessions,
which own cookie-backed application state, flash data, and CSRF tokens.

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

Authentication is one public `auth` capability. There are no standalone public
OTP or passkey modules.

```bash
php infbyte module:show auth
php infbyte module:install auth
```

The module bundle contains:

- `infocyph/otp ^6.0`
- `web-auth/webauthn-lib ^5.3.5`

Runtime readiness remains implementation-specific: selecting OTP MFA requires
OTP; selecting WebAuthn passkeys requires WebAuthn. One does not make the other
mandatory unless both behaviors are configured.

Other selected auth drivers may require the canonical `cache`, `database`,
`security`, or `communication` modules.

## Driver ownership

The final `auth.*` configuration selects application behavior while
infrastructure stays in its owning capability:

- `memory` storage requires no database;
- `database` storage uses DBLayer;
- process-local auth cache requires no CacheLayer;
- shared `cache` state uses CacheLayer;
- `native` password/token behavior remains Foundation-owned baseline behavior;
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
maps application state to OTP 6.0 primitives. OTP owns TOTP, HOTP, OCRA,
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
schema with the canonical module schema commands:

```bash
php infbyte module:schema:status auth
php infbyte module:schema:install auth
```

`module:schema:sync` also provisions the auth schema when current configuration
requires database-backed auth.

The schema includes accounts, authentication sessions, consumable verification
requests, remember/refresh tokens, MFA factors, passkey credentials,
authorization records, devices, audit records, and lockouts. MFA factors include
a scalar `revision` used for portable compare-and-swap updates.

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

## Verification phase

Credential, authorization, MFA, passkey, persistence, concurrency, Fiber,
persistent-runtime, and failure-path coverage belong in the dedicated deferred
Foundation release matrix. The documented contracts describe intended current
behavior; they do not assert that the full release-candidate test matrix has
already been executed.
