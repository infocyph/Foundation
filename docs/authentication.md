# Authentication and authorization

Foundation is the canonical owner of application authentication. It composes
storage, cryptography, OTP, passkey, notification, cache, and identifier
packages without moving their infrastructure behavior into Foundation.

## Activation and lifecycle

Authentication is not part of the global web path. Foundation activates its
auth provider only when application code requests an auth service or a selected
route uses an auth middleware alias.

The `resolve-auth` middleware resolves the configured principal sources. The
`auth`, `guest`, `verified`, `mfa`, `recent`, `role`, `permission`, and `policy`
aliases consume that request principal. The `web-auth` route preset applies
browser sessions and CSRF before principal resolution and authorization.

Current-principal state is isolated between Fibers and restored in a `finally`
block after successful or failed request handling. A persistent worker may
therefore reuse one application instance without carrying a principal from one
request or Fiber into another.

## Typed lazy services

`Application::auth()` returns the typed `AuthServices` gateway. Resolving the
gateway does not construct every authentication capability. Each accessor
resolves only its selected graph:

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
impersonation, step-up checks, password services, and the mutable application
gate. Requesting one accessor does not resolve its siblings.

## Accounts and login

Repository code may create accounts through the account manager or persist an
`AccountInterface` through the configured storage contract:

```php
$auth = $app->auth();
$hash = $auth->passwordHasher()->hash($plainPassword);
$created = $auth->accounts()->create($email, $hash);

$result = $auth->authenticator()->login(
    new LoginRequest($email, $plainPassword),
);

if (!$result->authenticated()) {
    // Map the explicit result status without exposing credential details.
}
```

Authentication sessions are identity/security records used by login and
principal resolution. They are distinct from Foundation browser sessions,
which own cookie-backed application state, flash data, and CSRF tokens.

## Authorization

The configured authorizer evaluates, in order:

1. explicit gate callbacks;
2. a resolved policy when the application has registered a
   `PolicyResolverInterface`;
3. direct permissions, role permissions, and delegated grants.

Denied decisions are recorded through the configured auth audit store.

```php
$permission = $auth->permissions()->create('invoice.read');
$auth->permissions()->assignToAccount($accountId, $permission->id);

$decision = $auth->authorizer()->can($principal, 'invoice.read');

$auth->gate()->define(
    'invoice.approve',
    static fn (PrincipalInterface $principal): bool => $principal->id() === $ownerId,
);
```

Do not perform hidden database access from policy properties. Load required
repository projections before authorization and pass the explicit resource to
the authorizer.

## Optional drivers

The final `auth.*` configuration selects behavior; infrastructure configuration
remains in its owning file:

- `memory` storage requires no database.
- `database` storage uses the selected DBLayer connection.
- `array` cache uses process-local stores.
- `cache` uses the configured CacheLayer stores and atomic counters.
- `native` passwords and `simple` tokens are development-capable built-ins.
- `security` passwords/tokens use Epicrypt.
- `simple` MFA is intended for controlled development/testing.
- `otp` MFA uses the OTP module.
- `memory`, `disabled`, and `webauthn` select the passkey implementation.
- `collect` records notifications in memory; `talkingbytes` maps auth events to
  TalkingBytes email and selects the sender named by `notifications.auth.sender`.

When the password driver is `security`, Foundation adapts the same native
Epicrypt `PasswordHasher` exposed by the crypto module; it does not construct a
second hashing engine. `security.password.*` selects the Epicrypt hash options.

When the token driver is `security`, Foundation owns auth claim/purpose mapping
and Epicrypt owns JWT signing and verification. `security.jwt.algorithm`
explicitly selects `HS256`, `HS384`, or `HS512`; corresponding raw
`auth.token_secret` minima are 32, 48, and 64 bytes. Issuer and audience are
required. See [Security boundaries](security.md) for the complete crypto
ownership model.

When the MFA driver is `otp`, Foundation owns factor/challenge persistence and
OTP owns TOTP, HOTP, OCRA, provisioning payloads, verification semantics, and
recovery-code mechanics. TOTP and counterless OCRA use a secure CacheLayer
authentication-state store for replay claims; HOTP and counter-bearing OCRA use
atomic persisted-factor counter advancement. Recovery-code digests are stored
through the same durable MFA factor persistence boundary rather than an OTP
process-local store. See [OTP-backed MFA](otp.md) for configuration,
provisioning, replay, concurrency, and recovery-code details.

TalkingBytes email transport definitions and reusable sender policies live under
`notifications.email.*`; authentication does not own an SMTP/spool transport
stack. In production, a TalkingBytes auth sender may deliver through SMTP,
sendmail, spool, PHP mail, or deliberately log, but it may not resolve to the
`null` or `fake` transport drivers.

Production validation rejects development-only driver combinations. Optional
packages are installed through `module:install` and remain outside unrelated
routes and console commands.

## Verification

The Foundation test suite covers the default complete credential, token,
authorization, MFA, passkey, device, impersonation, and step-up lifecycles,
including Fiber isolation and failure cleanup. The benchmark suite contains
separate login, authorization, token, MFA, passkey, and successful
authenticated-request workloads.

Use application-level benchmarks with production password costs, database/cache
drivers, OPcache, and representative concurrency before selecting operational
budgets.
