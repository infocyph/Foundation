.. _development-server:

Local development server
========================

The published Infbyte starter (Foundation 3) includes an application CLI
named ``infbyte``, not Laravel's Artisan console. It supports a local
server for development. The separate Foundation 4 candidate exists in
Infbyte PR #11, but needs fresh exact-source validation; see
:doc:`foundation4-candidate`.

Start the published starter
---------------------------

.. code-block:: bash

   composer create-project infocyph/infbyte my-app
   cd my-app
   php infbyte serve

The published skeleton documents ``127.0.0.1:8000`` as the default
local address. Specify a different port with:

.. code-block:: bash

   php infbyte serve --host=127.0.0.1 --port=8080

Inspect commands and routes
---------------------------

.. code-block:: bash

   php infbyte list
   php infbyte help route:list
   php infbyte route:list

The current application contains an explicit ``/api/health`` route.
Read :doc:`first-application` for its real handler and registration.

When changes become active
--------------------------

The existing starter initializes a compiled release for its development
server. Restart ``serve`` after changing route files, provider topology,
or other build-time configuration. Do not assume the production runtime
will rediscover modified PHP route files automatically.

Production is separate
----------------------

Do not expose this development server to public production traffic.
Foundation 4 production requires configuration/readiness checks and a
trusted compiled release. The selected host adapter and external process
supervision are independent concerns. Merely installing Runwire does not
start a persistent HTTP server.

See :doc:`../architecture/request-lifecycle` for the web boundary.
