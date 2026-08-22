# Security boundaries

Foundation is the application security composition layer; it is not a
cryptography library.

- Foundation owns authentication orchestration, authorization, browser
  sessions, CSRF, application security policy, secret-file workflows, and safe
  exception responses.
- Epicrypt owns password hashing engines, JWT and payload primitives, AEAD,
  secret/public-key encryption, MACs, signatures, key rings, certificates,
  integrity, and data protection.
- ReqShield owns request validation and sanitization.
- Webrick owns HTTP parsing, signed routes, middleware, and emission.
- Omnibus owns message serialization limits and delivery semantics.
- Pathwise owns filesystem and upload policy.

## Epicrypt activation

Install the optional crypto module with:

```bash
php infbyte module:install crypto
```

Resolving a native `Infocyph\Epicrypt\...` service through the application
activates the crypto provider lazily. Foundation does not provide a parallel
`SecurityManager` or duplicate Epicrypt method surface.

The configured password engine is available natively:

```php
use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Epicrypt\Password\PasswordHashOptions;

$options = $app->make(PasswordHashOptions::class);
$hasher = $app->make(PasswordHasher::class);
$cipher = $app->make(AeadCipher::class);
```

`PasswordHashOptions` and `PasswordHasher` reflect `security.password` because
Foundation authentication consumes that application policy. `AeadCipher`
uses Epicrypt's native secure default unless the application supplies an
explicit container binding. Other Epicrypt primitives retain their native
constructors/configuration and may be bound explicitly by the application when
custom policy is required.

Foundation deliberately does not publish generic key-ring, integrity, MAC,
signature, certificate, or data-protection configuration that it does not
consume itself.

## Password ownership

When `auth.drivers.passwords` is `security`, Foundation adapts the configured
native Epicrypt `PasswordHasher` to its authentication contracts. Hashing,
verification, rehash detection, algorithm availability, and cost bounds remain
Epicrypt behavior.

`security.password.algorithm` accepts:

- `argon2id` (default)
- `argon2i`
- `bcrypt`

Argon memory/time/thread bounds and bcrypt cost validation are enforced by
Epicrypt. Invalid Foundation configuration is rejected rather than silently
replaced with defaults.

## Token ownership

When `auth.drivers.tokens` is `security`, Foundation owns the meanings of its
authentication claims and token purposes while Epicrypt owns JWT issuance and
verification.

Foundation supplies these JWT policy values:

- `security.jwt.algorithm`: `HS256`, `HS384`, or `HS512`; default `HS256`.
- `security.jwt.issuer`: required for the security token driver.
- `security.jwt.audience`: required for the security token driver.
- `security.jwt.maximum_lifetime_seconds`: positive integer.
- `security.jwt.leeway_seconds`: non-negative integer.

The configured algorithm is passed explicitly to Epicrypt. Minimum raw secret
lengths are therefore deterministic:

| Algorithm | Minimum `auth.token_secret` |
| --- | ---: |
| `HS256` | 32 bytes |
| `HS384` | 48 bytes |
| `HS512` | 64 bytes |

`secret:generate`/application installation writes a random 64-character
hexadecimal token secret, which is 64 ASCII bytes and therefore satisfies all
three policies. Production rejects Foundation's development placeholder.

Access, refresh, password-reset, passwordless-login, and email-verification
adapters remain Foundation code because they translate Foundation auth claim
objects and purpose semantics to/from Epicrypt token primitives. They do not
reimplement signing or verification.

## Application secret workflow

`EnvironmentSecretManager` remains Foundation-owned because it is an
application filesystem/configuration workflow. It creates or rotates
`AUTH_TOKEN_SECRET` in `.env`, rejects symlink targets, writes through a
restricted temporary file, atomically renames it, applies `0600` permissions,
and invalidates the compiled configuration cache.

`auth.token_secret` remains an explicit application override. Because an
explicit config value is application configuration, it can be included in a
compiled config cache; applications that do not want that should use the
recommended `AUTH_TOKEN_SECRET` environment path. Foundation does not copy
`AUTH_TOKEN_SECRET` into its defaults or compiled configuration. The auth
secret resolver reads the process environment when the token service is
actually resolved. If a compiled configuration skipped normal `.env`
hydration, it lazily invokes Foundation's ArrayKit-backed `EnvironmentLoader`
at that point. Production readiness uses the same lookup rule. Consequently,
the recommended environment-secret path stays outside config caches and
unrelated cached requests do not pay for an additional environment-file parse.

The random-byte primitive used to generate the secret is native PHP; no wrapper
is introduced solely to call `random_bytes()`.

## Runtime and process safety

The configured Epicrypt password options, password hasher, and default AEAD
cipher are immutable process-safe services and may be reused as singletons.
Foundation auth token adapters are also stateless; request/job/principal state
continues to live inside the canonical execution scope rather than crypto
objects.

Do not store tenant-, request-, user-, or message-specific keys in mutable
application singletons. Applications requiring key rotation or key rings
should use Epicrypt's native key-ring APIs and their own durable key source.

## Broader security boundary

Optional providers remain lazy. Public stateless routes must not resolve auth
or browser-session services. Auth middleware is the activation boundary for
principals; session/CSRF middleware is the activation boundary for stateful
browser requests.

Production readiness rejects insecure auth-driver choices and missing secrets,
reports pending migrations and missing database-session schema, validates
cache topology, and reports unwritable runtime paths. It complements rather
than replaces a deployment review.

Keep exception messages and traces disabled unless operationally required.
Structured logging recursively redacts configured key fragments. Queue and
config caches must contain serializable explicit definitions—never closures or
secrets that should stay outside compiled artifacts.

Before release, review authentication, session cookies, trusted origins and
proxies, CSRF, upload roots, message aliases and payload limits, database
credentials, cache lock placement, signed URLs, log redaction, and runtime
permissions.
