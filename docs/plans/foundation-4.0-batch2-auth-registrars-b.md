# Foundation 4.0 — authentication registrar symbol census (part B)

**Source:** `182b5de43ade527ce427d5c1bf347a0d65efd3ee`; 13 remaining `Auth/Internal/` files by source declaration. Together with part A, all 26 `Auth/Internal/` source files have named method/type signatures enumerated. **This is not a completed public API/caller/AST or security sign-off.**

| Source | Types | Methods/functions |
| --- | --- | --- |
| `src/Auth/Internal/AuthPasskeyGraphFactory.php` | `class AuthPasskeyGraphFactory` | `public static passkey`, `public static service` |
| `src/Auth/Internal/AuthPasskeyRegistrar.php` | `class AuthPasskeyRegistrar` | `public register` |
| `src/Auth/Internal/AuthPasswordRegistrar.php` | `class AuthPasswordRegistrar` | `public register` |
| `src/Auth/Internal/AuthPersonalAccessTokenGraphFactory.php` | `class AuthPersonalAccessTokenGraphFactory` | `public static keys`, `public static policy` |
| `src/Auth/Internal/AuthPersonalAccessTokenRegistrar.php` | `class AuthPersonalAccessTokenRegistrar` | `public register` |
| `src/Auth/Internal/AuthProductionGuard.php` | `class AuthProductionGuard` | `public __construct`, `public guard`, `private guardTalkingBytesNotifications` |
| `src/Auth/Internal/AuthRuntimeGraphFactory.php` | `class AuthRuntimeGraphFactory` | `public static manager` |
| `src/Auth/Internal/AuthRuntimeRegistrar.php` | `class AuthRuntimeRegistrar` | `public register` |
| `src/Auth/Internal/AuthSecretResolver.php` | `class AuthSecretResolver` | `public __construct`, `public environmentName`, `public tokenSecret`, `private assertNoRawConfiguredSecret`, `private isInvalidProductionSecret` |
| `src/Auth/Internal/AuthStoreGraphFactory.php` | `class AuthStoreGraphFactory` | `public static forwardingAuditStore` |
| `src/Auth/Internal/AuthStoreRegistrar.php` | `class AuthStoreRegistrar` | `public register`, `private authConnection`, `private bindClockedDbStore`, `private bindMfaDbStore`, `private bindPlainDbStore`, `private clockedDbStores`, `private clockedMemoryStores`, `private plainDbStores`, `private plainMemoryStores`, `private registerDBLayerStores`, `private registerMemoryStores`, `private registerStoreAliases` |
| `src/Auth/Internal/AuthTokenRegistrar.php` | `class AuthTokenRegistrar` | `public __construct`, `public register`, `private bindClockedToken`, `private bindRememberTokens`, `private bindSingleDependencyToken`, `private bindTimedSingleDependencyToken`, `private registerEpicryptTokens`, `private registerSecretResolver`, `private registerSimpleTokens` |
| `src/Auth/Internal/EpicryptTokenPolicyResolver.php` | `class EpicryptTokenPolicyResolver` | `public __construct`, `public algorithm`, `public audience`, `public issuer`, `public leewaySeconds`, `public maximumLifetimeSeconds`, `public minimumKeyBytes`, `private nonNegativeInt`, `private positiveInt`, `private requiredValue` |

**Owner boundary:** Foundation account/session/authorization policy and graph registration; Epicrypt token/crypto/OAuth, OTP MFA/passkey, CacheLayer counters and DBLayer persistence. Compare each registrar recipe with corresponding published API and reject production simple/memory fallback. Mark retain/delegate/redesign/delete per method only with direct/reflective caller, persisted-state and CI service proof. Batch 2 #4/#5 still open.
