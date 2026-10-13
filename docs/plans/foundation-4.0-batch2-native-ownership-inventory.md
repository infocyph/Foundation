# Foundation 4.0 — Batch 2 native-owner source inventory

**Snapshot:** `57500fe8e97a6cb1f36f3be652bf9d5f0847bd9b` on `feature/foundation-3` (2026-10-13). Generated from GitHub's complete non-truncated recursive source tree. **662 tracked production PHP source paths, across 31 top-level groups.** This lists every source file on the captured revision; it is **not** a claim that every public method/function has been read or accepted.

## Review and acceptance rules

- This is the complete **file-level census for Batch 2 #4/#5**, not their symbol-level acceptance. Every file below begins at **pending full per-symbol disposition**. This avoids treating a namespace ownership decision as proof every method is necessary.
- Native owner is the cited specialist where available. A Foundation facade or adapter is retained only when it supplies distinct application policy, lifecycle, protocol mapping, configuration, trust or selected plugin glue. Unused compatibility wrappers, competing algorithms and dormant host engines must be eliminated **after** caller, migration and QA review.
- **Retain** development-only/array authentication implementations only while intentionally selected, production guards reject insecure configuration, and native runtime tests remain green; do not confuse these with a permitted production fallback.
- The authoritative PHPForge 8.4/8.5 lowest/stable release matrix passed at `2b31f351`. A new native-binding-policy regression at `57500fe8` requires separate exact-head QA.
- Batch 2 **#3** applicable library/consumer API surface, **#4** built-in service/owner consolidation, and **#5** individual class/method/function disposition remain open. No weakening of source-free trust, cleanup, or security gates.

## Verified candidate dispositions (source evidence)

| Mechanism | Current implementation | Disposition |
| --- | --- | --- |
| Compiled web scope cleanup | `Runtime/RuntimeExecutionState.php`, `Session/SessionExecutionState.php` | Native request-scope ownership and finalizers. Old `Runtime/RuntimeScopeCleanup.php` absent; native compiled release has no dynamic scope-leave hook fallback |
| Webrick rate-limiting counters | Webrick / CacheLayer native adapters | `Cache/WebrickAtomicCounter.php` and `Auth/Adapter/CacheLayer/CacheLayerCounterStore.php` absent; no duplicate framework engine |
| Web route caching/matching | Webrick compiled router and generation | `Routing/WebRouteGraphEnricher.php` absent; retain Foundation application route configuration/policy only |
| Browser versus authentication sessions | `Session/` and `Auth/Authentication/Session/` | Separate cookie/flash/CSRF presentation versus persisted account/device identity; cannot collapse without losing security semantics |
| Development authentication providers | `Auth/Support/*`, `Auth/Internal/AuthProductionGuard.php` | Simple/memory implementations are actively selected only as development/test drivers; guard rejects simple token, memory storage, simple MFA and in-memory passkey for production. Review every retained symbol in #4/#5 |
| CLI process execution | `Process/ProcessRunner.php`, module and command consumers | Has concrete CLI subprocess use; not evidence of duplicate Runwire host supervisor. Final host-owned lifecycle audit remains Batch 3 |
| Application cron expressions | `Scheduling/CronExpression.php` and `Scheduling/ScheduledCommand.php` | Foundation schedule syntax; distinguish from host process/timer leadership; inspect each method before sign-off |
| Cache env/settings | `Config/EnvironmentLoader.php`, `Cache/CacheLayerFactory.php` | Delegates env parsing to ArrayKit and cache/atomic backend to CacheLayer; retains Foundation topology and trusted configuration policy |
| Optional InterMix/DBLayer bound-value injection | Foundation `Container/FoundationGraph.php` | `enableAutoBind()` deliberately **not** invoked; only host opt-in may permit `TableRepository::fromBoundValue()`. New 12-case policy regression awaits QA |

## Complete production PHP file inventory

The area notes are **provisional responsibility mappings, not blanket retain decisions**. The numbered source paths are review work items: a reviewer must assign the actual public/protected symbols, native owner, caller and persisted-state implications, then classify each symbol as **retain, delegate, redesign or delete** before completing Batch 2 #5.

### Application — 8 file(s)

Native / Foundation responsibility: Foundation application lifecycle, capabilities and native graph orchestration (InterMix).

- `src/Application/Application.php`
- `src/Application/FoundationBuildContext.php`
- `src/Application/ProviderFileLoader.php`
- `src/Application/RuntimeMode.php`
- `src/Application/RuntimeModeFactory.php`
- `src/Application/ServiceProvider.php`
- `src/Application/ServiceProviderInterface.php`
- `src/Application/ServiceRegistry.php`

### Auth — 370 file(s)

Native / Foundation responsibility: Foundation account, auth-session, device, MFA, consent and authorization policy; Epicrypt/OTP/DBLayer/CacheLayer own mechanisms.

- `src/Auth/Account/Account.php`
- `src/Auth/Account/AccountActionStatus.php`
- `src/Auth/Account/AccountInterface.php`
- `src/Auth/Account/AccountManager.php`
- `src/Auth/Account/AccountResult.php`
- `src/Auth/Account/AccountStatus.php`
- `src/Auth/Adapter/CacheLayer/AtomicCounterStore.php`
- `src/Auth/Adapter/CacheLayer/CacheLayerTtlStore.php`
- `src/Auth/Adapter/DBLayer/ClockedDBLayerStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerAccountStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerAuditEventStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerConsumableStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerDeviceStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerEmailVerificationStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerEpicryptPersonalAccessTokenStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerFamilyTokenStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerGrantStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerJson.php`
- `src/Auth/Adapter/DBLayer/DBLayerLockoutStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerMfaFactorStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerPasskeyCredentialStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerPasswordResetStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerPermissionStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerRefreshTokenStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerRememberTokenStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerRoleStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerSessionStore.php`
- `src/Auth/Adapter/DBLayer/DBLayerStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerEpicryptAccessTokenStatusStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerEpicryptAuthorizationCodeStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerEpicryptJwtReplayStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerEpicryptOAuthAuthorizationStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerEpicryptRefreshTokenStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthAccessRevocationStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthAuthorizationCodeStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthAuthorizationStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthClientStore.php`
- `src/Auth/Adapter/DBLayer/OAuth/DBLayerOAuthConsentStore.php`
- `src/Auth/Adapter/Epicrypt/AbstractEpicryptTimedTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptAccessTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptAsymmetricSigningKeyResolver.php`
- `src/Auth/Adapter/Epicrypt/EpicryptClockAdapter.php`
- `src/Auth/Adapter/Epicrypt/EpicryptEmailVerificationTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptPasswordHasher.php`
- `src/Auth/Adapter/Epicrypt/EpicryptPasswordResetTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptPasswordVerifier.php`
- `src/Auth/Adapter/Epicrypt/EpicryptPasswordlessTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptPurposeTokenFactory.php`
- `src/Auth/Adapter/Epicrypt/EpicryptRefreshTokenService.php`
- `src/Auth/Adapter/Epicrypt/EpicryptTokenFactory.php`
- `src/Auth/Adapter/Epicrypt/MfaSecretProtectionResult.php`
- `src/Auth/Adapter/Epicrypt/MfaSecretProtector.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthAccessTokenService.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthAuthorizationAudienceResolver.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthAuthorizationClientStore.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthClientAuthenticationAdapter.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthErrorMapper.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthJwkSetProvider.php`
- `src/Auth/Adapter/Epicrypt/OAuth/EpicryptOAuthScopeAudienceResolver.php`
- `src/Auth/Adapter/Epicrypt/OAuth/OAuthProtectionKeyResolver.php`
- `src/Auth/Adapter/Epicrypt/Oidc/FoundationOpenIdClaimsProvider.php`
- `src/Auth/Adapter/Epicrypt/Oidc/FoundationOpenIdSubjectProvider.php`
- `src/Auth/Adapter/Otp/OtpChallengeFactorService.php`
- `src/Auth/Adapter/Otp/OtpMfaVerifier.php`
- `src/Auth/Adapter/Otp/OtpPasskeyService.php`
- `src/Auth/Adapter/Otp/OtpProvisioningService.php`
- `src/Auth/Adapter/Otp/OtpRecoveryCodeService.php`
- `src/Auth/Adapter/Otp/OtpRecoveryCodeStore.php`
- `src/Auth/Adapter/TalkingBytes/AuthNotificationMapper.php`
- `src/Auth/Adapter/TalkingBytes/TalkingBytesAuthNotifier.php`
- `src/Auth/Adapter/Uid/UidAuthIdGenerator.php`
- `src/Auth/Adapter/WebAuthn/WebAuthnConfig.php`
- `src/Auth/Adapter/WebAuthn/WebAuthnConfigResolver.php`
- `src/Auth/Audit/AuthEvent.php`
- `src/Auth/Audit/AuthEventSeverity.php`
- `src/Auth/Audit/AuthEventType.php`
- `src/Auth/AuthManager.php`
- `src/Auth/AuthOtpServiceProvider.php`
- `src/Auth/AuthPruner.php`
- `src/Auth/AuthServiceProvider.php`
- `src/Auth/AuthServices.php`
- `src/Auth/Authentication/EmailVerification/EmailVerificationManager.php`
- `src/Auth/Authentication/EmailVerification/EmailVerificationRequest.php`
- `src/Auth/Authentication/EmailVerification/EmailVerificationResult.php`
- `src/Auth/Authentication/EmailVerification/EmailVerificationStatus.php`
- `src/Auth/Authentication/EmailVerification/EmailVerificationTokenServiceInterface.php`
- `src/Auth/Authentication/Impersonation/ImpersonationManager.php`
- `src/Auth/Authentication/Impersonation/ImpersonationResult.php`
- `src/Auth/Authentication/Impersonation/ImpersonationSession.php`
- `src/Auth/Authentication/Lockout/LockoutConfig.php`
- `src/Auth/Authentication/Lockout/LockoutManager.php`
- `src/Auth/Authentication/Lockout/LockoutResult.php`
- `src/Auth/Authentication/Lockout/LockoutStatus.php`
- `src/Auth/Authentication/Login/Authenticator.php`
- `src/Auth/Authentication/Login/AuthenticatorInterface.php`
- `src/Auth/Authentication/Login/LoginRequest.php`
- `src/Auth/Authentication/Login/LoginResult.php`
- `src/Auth/Authentication/Login/LoginStatus.php`
- `src/Auth/Authentication/PasswordChange/PasswordChangeManager.php`
- `src/Auth/Authentication/PasswordChange/PasswordChangeResult.php`
- `src/Auth/Authentication/PasswordChange/PasswordChangeStatus.php`
- `src/Auth/Authentication/PasswordReset/PasswordResetManager.php`
- `src/Auth/Authentication/PasswordReset/PasswordResetRequest.php`
- `src/Auth/Authentication/PasswordReset/PasswordResetResult.php`
- `src/Auth/Authentication/PasswordReset/PasswordResetStatus.php`
- `src/Auth/Authentication/PasswordReset/PasswordResetTokenServiceInterface.php`
- `src/Auth/Authentication/Passwordless/PasswordlessManager.php`
- `src/Auth/Authentication/Passwordless/PasswordlessResult.php`
- `src/Auth/Authentication/Passwordless/PasswordlessStatus.php`
- `src/Auth/Authentication/Passwordless/PasswordlessTokenServiceInterface.php`
- `src/Auth/Authentication/RememberMe/RememberMeManager.php`
- `src/Auth/Authentication/RememberMe/RememberMeResult.php`
- `src/Auth/Authentication/RememberMe/RememberToken.php`
- `src/Auth/Authentication/RememberMe/RememberTokenRecord.php`
- `src/Auth/Authentication/RememberMe/RememberTokenServiceInterface.php`
- `src/Auth/Authentication/RememberMe/RememberTokenStatus.php`
- `src/Auth/Authentication/RememberMe/RememberTokenVerificationResult.php`
- `src/Auth/Authentication/Session/AuthSession.php`
- `src/Auth/Authentication/Session/SessionConfig.php`
- `src/Auth/Authentication/Session/SessionManager.php`
- `src/Auth/Authentication/Session/SessionStatus.php`
- `src/Auth/Authentication/StepUp/StepUpManager.php`
- `src/Auth/Authentication/StepUp/StepUpMethod.php`
- `src/Auth/Authentication/StepUp/StepUpRequirement.php`
- `src/Auth/Authentication/StepUp/StepUpResult.php`
- `src/Auth/Authentication/TokenAuth/AccessTokenClaims.php`
- `src/Auth/Authentication/TokenAuth/IssuedRefreshToken.php`
- `src/Auth/Authentication/TokenAuth/RefreshTokenClaims.php`
- `src/Auth/Authentication/TokenAuth/RefreshTokenRecord.php`
- `src/Auth/Authentication/TokenAuth/RefreshTokenRotationResult.php`
- `src/Auth/Authentication/TokenAuth/RefreshTokenServiceInterface.php`
- `src/Auth/Authentication/TokenAuth/TokenAuthManager.php`
- `src/Auth/Authentication/TokenAuth/TokenAuthResult.php`
- `src/Auth/Authentication/TokenAuth/TokenRevocationResult.php`
- `src/Auth/Authentication/TokenAuth/TokenRevocationStatus.php`
- `src/Auth/Authentication/TokenAuth/TokenType.php`
- `src/Auth/Authorization/Decision/AuthorizationDecision.php`
- `src/Auth/Authorization/Gate/Ability.php`
- `src/Auth/Authorization/Gate/AbilityMatcher.php`
- `src/Auth/Authorization/Gate/AuditingAuthorizer.php`
- `src/Auth/Authorization/Gate/AuthorizerInterface.php`
- `src/Auth/Authorization/Gate/Gate.php`
- `src/Auth/Authorization/Gate/PermissionAuthorizer.php`
- `src/Auth/Authorization/Grant/AccessGrant.php`
- `src/Auth/Authorization/Grant/DelegationManager.php`
- `src/Auth/Authorization/Grant/DelegationResult.php`
- `src/Auth/Authorization/Grant/DelegationStatus.php`
- `src/Auth/Authorization/Grant/GrantResolver.php`
- `src/Auth/Authorization/Grant/GrantStoreInterface.php`
- `src/Auth/Authorization/Permission/Permission.php`
- `src/Auth/Authorization/Permission/PermissionAssignmentStoreInterface.php`
- `src/Auth/Authorization/Permission/PermissionManager.php`
- `src/Auth/Authorization/Permission/PermissionResolver.php`
- `src/Auth/Authorization/Permission/PermissionStoreInterface.php`
- `src/Auth/Authorization/Policy/PolicyInterface.php`
- `src/Auth/Authorization/Policy/PolicyResolverInterface.php`
- `src/Auth/Authorization/Role/Role.php`
- `src/Auth/Authorization/Role/RoleAssignmentStoreInterface.php`
- `src/Auth/Authorization/Role/RoleManager.php`
- `src/Auth/Authorization/Role/RolePermissionResolver.php`
- `src/Auth/Authorization/Role/RoleStoreInterface.php`
- `src/Auth/Contract/Cache/CounterStoreInterface.php`
- `src/Auth/Contract/Cache/TtlStoreInterface.php`
- `src/Auth/Contract/Clock/ClockInterface.php`
- `src/Auth/Contract/Id/AuthIdGeneratorInterface.php`
- `src/Auth/Contract/Notification/AuthNotifierInterface.php`
- `src/Auth/Contract/Security/AccessTokenServiceInterface.php`
- `src/Auth/Contract/Security/PasswordHasherInterface.php`
- `src/Auth/Contract/Security/PasswordPolicyInterface.php`
- `src/Auth/Contract/Security/PasswordPolicyResult.php`
- `src/Auth/Contract/Security/PasswordVerificationResult.php`
- `src/Auth/Contract/Security/PasswordVerifierInterface.php`
- `src/Auth/Contract/Security/TokenVerificationResult.php`
- `src/Auth/Contract/Storage/AccountProviderInterface.php`
- `src/Auth/Contract/Storage/AccountStoreInterface.php`
- `src/Auth/Contract/Storage/AuditEventStoreInterface.php`
- `src/Auth/Contract/Storage/EmailVerificationStoreInterface.php`
- `src/Auth/Contract/Storage/LockoutReason.php`
- `src/Auth/Contract/Storage/LockoutStoreInterface.php`
- `src/Auth/Contract/Storage/PasswordResetStoreInterface.php`
- `src/Auth/Contract/Storage/RefreshTokenStoreInterface.php`
- `src/Auth/Contract/Storage/RememberTokenStoreInterface.php`
- `src/Auth/Contract/Storage/SessionStoreInterface.php`
- `src/Auth/Device/DeviceManager.php`
- `src/Auth/Device/DeviceRecord.php`
- `src/Auth/Device/DeviceResult.php`
- `src/Auth/Device/DeviceStatus.php`
- `src/Auth/Device/DeviceStoreInterface.php`
- `src/Auth/Driver/AuthCacheDriver.php`
- `src/Auth/Driver/AuthDriverResolver.php`
- `src/Auth/Driver/AuthMfaDriver.php`
- `src/Auth/Driver/AuthNotificationDriver.php`
- `src/Auth/Driver/AuthPasskeyDriver.php`
- `src/Auth/Driver/AuthPasswordDriver.php`
- `src/Auth/Driver/AuthStorageDriver.php`
- `src/Auth/Driver/AuthTokenDriver.php`
- `src/Auth/Exception/AuthenticationException.php`
- `src/Auth/Exception/AuthorizationException.php`
- `src/Auth/Exception/ConfigurationException.php`
- `src/Auth/Exception/FoundationAuthException.php`
- `src/Auth/Exception/LockoutException.php`
- `src/Auth/Exception/MfaException.php`
- `src/Auth/Exception/PasskeyException.php`
- `src/Auth/Exception/SessionException.php`
- `src/Auth/Exception/StorageException.php`
- `src/Auth/Exception/TokenAuthException.php`
- `src/Auth/Http/AuthActions.php`
- `src/Auth/Http/LogoutResult.php`
- `src/Auth/Internal/AbstractAuthRegistrar.php`
- `src/Auth/Internal/AuthAuthorizationRegistrar.php`
- `src/Auth/Internal/AuthCacheRegistrar.php`
- `src/Auth/Internal/AuthCoreRegistrar.php`
- `src/Auth/Internal/AuthHttpGraphFactory.php`
- `src/Auth/Internal/AuthManagerRegistrar.php`
- `src/Auth/Internal/AuthMfaGraphFactory.php`
- `src/Auth/Internal/AuthMfaKeyResolver.php`
- `src/Auth/Internal/AuthMfaRegistrar.php`
- `src/Auth/Internal/AuthNotificationGraphFactory.php`
- `src/Auth/Internal/AuthNotificationRegistrar.php`
- `src/Auth/Internal/AuthOAuthGraphFactory.php`
- `src/Auth/Internal/AuthOAuthRegistrar.php`
- `src/Auth/Internal/AuthPasskeyGraphFactory.php`
- `src/Auth/Internal/AuthPasskeyRegistrar.php`
- `src/Auth/Internal/AuthPasswordRegistrar.php`
- `src/Auth/Internal/AuthPersonalAccessTokenGraphFactory.php`
- `src/Auth/Internal/AuthPersonalAccessTokenRegistrar.php`
- `src/Auth/Internal/AuthProductionGuard.php`
- `src/Auth/Internal/AuthRuntimeGraphFactory.php`
- `src/Auth/Internal/AuthRuntimeRegistrar.php`
- `src/Auth/Internal/AuthSecretResolver.php`
- `src/Auth/Internal/AuthStoreGraphFactory.php`
- `src/Auth/Internal/AuthStoreRegistrar.php`
- `src/Auth/Internal/AuthTokenRegistrar.php`
- `src/Auth/Internal/EpicryptTokenPolicyResolver.php`
- `src/Auth/Mfa/MfaChallenge.php`
- `src/Auth/Mfa/MfaChallengePurpose.php`
- `src/Auth/Mfa/MfaChallengeResult.php`
- `src/Auth/Mfa/MfaEnrollmentResult.php`
- `src/Auth/Mfa/MfaFactor.php`
- `src/Auth/Mfa/MfaFactorCompareAndSwapStoreInterface.php`
- `src/Auth/Mfa/MfaFactorStoreInterface.php`
- `src/Auth/Mfa/MfaFactorType.php`
- `src/Auth/Mfa/MfaManager.php`
- `src/Auth/Mfa/MfaStatus.php`
- `src/Auth/Mfa/MfaVerificationResult.php`
- `src/Auth/Mfa/MfaVerifierInterface.php`
- `src/Auth/Mfa/RecoveryCodeServiceInterface.php`
- `src/Auth/Mfa/RecoveryCodeVerificationResult.php`
- `src/Auth/Notification/AuthNotification.php`
- `src/Auth/Notification/AuthNotificationType.php`
- `src/Auth/OAuth/Audit/OAuthAuditRecorder.php`
- `src/Auth/OAuth/Authorization/AuthorizationCodeManager.php`
- `src/Auth/OAuth/Authorization/AuthorizationProtocolResult.php`
- `src/Auth/OAuth/Authorization/AuthorizationRedirectContext.php`
- `src/Auth/OAuth/Authorization/AuthorizationRequest.php`
- `src/Auth/OAuth/Authorization/AuthorizationRequestValidator.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorization.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorizationCode.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorizationCodeConsumeResult.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorizationCodeConsumeStatus.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorizationCodeIssue.php`
- `src/Auth/OAuth/Authorization/OAuthAuthorizationCodeKeyResolver.php`
- `src/Auth/OAuth/Client/OAuthClient.php`
- `src/Auth/OAuth/Client/OAuthClientManager.php`
- `src/Auth/OAuth/Client/OAuthClientRegistration.php`
- `src/Auth/OAuth/Configuration/OAuthConfigValidator.php`
- `src/Auth/OAuth/Configuration/OAuthSigningConfigValidator.php`
- `src/Auth/OAuth/Configuration/OpenIdConfigValidator.php`
- `src/Auth/OAuth/Consent/ConsentManager.php`
- `src/Auth/OAuth/Consent/OAuthConsent.php`
- `src/Auth/OAuth/Contract/JwkSetProviderInterface.php`
- `src/Auth/OAuth/Contract/OAuthAccessRevocationStoreInterface.php`
- `src/Auth/OAuth/Contract/OAuthAccessTokenServiceInterface.php`
- `src/Auth/OAuth/Contract/OAuthAuthorizationCodeStoreInterface.php`
- `src/Auth/OAuth/Contract/OAuthAuthorizationStoreInterface.php`
- `src/Auth/OAuth/Contract/OAuthClientStoreInterface.php`
- `src/Auth/OAuth/Contract/OAuthConsentStoreInterface.php`
- `src/Auth/OAuth/Exception/OAuthProtocolException.php`
- `src/Auth/OAuth/Exception/OAuthTokenException.php`
- `src/Auth/OAuth/Http/OAuthAuthorizationController.php`
- `src/Auth/OAuth/Http/OAuthHttpHandler.php`
- `src/Auth/OAuth/Http/OAuthHttpInput.php`
- `src/Auth/OAuth/Http/OAuthHttpResponseFactory.php`
- `src/Auth/OAuth/Http/OAuthHttpThrottleFactory.php`
- `src/Auth/OAuth/Http/OAuthRateLimitMiddleware.php`
- `src/Auth/OAuth/Metadata/AuthorizationServerMetadata.php`
- `src/Auth/OAuth/Metadata/OpenIdMetadataProvider.php`
- `src/Auth/OAuth/OAuthManager.php`
- `src/Auth/OAuth/Scope/OAuthScopeResolver.php`
- `src/Auth/OAuth/Scope/OAuthScopeSelection.php`
- `src/Auth/OAuth/Token/OAuthAccessTokenClaims.php`
- `src/Auth/OAuth/Token/OAuthAccessTokenRevocation.php`
- `src/Auth/OAuth/Token/OAuthAccessTokenValidator.php`
- `src/Auth/OAuth/Token/OAuthClientAuthentication.php`
- `src/Auth/OAuth/Token/OAuthIntrospectionManager.php`
- `src/Auth/OAuth/Token/OAuthIntrospectionResult.php`
- `src/Auth/OAuth/Token/OAuthRevocationManager.php`
- `src/Auth/OAuth/Token/OAuthSigningKeyResolver.php`
- `src/Auth/OAuth/Token/OAuthSigningKeySet.php`
- `src/Auth/OAuth/Token/OAuthTokenManager.php`
- `src/Auth/OAuth/Token/OAuthTokenResponse.php`
- `src/Auth/OAuth/Token/OAuthVerifiedAccessToken.php`
- `src/Auth/OAuth/Value/OAuthClientAuthenticationMethod.php`
- `src/Auth/OAuth/Value/OAuthClientType.php`
- `src/Auth/OAuth/Value/OAuthGrantType.php`
- `src/Auth/Otp/GridOtpEnrollmentResult.php`
- `src/Auth/Otp/OtpEnrollmentConfirmationResult.php`
- `src/Auth/Otp/OtpEnrollmentResult.php`
- `src/Auth/Otp/OtpManager.php`
- `src/Auth/Passkey/PasskeyAuthenticationOutcome.php`
- `src/Auth/Passkey/PasskeyAuthenticationResult.php`
- `src/Auth/Passkey/PasskeyAuthenticationStatus.php`
- `src/Auth/Passkey/PasskeyChallenge.php`
- `src/Auth/Passkey/PasskeyCredential.php`
- `src/Auth/Passkey/PasskeyCredentialCompareAndSwapStoreInterface.php`
- `src/Auth/Passkey/PasskeyCredentialStoreInterface.php`
- `src/Auth/Passkey/PasskeyManager.php`
- `src/Auth/Passkey/PasskeyRegistrationOutcome.php`
- `src/Auth/Passkey/PasskeyRegistrationResult.php`
- `src/Auth/Passkey/PasskeyRegistrationStatus.php`
- `src/Auth/Passkey/PasskeyServiceInterface.php`
- `src/Auth/Passkey/PasskeyVerificationResult.php`
- `src/Auth/Principal/CurrentPrincipalContext.php`
- `src/Auth/Principal/CurrentPrincipalProviderInterface.php`
- `src/Auth/Principal/CurrentPrincipalState.php`
- `src/Auth/Principal/Principal.php`
- `src/Auth/Principal/PrincipalInterface.php`
- `src/Auth/Principal/PrincipalType.php`
- `src/Auth/Support/AbstractConsumableRequest.php`
- `src/Auth/Support/AbstractFamilyTokenRecord.php`
- `src/Auth/Support/AbstractInMemoryConsumableStore.php`
- `src/Auth/Support/AbstractInMemoryFamilyTokenStore.php`
- `src/Auth/Support/AbstractSimpleTimedTokenService.php`
- `src/Auth/Support/ArrayTtlStore.php`
- `src/Auth/Support/AuthEventRecorder.php`
- `src/Auth/Support/BaselinePasswordPolicy.php`
- `src/Auth/Support/CollectingAuthNotifier.php`
- `src/Auth/Support/ContextValue.php`
- `src/Auth/Support/DisabledPasskeyService.php`
- `src/Auth/Support/ForwardingAuditEventStore.php`
- `src/Auth/Support/GeneratesAuthIds.php`
- `src/Auth/Support/InMemoryAccountStore.php`
- `src/Auth/Support/InMemoryAuditEventStore.php`
- `src/Auth/Support/InMemoryCounterStore.php`
- `src/Auth/Support/InMemoryDeviceStore.php`
- `src/Auth/Support/InMemoryEmailVerificationStore.php`
- `src/Auth/Support/InMemoryGrantStore.php`
- `src/Auth/Support/InMemoryLockoutStore.php`
- `src/Auth/Support/InMemoryMfaFactorStore.php`
- `src/Auth/Support/InMemoryPasskeyCredentialStore.php`
- `src/Auth/Support/InMemoryPasskeyService.php`
- `src/Auth/Support/InMemoryPasswordResetStore.php`
- `src/Auth/Support/InMemoryPermissionStore.php`
- `src/Auth/Support/InMemoryRecoveryCodeService.php`
- `src/Auth/Support/InMemoryRefreshTokenStore.php`
- `src/Auth/Support/InMemoryRememberTokenStore.php`
- `src/Auth/Support/InMemoryRoleStore.php`
- `src/Auth/Support/InMemorySessionStore.php`
- `src/Auth/Support/NativePasswordHasher.php`
- `src/Auth/Support/NativePasswordVerifier.php`
- `src/Auth/Support/NormalizesTokenClaims.php`
- `src/Auth/Support/SimpleAccessTokenService.php`
- `src/Auth/Support/SimpleEmailVerificationTokenService.php`
- `src/Auth/Support/SimpleMfaVerifier.php`
- `src/Auth/Support/SimplePasswordResetTokenService.php`
- `src/Auth/Support/SimplePasswordlessTokenService.php`
- `src/Auth/Support/SimpleRefreshTokenService.php`
- `src/Auth/Support/SimpleRememberTokenService.php`
- `src/Auth/Support/SystemClock.php`
- `src/Auth/Support/TracksSuccessfulStatus.php`

### Bootstrap — 1 file(s)

Native / Foundation responsibility: Foundation provider composition before immutable InterMix build.

- `src/Bootstrap/Bootstrapper.php`

### Cache — 10 file(s)

Native / Foundation responsibility: Foundation store selection; CacheLayer owns storage, atomic counters, distributed locks, cluster/outbox.

- `src/Cache/CacheDriver.php`
- `src/Cache/CacheGraphFactory.php`
- `src/Cache/CacheLayerFactory.php`
- `src/Cache/CacheManager.php`
- `src/Cache/CacheNamespace.php`
- `src/Cache/CacheSchemaManager.php`
- `src/Cache/CacheServiceProvider.php`
- `src/Cache/FoundationCacheKey.php`
- `src/Cache/Internal/CacheTierDescriptorResolver.php`
- `src/Cache/Internal/MemcachedConnector.php`

### Command — 31 file(s)

Native / Foundation responsibility: Foundation CLI policy and operational commands; process execution distinct from host supervision.

- `src/Command/CliPreflight.php`
- `src/Command/Command.php`
- `src/Command/CommandCacheManager.php`
- `src/Command/CommandCatalog.php`
- `src/Command/CommandContext.php`
- `src/Command/CommandDefinition.php`
- `src/Command/CommandDescriptor.php`
- `src/Command/CommandDispatcher.php`
- `src/Command/CommandExecutionCoordinator.php`
- `src/Command/CommandExecutionPolicy.php`
- `src/Command/CommandHandlerInterface.php`
- `src/Command/CommandIO.php`
- `src/Command/CommandRegistry.php`
- `src/Command/CommandResolver.php`
- `src/Command/CommandStatus.php`
- `src/Command/CompletionGenerator.php`
- `src/Command/ExitCode.php`
- `src/Command/OverlapMode.php`
- `src/Command/ParsedInput.php`
- `src/Command/ProgressIndicator.php`
- `src/Command/System/ApplicationSystemCommand.php`
- `src/Command/System/ArtifactSystemCommand.php`
- `src/Command/System/CacheSystemCommand.php`
- `src/Command/System/DatabaseSystemCommand.php`
- `src/Command/System/MessagingSystemCommand.php`
- `src/Command/System/ModuleSystemCommand.php`
- `src/Command/System/OAuthSystemCommand.php`
- `src/Command/System/OperationsSystemCommand.php`
- `src/Command/System/RuntimeSystemCommand.php`
- `src/Command/System/SystemCommand.php`
- `src/Command/TerminalIO.php`

### Communication — 5 file(s)

Native / Foundation responsibility: Foundation transport profile and webhook policy; TalkingBytes owns HTTP/email/gRPC transports.

- `src/Communication/CacheLayerWebhookReplayStore.php`
- `src/Communication/CommunicationGraphFactory.php`
- `src/Communication/CommunicationProfiles.php`
- `src/Communication/CommunicationServiceProvider.php`
- `src/Communication/GrpcInboundWorker.php`

### Config — 31 file(s)

Native / Foundation responsibility: Foundation config policy/trust; ArrayKit owns env parsing, materialization and cache generations.

- `src/Config/ApiPreset.php`
- `src/Config/ArrayKitConfigCache.php`
- `src/Config/AuthDefaults.php`
- `src/Config/ConfigCacheManager.php`
- `src/Config/ConfigExportValidator.php`
- `src/Config/ConfigIssue.php`
- `src/Config/ConfigLoader.php`
- `src/Config/ConfigRepository.php`
- `src/Config/ConfigValidationResult.php`
- `src/Config/ConfigValidator.php`
- `src/Config/ConfigurationRedactor.php`
- `src/Config/DeploymentTopology.php`
- `src/Config/EnvironmentLoader.php`
- `src/Config/FoundationDefaults.php`
- `src/Config/FoundationPreset.php`
- `src/Config/Internal/CacheTopologyValidator.php`
- `src/Config/Internal/ConfiguredCapabilities.php`
- `src/Config/Internal/RuntimeCoreValidator.php`
- `src/Config/Internal/RuntimeLoggingValidator.php`
- `src/Config/Internal/RuntimeMessagingValidator.php`
- `src/Config/Internal/RuntimeMigrationValidator.php`
- `src/Config/Internal/RuntimeOperationsValidator.php`
- `src/Config/Internal/TokenSecretConfigValidator.php`
- `src/Config/Internal/WebAuthnConfigValidator.php`
- `src/Config/LocalPreset.php`
- `src/Config/OtpConfigValidator.php`
- `src/Config/ProductionPreset.php`
- `src/Config/ProductionSecurityValidator.php`
- `src/Config/RuntimeConfigValidator.php`
- `src/Config/SharedStateTopology.php`
- `src/Config/helpers.php`

### Container — 2 file(s)

Native / Foundation responsibility: Foundation InterMix builder presets and development-only bindings.

- `src/Container/DevelopmentRuntimeBindings.php`
- `src/Container/FoundationGraph.php`

### Database — 16 file(s)

Native / Foundation responsibility: Foundation schema/migration selection and connection scope; DBLayer owns transactions/pools/query layer.

- `src/Database/AuthSchema/AuthMfaRevisionSchema.php`
- `src/Database/AuthSchema/AuthOAuthEpicryptProtocolSchema.php`
- `src/Database/AuthSchema/AuthOAuthEpicryptRevisionSchema.php`
- `src/Database/AuthSchema/AuthOAuthRevisionSchema.php`
- `src/Database/AuthSchema/AuthPasskeyRecordSchema.php`
- `src/Database/AuthSchema/AuthPasskeyRevisionSchema.php`
- `src/Database/AuthSchema/AuthPersonalAccessTokenSchema.php`
- `src/Database/AuthSchema/AuthSchema.php`
- `src/Database/AuthSchema/AuthSchemaInstaller.php`
- `src/Database/AuthSchema/AuthTables.php`
- `src/Database/DBLayerFactory.php`
- `src/Database/DatabaseConnectionResolver.php`
- `src/Database/DatabaseGraphFactory.php`
- `src/Database/DatabaseMigrationManager.php`
- `src/Database/DatabaseRepository.php`
- `src/Database/DatabaseServiceProvider.php`

### Diagnostics — 1 file(s)

Native / Foundation responsibility: Foundation app readiness and diagnostics policy.

- `src/Diagnostics/ReadinessReport.php`

### Exception — 5 file(s)

Native / Foundation responsibility: Foundation domain errors, primary failure preservation.

- `src/Exception/BootstrapException.php`
- `src/Exception/ConfigurationException.php`
- `src/Exception/FoundationException.php`
- `src/Exception/IntegrationException.php`
- `src/Exception/ServiceResolutionException.php`

### Filesystem — 11 file(s)

Native / Foundation responsibility: Foundation disk/profile and permissions; Pathwise owns storage/stream/range engines.

- `src/Filesystem/FilesystemGraphFactory.php`
- `src/Filesystem/FilesystemMalwareScannerResolver.php`
- `src/Filesystem/FilesystemPublicFileResolver.php`
- `src/Filesystem/FilesystemResponseFactory.php`
- `src/Filesystem/FilesystemServiceProvider.php`
- `src/Filesystem/FilesystemTransferFactory.php`
- `src/Filesystem/FilesystemUploadRequestHandler.php`
- `src/Filesystem/PathManager.php`
- `src/Filesystem/PathServiceProvider.php`
- `src/Filesystem/StorageLinkManager.php`
- `src/Filesystem/StorageRegistry.php`

### Foundation.php — 1 file(s)

Native / Foundation responsibility: Foundation public facade across application modes.

- `src/Foundation.php`

### Generator — 1 file(s)

Native / Foundation responsibility: Foundation application scaffolding.

- `src/Generator/ArtifactGenerator.php`

### Http — 31 file(s)

Native / Foundation responsibility: Foundation middleware and response policy; Webrick owns request/dispatch/emission.

- `src/Http/HttpKernel.php`
- `src/Http/HttpRuntimeFactory.php`
- `src/Http/HttpServiceProvider.php`
- `src/Http/JsonDispatch/Issue.php`
- `src/Http/JsonDispatch/JsonDispatchResponseFactory.php`
- `src/Http/JsonDispatch/JsonDispatchServiceProvider.php`
- `src/Http/Middleware/AbstractPrincipalRequirementMiddleware.php`
- `src/Http/Middleware/AuthMiddleware.php`
- `src/Http/Middleware/GuestMiddleware.php`
- `src/Http/Middleware/MfaRequiredMiddleware.php`
- `src/Http/Middleware/OAuthAudienceMiddleware.php`
- `src/Http/Middleware/OAuthScopeMiddleware.php`
- `src/Http/Middleware/PermissionMiddleware.php`
- `src/Http/Middleware/PolicyMiddleware.php`
- `src/Http/Middleware/RecentAuthMiddleware.php`
- `src/Http/Middleware/ResolvePrincipalMiddleware.php`
- `src/Http/Middleware/RoleMiddleware.php`
- `src/Http/Middleware/VerifiedMiddleware.php`
- `src/Http/Resolver/AbstractPrincipalResolver.php`
- `src/Http/Resolver/BearerTokenPrincipalResolver.php`
- `src/Http/Resolver/OAuthBearerTokenPrincipalResolver.php`
- `src/Http/Resolver/PrincipalResolverInterface.php`
- `src/Http/Resolver/RememberMePrincipalResolver.php`
- `src/Http/Resolver/RequestPrincipalResolver.php`
- `src/Http/Resolver/SessionPrincipalResolver.php`
- `src/Http/Resource/JsonResource.php`
- `src/Http/Resource/ResourceCollection.php`
- `src/Http/Response/AuthExceptionMapper.php`
- `src/Http/Response/AuthResponseFactory.php`
- `src/Http/Response/ExceptionRenderer.php`
- `src/Http/Response/ValidationExceptionMapper.php`

### Logging — 5 file(s)

Native / Foundation responsibility: Foundation exception reporting; PSR logger interfaces.

- `src/Logging/ExceptionReporter.php`
- `src/Logging/HttpExceptionLogger.php`
- `src/Logging/JsonLogger.php`
- `src/Logging/LogTailer.php`
- `src/Logging/LoggingServiceProvider.php`

### Messaging — 14 file(s)

Native / Foundation responsibility: Foundation job declarations and configuration; Omnibus owns queue/delivery/worker engine.

- `src/Messaging/ConsumerFactory.php`
- `src/Messaging/InterMixExecutionScope.php`
- `src/Messaging/Job.php`
- `src/Messaging/JobContext.php`
- `src/Messaging/JobMiddleware.php`
- `src/Messaging/JobMiddlewarePipeline.php`
- `src/Messaging/MessagingDatabaseSchema.php`
- `src/Messaging/MessagingGraphFactory.php`
- `src/Messaging/MessagingRuntimeResolver.php`
- `src/Messaging/MessagingServiceProvider.php`
- `src/Messaging/OmnibusDurableFactory.php`
- `src/Messaging/OmnibusWorkerFactory.php`
- `src/Messaging/ResolvingHandlerMiddleware.php`
- `src/Messaging/ResolvingJobMiddleware.php`

### Module — 11 file(s)

Native / Foundation responsibility: Foundation optional module catalog, installer, readiness and activation.

- `src/Module/FreshModuleSchemaRunner.php`
- `src/Module/Internal/ModuleCatalogValidator.php`
- `src/Module/Internal/ModuleConfigPublisher.php`
- `src/Module/Internal/ModuleDependencyResolver.php`
- `src/Module/Internal/ModulePackageStateResolver.php`
- `src/Module/Internal/ModulePlatformResolver.php`
- `src/Module/ModuleActivationManager.php`
- `src/Module/ModuleCatalog.php`
- `src/Module/ModuleManager.php`
- `src/Module/ModuleSchemaManager.php`
- `src/Module/ModuleStateResolver.php`

### Notifications — 12 file(s)

Native / Foundation responsibility: Foundation notification templates/recipient selection; TalkingBytes owns transports.

- `src/Notifications/EmailProfiles.php`
- `src/Notifications/MailMessage.php`
- `src/Notifications/MailNotificationChannel.php`
- `src/Notifications/Mailer.php`
- `src/Notifications/Notification.php`
- `src/Notifications/NotificationChannel.php`
- `src/Notifications/NotificationChannelRegistry.php`
- `src/Notifications/NotificationDispatcher.php`
- `src/Notifications/NotificationGraphFactory.php`
- `src/Notifications/NotificationRecipient.php`
- `src/Notifications/NotificationServiceProvider.php`
- `src/Notifications/NotificationTemplateRegistry.php`

### Operations — 6 file(s)

Native / Foundation responsibility: Foundation deployment/control policy; no duplicate process host.

- `src/Operations/ExecutionHistory.php`
- `src/Operations/Internal/ExecutionHistoryStorage.php`
- `src/Operations/MaintenanceManager.php`
- `src/Operations/MaintenanceRuntimeState.php`
- `src/Operations/RuntimeControl.php`
- `src/Operations/RuntimeProcessRegistry.php`

### Process — 8 file(s)

Native / Foundation responsibility: Foundation CLI subprocess execution; verify native Runwire host-supervision boundary in Batch 3.

- `src/Process/Internal/ProcessCapture.php`
- `src/Process/Internal/ProcessOutcome.php`
- `src/Process/Internal/ProcessSignals.php`
- `src/Process/Internal/ProcessTree.php`
- `src/Process/ProcessOptions.php`
- `src/Process/ProcessResult.php`
- `src/Process/ProcessRunner.php`
- `src/Process/ProcessTerminationReason.php`

### Release — 7 file(s)

Native / Foundation responsibility: Foundation outer generation activation/fencing; InterMix/Webrick/ArrayKit own artifact formats.

- `src/Release/ActiveGeneration.php`
- `src/Release/FoundationReleaseBootstrap.php`
- `src/Release/FoundationReleaseBuildLock.php`
- `src/Release/FoundationReleaseCompiler.php`
- `src/Release/FoundationReleaseConfig.php`
- `src/Release/FoundationReleaseManifest.php`
- `src/Release/FoundationReleaseRuntime.php`

### Routing — 18 file(s)

Native / Foundation responsibility: Foundation route registration/policy; Webrick owns matching, URLs and compiled router.

- `src/Routing/MiddlewareConfigValidator.php`
- `src/Routing/OAuthRouteRegistrar.php`
- `src/Routing/RouteFileLoader.php`
- `src/Routing/RouteInspector.php`
- `src/Routing/RouteMiddlewareRegistrar.php`
- `src/Routing/RouteMiddlewareRuntimeResolver.php`
- `src/Routing/RoutePresetRegistrar.php`
- `src/Routing/RoutingServiceProvider.php`
- `src/Routing/SignedUrlKeyResolver.php`
- `src/Routing/WebGraphComposition.php`
- `src/Routing/WebGraphFactory.php`
- `src/Routing/WebProductionGraph.php`
- `src/Routing/WebReleaseCompiler.php`
- `src/Routing/WebReleaseConfiguration.php`
- `src/Routing/WebReleaseRuntime.php`
- `src/Routing/WebrickMiddlewareFactory.php`
- `src/Routing/WebrickRouterFactory.php`
- `src/Routing/WebrickRuntimeFactory.php`

### Runtime — 13 file(s)

Native / Foundation responsibility: Foundation app execution resource scoping; InterMix owns DI/scopes, Runwire host passes contexts.

- `src/Runtime/CleanupGuard.php`
- `src/Runtime/ExecutionId.php`
- `src/Runtime/ExecutionScope.php`
- `src/Runtime/ForkSafetyTracker.php`
- `src/Runtime/GeneratedRuntime.php`
- `src/Runtime/GeneratedRuntimeCompiler.php`
- `src/Runtime/GeneratedRuntimeMetadata.php`
- `src/Runtime/LoadedReleaseGeneration.php`
- `src/Runtime/NonWebGraphComposition.php`
- `src/Runtime/NonWebGraphFactory.php`
- `src/Runtime/NonWebProductionGraph.php`
- `src/Runtime/ReleaseGenerationLease.php`
- `src/Runtime/RuntimeExecutionState.php`

### Scheduling — 6 file(s)

Native / Foundation responsibility: Foundation schedule declarations and CLI orchestration; Runwire/CacheLayer own selected host/leadership primitives.

- `src/Scheduling/CronExpression.php`
- `src/Scheduling/Schedule.php`
- `src/Scheduling/ScheduleManager.php`
- `src/Scheduling/ScheduleRun.php`
- `src/Scheduling/ScheduledCommand.php`
- `src/Scheduling/SchedulerRuntime.php`

### Security — 4 file(s)

Native / Foundation responsibility: Foundation environment and security configuration policy; native crypto via Epicrypt.

- `src/Security/EnvironmentFileProtector.php`
- `src/Security/EnvironmentSecretManager.php`
- `src/Security/SecurityGraphFactory.php`
- `src/Security/SecurityServiceProvider.php`

### Session — 18 file(s)

Native / Foundation responsibility: Foundation browser cookie/flash/CSRF policy, separate from account/device auth sessions; Webrick/CacheLayer/DBLayer own primitives.

- `src/Session/BrowserSession.php`
- `src/Session/Middleware/CsrfMiddleware.php`
- `src/Session/Middleware/SessionMiddleware.php`
- `src/Session/SessionCommit.php`
- `src/Session/SessionConfig.php`
- `src/Session/SessionDatabaseSchema.php`
- `src/Session/SessionExecutionState.php`
- `src/Session/SessionGraphFactory.php`
- `src/Session/SessionManager.php`
- `src/Session/SessionPayload.php`
- `src/Session/SessionServiceProvider.php`
- `src/Session/SessionStoreFactory.php`
- `src/Session/SessionStoreInterface.php`
- `src/Session/SessionTopologyGuard.php`
- `src/Session/Store/ArraySessionStore.php`
- `src/Session/Store/CacheSessionStore.php`
- `src/Session/Store/DatabaseSessionStore.php`
- `src/Session/Store/FileSessionStore.php`

### Support — 1 file(s)

Native / Foundation responsibility: Foundation small shared policy normalization; audit for duplicate native operations.

- `src/Support/ValueNormalizer.php`

### Testing — 5 file(s)

Native / Foundation responsibility: Foundation test helpers, not production host machinery.

- `src/Testing/DatabaseTestManager.php`
- `src/Testing/FrozenClock.php`
- `src/Testing/HttpTestClient.php`
- `src/Testing/TestKit.php`
- `src/Testing/TestResponse.php`

### Validation — 5 file(s)

Native / Foundation responsibility: Foundation validation presentation; ReqShield owns validation/sanitization mechanisms.

- `src/Validation/AuthRequestSchemas.php`
- `src/Validation/FormRequest.php`
- `src/Validation/ValidationGraphFactory.php`
- `src/Validation/ValidationServiceProvider.php`
- `src/Validation/ValidatorFactory.php`

### Worker — 5 file(s)

Native / Foundation responsibility: Foundation selected job provider configuration; Omnibus/Runwire own worker/host execution.

- `src/Worker/WorkerManager.php`
- `src/Worker/WorkerProvider.php`
- `src/Worker/WorkerRestartRequested.php`
- `src/Worker/WorkerRuntime.php`
- `src/Worker/WorkerTopology.php`

## Outstanding closure proof

Batch 2 #3: update the native package register with exact minimums, selected producer/consumer methods, negative security paths, persistence/migration and CI service/host receipts; distinguish unavailable/unselected optional transports from required runtime surface.

Batch 2 #4: inspect high-risk registrar, in-memory fallback, browser/auth session, OAuth token workflow, process runner, cache lock, filesystem, module and notification classes against published owner APIs. Remove duplicates rather than merely listing them.

Batch 2 #5: complete a **symbol-level** source/consumer ledger of all classes, public/protected methods, free functions and generated aliases; inspect reflection-driven registrations and dynamic references before any deletions; verify corresponding tests, migration semantics and source-free trusted artifacts.

Until these audits are complete, the tracker remains **Batch 2 5/8, overall 11/37**. Keep PR #20 draft and unmerged.
