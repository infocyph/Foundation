# Foundation 4.0 — browser-session symbol census

**Source:** `182b5de43ade527ce427d5c1bf347a0d65efd3ee`, all 18 production `Session/` files inspected for named PHP type/method/function declarations. This is a signature census, **not** automatic disposition or AST verification. Browser sessions own cookie, flash, request CSRF and per-request finalization; account/device auth sessions are separately stored in `Auth/Authentication/Session/`. Webrick owns HTTP execution scopes, CacheLayer owns atomic locks, DBLayer owns persistence.

| Source | Types | Methods/functions |
| --- | --- | --- |
| `src/Session/BrowserSession.php` | `class BrowserSession` | `public __construct`, `public static fromRequest`, `public all`, `public commit`, `public csrfToken`, `public flash`, `public flashInput`, `public forget`, `public get`, `public has`, `public id`, `public invalidate`, `public keep`, `public oldInput`, `public pull`, `public put`, `public reflash`, `public regenerate`, `public regenerateCsrfToken`, `public release`, `public wasAccessed`, `private static generateId`, `private acquireLock`, `private assertLockOwned`, `private assertOpen`, `private deleteCurrent`, `private load` |
| `src/Session/Middleware/CsrfMiddleware.php` | `class CsrfMiddleware` | `public __construct`, `public __invoke`, `private rejected`, `private validOrigin` |
| `src/Session/Middleware/SessionMiddleware.php` | `class SessionMiddleware` | `public __construct`, `public __invoke`, `private cookie` |
| `src/Session/SessionCommit.php` | `class SessionCommit` | `public __construct` |
| `src/Session/SessionConfig.php` | `class SessionConfig` | `public __construct`, `public static fromRepository`, `private static absolute`, `private static bool`, `private static filePath`, `private static identifier`, `private static nonEmptyString`, `private static nonNegativeFloat`, `private static nullableString`, `private static oneOf`, `private static positiveFloat`, `private static positiveInt` |
| `src/Session/SessionDatabaseSchema.php` | `class SessionDatabaseSchema` | `public __construct`, `public install`, `public readiness`, `private schema` |
| `src/Session/SessionExecutionState.php` | `class SessionExecutionState` | `public __destruct`, `public reset` |
| `src/Session/SessionGraphFactory.php` | `class SessionGraphFactory` | `public static config`, `public static current`, `public static lockedManager`, `public static manager` |
| `src/Session/SessionManager.php` | `class SessionManager` | `public __construct`, `public config`, `public current`, `public enter`, `public leave`, `public open`, `public prune`, `public resetContext`, `private state`, `private store` |
| `src/Session/SessionPayload.php` | `class SessionPayload` | `public __construct`, `public static fromArray`, `public static fromJson`, `public toArray`, `public toJson` |
| `src/Session/SessionServiceProvider.php` | `class SessionServiceProvider` | `public contribute`, `private registerCore`, `private registerManager`, `private registerMiddleware`, `private registerStore` |
| `src/Session/SessionStoreFactory.php` | `class SessionStoreFactory` | `public __construct`, `public make` |
| `src/Session/SessionStoreInterface.php` | `interface SessionStoreInterface` | `public delete`, `public load`, `public prune`, `public save` |
| `src/Session/SessionTopologyGuard.php` | `class SessionTopologyGuard` | `public __construct`, `public assert` |
| `src/Session/Store/ArraySessionStore.php` | `class ArraySessionStore` | `public delete`, `public load`, `public prune`, `public save` |
| `src/Session/Store/CacheSessionStore.php` | `class CacheSessionStore` | `public __construct`, `public delete`, `public load`, `public prune`, `public save`, `private key` |
| `src/Session/Store/DatabaseSessionStore.php` | `class DatabaseSessionStore` | `public __construct`, `public delete`, `public load`, `public prune`, `public save` |
| `src/Session/Store/FileSessionStore.php` | `class FileSessionStore` | `public __construct`, `public delete`, `public load`, `public prune`, `public save`, `private static filesystem`, `private ensureDirectory`, `private isPrunable`, `private path` |

**Remaining:** inspect direct/reflective callers and per-request ownership, exactly-once lock release, cleanup failure precedence, source-free release, tenant boundaries and persisted state. Do not delete browser-session policy merely because DBLayer/Webrick owns primitives. Criterion #4/#5 remain open at 5/8.
