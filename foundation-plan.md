# Foundation OAuth 2.1 Work Tracker

Branch: `feature/oauth-2.1`
Target: Foundation `2.1.0`
Source plan: OAuth 2 Extension Plan
Tracker started from branch head: `89939c1aca33a842694ef571de6b2a30770cee59`

This file is the running source of truth for the remaining Foundation OAuth 2.1 implementation. A checkbox is marked complete only after the corresponding code/tests/docs work is committed with evidence. Update this file in the same completion commit or in the immediately following tracker commit so the repository always exposes the current resume point.

## Workflow

- [x] Create this tracker before beginning the remaining work.
- [ ] Keep OAuth disabled by default and preserve Foundation 2.0 public auth behavior.
- [ ] Do not mark release/test gates complete without executable evidence.
- [ ] Do not begin Infbyte integration until the Foundation surface and release gates are complete.

## Completed before tracker creation

These items are already implemented on this branch and were re-verified from the current branch before the tracker was created.

- [x] Baseline compatibility test for existing application access-token semantics and additive resolver behavior.
- [x] Reuse-gap record documenting the OAuth refresh-token atomicity/storage boundary.
- [x] Additive OAuth auth-schema revision and conditional auth schema registration.
- [x] OAuth value types, protocol errors, client/grant/auth-method policy types, and distinct OAuth access-token claims/service contract.
- [x] DBLayer authoritative stores for clients, redirects/scopes, authorization codes, consents, authorizations, OAuth refresh tokens, and access-token revocations.
- [x] Atomic OAuth refresh rotation/family-reuse handling and one-time authorization-code consumption.
- [x] Epicrypt asymmetric OAuth access-token signing/verification, signing-key resolver, key-set readiness, and JWKS provider.
- [x] OAuth client manager, exact redirect policy, secret verification/rotation, scope/audience policy, consent, authorization-request validation, and PKCE S256 code issuance/consumption.
- [x] Token endpoint domain manager for authorization-code, client-credentials, and refresh grants.
- [x] OAuth resource-token durable validation, revocation, introspection, OAuth bearer principal resolution, and scope/audience middleware.
- [x] Conditional `AuthOAuthRegistrar`, `OAuthManager`, and `AuthServices::oauth()` integration.
- [x] OAuth configuration validation integrated into Foundation validation, including resource audiences and scope-permission mapping.
- [x] OAuth-enabled readiness integration for DBLayer/Epicrypt/schema/signing keys; disabled mode remains key/schema inert.
- [x] OAuth lifecycle audit events use Foundation's existing audit store with secret/token/key sanitization.
- [x] `auth:prune` extended for OAuth state while retaining refresh replay evidence until expiry.
- [x] Atomic client registration and revoked-consent regrant integrity fixes.
- [x] Revocation/introspection operational failures are no longer silently converted into inactive-token results.

## Remaining Foundation work

### F1 — CLI administration

- [x] Add bounded client administration commands:
  - `auth:oauth:client:create`
  - `auth:oauth:client:list`
  - `auth:oauth:client:show`
  - `auth:oauth:client:rotate-secret`
  - `auth:oauth:client:enable`
  - `auth:oauth:client:disable`
- [x] Add bounded authorization commands:
  - `auth:oauth:authorization:list`
  - `auth:oauth:authorization:revoke`
- [x] Add `auth:oauth:key:check` without exposing private material or locator paths.
- [x] Ensure explicit options permit non-interactive use, secret output is one-time only, and stable exit codes are used.
- [x] Add CLI command tests.

Implementation evidence: command handler `f49924f2675e3ba49727621fe7222f04a529d357`, catalog wiring `a730d979619d6e83cf1c2d80875dbb5e2f3fe08c`, repeated-value API correction `898b129c954afd89cc7c81381eb116386cc35dde`, CLI tests `b07e82f7d3e3ef05e2929912ce0b1a7751efce1c`. Test files are committed; execution evidence remains intentionally unchecked under F7.

Exit: OAuth client/authorization/key administration is usable through Foundation's existing command ownership without a parallel CLI subsystem.

### F2 — Reusable OAuth HTTP protocol boundary

- [x] Add strict form/query parameter extraction with size bounds, duplicate-parameter rejection, and content-type validation.
- [x] Add safe `client_secret_basic` parsing; reject query credentials and `client_secret_post`.
- [x] Add Foundation HTTP handlers/adapters for metadata, JWKS, token, revocation, and introspection responses while leaving route declarations/presentation to Infbyte.
- [x] Add authorization success/error redirect builder with exact registered redirect preservation, unchanged `state`, and RFC 9207 `iss` response parameter.
- [x] Redirect OAuth errors only after the redirect URI has been validated.
- [x] Add `Cache-Control: no-store` / `Pragma: no-cache` to token-like responses as applicable.
- [x] Add RFC 6750 bearer error response handling for OAuth resource middleware.
- [x] Integrate existing Foundation/Webrick rate-limiting capability or a narrow existing-policy adapter for authorization/token/revocation/introspection/client-auth failure surfaces; no cache-only correctness.
- [x] Add HTTP boundary tests for malformed, duplicated, oversized, unsupported-content-type, and credential-downgrade inputs.

Implementation evidence: redirect validation/context `4469d9769596f0203938ecad8ca2eadb09121596`, protocol error extension `11a003c09dc57f1ed156001b6963da27b50aea7f`, strict HTTP input `0b9ef344b114478ae50c5bd04f57130b92d8ceb9`, response factory `222dc601929002ae37c8500d969ea3ba46c27800`, reusable HTTP handler/manager support `1762ccb09260e351494d5e8bba7a0f0f4e697741` and `0183705e04ea8f2a56836c35575325278f525e0a`, conditional DI `688b3e1ca2d1de73b223639371e79cdff89e5246`, HTTP boundary tests `98677594c3be2637f43a643f09e3ac996d61e6d0`, throttle adapter `484f192b26155f6fa4bc10219a4d195b98f85e1b`, rate-limit defaults `2aae12b52757c56adb409185e294e0d74c47fa3b`, validation `83e7d4192be32e880d080d8af0b378dd94a21c69`, registrar binding `30283a3189727c9f18e35a762719a7e6e24b7916`, and throttle-policy tests `61d4f6a2bb0766841c78008bb053e3bcbcab9d5e`. Test files are committed; execution evidence remains intentionally unchecked under F7.

Exit: Infbyte can expose thin opt-in routes with no protocol/crypto/persistence logic in application controllers.

### F3 — Security/audit/operational closure

- [x] Verify every planned OAuth audit event is emitted at the correct lifecycle boundary: authorization denial, code consume/expire/replay, refresh rotate/revoke/reuse, authorization revoke, invalid redirect/scope, rate-limit rejection, and signing-key readiness/selection failure.
- [x] Add tests proving raw secrets, codes, refresh/access tokens, PKCE verifiers, private keys, Authorization headers, and private-key locator paths never enter audit/log/CLI/error output.
- [ ] Verify persistent request/principal OAuth metadata is cleared by existing request scope/reset behavior.
- [ ] Verify existing auth/verified/MFA/recent/role/permission/policy flows accept OAuth principals without special alternate authorization semantics.
- [ ] Confirm pruning is idempotent and does not remove active authorizations or refresh-family replay evidence early.

Audit lifecycle evidence: existing `OAuth21AuditLifecycleTest.php`, `OAuth21AuditSecurityTest.php`, and `OAuth21RevocationAuditTest.php`, plus `OAuth21AuditClosureTest.php` and `OAuth21SigningKeySelectionAuditTest.php` committed in `520d0b628767c301fee0168f71d146dc30bc5841` and `64aa5b0440328b8c49fd9ff7471fc8d3ab16c0d1`. Execution remains part of F7.
Sensitive-output evidence: existing audit/CLI security assertions plus `OAuth21SensitiveOutputClosureTest.php` commit `246db78afee4c7331b4923545c6e06df752a627b`; the designed one-time generated/rotated client-secret return remains the explicit F1 administration output, while incidental audit/log/error/client-view output is covered here. Execution remains part of F7.

Exit: planned security and operational invariants are covered by tests rather than documentation only.

### F4 — Persistence, migration, and concurrency closure

- [ ] Add schema install/status/upgrade tests from released Foundation 2.0 auth state into the additive OAuth revision.
- [ ] Add rollback/partial-failure coverage where the existing schema runner supports it.
- [ ] Add simultaneous authorization-code redemption test proving exactly one success.
- [ ] Add simultaneous refresh redemption test proving exactly one rotation and family revocation on replay.
- [ ] Verify durable revocation survives fresh process/store instances and does not rely on cache correctness.
- [ ] Verify consent regrant after revoke and client registration atomicity with integration tests.

Exit: database atomicity and upgrade behavior are demonstrated, not inferred.

### F5 — Protocol and compatibility test matrix

- [ ] Update existing OAuth access-token tests for the current signing-key-set constructor and trusted-audience `verify(token, audience)` contract.
- [ ] Public Authorization Code + S256 success flow.
- [ ] Confidential Authorization Code + S256 success flow.
- [ ] Client Credentials success flow.
- [ ] Refresh equal/narrowed-scope success; widened-scope rejection; rotated-token reuse handling.
- [ ] Metadata/JWKS correctness and key rotation/fallback/unknown-kid behavior.
- [ ] Access-token durable revocation and access/refresh introspection active/inactive states.
- [ ] OAuth bearer -> existing account/service principal -> existing authorization middleware/gates.
- [ ] OAuth/application bearer semantic separation in both directions.
- [ ] Disabled OAuth leaves resolver order/bindings/bootstrap/schema/key loading unchanged.
- [ ] Rejection matrix: unknown/disabled/mismatched client; redirect attacks; PKCE failures; expired/replayed code; unsupported grant/response/auth method; invalid/widened scope; issuer/audience/token-use/algorithm/signature/kid/time/revocation failures; disabled account/client/authorization; malformed input.

Exit: the plan's protocol-success, protocol-rejection, compatibility, and separation matrix is executable.

### F6 — Documentation and operations

- [ ] Document `auth.oauth` configuration, including disabled behavior, `resource_audiences`, `scope_permissions`, route policy, TTL units, and selected public-key list format.
- [ ] Document signing-key provisioning/rotation and one-time client-secret handling.
- [ ] Document Foundation/Infbyte ownership boundary and OAuth/app-token separation.
- [ ] Document schema deployment, readiness, pruning, canary, and rollback procedures.
- [ ] Document implemented RFC claims accurately; do not claim generic final OAuth 2.1 RFC compliance while relying on draft guidance.
- [ ] Document unsupported/deferred features (OIDC, dynamic registration, device flow, password/implicit grants, generic OAuth login, DPoP/PAR/etc.).

Exit: operators and Infbyte integrators can deploy and use Foundation OAuth without reading internals.

### F7 — Performance and engineering gates

- [ ] Extend existing benchmark framework with OAuth-disabled minimal/app-bearer/session/gate paths and OAuth authorization/token/resource/refresh/revoke/introspect workloads.
- [ ] Record environment evidence and representative baseline/candidate measurements where executable infrastructure is available.
- [ ] Prove OAuth-disabled existing-path median regression is within the plan's measured acceptance budget (default 2% only after accounting for environmental noise).
- [ ] Run persistent-worker repeated-request/reset checks and available soak coverage.
- [ ] Run Composer validation/audit/runtime compatibility.
- [ ] Run PHPStan/Psalm/PHPCS/Pint/Rector/complexity/architecture/duplicate/comment gates through the active PHPForge workflow.
- [ ] Run the complete Pest suite with no unexpected skips.
- [ ] Run available OAuth interoperability/conformance coverage.
- [ ] Do not add suppressions, baselines, exclusions, weakened assertions/security checks, or raised complexity limits to obtain green gates.

Exit: Foundation has reproducible release evidence. Do not check items that could not actually be executed.

### F8 — Foundation release handoff

- [ ] Resolve all gate failures introduced by OAuth work.
- [ ] Re-run the full Foundation release guard after fixes.
- [ ] Confirm `foundation-plan.md` has no unchecked Foundation implementation items other than explicitly unavailable external/multi-node evidence, which must be documented as blocked rather than silently skipped.
- [ ] Prepare Foundation `2.1.0` release notes and immutable release boundary.
- [ ] Release/tag Foundation `2.1.0` only after release evidence is green.

Exit: Foundation is complete before Infbyte integration lock/release.

## Deferred until Foundation completion

### I1 — Infbyte integration

- [ ] Keep Infbyte untouched while Foundation tasks F1-F8 are incomplete.
- [ ] After Foundation release: add disabled-default `auth.oauth` application config, opt-in `routes/oauth.php`, thin handlers/consent presentation hooks, integration tests against released Foundation `2.1.x`, deployment/canary/rollback steps, then Infbyte release closure.

## Resume point

Last completed checkpoint: **F3.2 — OAuth sensitive-output closure**.
Implementation/test commit: `246db78afee4c7331b4923545c6e06df752a627b` (plus existing audit/CLI security tests).
Current active task: **F3 — Security/audit/operational closure**.
First unchecked action: verify OAuth principal/request metadata is cleared by persistent runtime reset behavior.
Execution evidence: implementation/test files are committed but have not yet been run in this environment; suite/release evidence remains under F7.
