.. _home:

===================================
Infbyte — PHP Application Framework
===================================

Build PHP applications on Foundation's explicit lifecycle, native libraries,
and lightweight application skeleton.

.. warning::

   **Foundation 4 development documentation.** This manual describes the
   in-progress Foundation 4 contracts in the
   `feature/foundation-3` branch. The Infbyte starter application's
   published `main` branch still requires Foundation 3. Do not assume the
   current `composer create-project` command creates a Foundation 4 application.
   A Foundation 4 **development candidate** is available on Infbyte PR #11;
   it is not a stable published starter.

This manual is written for application developers. When a feature belongs to
a specialist Infocyph package, its guide shows how it fits into Infbyte
without inventing a replacement framework API.

Start here
==========

Read :doc:`start/overview` to understand the framework, follow
:doc:`start/installation` and :doc:`start/first-application`, then explore
the application lifecycle and providers.

.. toctree::
   :caption: Getting started
   :maxdepth: 2

   start/overview
   start/installation
   start/foundation4-candidate
   start/directory-structure
   start/configuration
   start/development-server
   start/first-route
   start/first-application

.. toctree::
   :caption: Step-by-step tutorials
   :maxdepth: 2

   tutorials/validate-an-api-request
   tutorials/secure-browser-session
   tutorials/database-migrations
   tutorials/durable-queue
   tutorials/json-api-responses
   tutorials/choose-capabilities
   tutorials/test-an-application
   tutorials/release-and-rollback

.. toctree::
   :caption: HTTP development
   :maxdepth: 2

   http/routing
   http/controllers
   http/middleware
   http/requests-responses
   http/validation

.. toctree::
   :caption: Security
   :maxdepth: 2

   security/authentication
   security/authorization
   security/sessions-csrf
   security/advanced-authentication
   security/production

.. toctree::
   :caption: Data & storage
   :maxdepth: 2

   data/database
   data/cache
   data/filesystem

.. toctree::
   :caption: Application services
   :maxdepth: 2

   services/events-jobs
   services/queues-workers
   services/communication
   services/notifications
   services/resources-logging

.. toctree::
   :caption: Console and scheduling
   :maxdepth: 2

   console/overview
   console/commands
   console/scheduling
   console/workers

.. toctree::
   :caption: Testing
   :maxdepth: 2

   testing/overview
   testing/http
   testing/fakes
   testing/database
   testing/persistent-runtimes

.. toctree::
   :caption: Advanced
   :maxdepth: 2

   advanced/runtime-modes
   advanced/modules
   advanced/performance

.. toctree::
   :caption: Deployment
   :maxdepth: 2

   deployment/overview
   deployment/hosting
   deployment/releases
   deployment/operations
   deployment/troubleshooting

.. toctree::
   :caption: Framework & reference
   :maxdepth: 2

   reference/cli
   reference/application-api
   reference/http-security-api
   reference/console-testing-api
   reference/configuration
   reference/publication-readiness

.. toctree::
   :caption: Framework & internals
   :maxdepth: 2

   architecture/application-lifecycle
   architecture/request-lifecycle
   architecture/service-providers
   internals/native-boundaries
   reference/technical-guides
   reference/maintenance
