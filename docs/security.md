# Security boundaries

Foundation security is layered by ownership:

- Foundation owns auth orchestration, authorization, browser sessions, CSRF,
  configuration policy, and safe exception responses.
- Epicrypt owns password, token, key, and cryptographic primitives.
- ReqShield owns request validation and sanitization.
- Webrick owns HTTP parsing, signed routes, middleware, and emission.
- Omnibus owns message serialization limits and delivery semantics.
- Pathwise owns filesystem and upload policy.

Optional providers remain lazy. Public stateless routes must not resolve auth
or browser-session services. Auth middleware is the activation boundary for
principals; session/CSRF middleware is the activation boundary for stateful
browser requests.

Production readiness rejects insecure auth-driver choices and missing secrets,
reports pending migrations and missing database-session schema, validates
cache topology, and reports unwritable runtime paths. It complements rather
than replaces a deployment review.

Keep exception messages and traces disabled unless operationally required.
Structured logging recursively redacts configured key fragments. Queue and
config caches must contain serializable explicit definitions—never closures or
secrets that should stay outside compiled artifacts.

Persistent HTTP and queue runtimes clear principals, sessions and leases,
transactions, observation buffers, and scoped container instances in `finally`
blocks. Any application singleton that stores tenant-, request-, message-, or
principal-specific state violates the runtime contract.

Before release, review authentication, session cookies, trusted origins and
proxies, CSRF, upload roots, message aliases and payload limits, database
credentials, cache lock placement, signed URLs, log redaction, and runtime
permissions.

