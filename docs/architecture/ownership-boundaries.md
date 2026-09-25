# Foundation ownership and extension boundaries

Foundation is the application-composition and policy layer. It owns configuration,
capability selection, provider composition, request/job policy, release generation
coordination and application-facing integration contracts. It does not own the
transport, cryptographic, database, cache, filesystem or message-broker mechanics
provided by specialist Infocyph packages.

## Dependency direction

Production Foundation code must not depend on Infbyte, PHPForge, PHPProbe or
Foundation's `Testing` namespace. Infbyte is a consumer. PHPForge/PHPProbe are
development verification tools. Testing helpers are application-test conveniences.

Request/job execution code under Auth, HTTP, Messaging, Scheduling, Session and
Worker must not invoke release/build compilers. Release compilation belongs to the
build/deploy plane; runtime code may consume immutable generated artifacts and
loaded-generation identities only.

Optional specialist packages remain outside Foundation's runtime `require` set.
CacheLayer is the deliberate exception because cache/coordination is Foundation
core infrastructure in 3.0.

## Provider lifetimes

Application providers contribute deterministic definitions during composition.
Singletons may hold immutable configuration or process-safe services. Request/job
state must use InterMix scoped lifetimes or explicit execution state and must be
cleared on scope leave. Persistent hosts must not retain principals, browser
sessions, transactions, request objects or execution identifiers between requests.

## Public extension seams

Use existing contracts rather than adding forwarding facades:

- application composition: `ServiceProvider` / `ServiceProviderInterface`;
- commands: `CommandHandlerInterface` plus explicit `CommandDefinition`;
- browser-session persistence: `SessionStoreInterface`;
- notification delivery: register channels through the native notification channel
  registry/dispatcher contract;
- workers and scheduled work: the existing Worker/Scheduling provider contracts;
- HTTP: Webrick request/response/router/runtime contracts;
- specialist behavior: the owning package's native interfaces.

Extensions are explicit registration/build-time inputs. Foundation does not scan
arbitrary packages or execute package code merely because Composer installed it.


## Native integration ownership

Foundation should prefer a specialist package's existing bridge when the bridge
already matches the required contract exactly. A Foundation adapter remains
appropriate only when it contributes application policy, lifecycle, persistence,
trust-boundary translation, or another contract that the native bridge does not
own.

Current Foundation 3 decisions:

- Webrick's `Interop\CacheLayer\AtomicCounterAdapter` is the active bridge from
  Foundation's selected CacheLayer atomic counter to Webrick throttling. The
  older Foundation wrapper is compatibility-only.
- Shared cache-backed authentication requires a configured CacheLayer atomic
  counter. Foundation must not silently replace shared lockout state with
  read/modify/write cache mutation. Explicit array/in-memory auth state remains
  process-local development/test behavior.
- Epicrypt owns OAuth/OIDC protocol validation and refresh-token protocol
  mechanics. Foundation maps accepted protocol results into application scopes,
  permissions, account state, audit, persistence, and HTTP presentation. The
  Foundation 3 HTTP authorization path reuses one native protocol evaluation.
- Epicrypt's `RefreshTokenManager` and native refresh-token contracts are the
  canonical Foundation 3 OAuth refresh runtime. Legacy Foundation OAuth refresh
  coordinator/store/types are compatibility-only and are not registered in the
  default graph.
- Pathwise owns download preparation, range resolution, validation, and streaming
  mechanics. Foundation may reuse a Pathwise preparation for HTTP conditional
  handling and a full response, but it must retain Pathwise's later stream/body
  freshness checks.
- Foundation deliberately retains its raw browser-session CSRF proof contract in
  3.x. Webrick's `Csrf::matchesValue()` additionally accepts masked proofs, so
  substituting it would be a behavior expansion rather than a semantics-neutral
  reuse change.
- `HttpKernel` remains a Foundation 3.x public/development compatibility facade.
  Generated production web graphs already remove it and execute through Webrick's
  native release runtime.


### Named cache resource identity

Foundation's `CacheManager` owns application/generation identity for named
CacheLayer stores and lock providers. The configured default store is an alias
of its canonical configured name, not a second cache identity. Consumers that
select a named application cache resource—including DBLayer query caching,
browser-session coordination, OTP/passkey transient state, webhook replay
state, migration locks, scheduler overlap locks, and singleton-worker locks—
resolve that resource through `CacheManager`.

`CacheLayerFactory` remains the construction boundary for CacheLayer-native
stores, counters, clusters, transports, schema/runtime helpers, and explicit
infrastructure clients. It does not become a second application cache registry.

Lock providers may be generation-owned and reused; each acquired lock handle
remains operation/request/job-local and must retain its native acquire,
refresh, and release lifecycle.

The transactional invalidation factory is intentionally separate. It binds
CacheLayer's outbox to the exact execution-owned DBLayer transaction/PDO and
must not be globally memoized or redirected through infrastructure-owned PDO
state.
