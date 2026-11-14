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
   published stable Foundation 4 version. The Foundation-side
   read-only candidate integration is current-source checked, but
   Infbyte PR #11's separate full Security & Standards and candidate
   integration jobs still fail. PHPForge's stable-runtime release
   constraint remains unfulfilled.
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

The **Infbyte 4 candidate** at ``feature/infbyte-4`` is a real
application skeleton. Its ``composer.json`` still requires the
moving Foundation ``dev-feature/foundation-3`` reference,
so every test must record the **resolved immutable Foundation SHA**.

As of November 13, 2026, the Foundation PR's **read-only
Infbyte 4 compatibility workflow** successfully checked the
candidate with PHP 8.4 and PHP 8.5 at the exact Foundation
source under test. The D15-A documentation checkpoint
``0f30110ded3f02c2c7d7d44daecfb3e03ae05c8d``
passed its candidate workflow (``43362530538``);
the workflow verifies the Core-only application layout,
feature catalog, Webrick/TestKit health endpoint and
trusted Core release against that source.

**This is a scoped compatibility probe, not the full consumer
release matrix.** Infbyte draft PR #11 at source
``d4dc214dce55e8c0217dd39a24c1e50878e0320f``
has its own failing Security & Standards
(``43352044978``) and Foundation 4 Candidate Integration
(``43352044897``) workflows. Inspect those job logs on
the actual consumer head; older October successes do not
cancel a newer failure. Do not weaken the intentionally
stable-version guard merely to label a development branch
as release-ready.

The source-of-truth Infbyte starter currently ships exactly six
Core configuration files: ``config/app.php``, ``auth.php``,
``cache.php``, ``modules.php``, ``notifications.php``
and ``router.php``. Optional configurations such as
``config/database.php`` are published to the application
only after feature selection. Core customization templates such
as ``config/session.php`` or ``config/logging.php``
are not preinstalled files in the candidate.

The final publication gate requires a **released Foundation 4
constraint**, no moving VCS override, a locked compatible Infbyte
application dependency graph and green consumer QA across actual
selected native hosts/capabilities. Documentation syntax and the
read-only probe cannot replace that acceptance.


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
