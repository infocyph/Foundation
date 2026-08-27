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

## Deployment and operations

OAuth persistence is an additive revision of Foundation's existing `auth`
schema. A normal 2.1 deployment should preserve existing Foundation 2.0 account,
session, MFA and application-token data.

### Enablement sequence

A production rollout should use this order:

1. Deploy Foundation 2.1 code while OAuth remains disabled. This preserves the
   existing authentication bootstrap and allows the release to be deployed
   without loading OAuth key material.
2. Provision the DBLayer connection, signing-key files and final OAuth
   configuration on the target deployment.
3. In a controlled enablement/canary environment, set `auth.oauth.enabled=true`
   and validate the resolved configuration:

   ```bash
   php infbyte config:validate --production
   ```

4. Inspect and install the additive auth schema revision:

   ```bash
   php infbyte module:schema:status auth
   php infbyte module:schema:install auth
   ```

   `module:schema:sync` may be used when the deployment intentionally syncs all
   schemas required by its resolved configuration.
5. Check the active signing key and full application readiness before traffic:

   ```bash
   php infbyte auth:oauth:key:check
   php infbyte app:ready
   ```

6. Expose the thin application OAuth routes only after schema and key readiness
   are green. Verify metadata/JWKS, one public Authorization Code + S256 flow,
   one confidential flow if used, resource validation, revocation and
   introspection on the canary.
7. Expand traffic only after the canary remains healthy and audit/error telemetry
   shows no unexpected OAuth failures.

Readiness is authoritative. Do not turn a missing schema or signing-key failure
into a warning merely to continue deployment.

### Pruning

Use Foundation's existing authentication pruning command as part of normal
maintenance:

```bash
php infbyte auth:prune
```

OAuth pruning removes expired authorization codes, expired refresh-token rows,
expired access-revocation evidence and sufficiently old revoked consent or
authorization state according to the existing pruning policy. It is idempotent.
Active authorizations and unexpired refresh-token rotation/replay evidence must
not be removed early.

Run pruning as routine retention maintenance, not as a revocation mechanism.
Client, authorization, refresh-family and access-token revocation are explicit
durable protocol operations.

### Canary and rollback

The preferred operational rollback is **disablement, not destructive schema
rollback**:

1. Stop exposing the application OAuth routes or set `auth.oauth.enabled=false`
   in the rollback deployment.
2. Re-run configuration/readiness for the restored application surface.
3. Leave the additive OAuth tables and durable state in place while the incident
   is investigated. Existing Foundation 2.0 auth data remains independent of
   those tables.
4. Roll application code/configuration back to the previously approved release
   if necessary.

A database rollback of the OAuth schema revision is a separate, destructive
maintenance decision. Use it only with an appropriate backup/change window and
only through the schema runner supported by that deployment. It is not part of
the routine 2.1 rollback path. The Foundation integration matrix separately
covers OAuth-revision rollback/re-application and transactional partial-failure
behavior.

For a signing-key incident, preserve any still-trusted fallback public key needed
to validate already-issued tokens. Disabling OAuth immediately is safer than
removing verification keys while bearer tokens may still be in circulation.

## Protocol scope and standards

The release name “OAuth 2.1 extension” describes Foundation's implementation
track; it is not a blanket conformance claim for every OAuth specification or
profile.

At this documentation baseline in August 2026, the IETF OAuth 2.1 authorization
framework is still published as `draft-ietf-oauth-v2-1-15`, not as a final RFC.
Foundation therefore documents the protocol pieces it actually implements and
uses the OAuth 2.1 draft/security guidance as a design input rather than claiming
conformance to a final OAuth 2.1 RFC.

Implemented protocol pieces include:

- OAuth 2.0 authorization-code, client-credentials and refresh-token semantics
  from RFC 6749, constrained by the Foundation 2.1 security policy;
- PKCE S256 from RFC 7636 for Authorization Code clients;
- HTTP bearer-token handling/error semantics from RFC 6750;
- token revocation behavior based on RFC 7009;
- token introspection behavior based on RFC 7662;
- authorization-server metadata fields based on RFC 8414;
- asymmetric JWT access tokens using the RFC 9068 access-token profile;
- the authorization-response `iss` parameter from RFC 9207;
- security choices informed by the OAuth 2.0 Security Best Current Practice,
  RFC 9700, including removal of legacy/insecure grant behavior from this
  surface.

The implementation may intentionally be stricter than a base RFC. Examples are
S256-only PKCE, exact registered redirect matching, bounded inputs, no
`client_secret_post`, durable one-time authorization-code consumption, refresh
rotation/reuse handling and explicit OAuth/application token separation.

Interoperability and conformance execution remain release gates under F7; the
presence of these protocol implementations in source and tests is not itself a
claim that an external conformance suite has passed.

## Unsupported and deferred features

Foundation 2.1 intentionally does **not** provide:

- OpenID Connect: no ID Tokens, UserInfo, OIDC discovery/login semantics or
  generic “Sign in with OAuth/OIDC” application login;
- dynamic client registration;
- OAuth Device Authorization Grant/device flow;
- Resource Owner Password Credentials/password grant;
- implicit grant;
- DPoP proof-of-possession tokens;
- Pushed Authorization Requests (PAR);
- a generic social-login/provider abstraction;
- application-owned replacements for Foundation protocol/crypto/persistence.

Other OAuth extensions are not implicitly supported merely because they can be
combined with OAuth. Additions such as JAR/JARM, richer client-authentication
methods, token exchange, Rich Authorization Requests or new proof-of-possession
profiles require their own design, threat model, configuration, tests and
release decision before they are advertised.

Browser-based clients should also evaluate the current browser OAuth best
practices applicable to their deployment; Foundation's protocol implementation
does not replace client-side/browser architecture guidance.
