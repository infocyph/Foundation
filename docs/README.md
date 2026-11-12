# Infbyte documentation

The public-facing framework manual is built with Sphinx from [`index.rst`](index.rst) and published as **Infbyte — PHP Application Framework**.

> **Version status:** the new manual currently targets the *unpublished Foundation 4 development branch*. The Infbyte starter application on its `main` branch still requires Foundation 3. The Foundation 4 onboarding examples must be validated against a compatible Infbyte skeleton before publication.

Build the manual with:

```bash
python -m pip install -r docs/requirements.txt
sphinx-build -b html -n -W --keep-going docs docs/_build/html
```

For deeper application workflows, the Sphinx manual now includes four task-oriented walkthroughs covering request validation, secure browser sessions, database migrations, and durable messaging (see [the RST navigation](index.rst)).

The legacy Markdown links below remain technical source references during the phased RST conversion. Do not remove them until their content and links are checked and migrated.

## Foundation technical reference

Foundation is the application composition layer for the Infocyph packages. It
owns application lifecycle and integration policy; standalone libraries retain
their domain behavior.

- [Architecture and lifecycle](architecture.md)
- [Foundation 4 release notes](foundation-4.0-release-notes.md)
- [Foundation 4 setup and runtime guide](foundation-4-migration.md)
- [Foundation 4 public/provider/config contract](foundation-4-api-map.md)
- [Foundation 4 state-integrity and recovery](foundation-4-retained-data-rehearsal.md)
- [Foundation 4 host performance certification](foundation-4-host-benchmark-certification.md)
- [Runtime hosting and Runwire](runtime-hosting.md)
- [Configuration](configuration.md)
- [HTTP and optional capabilities](http-and-capabilities.md)
- [Communication and email](communication.md)
- [CLI, schedules, and workers](console.md)
- [Authentication and authorization](authentication.md)
- [OAuth 2.1 extension](oauth-2.1.md)
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
key-by-key reference for publishable configuration. Runtime behavior and public
command/module names in these guides follow the Foundation source on the current
branch; release verification is enforced by the
[QA workflow](../.github/workflows/security-standards.yml) and
[native host certification](foundation-4-host-benchmark-certification.md).
