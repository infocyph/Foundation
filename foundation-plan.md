# Foundation OAuth 2.1 Work Tracker

Branch: `feature/oauth-2.1`
Target: Foundation `2.1.0`
Source plan: OAuth 2 Extension Plan
Tracker started from: `89939c1aca33a842694ef571de6b2a30770cee59`

This is the running source of truth. Mark an item complete only after committed implementation/test/docs evidence exists. Runtime/release execution evidence stays under F7.

## Workflow
- [x] Create this tracker before remaining work.
- [ ] Keep OAuth disabled by default and preserve Foundation 2.0 public auth behavior.
- [ ] Do not mark release/test gates complete without executable evidence.
- [ ] Do not begin Infbyte integration until Foundation F1-F8 is complete.

## Completed before tracker creation
- [x] Baseline application-token compatibility and additive OAuth resolver behavior.
- [x] OAuth refresh atomicity/reuse-gap record.
- [x] Additive OAuth schema revision and conditional registration.
- [x] OAuth protocol/value/client/grant/auth-method types and distinct access-token contract.
- [x] DBLayer OAuth clients/redirects/scopes/codes/consents/authorizations/refresh/revocation stores.
- [x] Atomic refresh rotation/family-reuse and one-time authorization-code consume.
- [x] Epicrypt asymmetric signing, verification, key resolver/readiness and JWKS.
- [x] Client, redirect, secret, scope/audience, consent, authorization and PKCE S256 managers.
- [x] Authorization-code, client-credentials and refresh token exchange.
- [x] Durable resource validation/revocation/introspection, OAuth principals, scope/audience middleware.
- [x] Conditional registrar, `OAuthManager`, `AuthServices::oauth()` and configuration/readiness integration.
- [x] OAuth audit sanitization, OAuth-aware pruning, client registration/regrant integrity, operational error handling.

## F1 — CLI administration
- [x] Bounded client administration commands.
- [x] Authorization list/revoke commands.
- [x] Signing-key check command without private material/locator leakage.
- [x] Non-interactive options, one-time secret output, stable exit codes.
- [x] CLI command tests.

Evidence: `f49924f`, `a730d97`, `898b129`, `b07e82f`. Execution remains F7.

## F2 — Reusable OAuth HTTP protocol boundary
- [x] Strict bounded form/query parsing and duplicate/content-type rejection.
- [x] Safe `client_secret_basic`; reject query credentials and `client_secret_post`.
- [x] Metadata/JWKS/token/revocation/introspection HTTP adapters.
- [x] Safe authorization success/error redirects with unchanged `state` and RFC 9207 `iss`.
- [x] Redirect errors only after redirect validation.
- [x] No-store/no-cache on token-like responses.
- [x] RFC 6750 bearer error handling.
- [x] Existing-policy rate limiting for OAuth surfaces.
- [x] HTTP malformed/duplicate/oversized/content-type/downgrade tests.

Evidence: `4469d97`, `11a003c`, `0b9ef34`, `222dc60`, `1762ccb`, `0183705`, `688b3e1`, `9867759`, `484f192`, `2aae12b`, `83e7d41`, `30283a3`, `61d4f6a`. Execution remains F7.

## F3 — Security/audit/operational closure
- [x] Audit lifecycle boundaries: denial, code outcomes, refresh outcomes, authorization revoke, invalid redirect/scope, rate limit, signing-key readiness/selection.
- [x] Sensitive-output tests for secrets/codes/tokens/PKCE/private keys/Authorization headers/key locators.
- [x] Persistent request/principal OAuth metadata reset isolation.
- [x] Existing auth/verified/MFA/recent/role/permission/policy semantics for OAuth principals.
- [x] Idempotent pruning with active authorization and replay-evidence retention.

Evidence: `520d0b6`, `64aa5b0`, `246db78`, `604af3b`, `c11c0ae`, `87a306c` plus existing audit/revocation tests. Execution remains F7.

## F4 — Persistence, migration, and concurrency closure
- [x] Schema install/status/upgrade from released Foundation 2.0 auth state.
- [x] Rollback/partial-failure coverage supported by DBLayer runner.
- [x] Simultaneous authorization-code redemption: exactly one success.
- [x] Simultaneous refresh redemption: exactly one rotation and family revoke on replay.
- [x] Durable revocation survives fresh process/store instances without cache correctness.
- [x] Consent regrant after revoke and client registration atomicity integration tests.

Evidence: `f13ba53`, `7146bfb`, `99fca7d`, `d806cbe`, `f315660`, `1f9d9e9`. Execution remains F7.

## F5 — Protocol and compatibility test matrix
- [x] Update existing OAuth access-token tests for current signing-key-set constructor and trusted-audience `verify(token, audience)` contract.
- [ ] Public Authorization Code + S256 success flow.
- [ ] Confidential Authorization Code + S256 success flow.
- [ ] Client Credentials success flow.
- [ ] Refresh equal/narrowed-scope success; widened-scope rejection; rotated-token reuse handling.
- [ ] Metadata/JWKS correctness and key rotation/fallback/unknown-kid behavior.
- [ ] Access-token durable revocation and access/refresh introspection active/inactive states.
- [ ] OAuth bearer -> existing account/service principal -> existing authorization middleware/gates.
- [ ] OAuth/application bearer semantic separation in both directions.
- [ ] Disabled OAuth leaves resolver order/bindings/bootstrap/schema/key loading unchanged.
- [ ] Rejection matrix: client/redirect/PKCE/code/grant/response/auth-method/scope/issuer/audience/token-use/algorithm/signature/kid/time/revocation/account/client/authorization/malformed failures.

Access-token contract evidence: `b36d54f60ba3143c3cc6dd70601ffbaedf585220` updates the tests to `OAuthSigningKeySet` plus explicit trusted-audience verification and fixes issued `scope` to the RFC-style space-delimited claim expected by the verifier. Execution remains F7.

## F6 — Documentation and operations
- [ ] Document `auth.oauth` configuration: disabled behavior, `resource_audiences`, `scope_permissions`, route policy, TTL units, public-key list format.
- [ ] Document signing-key provisioning/rotation and one-time client-secret handling.
- [ ] Document Foundation/Infbyte ownership boundary and OAuth/application-token separation.
- [ ] Document schema deployment, readiness, pruning, canary and rollback procedures.
- [ ] Document implemented RFC claims accurately; do not claim generic final OAuth 2.1 RFC compliance while relying on draft guidance.
- [ ] Document unsupported/deferred features: OIDC, dynamic registration, device flow, password/implicit grants, generic OAuth login, DPoP/PAR/etc.

## F7 — Performance and engineering gates
- [ ] Extend benchmark framework with OAuth-disabled and OAuth workloads.
- [ ] Record environment and representative baseline/candidate measurements.
- [ ] Prove OAuth-disabled existing-path median regression within measured acceptance budget.
- [ ] Run persistent-worker repeated-request/reset and available soak coverage.
- [ ] Run Composer validation/audit/runtime compatibility.
- [ ] Run PHPForge static/style/refactor/complexity/architecture/duplicate/comment gates.
- [ ] Run complete Pest suite with no unexpected skips.
- [ ] Run available OAuth interoperability/conformance coverage.
- [ ] No suppressions/baselines/exclusions/weakened checks/raised limits to obtain green gates.

## F8 — Foundation release handoff
- [ ] Resolve OAuth-introduced gate failures.
- [ ] Re-run full Foundation release guard.
- [ ] Ensure tracker has no unchecked Foundation implementation work except explicitly documented unavailable external/multi-node evidence.
- [ ] Prepare Foundation `2.1.0` release notes and immutable release boundary.
- [ ] Release/tag Foundation `2.1.0` only after green evidence.

## Deferred until Foundation completion — I1 Infbyte integration
- [ ] Keep Infbyte untouched while F1-F8 are incomplete.
- [ ] After Foundation release, add disabled-default OAuth application config/routes/thin presentation hooks/integration tests/deployment closure.

## Resume point
Last completed checkpoint: **F5.1 — current OAuth access-token signing and trusted-audience contract**.
Implementation/test commit: `b36d54f60ba3143c3cc6dd70601ffbaedf585220`.
Current active task: **F5 — Protocol and compatibility test matrix**.
First unchecked action: Public Authorization Code + S256 success flow.
Execution evidence: implementation/test files are committed but not yet run in this environment; suite/release evidence remains under F7.
