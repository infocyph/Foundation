# Foundation 2.1.1 → 4.0 replacement map

**Source comparison:** published tag `2.1.1`, commit `f6749ebe0aa1f93c6b3586e8cb63ecc1f552d98b` versus draft Foundation 4 branch `feature/foundation-3`. This table accounts for **all 28 removed PHP paths beneath `src/`**. A replacement is a migration destination, not a compatibility alias or identical constructor. Retained class signatures can also have changed; rebuild and retest all consumer source and generated artifacts.

## Removed source paths

| Published 2.1.1 path (relative to `src/`, add `.php`) | Foundation 4 migration destination |
| --- | --- |
| `Auth/Adapter/CacheLayer/CacheLayerCounterStore` | CacheLayer native AtomicCounterStore; reject non-atomic stores |
| `Auth/Adapter/DBLayer/OAuth/DBLayerOAuthRefreshTokenStore` | DBLayerEpicryptRefreshTokenStore for Epicrypt-owned refresh rotation |
| `Auth/Adapter/WebAuthn/NoneWebAuthnAttestationPolicy` | native OTP Passkey/WebAuthn attestation policy |
| `Auth/Adapter/WebAuthn/Support/Base64Url` | native OTP/WebAuthn codec |
| `Auth/Adapter/WebAuthn/WebAuthnAttestationPolicyInterface` | OTP/WebAuthn-owned attestation contract |
| `Auth/Adapter/WebAuthn/WebAuthnChallengeRecord` | native OTP Passkey challenge |
| `Auth/Adapter/WebAuthn/WebAuthnChallengeStore` | native OTP Passkey state store |
| `Auth/Adapter/WebAuthn/WebAuthnCredentialMapper` | native OTP/WebAuthn credential mapping |
| `Auth/Adapter/WebAuthn/WebAuthnException` | native ceremony exception translated at application boundary |
| `Auth/Adapter/WebAuthn/WebAuthnPasskeyService` | AuthServices::passkeys() with selected OTP Passkey |
| `Auth/Adapter/WebAuthn/WebAuthnPublicKeyOptionsFactory` | native OTP Passkey public-key options |
| `Auth/Adapter/WebAuthn/WebAuthnRuntime` | native selected OTP Passkey runtime |
| `Auth/OAuth/Contract/OAuthRefreshTokenStoreInterface` | native Epicrypt OAuth refresh store contract |
| `Auth/OAuth/Token/OAuthRefreshRotationResult` | Epicrypt refresh-rotation result |
| `Auth/OAuth/Token/OAuthRefreshRotationStatus` | Epicrypt refresh-rotation status |
| `Auth/OAuth/Token/OAuthRefreshTokenCoordinator` | Epicrypt OAuth token manager/exchange |
| `Auth/OAuth/Token/OAuthRefreshTokenIssue` | Epicrypt refresh-token issuance |
| `Auth/OAuth/Token/OAuthRefreshTokenRecord` | Epicrypt Auth/OAuth/RefreshTokenRecord; preserve old family IDs as opaque |
| `Auth/Support/HmacTokenCodec` | configured Epicrypt purpose-token policy, or explicit Foundation simple-token driver |
| `Config/ConfigMerger` | ConfigLoader + compiled ConfigRepository; no per-request merges |
| `Container/ContainerCacheManager` | InterMix native generated graph in immutable release |
| `Container/ContainerFactory` | FoundationGraph + native InterMix ContainerBuilder |
| `Module/Internal/CacheSchemaManager` | Cache/CacheSchemaManager with cache:schema:install/status |
| `Routing/RouteCacheManager` | native Webrick 6 compiled/fused route release |
| `Routing/RouteCachePath` | release trust identity and Webrick native manifest paths |
| `Runtime/RuntimeContextTracker` | ExecutionScope with scoped RuntimeExecutionState and host-passed Runwire contexts |
| `Validation/ReqShieldDatabaseProvider` | ReqShield native database validation contracts |
| `Validation/ValidationSchemaRegistry` | ReqShield native schema validation ownership |

## Changed retained contracts and configuration

| 2.1.1 behavior/entry | Direct 4.0 action |
| --- | --- |
| `ServiceProviderInterface::register(Application)`, `$app->register`, legacy binding helpers | Graph-time `ServiceProvider::contribute(ContainerBuilder, FoundationBuildContext)` with native InterMix `factory` / `input` / lifetime declarations. No runtime mutation |
| `Application::container()` used as mutable singleton registry | Resolve through `make()` only inside an active owner scope; use `ExecutionScope::run()` for CLI/worker/scheduler, Webrick for web |
| `app.container.alias`, `app.container.compiled`, `app.container.compiled_activation`, `router.cache` | Remove settings; generate one trusted immutable release with InterMix ABI 3 and Webrick 6 format-3 router |
| Optional cache/cachelayer module | CacheLayer `^4` is directly required; use `cache:schema:status` and `cache:schema:install`, not `module:install cache` |
| Legacy optional modules and embedded notification ownership | Seven specialist modules and four built-ins; explicitly select optional features and retain native notifications |
| Foundation-owned token/WebAuthn/OAuth refresh semantics | Epicrypt owns OAuth/token cryptography, OTP Passkey owns ceremonies, Foundation owns app policy and persistence integration |
| Old independently generated container/router/cached config | Throw away artifacts; recompile web/CLI/worker/scheduler and worker topology under a single generation with trusted SHA and dependency identity |
| Runwire installation or implicit process context | Only when capability selected: pass *host-owned* `Runwire\RuntimeContext` to `WebReleaseRuntime::runwireApplication($runtime)`; pass request/CoroutineScope through native Webrick bridge; no second emitter/host |
| Worker child scope recreation | Capture live native InterMix `ScopeContext` on the owner and attach through `ExecutionScope::withinBorrowedScope`; no additional cleanup |

The 4.0 [migration guide](foundation-4-migration.md), [data/restore rehearsal](foundation-4-retained-data-rehearsal.md), and [CLI example](examples/foundation-4-core.php) provide the cutover workflow. **No backwards-compatibility shim** is provided for retired symbols.

Infbyte application's [main composer manifest](https://github.com/infocyph/Infbyte/blob/main/composer.json) still requires Foundation `^3.0.1` as of 2026-10-14; candidate rehearsals must use an isolated temporary Composer path repository and update the application constraints separately before a 4.x release.
