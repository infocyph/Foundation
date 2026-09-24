# Security boundaries

Foundation is the application security composition layer; it is not a
cryptography library.

- Foundation owns authentication orchestration, authorization, browser
  sessions, CSRF, application security policy, environment-file workflows, and
  safe exception responses.
- Epicrypt owns password hashing engines, JWT/payload primitives, AEAD,
  secret/public-key encryption, MACs, signatures, key rings, certificates,
  integrity, and data protection.
- ReqShield owns validation and sanitization mechanics.
- Webrick owns HTTP parsing/routing/middleware behavior.
- Omnibus owns message delivery/serialization/failure semantics.
- Pathwise owns generic filesystem/storage capability behavior.

## Epicrypt activation

Install the canonical `security` module:

```bash
php infbyte module:install security
```

`crypto` and `epicrypt` remain accepted aliases, but `security` is the public
purpose name.

Resolving a native `Infocyph\Epicrypt\...` service through the application
activates the security provider lazily. Foundation does not expose a parallel
SecurityManager/facade or duplicate Epicrypt's generic method surface.

```php
use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Epicrypt\Password\PasswordHashOptions;

$options = $app->make(PasswordHashOptions::class);
$hasher = $app->make(PasswordHasher::class);
$cipher = $app->make(AeadCipher::class);
```

`PasswordHashOptions` and `PasswordHasher` reflect Foundation application policy
under `security.password.*` because authentication consumes those values.
Generic Epicrypt primitives retain their native APIs/configuration.

Foundation deliberately does not publish generic key-ring, MAC, signature,
certificate, integrity, or data-protection config it does not itself consume.

## Password ownership

When `auth.drivers.passwords=security`, Foundation maps application password
policy to Epicrypt. Hashing, verification, rehash detection, algorithm support,
and cost validation remain Epicrypt behavior.

Supported Foundation password-policy algorithms are determined by the current
`security.password.*` config and Epicrypt's supported algorithms. Invalid
configuration fails validation rather than silently changing algorithm/cost.

## Token ownership

When `auth.drivers.tokens=security`, Foundation owns auth claim/purpose mapping
while Epicrypt owns token signing and verification.

Foundation's JWT policy is configured under `security.jwt.*`, including
algorithm, issuer, audience, maximum lifetime, and leeway. The token root is
resolved only at runtime from the environment locator selected by
`auth.token_secret_environment` (default `AUTH_TOKEN_SECRET`). Raw
`auth.token_secret` values are rejected so generated InterMix/release artifacts
contain a locator, never the token root itself. Token-secret/key material must
satisfy the selected Epicrypt/JWT algorithm and production policy.

Foundation auth adapters exist to translate Foundation authentication records
and purposes to Epicrypt primitives; they do not reimplement signing or
verification.

## Application secret generation

Foundation owns the application workflow that creates/rotates authentication
secret material:

```bash
php infbyte secret:generate
php infbyte secret:generate --force
```

The workflow does not print generated secret material. The bootstrap token root
is generated directly from PHP's operating-system CSPRNG and therefore does not
require the optional `security`/Epicrypt module merely to install a lean
application. Application secret resolution remains separate from generic
Epicrypt key management; Foundation does not introduce a generic ID/key/secret
manager merely to proxy library APIs.

## Environment-file protection

Environment-file encryption/decryption delegates protection to Epicrypt while
Foundation owns safe application file orchestration:

```bash
php infbyte env:encrypt --key-file=/secure/env.key
php infbyte env:decrypt --key-file=/secure/env.key
```

Key material may instead come from an external process environment variable
(default `ENV_ENCRYPTION_KEY`, selectable with `--key-env`). There is no literal
`--key=<secret>` option, avoiding shell-history/process-list leakage.

Destination writes are staged, forced replacement is rollback-safe, and
symbolic-link destinations are refused. The encryption key must not live inside
the `.env` file it protects or in `.env.example`. Environment-file
protection is an independent external-only key domain,
`foundation.environment.file.v1`; it is not derived from or shared with the
auth token, MFA, recovery-code, OAuth, or signed-URL roots.

## MFA secret protection and migration

Durable OTP factor secrets are protected at the DBLayer persistence boundary with
Epicrypt `StringProtector` under the independent
`foundation.auth.mfa-secret.v1` domain. Recovery-code HMAC material uses the
separate `foundation.auth.recovery-hmac.v1` lifecycle. Neither domain reuses the
application token, OAuth, environment-file, or signed-URL roots.

Normal operation requires protected values. Legacy plaintext compatibility is a
bounded migration mode only:

1. Provision one active `AUTH_OTP_SECRET_PROTECTION_KEYS` entry and the
   independent `AUTH_OTP_RECOVERY_HMAC_KEY` on every application/worker host.
2. Temporarily set `auth.otp.secret_protection.allow_legacy_plaintext=true`
   only for the migration deployment.
3. Read legacy factors through Foundation and rewrite them through the normal
   factor persistence boundary. New writes are always protected with the active
   key; fallback keys are read-only.
4. Verify durable factor metadata contains `ep2.` protected values and no raw
   OTP secret/MobileOTP PIN material.
5. Set `allow_legacy_plaintext=false`, rebuild generated/runtime artifacts,
   run `php infbyte config:validate --production` and `php infbyte app:ready`,
   then deploy the strict configuration everywhere.
6. Keep an old protection key only as a bounded `fallback` while rows encrypted
   with it still exist. Reprotect only through an explicit successful
   revision-aware write; never let fallback reads overwrite a newer HOTP/OCRA
   counter revision.
7. Remove the fallback key only after the durable-data audit confirms no row
   references it. A strict deployment encountering plaintext, a retired/unknown
   key, malformed metadata, or tampered ciphertext fails closed.

Do not leave legacy plaintext compatibility enabled as a steady-state recovery
mechanism.

## Signed URL key lifecycle

Foundation selects deployment key locators and rotation state while Webrick
remains the sole owner of URL canonicalization, signing, expiry, and
verification. Runtime keys are derived through Epicrypt `KeyDeriver` and
`KeyRing` under `foundation.signed-url.v1`. Exactly one active key writes;
bounded fallback keys remain read-only for verification, and disabled/retired
keys are ineligible. Generated release artifacts retain behavior options and
environment locators only, never resolved signed-URL key material.

## Runtime and process safety

Immutable cryptographic configuration/services may be reused according to their
native contracts. Request, principal, job, and tenant-specific state belongs to
Foundation's execution scope or application-owned durable storage, not mutable
process-wide crypto singletons.

Applications requiring advanced key rotation/key rings should use Epicrypt's
native key APIs with an explicit durable key source.

## Production policy

Production readiness combines Foundation-owned policy with specialist
capability checks. Examples include:

- secure authentication driver selections;
- required secret/token policy;
- OTP replay-state visibility and atomic coordination;
- shared-state/cache topology where deployment-wide coordination is required;
- applicable auth/session/cache schemas;
- writable runtime paths and safe configuration.

Use:

```bash
php infbyte config:validate --production
php infbyte app:ready
```

These checks complement rather than replace deployment security review. The release suite also exercises the Foundation auth atomic-counter adapter and replay stores from independent processes against Redis so distributed lockout/replay policy is verified at the Foundation-to-CacheLayer boundary.

Proxy parsing and forwarded-host/scheme trust remain Webrick/selected-host responsibilities. Foundation consumes the effective Webrick request and verifies its own consequences: CSRF origin policy, secure browser-session cookies, signed-link policy and OAuth redirect/callback validation.

Before release, review authentication/session cookies, trusted proxies/origins,
CSRF, storage/upload roots, database credentials, cache lock placement, message
payload policy, signed URLs, log redaction, environment-key handling, and
runtime filesystem permissions.
