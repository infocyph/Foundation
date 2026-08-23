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
algorithm, issuer, audience, maximum lifetime, and leeway. Token-secret/key
material must satisfy the selected Epicrypt/JWT algorithm and production policy.

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

The workflow does not print generated secret material. Application secret
resolution remains separate from generic Epicrypt key management; Foundation
does not introduce a generic ID/key/secret manager merely to proxy library APIs.

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
the `.env` file it protects or in `.env.example`.

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

These checks complement rather than replace deployment security review.

Before release, review authentication/session cookies, trusted proxies/origins,
CSRF, storage/upload roots, database credentials, cache lock placement, message
payload policy, signed URLs, log redaction, environment-key handling, and
runtime filesystem permissions.
