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
   start/directory-structure
   start/configuration
   start/development-server
   start/first-route
   start/first-application

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

.. toctree::
   :caption: Framework & references
   :maxdepth: 2

   architecture/application-lifecycle
   architecture/request-lifecycle
   architecture/service-providers
   internals/native-boundaries
   reference/technical-guides
