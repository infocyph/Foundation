.. _hosting-guide:

Choosing a production host
==========================

Hosting determines who owns sockets, worker processes, request
lifetime and termination. Infbyte keeps this separate from the
Foundation web application mode.

Option A — ordinary PHP/FPM
---------------------------

A conventional Nginx/Apache + PHP/FPM deployment serves
``public/`` assets and forwards PHP entry requests to
``public/index.php``. Foundation loads the trusted generated
web release; Webrick owns native SAPI adaptation and response
emission. An active Runwire host context is **not required**.

Prefer this path when it fits the operational environment and
measured workloads. Avoid enabling persistent-worker behavior
solely because the Runwire package is a required dependency.

Option B — native Runwire listener
----------------------------------

A supported native runtime can be selected explicitly from the
application CLI. For diagnostics without starting a listener
or publishing a release:

.. code-block:: bash

   php infbyte serve --dry-run
   php infbyte serve --dry-run --json

For a selected native listener:

.. code-block:: bash

   php infbyte serve --runtime=native --workers=4 --protocol-policy=latency

The example requests four native workers **only when the platform
supports prefork**. Without required capabilities, native serving
uses one process and rejects a worker count above one.
A value of ``--workers=0`` requests Runwire automatic sizing.
The default native HTTP protocol policy is ``fixed``.

These commands are **not generic production recommendations**.
Evaluate an actual host and capacity first. A web server or route
layer still handles public static files; ``serve`` is not a
drop-in replacement for PHP's built-in static-file server.

Option C — external persistent host
-----------------------------------

FrankenPHP, RoadRunner and Swoole/OpenSwoole have host-owned
worker/application lifetimes. Runwire's AUTO selector recognizes
certain already-active hosts. Swoole-family selection requires an
explicit driver even if its extension is installed.

Do not start a native listener inside a host-managed service.
Use Webrick's selected native adapter and the existing
``FoundationReleaseBootstrap`` to load the trusted application
inside the host's worker lifetime. Runwire owns native context,
cancellation and admission when selected; Webrick owns exactly
one request-to-response path.

Trusted bootstrap inputs
------------------------

The environment variable names are part of the Foundation 4
deployment contract:

.. code-block:: text

   INFOCYPH_FOUNDATION_RELEASE_ROOT
   INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256

Supply the resolved release-root path and the **independently
trusted** manifest digest through the real PHP process environment:
the FPM pool, systemd, container runtime or comparable supervisor.
The bootstrap reads these via ``getenv()``; populating
``$_ENV`` or ``$_SERVER`` from an incoming request cannot
select another trusted generation.

Do not paste the digest into a public route, log it with secrets,
or fetch it from the untrusted generated directory at runtime.
A process with a stale vendor graph must be prevented from loading
an incompatible generation.

Runtime ownership rules
-----------------------

* Runwire owns host lifecycle, worker selection, cancellation
  deadlines and drain/shutdown.
* Webrick owns request adaptation, middleware execution,
  InterMix HTTP scope and native response emission.
* Foundation owns application policy and cleanup of application
  resources at the established scope boundary.
* External supervisors own process replacement and scaling.

A host parameter named "persistent" does not itself guarantee
that an application or a native service can be shared safely.
Use real capability reports and execute multiple requests
in one worker during qualification.

Choosing among modes
--------------------

Prefer the simplest production path that satisfies measurable
requirements. Start with PHP/FPM when no persistence/concurrency
benefit has been demonstrated. Use a long-lived host only when
its memory isolation, cancellation, restart and backend lifetime
behavior has been tested.

Read `Foundation runtime hosting
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/runtime-hosting.md>`_,
:doc:`../advanced/runtime-modes` and
:doc:`troubleshooting`.
