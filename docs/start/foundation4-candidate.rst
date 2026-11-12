.. _foundation-4-candidate:

Foundation 4 candidate: run the Infbyte starter
===============================================

An **Infbyte 4 development candidate already exists** in the
Infbyte repository, branch ``feature/infbyte-4``, draft PR #11:

https://github.com/infocyph/Infbyte/pull/11

It follows the unpublished Foundation 4 branch
``feature/foundation-3`` (draft Foundation PR #20).
This candidate, **not Infbyte main**, is the appropriate source
reference for a Foundation 4 application skeleton.

.. warning::

   **Development-only instructions.** The candidate currently requires
   ``dev-feature/foundation-3`` via a Composer VCS override, not a
   published stable Foundation 4 version. Earlier candidate CI jobs
   passed against an October 18 Foundation source; those results do
   not certify compatibility with the latest Foundation commit.
   PHPForge's stable-runtime constraint remains unfulfilled.
   Do not merge, tag or publish this development integration.

Prerequisites
-------------

Use PHP 8.4+ (64-bit), Composer 2 and Git access. Optional
capabilities and native hosts require their selected PHP
extensions and configured backend services.

The current candidate ships ``config/modules.php`` with
``['features' => []]`` and ``APP_CAPABILITIES`` empty. Core
behavior is available without enabling all eight optional features.

Acquire the candidate
---------------------

Clone the development branch rather than using the public
``composer create-project`` release (which still targets Foundation 3):

.. code-block:: bash

   git clone --branch feature/infbyte-4 --single-branch https://github.com/infocyph/Infbyte.git my-app
   cd my-app
   composer update --no-interaction --prefer-dist
   php infbyte --version
   php infbyte module:list
   php infbyte config:validate

``composer update`` resolves a moving development dependency.
Always record the immutable Foundation source SHA that Composer
actually installed. The exact package graph can differ from that
used in an older CI run.

A fresh development clone can also initialize its application
directories and inspect readiness:

.. code-block:: bash

   php infbyte app:install
   php infbyte app:ready

Review generated files and secrets. Never commit application secrets
or pass a development trust digest off as a production release.

Inspect real Webrick routes
---------------------------

The candidate's ``routes/api.php`` contains exactly these source
registrations:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Http\Controllers\SystemController;
   use Infocyph\Webrick\Router\Definition\Registrar;

   /** @var Registrar $router */
   $router->get('/api/health', SystemController::health(...));
   $router->get('/json', SystemController::json(...), 'json');

The matching application controller implements the handlers:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Controllers;

   final readonly class SystemController
   {
       public static function health(): array
       {
           return ['status' => 'ok'];
       }

       public static function json(): array
       {
           return ['memory' => memory_get_usage(true)];
       }
   }

The route source is explicitly selected by ``config/router.php``.
Production Webrick routing is compiled into the trusted generation;
controllers are not automatically discovered by scanning folders.

Inspect routes and the host selection policy:

.. code-block:: bash

   php infbyte route:list
   php infbyte serve --dry-run --json=1

The dry run does not bind a listener. Actual serving depends on
the selected Runwire driver and installed host extensions. Do not
assume PHP/FPM and a persistent native worker share a host lifecycle.

Optional feature development
----------------------------

Foundation 4 offers exactly three optional groups and eight
qualified features. Installing a package does not enable it:

.. code-block:: bash

   php infbyte module:plan security.validation
   php infbyte module:install security.validation
   php infbyte module:enable security.validation
   php infbyte module:doctor security.validation

Review the plan before changes. Feature enablement and the
compiled application's ``app.capabilities`` topology are
distinct, deliberate decisions. See :doc:`../tutorials/choose-capabilities`.

Current evidence and drift
--------------------------

The candidate's October 18 integration workflow passed all
13 jobs (PHP 8.4/8.5, selected native hosts, packaged project
and optional feature consumers) using an older Foundation
development SHA. It has not been rerun against the latest
Foundation 4 branch in this documentation batch.

The current Foundation feature catalog requires DBLayer
``^6.1.4``, but an Infbyte candidate module test still asserts
``^6.1.1`. That stale test expectation needs to follow the
actual Foundation ``ModuleArchitectureCatalog`` mapping. Do not
downgrade Foundation to accommodate an outdated application test.

The candidate's separate PHPForge Security & Standards workflow
has four failed QA matrix jobs because its development Composer
constraint violates the intentionally strict stable-runtime
guard. Keep the guard intact until the genuine stable cutover.

The Foundation 4 development documentation still requires a fresh
Infbyte candidate CI run against the **current** dependency graph.
Historic green checks are retained as historical evidence only.

Stable release remains gated
----------------------------

The Infbyte production entrypoint validates a trusted Foundation
generation, then delegates HTTP to Webrick. Foundation's independent
P6 source/performance and retained-data gates remain prerequisites.

After a real Foundation 4 release becomes available, apply the
prepared stable cutover on Infbyte PR #11: require ``^4.0``,
remove the development VCS override and run normal Composer
resolution, PHPForge stable/lowest checks, no-dev consumers and
native host verification. That cutover has **not** happened.

For the existing handoff, see:

https://github.com/infocyph/Infbyte/blob/feature/infbyte-4/docs/plans/infbyte-4.0-release-handoff.md

Continue with :doc:`installation`,
:doc:`first-application` and
:doc:`../reference/publication-readiness`.
