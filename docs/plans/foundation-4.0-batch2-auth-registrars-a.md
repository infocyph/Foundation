# Foundation 4.0 — authentication registrar symbol census (part A)

**Source:** `182b5de43ade527ce427d5c1bf347a0d65efd3ee`; named declarations in 13 selected `Auth/Internal/` files, first half of that directory. Text-signature census only, not complete security/AST/consumer review. Foundation owns account/consent/authorization/MFA/provider selection, whereas Epicrypt/OTP/CacheLayer/DBLayer own algorithms, protocol, counters and persistence. Production guards must reject insecure simple/memory implementations.

| Source | Types | Methods/functions |
| --- | --- | --- |
| `src/Auth/Internal/AbstractAuthRegistrar.php` | `class AbstractAuthRegistrar` | `public __construct`, `protected alias`, `protected boolConfig`, `protected intConfig`, `protected nullableString`, `protected recipe`, `protected ref`, `protected requirePackage`, `protected staticRecipe`, `protected stringConfig`, `protected stringList` |
| `src/Auth/Internal/AuthAuthorizationRegistrar.php` | `class AuthAuthorizationRegistrar` | `public static gate`, `public register` |
| `src/Auth/Internal/AuthCacheRegistrar.php` | `class AuthCacheRegistrar` | `public register` |
| `src/Auth/Internal/AuthCoreRegistrar.php` | `class AuthCoreRegistrar` | `public __construct`, `public register` |
| `src/Auth/Internal/AuthHttpGraphFactory.php` | `class AuthHttpGraphFactory` | `public static requestPrincipalResolver` |
| `src/Auth/Internal/AuthManagerRegistrar.php` | `class AuthManagerRegistrar` | `public register` |
| `src/Auth/Internal/AuthMfaGraphFactory.php` | `class AuthMfaGraphFactory` | `public static challengeFactors`, `public static recoveryCodes`, `public static recoveryCodeStore`, `public static secretProtector`, `public static verifier` |
| `src/Auth/Internal/AuthMfaKeyResolver.php` | `class AuthMfaKeyResolver` | `public __construct`, `public allowLegacyPlaintext`, `public assertProductionReady`, `public mfaSecretKeyRing`, `public recoveryHmacKey`, `private assertProtectionKey`, `private environmentName`, `private keyStatus`, `private mfaKeyEntry`, `private timestamp` |
| `src/Auth/Internal/AuthMfaRegistrar.php` | `class AuthMfaRegistrar` | `public register`, `public registerOtpSupport`, `private registerOtpDriver` |
| `src/Auth/Internal/AuthNotificationGraphFactory.php` | `class AuthNotificationGraphFactory` | `public static talkingBytes` |
| `src/Auth/Internal/AuthNotificationRegistrar.php` | `class AuthNotificationRegistrar` | `public register`, `private criticalTypes`, `private notificationFrom` |
| `src/Auth/Internal/AuthOAuthGraphFactory.php` | `class AuthOAuthGraphFactory` | `public static authorizationCodeKeys`, `public static endpointUri`, `public static epicryptSigningKeySet`, `public static openIdSigningKeySet`, `public static refreshTokenKeys`, `public static signingKeySet` |
| `src/Auth/Internal/AuthOAuthRegistrar.php` | `class AuthOAuthRegistrar` | `public enabled`, `public register`, `private authConnection`, `private registerEpicryptProtocol`, `private registerEpicryptStores`, `private registerFoundationFacades`, `private registerFoundationPolicy`, `private registerFoundationStores` |

**Still open:** direct/reflective dependency owners, user account/replay/race safety, token migration and native API comparisons for each symbol; no #4/#5 acceptance until that proof is complete.
