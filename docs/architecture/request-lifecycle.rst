.. _request-lifecycle:

HTTP request lifecycle
======================

An HTTP request crosses three responsibilities: the selected HTTP host,
the compiled Foundation application, and Webrick's HTTP execution engine.
This separation avoids redundant middleware, duplicate emitters and
unnecessary work on simple routes.

Registration during build
-------------------------

Application route files are selected explicitly in the router
configuration. The current Infbyte skeleton's ``config/router.php``
includes ``api.php``, where the native Webrick ``Registrar``
registers routes.

Foundation arranges application composition; Webrick compiles HTTP
routing and execution metadata. Production requests use the compiled
artifacts rather than rescanning application route files.

Trusted web bootstrap
---------------------

The current Infbyte starter's ``public/index.php`` uses the
``FoundationReleaseBootstrap`` entrypoint, illustrating the trusted
release boundary. This shortened example retains the same API calls:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Release\FoundationReleaseBootstrap;

   require dirname(__DIR__) . '/vendor/autoload.php';

   $config = ['base_path' => dirname(__DIR__)];
   $release = FoundationReleaseBootstrap::fromEnvironment($config)
       ?? throw new RuntimeException('No trusted release generation configured.');

   $release->web($config)->server->handle();

External deployment/service configuration supplies the trusted release
manifest identity. Never consider a checksum read solely from a writable
generation to be independent trust evidence.

From request to response
------------------------

1. The selected host chooses the HTTP adapter. Plain PHP/FPM may use
   Webrick's SAPI path without a live Runwire context.
2. Webrick finds the compiled route and invokes selected middleware
   and handler logic.
3. If scoped services are needed, Webrick enters and exits the
   appropriate InterMix request scope.
4. Webrick adapts the handler result to its response contract and
   owns the single native response write.
5. Foundation completes its own scoped application cleanup.

Foundation does not wrap each route in a separate universal outer
scope. Do not globally enable authentication, sessions, CSRF or
database work for routes that do not need them.

Troubleshooting
---------------

* A new route is missing: check the explicit route file list and rebuild.
* Production bootstrap rejects an artifact: verify external manifest
  identity and Composer/dependency fingerprints; do not bypass trust.
* State survives across persistent-host requests: examine scoped
  lifetimes and release behavior.
* Runwire is installed but not active: this may be entirely correct for
  the selected plain PHP/FPM path.

See :doc:`application-lifecycle` and
:doc:`../start/first-application`.
