.. _runtime-modes:

Application runtime modes and hosting
=====================================

Infbyte builds application behavior on **Foundation**, which has four
application modes: web, CLI, worker and scheduler. The **HTTP host**
is a separate decision, owned by the selected runtime driver.

Understanding the difference is essential for reliable persistent
workers, production boot and optional service activation.

Application modes are explicit
------------------------------

In Foundation 4, the ``Foundation`` factory exposes:

.. code-block:: php

   use Infocyph\Foundation\Foundation;

   $web = Foundation::web(['base_path' => $root]);
   $cli = Foundation::cli(['base_path' => $root]);
   $worker = Foundation::worker(['base_path' => $root]);
   $scheduler = Foundation::scheduler(['base_path' => $root]);

This is a **fragment** showing the API, not a recommendation to create
four applications in one request; ``$root`` is your actual project
base path. The application entrypoint selects one mode deliberately.
Do not infer the runtime mode from ``PHP_SAPI``: schedulers and
workers typically execute under CLI SAPI too.

How the service graphs differ
-----------------------------

The web graph includes selected Webrick routing and HTTP adapters.
The CLI graph includes command parsing/execution policy but does not
load a route registrar merely because a web feature exists.
The worker/scheduler graphs create per-unit InterMix execution scopes
around processing and retain only safe shared infrastructure.

A configured optional module does not force every runtime to instantiate
its services. For example, a scheduled console report should not
eagerly open HTTP session storage or build browser middleware.

Which HTTP host?
----------------

.. list-table:: HTTP entry paths
   :header-rows: 1
   :widths: 24 42 34

   * - Host
     - Responsible system
     - Appropriate integration
   * - Ordinary PHP/FPM
     - Web server/FPM lifecycle.
     - Infbyte public entrypoint, Webrick SAPI adapter.
   * - Runwire native
     - Runwire listener and event loop, optional prefork.
     - Webrick's Runwire bridge with trusted release.
   * - FrankenPHP/RoadRunner
     - Host-owned workers and request lifetime.
     - Corresponding selected runtime/adapter.
   * - Swoole/OpenSwoole
     - Host coroutine/worker lifecycle.
     - Explicit Runwire driver or deliberate direct Webrick adapter.

Runwire is a required Foundation Core dependency but it is **not**
automatically running for ordinary PHP/FPM requests.
Host-specific extensions/PCNTL/POSIX provide capabilities only
where available. A driver name in an enum is not proof that a
particular deployment has its extension or process manager installed.

Context ownership and cleanup
-----------------------------

Runwire owns host admission, cancellation, deadlines and shutdown.
Webrick owns HTTP request adaptation, request-scope entry/exit and
the single response emission. Foundation owns configured application
policy and its own scoped cleanup.

For non-web work, Foundation uses stable ``foundation.cli``,
``foundation.worker`` and ``foundation.scheduler`` scopes.
When another library supplies a borrowed Runwire context, pass
the **same native objects** rather than constructing synthetic
contexts or another event loop.

Never store a current request, principal, session, open database
transaction or worker execution ID inside a process singleton.
Test multiple independent requests on the same persistent worker.

Build versus request-time operation
-----------------------------------

During build/deploy, Foundation composes configuration, providers
and routes and publishes immutable generated artifacts. Production
loads these verified artifacts without rebuilding container/router
definitions on each request. A missing or mismatched release must
fail closed rather than booting a mutable fallback application.

For production deployment choices, continue with
:doc:`../deployment/hosting` and :doc:`../deployment/releases`.
