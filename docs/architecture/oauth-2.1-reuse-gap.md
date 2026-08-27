# OAuth 2.1 Reuse-Gap Record

This record freezes the Foundation 2.0 authentication boundaries that Foundation 2.1 OAuth server work must preserve. It is a Phase 0/1 implementation gate, not an OAuth protocol design replacement.

## Compatibility baseline

Foundation OAuth extends the existing `Auth` domain. Existing accounts, sessions, MFA, application access tokens, application refresh tokens, principals, roles, permissions, gates, audit storage, and notifications keep their released semantics.

In particular, `Authentication\\TokenAuth\\AccessTokenClaims` remains the application-token claim type. OAuth-only issuer, audience, client, authorization, token-use, and RFC 9068 semantics must live in an OAuth-specific claim type.

## Reuse decisions

### Principal resolution: reuse

`RequestPrincipalResolver` already accepts a named resolver map and a configured resolver order. An OAuth bearer resolver can therefore join the existing request-principal chain without modifying the resolver contract or changing legacy resolver precedence when OAuth is disabled.

Decision: reuse `PrincipalResolverInterface`, `RequestPrincipalResolver`, `CurrentPrincipalContext`, and the existing `PrincipalInterface` pipeline. Register the OAuth resolver only when `auth.oauth.enabled` is true.

### Audit: reuse

`AuditEventStoreInterface` records the existing `AuthEvent` model. OAuth security events can be represented as additive auth event types with bounded, non-secret metadata.

Decision: reuse the existing audit sink and correlation model. Never create an OAuth-specific audit subsystem.

### Authorization: reuse at an explicit scope boundary

OAuth scopes are client/resource-server delegation constraints; Foundation permissions remain application authorization constraints. OAuth services may map a validated scope to a required Foundation permission, but scope possession must never synthesize a Foundation permission.

Decision: reuse existing principals, `AuthorizerInterface`, gates, roles, and permissions after OAuth scope validation.

### Epicrypt asymmetric JWT and JWKS: reuse

Epicrypt already provides asymmetric JWT issuance/verification, explicit algorithm policy, `kid` handling, key-ring verification, key-strength validation, and JWKS public-key export.

Decision: reuse Epicrypt primitives behind a narrow OAuth access-token service. Do not use the existing symmetric application-token factory and do not add a third-party OAuth server dependency for cryptography.

### Application refresh-token lifecycle: do not reuse for OAuth rotation

The released `RefreshTokenStoreInterface::rotate()` returns `void`. The DBLayer implementation marks the current token rotated and saves the replacement in two separate store operations. This does not provide an atomic compare-and-rotate result capable of proving exactly one successful redemption under concurrent OAuth refresh requests.

Changing the released store contract or strengthening its semantics in place would alter Foundation 2.0 application-token behavior, which the 2.1 compatibility commitment forbids.

Decision: add an OAuth-specific refresh persistence contract under `Auth\\OAuth` with an atomic rotation operation. Reuse existing account/device identifiers and security primitives, but keep OAuth authorization/scope/audience/client binding in the OAuth record. DBLayer implementations must enforce the rotation transaction and return an explicit success/reuse outcome.

## Required OAuth-specific boundaries justified by Phase 1

The following additions are justified rather than speculative:

- OAuth access-token claims and issuer/verifier service, because RFC 9068 semantics must not widen `AccessTokenClaims`.
- OAuth refresh-token record/store/coordinator, because the existing rotation contract cannot prove the concurrency invariant.
- OAuth client, authorization, consent, authorization-code, and revocation stores, because these are protocol state not represented by existing auth tables.
- OAuth bearer principal resolver, because existing application bearer tokens must remain distinct.

No new account model, principal context, permission engine, audit sink, notification system, top-level service provider, or module is justified.

## Gates before protocol routes

Before `routes/oauth.php` is introduced in Infbyte:

1. OAuth configuration must default disabled and register no OAuth runtime services when disabled.
2. Authorization-code consumption and OAuth refresh rotation must have database-atomic one-success concurrency tests.
3. OAuth access tokens must be asymmetrically signed and discriminated from application access tokens.
4. Resolver precedence tests must prove existing session/application-bearer/remember behavior is unchanged when OAuth is disabled.
5. Key readiness and JWKS behavior must fail closed for missing, unknown, incompatible, or expired-retirement keys.
