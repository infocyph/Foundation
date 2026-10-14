# Foundation 4.0 public API and native ownership contract

Foundation 4.0 is a deliberate breaking major release. This document describes **current 4.0** entrypoints and integration responsibilities; it is **not** a backwards-compatibility, removed-symbol, or migration map.

## Current public application contract

| Surface | Supported 4.0 behavior |
| --- | --- |
| Bootstrap | `Foundation::web()`, `::cli()`, `::worker()`, `::scheduler()`; select the runtime explicitly |
| Providers | `ServiceProviderInterface::contribute(ContainerBuilder, FoundationBuildContext)` declares services at graph-build time; `boot(Application)` performs post-finalization side effects, never graph mutation |
| Resolution | `Application::make()` in its valid owner scope; `Application::container()` is development-only and is unavailable in generated production runtimes |
| Non-web scope | `ExecutionScope::run()` and `::withinBorrowedScope()` preserve native InterMix input/scope ownership |
| Capabilities | Explicit `app.capabilities` selects optional integrations; installing a library does not activate it |
| Native HTTP | Webrick owns HTTP request lifecycle, route matching and response output |
| Release | InterMix ABI 3 + Webrick 6 format-3 artifacts are generated as one trusted immutable release with validated generation/dependency identity |
| Runwire | Optional and explicitly selected; host passes exact `RuntimeContext` and request/scope inputs to the native Webrick/InterMix integration; Foundation does not create another server, scheduler or context owner |
| Persistence | DBLayer owns database operations/migrations; CacheLayer owns cache/counters/outbox; Omnibus owns messaging |
| Authentication | Foundation owns application policy; Epicrypt owns crypto/OAuth/token operations; OTP/WebAuthn owns MFA and passkey ceremonies |
| Other specialists | Pathwise owns filesystem/storage, TalkingBytes owns communication, ReqShield owns validation, ArrayKit owns configuration primitives |

## Release contract verification

The Security & Standards matrix verifies PHP 8.4/8.5, lowest/stable dependency resolution, production `--no-dev` core-only, database-only, and selected-specialist consumers. The full selected consumer builds and boots a trusted compiled Webrick route and CLI/worker/scheduler generations; the Runwire worker validates host-owned request/runtime identity.

Use [the Foundation 4 setup guide](foundation-4-migration.md) and [current-state recovery guidance](foundation-4-retained-data-rehearsal.md). Source APIs and canonical `resources/config/` files remain authoritative. Additional current-4.0 contract verification is an open Batch 5 acceptance gate until supported by exact-source CI.

**No compatibility alias or retained public API parity with Foundation 2.x or 3.x is required.** Infbyte application integration is an independent follow-up.
