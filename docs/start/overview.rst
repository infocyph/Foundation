.. _getting-started-overview:

==========================
What is Infbyte?
==========================

**Infbyte** is a lightweight PHP application skeleton and developer experience
built on **Infocyph Foundation**. You write application code in your Infbyte
project; Foundation composes lifecycle, configuration, service providers,
commands and trusted production releases. Native libraries do the specialized
work. The goal is to build real web, CLI and background applications without
paying to activate unused features.

This is the **Infbyte 4 development manual**. The published Infbyte
``main`` starter still requires Foundation 3. The compatible 4 candidate
is on the Infbyte ``feature/infbyte-4`` branch (PR #11), with an unpublished
Foundation 4 development dependency. Do not present it as stable.

What belongs to your project?
=============================

In a candidate Infbyte checkout:

.. code-block:: text

   my-app/
     app/Http/Controllers/SystemController.php
     bootstrap/app.php
     bootstrap/providers.php
     config/app.php
     config/auth.php
     config/cache.php
     config/modules.php
     config/notifications.php
     config/router.php
     routes/api.php
     routes/console.php
     routes/schedule.php
     routes/workers.php
     public/index.php
     tests/
     infbyte
     composer.json

**Every path on this page is relative to the Infbyte project**. The
Foundation library may contain similarly named configuration *templates* under
``resources/config/``, but those are not where an Infbyte developer makes
application changes.

A feature such as database, filesystem or messaging publishes an additional
application file (for example ``config/database.php``) only after its
optional feature is selected. You do not need all features for a Core HTTP
health endpoint.

What happens to one request?
============================

The candidate's ``config/router.php`` explicitly loads ``routes/api.php``.
Webrick matches the route and invokes the application controller. One shipped
endpoint is ``GET /api/health``, returning ``{"status":"ok"}``.

.. code-block:: text

   Selected HTTP host (PHP/FPM or supported native driver)
          |
   Infbyte public/index.php (trusted production bootstrap)
          |
   Foundation application composition and selected policies
          |
   Webrick matcher -> route middleware -> application handler
          |
   Webrick response -> HTTP host

A fixed handler doesn't have to instantiate an ORM, database connection,
session, queue worker or request-global service. Route middleware is
selected only when the route requires it.

What is the relationship to Laravel?
====================================

The **documentation experience** follows the useful parts of
Laravel-style learning: install an app, change an application-owned file,
run a command, verify a response, test failure paths and deploy correctly.
The APIs are **not** assumed to match Laravel.

Infbyte uses Webrick ``Registrar`` rather than a Route facade, InterMix
rather than Laravel's service container, DBLayer rather than Eloquent,
and Omnibus rather than a replacement queue engine. No bundled Blade
templating or automatic route/controller discovery should be inferred.

Application modes versus HTTP hosts
===================================

Foundation has four explicit application modes: **web, CLI, worker and
scheduler**. The selected HTTP host is a separate concern. An ordinary
nginx/PHP-FPM deployment can run with Webrick's SAPI adapter without a
native Runwire listener; a persistent Runwire host has different resource
and cleanup behavior.

For each request/job, scope current principals, browser sessions,
transactions and request-specific clients to that execution. Do not place
user-specific state in a process-wide singleton.

Where to begin
==============

For a working candidate installation, start with :doc:`installation`
and :doc:`foundation4-candidate`. Then complete
:doc:`first-route` and :doc:`first-application`.

After that, the central guides are :doc:`../http/routing`,
:doc:`../security/authentication`, :doc:`../data/database`,
:doc:`../testing/http` and :doc:`../deployment/overview`.
For detailed module ownership see :doc:`../internals/native-boundaries`.

A successful Sphinx build proves that this manual compiles; it does not
approve the separate Foundation source/host qualification or the
downstream Infbyte 4 release.
