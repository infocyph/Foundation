# Foundation 3.0

Foundation 3.0 is the application composition, runtime policy and release layer
for the Infocyph stack. Start here, then follow the focused guides below.

## Start

- [README / minimal application](../README.md)
- [Configuration](configuration.md)
- [Modules and capabilities](modules.md)
- [Provider and ownership boundaries](architecture/ownership-boundaries.md)
- [Console and lifecycle commands](console.md)

## Web and application state

- [HTTP and capabilities](http-and-capabilities.md)
- [Browser sessions and CSRF](browser-sessions.md)
- [Authentication and authorization](authentication.md)
- [Security boundaries](security.md)
- [Filesystem HTTP bridge](filesystem.md)

## Infrastructure integrations

Foundation deliberately composes rather than reimplements specialist libraries:

- DBLayer for database mechanics;
- CacheLayer for core cache/coordination;
- Pathwise for filesystem/storage mechanics;
- Omnibus for messaging;
- TalkingBytes for communication;
- Epicrypt/OTP/WebAuthn for selected security/auth features.

Use the native specialist API for specialist behavior and Foundation's config /
provider boundary only for application policy.

## Production release

1. Select explicit production capabilities.
2. Run `php infbyte config:validate --production`.
3. Run `php infbyte app:ready`.
4. Build the immutable generated release with `php infbyte optimize`.
5. Provide the generated Foundation manifest digest to each process through
   deployment-controlled configuration.
6. Start web/CLI/worker/scheduler processes from that generation.
7. Keep at least one previous generation until old workers have drained; rollback
   by atomically activating the retained generation.
8. Prune old generations only after they are no longer referenced by running
   processes.

See:

- [3.0 tested runtime support](foundation-3-runtime-support.md)
- [3.0 migration guide](foundation-3-migration.md)
- [Architecture](architecture.md)
- [Operations](operations.md)
- [Security](security.md)

## Infbyte consumers

Infbyte is a consumer of Foundation, not a Foundation dependency. Foundation 3.0
keeps its public module/config/release contracts standalone. Publishing the
matching stable Infbyte skeleton dependency/bootstrap update is a separate
post-Foundation task and is intentionally not part of this repository's 3.0
release gate.
