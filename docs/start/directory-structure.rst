.. _directory-structure:

Application directory structure
===============================

The Infbyte application skeleton supplies the application code and configuration;
Foundation supplies reusable runtime behavior. Most developers work inside
``app/``, ``routes/``, ``config/`` and ``tests/``.

This layout describes **Infbyte main (Foundation 3)**. A development
Foundation 4 candidate already exists in Infbyte PR #11 and additionally
contains ``config/modules.php`` for explicit feature selection. It is
**not a published stable starter**; see :doc:`foundation4-candidate`.

.. code-block:: text

   my-app/
   ├── app/
   │   └── Http/Controllers/SystemController.php
   ├── bootstrap/
   │   ├── app.php
   │   ├── providers.php
   │   └── cache/
   ├── config/
   │   ├── app.php
   │   ├── auth.php
   │   ├── cache.php
   │   ├── notifications.php
   │   └── router.php
   ├── public/index.php
   ├── routes/
   │   ├── api.php
   │   ├── console.php
   │   ├── schedule.php
   │   └── workers.php
   ├── storage/releases/
   ├── tests/
   ├── composer.json
   └── infbyte

What each directory owns
------------------------

* **app/** contains your application classes. Composer maps the
  ``App\\`` namespace to this directory.
* **bootstrap/app.php** selects Foundation's web runtime for the development
  bootstrap. **bootstrap/providers.php** registers application service
  providers, separated by runtime.
* **config/** contains application-owned configuration. Selected optional
  feature configuration is published here only when needed.
* **routes/** holds explicit registration inputs. The router loads only
  configured route files; arbitrary controllers and routes are not discovered.
* **public/index.php** is the web entrypoint, using trusted release metadata
  and Webrick's native response writer.
* **storage/releases/** contains generated trusted release artifacts. Never
  edit these files or reuse an incompatible compiled generation.

How to extend it
----------------

The application CLI offers generators such as:

.. code-block:: bash

   php infbyte create:controller Admin/User
   php infbyte create:command Reports/Daily

A generated class is not automatically registered as a route or provider;
register it deliberately in the appropriate application file. Availability
of optional generators depends on selected capabilities.

See :doc:`configuration` and
:doc:`../architecture/service-providers` before adding services.
