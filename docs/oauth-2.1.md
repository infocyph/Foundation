# OAuth 2.1 extension

Foundation 2.1 adds an opt-in OAuth authorization-server and resource-token
capability. It is disabled by default. When `auth.oauth.enabled=false`, OAuth
stores, protocol services, signing-key loading, OAuth bearer resolution and the
OAuth schema revision remain inactive; the Foundation 2.0 authentication surface
keeps its existing resolver order and behavior.

This guide describes the Foundation-owned OAuth implementation and the thin
application integration boundary expected by Infbyte.

## Configuration

OAuth configuration lives under `auth.oauth`.

```php
'auth' => [
    'oauth' => [
        'enabled' => true,
        'issuer' => 'https://identity.example.com',

        // Seconds.
        'access_token_ttl' => 300,
        'authorization_code_ttl' => 60,
        'refresh_token_ttl' => 1209600,

        'grants' => [
            'authorization_code',
            'client_credentials',
            'refresh_token',
        ],
        'pkce_methods' => ['S256'],

        'resource_audiences' => [
            'https://api.example.com',
        ],
        'scope_permissions' => [
            'profile.read' => 'profile.read',
            'orders.read' => 'orders.read',
        ],

        'signing' => [
            'algorithm' => 'RS256',
            'active_key_id' => 'oauth-2026-08',
            'private_key' => 'storage/keys/oauth-2026-08-private.pem',
            'public_keys' => [
                [
                    'id' => 'oauth-2026-08',
                    'path' => 'storage/keys/oauth-2026-08-public.pem',
                    'status' => 'active',
                ],
                [
                    'id' => 'oauth-2026-07',
                    'path' => 'storage/keys/oauth-2026-07-public.pem',
                    'status' => 'fallback',
                ],
            ],
        ],

        'routes' => [
            'authorization' => '/oauth/authorize',
            'token' => '/oauth/token',
            'revocation' => '/oauth/revoke',
            'introspection' => '/oauth/introspect',
            'jwks' => '/.well-known/jwks.json',
        ],

        'rate_limits' => [
            'authorization' => ['max' => 60, 'window' => 60],
            'token' => ['max' => 30, 'window' => 60],
            'revocation' => ['max' => 60, 'window' => 60],
            'introspection' => ['max' => 120, 'window' => 60],
        ],
    ],
],
```

`issuer` must be a valid URL and must use HTTPS in production. Query and
fragment components are not permitted. OAuth also requires a configured default
DBLayer connection because protocol state is authoritative in the database.

The three token/code TTL settings are expressed in **seconds**.
`authorization_code_ttl` may not exceed 60 seconds. The default access-token TTL
is 300 seconds, authorization-code TTL is 60 seconds, and refresh-token TTL is
1,209,600 seconds.

`resource_audiences` is the allow-list of resource-server audience identifiers.
A client may only receive audiences allowed by its registration and by this
server policy. Keep audience identifiers stable and specific to the resource
that validates the token.

`scope_permissions` maps OAuth scope names to the existing Foundation permission
names used by the authorization layer. A scope does not create an alternate
permission system: account principals produced from OAuth still pass through the
normal Foundation authorizer and middleware semantics.

`routes` are local absolute paths only. They must be unique and must not contain
a scheme, host, query or fragment. Foundation owns the protocol handlers and
response construction; an application may mount these paths through thin route
adapters without reimplementing protocol behavior.

The configured rate-limit `window` values are seconds. Rate limiting is a
protective policy; correctness, code one-time use, refresh rotation and
revocation do not depend on cache availability.

## Signing keys

OAuth access tokens use a signing-key set that is separate from Foundation
application access-token security.

`auth.oauth.signing.private_key` is a deployment-owned **file locator**, not PEM
content. Relative paths are resolved from `app.base_path`; absolute paths are
also accepted. The active private key is never exposed through JWKS.

`auth.oauth.signing.public_keys` is a non-empty ordered list of maps. Each entry
contains:

- `id`: a unique Base64URL-safe key id, 1-128 characters;
- `path`: a deployment-owned public-key file locator;
- `status`: `active` or `fallback`;
- optional `not_before` and `not_after`: positive Unix timestamps limiting when
  the key may be selected for verification.

Exactly one public key must be `active`, and its `id` must equal
`active_key_id`. Fallback public keys remain available for verification and JWKS
publication while tokens signed by the previous key can still be valid.

At readiness resolution Foundation verifies that the configured active private
key and active public key form a working signing pair and that the public key set
can be exported as JWKS. Key locator paths and private material are treated as
sensitive diagnostics.

### Rotation procedure

Use an overlap window rather than replacing the only verification key:

1. Provision a new private/public key pair outside the repository.
2. Add the new public key as `active`, change the previous active public key to
   `fallback`, and point `active_key_id` and `private_key` at the new pair.
3. Deploy the configuration/key files together and run normal configuration and
   application readiness checks before receiving traffic.
4. Confirm JWKS exposes both the new active public key and the fallback key.
5. Keep the fallback key until every token that could have been signed with it
   has expired, including configured clock/leeway and deployment overlap.
6. Remove the old fallback key in a later deployment.

Do not delete the previous public key in the same deployment that starts signing
with the replacement key.

## OAuth clients and secrets

Foundation owns client registration, enable/disable state and secret rotation.
The bounded administration commands are:

```text
auth:oauth:client:create
auth:oauth:client:list
auth:oauth:client:show
auth:oauth:client:rotate-secret
auth:oauth:client:enable
auth:oauth:client:disable
auth:oauth:authorization:list
auth:oauth:authorization:revoke
auth:oauth:key:check
```

A generated or rotated confidential-client secret is intentionally returned only
at the administration operation that creates it. Capture that value immediately
into the deployment's secret manager and deliver it to the client through an
appropriate secret channel. List/show, audits, logs and error output must not be
used to recover a raw secret later.

Public clients do not have a client secret. Authorization Code clients must use
PKCE S256; `plain`, password grant, implicit grant and credential transport in
query parameters are not accepted by this Foundation surface.

## Foundation and Infbyte ownership

Foundation owns reusable OAuth mechanics:

- request/parameter validation and client authentication policy;
- exact redirect validation, PKCE, consent and authorization-code lifecycle;
- client, authorization, code, refresh-token and revocation persistence;
- access-token signing/verification, JWKS, metadata, revocation and
  introspection;
- OAuth audit events, pruning hooks, rate-limit policy adapters and CLI
  administration;
- OAuth bearer principal resolution and scope/audience middleware;
- protocol-oriented HTTP handlers and response construction.

Infbyte must remain a thin presentation/integration layer. After Foundation is
released, Infbyte may provide opt-in route declarations, authorization/consent
presentation, application configuration and integration/deployment tests. It
must not duplicate Foundation crypto, OAuth persistence, token parsing, grant
logic or protocol error handling in application controllers.

## OAuth bearer versus application bearer

OAuth access tokens and existing Foundation application access tokens are
separate security domains even though both are carried using the HTTP `Bearer`
scheme.

Foundation application access tokens use the existing application token service
and application bearer resolver. OAuth access tokens use the asymmetric OAuth
access-token profile, OAuth issuer/audience policy, OAuth authorization state and
durable OAuth revocation stores. The two token types are intentionally rejected
by the opposite resolver.

Do not interchange these tokens, share validators between them, or make an
application bearer token satisfy OAuth scope/audience policy. When OAuth is
enabled, its bearer resolver is additive to the existing resolver chain rather
than a replacement for Foundation authentication.
