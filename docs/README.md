# Foundation documentation

Foundation is the application composition layer for the Infocyph packages. It
owns application lifecycle and integration policy; standalone libraries retain
their domain behavior.

- [Architecture and lifecycle](architecture.md)
- [Configuration](configuration.md)
- [HTTP and optional capabilities](http-and-capabilities.md)
- [Communication and email](communication.md)
- [Console, schedules, and workers](console.md)
- [Authentication and authorization](authentication.md)
- [OTP-backed MFA](otp.md)
- [Browser sessions and CSRF](browser-sessions.md)
- [Database migrations and seeding](database.md)
- [Filesystem and storage](filesystem.md)
- [Events, queues, and scheduled messages](messaging.md)
- [JSON resources and JsonDispatch](json-responses.md)
- [Structured logging](logging.md)
- [Testing](testing.md)
- [Modules](modules.md)
- [Operations](operations.md)
- [Security boundaries](security.md)

The configuration templates under `resources/config/` are the canonical
key-by-key reference. Each template documents types, defaults, predefined
values, and examples next to the value that is published into an application.
