.. _technical-guides:

Foundation internals and source references
==========================================

The **Infbyte** manual is the developer-facing documentation for application
projects. This page links to deliberately retained **Foundation package
internals** and release evidence. Normal application paths belong under the
Infbyte checkout's ``config/``, ``app/``, ``routes/`` and
``bootstrap/``, not Foundation's ``resources/config/`` templates.

For day-to-day usage prefer :doc:`../start/overview`,
:doc:`../architecture/application-lifecycle`,
:doc:`../http/routing`, :doc:`../security/authentication`,
:doc:`../data/database`, :doc:`../services/queues-workers`
and :doc:`../testing/overview`.

The remaining Markdown files document advanced ownership, migration,
protocol and certification topics that do not belong in the quickstart:

* `Foundation 4 setup and migration <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-migration.md>`_
* `Foundation 4 release notes <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4.0-release-notes.md>`_
* `Retained-state and recovery rehearsal <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-retained-data-rehearsal.md>`_
* `Native host certification <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-host-benchmark-certification.md>`_
* `Module implementation contracts <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/modules.md>`_
* `OAuth 2.1, OIDC and PAT <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/oauth-2.1.md>`_
* `OTP and WebAuthn internals <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/otp.md>`_
* `Runtime host ownership <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/runtime-hosting.md>`_
* `Operations and retained runtime state <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/operations.md>`_
* `Provider and ownership boundaries <https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/architecture/ownership-boundaries.md>`_

The source-checked API references are :doc:`application-api`,
:doc:`http-security-api`, :doc:`console-testing-api` and
:doc:`configuration`. Release/publication prerequisites remain in
:doc:`publication-readiness`.

For native component internals follow their own maintained owner manuals,
beginning with `InterMix <https://github.com/infocyph/InterMix/tree/main/docs>`_.
