.. _app-lifecycle:

Application and runtime lifecycle
=================================

Foundation 4 composes the application and owns the lifecycle policy beneath
Infbyte. A runtime is selected **explicitly**, regardless of PHP SAPI.

Four explicit modes
-------------------

The current Foundation 4 API provides:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;

   $web = Foundation::web(['base_path' => dirname(__DIR__)]);
   $cli = Foundation::cli(['base_path' => dirname(__DIR__)]);
   $worker = Foundation::worker(['base_path' => dirname(__DIR__)]);
   $scheduler = Foundation::scheduler(['base_path' => dirname(__DIR__)]);

This illustrates the factory surface; a normal web request does **not**
instantiate all four. The ``infbyte`` executable or web entrypoint
chooses the appropriate mode.

Configuration and composition
-----------------------------

During development/build, Foundation resolves defaults, preset, project
config, and inline bootstrap overrides. Its providers contribute native
InterMix ``ContainerBuilder`` service definitions before graph finalization.

Foundation 4 has prebuilt Core and optional qualified feature selections.
Packages are not automatically activated merely because Composer installed
them. The web graph includes routing and HTTP-only adapters; non-web
graphs do not carry an unnecessary HTTP stack.

Execution boundaries
--------------------

Webrick owns web request adaptation, route matching, selected middleware
and request-scope entry/exit. A minimal route can remain free of a
request object and scoped resources.

The non-web modes use stable InterMix scope labels:

* ``foundation.cli``
* ``foundation.worker``
* ``foundation.scheduler``

Services holding current user, request, transaction, or job state must
be scoped rather than leaking into long-lived singletons.

Production compilation
----------------------

In Foundation 4, the build plane publishes one trusted generation with
web, CLI, worker, and scheduler artifacts. Its contents include normalized
configuration, native InterMix and Webrick compiled state and the verified
release manifest. A build failure must not activate a partially written
generation.

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report
   php infbyte app:ready

Passing these commands is not a substitute for backend recovery or actual
host qualification. Loaded production generations do not fall back to
mutable source bootstrap on artifact identity failures.

Read :doc:`service-providers` for composition and
:doc:`request-lifecycle` for the native HTTP execution flow.
