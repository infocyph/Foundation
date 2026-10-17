# Foundation 4.0 — immutable-release declaration census

**Source:** `182b5de43ade527ce427d5c1bf347a0d65efd3ee`; all seven `Release/` production files enumerated. Named type/method signatures only, not AST or consumer reachability certification. Foundation owns staging, trusted release manifests, atomic activation/fencing and generation leases; InterMix/Webrick/ArrayKit own their own native artifact format/trust.

| Source | Types | Named methods/functions |
| --- | --- | --- |
| `src/Release/ActiveGeneration.php` | `class ActiveGeneration` | `public activate`, `public clear`, `public current`, `public exists`, `public replacementRequired`, `private pointer`, `private root` |
| `src/Release/FoundationReleaseBootstrap.php` | `class FoundationReleaseBootstrap` | `public __construct`, `public static fromEnvironment`, `public static resolveReleaseRoot`, `public nonWeb`, `public web`, `private static absolute`, `private static basePath`, `private static environment` |
| `src/Release/FoundationReleaseBuildLock.php` | `class FoundationReleaseBuildLock` | `private __construct`, `public static acquire`, `public release` |
| `src/Release/FoundationReleaseCompiler.php` | `class FoundationReleaseCompiler` | `public __construct`, `public buildAndActivate`, `public clear`, `public prune`, `public status`, `private activeGenerationOrNull`, `private assertGeneration`, `private compileGeneration`, `private compileNonWebSection`, `private digestField`, `private generationPaths`, `private generationTimes`, `private matcherCacheRelativePath`, `private mkdir`, `private publishStage`, `private rebaseWebReleaseManifest`, `private releaseConfig`, `private removeDirectory`, `private root`, `private sha256`, `private stringField`, `private verifyStage`, `private writeAtomic` |
| `src/Release/FoundationReleaseConfig.php` | `class FoundationReleaseConfig` | `public static load`, `public static write` |
| `src/Release/FoundationReleaseManifest.php` | `class FoundationReleaseManifest` | `public static assertValid`, `public static capabilities`, `public static dependencyFingerprint`, `public static digest`, `public static load`, `public static nonEmptyString`, `public static relativePath`, `public static section`, `public static write`, `private static identifier`, `private static matcherCache`, `private static stringMap`, `private static workerTopology` |
| `src/Release/FoundationReleaseRuntime.php` | `class FoundationReleaseRuntime` | `public __construct`, `public nonWeb`, `public nonWebPrevalidated`, `public trustedActiveManifest`, `public web`, `public webPrevalidated`, `private activeManifest`, `private assertNonWeb`, `private attachGeneration`, `private attachWebGeneration`, `private matcherCachePath`, `private relative`, `private releaseConfig`, `private trustedActiveManifestWithLease` |

Each named symbol still needs actual owner/consumer/disposition and persisted-upgrade proof before Batch 2 #4/#5 acceptance. Count remains 5/8.
