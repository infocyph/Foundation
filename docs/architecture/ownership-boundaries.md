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
