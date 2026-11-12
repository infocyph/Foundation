.. _configuration-reference:

Application configuration reference
===================================

The canonical key-by-key defaults for Foundation 4 are the PHP
templates under ``resources/config/``. This page is a
developer-oriented **index and dependency map**, not a duplicate
source of truth for every underlying DBLayer, Omnibus or
TalkingBytes option.

Foundation 4's public Infbyte documentation is still development
material. The Infbyte starter on ``main`` depends on
Foundation 3 and may have different configuration structure.

Configuration precedence and release
------------------------------------

In development/build composition, Foundation loads its defaults,
selected preset, project ``config/*.php`` and inline bootstrap
overrides. For production, a validated immutable generation contains
the composed config snapshot. Runtime requests do **not** re-evaluate
application config sources on every request.

``Application::config(): ConfigRepository`` exposes the
configured runtime policy. ``ConfigRepository::get()`` accepts
a key/default and returns the selected value; ``all()``
returns the current composed configuration.

Keep secret values out of debug output and committed files. The
application's own lockfile should pin its installed dependency
graph; Foundation's library repository intentionally ignores its
lockfile.

Configuration ownership map
---------------------------

.. list-table::
   :header-rows: 1
   :widths: 24 28 22 26

   * - App config file
     - Purpose
     - Selected capability
     - Native owner / notes
   * - ``cache.php``
     - Stores, counters, coordination
     - Core, when selected
     - CacheLayer
   * - ``database.php``
     - Connection/migration/seeder maps
     - ``data.database``
     - DBLayer
   * - ``filesystem.php``
     - Disks, uploads, download policy
     - ``data.filesystem``
     - Pathwise
   * - ``logging.php``
     - PSR-3 driver, redaction
     - Core
     - Foundation/PSR logger
   * - ``messaging.php``
     - Handlers, routes, codecs, workers
     - ``execution.messaging``
     - Omnibus
   * - ``communication.php``
     - HTTP/mail/webhook/gRPC profiles
     - ``execution.communication``
     - TalkingBytes
   * - ``notifications.php``
     - Application notification routing
     - Core with optional transport
     - Native TalkingBytes when configured
   * - ``operations.php``
     - Release/host/worker policy
     - Core
     - Foundation integration
   * - ``responses.php``
     - JsonDispatch envelope configuration
     - Core
     - Webrick response writer remains owner
   * - ``security.php``
     - Application password/JWT/crypto policy
     - ``security.crypto`` for Epicrypt driver
     - Epicrypt mechanism
   * - ``session.php``
     - Browser store, cookies, CSRF, lock
     - Core; DB driver optional
     - Foundation policy + CacheLayer/DBLayer
   * - ``validation.php``
     - Profiles, aliases, rules/sanitization defaults
     - ``security.validation``
     - ReqShield

Core configuration files are not necessarily bundled into one
installed Infbyte 3 application. Copy/use only the template
owned by a selected feature or Core policy. Do not assume
publishing a file enables its module.

Representative verified keys
----------------------------

``session.php`` (Core) defines the browser
``driver`` choice, ``lifetime`` seconds,
``max_payload_bytes``, ``cookie`` attributes
and ``stores`` for file, cache and database.
The available drivers are ``array``, ``file``,
``cache`` and ``database``.

``database.php`` (optional) defines ``default``,
connection descriptors, ``migrations.classes`` and
``seeders`` for the explicit migration registry.
DBLayer owns actual connection/transaction behavior.

``responses.php`` (Core) contains
``json_dispatch.vendor``,
``json_dispatch.application_version``,
and ``json_dispatch.tunnel_errors``.
The last defaults to ``false``; non-tunneled error
envelopes preserve native HTTP error status codes.

``messaging.php`` (optional) defines
``handlers``, ``routes``, ``workers``,
``handler_middleware``, ``job_middleware``,
durable transport configuration and serialization codecs.

``operations.php`` (Core) controls process/runtime
operational behavior. Validate host-specific selection against
actual Runwire capabilities and external process supervision.

For the precise schema and comments, read the appropriate
`source configuration directory
<https://github.com/infocyph/Foundation/tree/feature/foundation-3/resources/config>`_.

Common operational actions
--------------------------

.. code-block:: bash

   php infbyte config:show router.matcher
   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte config:cache
   php infbyte config:clear

A development config cache is not the production
four-runtime release generation. Changing selected runtime
input requires a new trusted generation:

.. code-block:: bash

   php infbyte optimize
   php infbyte optimize:report

Never fall back to mutable source compilation to bypass
a production manifest mismatch.

Security and performance checklist
----------------------------------

* Keep environment secrets in trusted deployment/process state.
* Validate both the configuration and the activated feature topology.
* Avoid enabling auth/session/database middleware globally for
  routes that do not need those services.
* Do not use a process-local cache for replay markers or
  cross-worker session coordination.
* Keep TLS host and peer verification for configured outbound
  HTTP and database connections.
* Check actual backend readiness separately from syntactic config.
* Rebuild immutable artifacts whenever selected input or
  package versions change.
* Treat the config templates as authoritative when an example
  or this summary is older than the current branch.

See :doc:`../start/configuration`,
:doc:`../advanced/modules`, and
:doc:`../tutorials/choose-capabilities`.
