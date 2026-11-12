.. _deployment-guide:

Deploying Infbyte applications
==============================

Production deployment is not just copying PHP source to a web root.
A Foundation 4 application publishes one **trusted release generation**
containing its web, CLI, worker and scheduler graphs. The application
and its external services must agree on configuration, dependencies,
secrets, data formats and the active generation identity.

This chapter describes the **unpublished Foundation 4 candidate**.
The current Infbyte application skeleton on ``main`` targets
Foundation 3, so do not treat this as an already released Infbyte 4
installation procedure.

Before deployment
-----------------

Confirm:

* PHP 8.4+ and the selected optional extensions/packages are
  installed on the target runtime.
* A compatible Infbyte starter or consumer application has been
  migrated to Foundation 4 contracts.
* The application has an explicit production capability topology.
* Required database, cache, queue, mail and object-storage
  backends are available from the actual target network.
* Writable storage is limited to documented paths; source and
  compiled code can be read-only at runtime.
* Secrets and the trusted manifest digest are supplied through
  controlled process/deployment configuration.
* Process supervision, TLS termination, logging and rollback
  procedures are defined before switching traffic.

Step 1 — inspect and validate
-----------------------------

In an application build/deploy environment with the correct
dependencies and configuration:

.. code-block:: bash

   php infbyte about
   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte module:list
   php infbyte app:ready

``app:ready`` is a bounded dependency/policy/readiness check
for **selected capabilities**. It is not a live traffic-health
endpoint. An inactive optional module is intentionally not contacted.
Use ``--json`` when structured reporting is required.

Step 2 — prepare data and messages
----------------------------------

For a database deployment, review and stage applicable migrations
before switching a release:

.. code-block:: bash

   php infbyte migrate:status
   php infbyte migrate --pretend

Confirm a recoverable snapshot exists, especially if existing
persistent queues, sessions, auth records or factor/crypto state
will be read by the candidate. Prefer expand/contract migrations
that allow both old and new generations to coexist temporarily.

Do not run destructive migration/reset commands as a generic
deployment step. Schema compatibility must be deliberately
verified against the real database and installed native packages.

Step 3 — build the generation
-----------------------------

.. code-block:: bash

   php infbyte optimize
   php infbyte optimize:report

Foundation stages a generation, validates its artifacts and
atomically switches the active pointer. The compiled release
includes normalized configuration, dependency fingerprint and
native InterMix/Webrick artifacts. A build failure must not
replace the previously active generation.

Rebuilding only a command, schedule or development config cache
does **not** substitute for a complete production generation.

Step 4 — activate the trusted identity
--------------------------------------

The deployment system must supply the genuine external SHA-256 of
the generated ``foundation.php`` manifest. Both
``INFOCYPH_FOUNDATION_RELEASE_ROOT`` and
``INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256`` must reach the
**actual process environment** of the web/CLI/worker/scheduler
process, not merely a request superglobal.

Never derive the trust digest at runtime from the same writable
directory being checked. A missing or mismatched digest must
cause a controlled failure, not fallback source compilation.

See :doc:`releases` for activation and rollback.

Step 5 — start the chosen host and workers
------------------------------------------

For ordinary PHP/FPM, configure the web server's document root
to Infbyte's ``public/`` and use ``public/index.php``
as the application entrypoint. For Runwire native serving,
configure a supported native listener and its explicit worker
count. External persistent hosts must own their own workers
and lifecycle; do not start another listener inside one.

For jobs or scheduling, configure an external supervisor. Foundation
reload/interrupt commands ask existing processes to stop; they
do not spawn replacements.

Step 6 — verify with real traffic
---------------------------------

Check:

1. An unauthenticated minimal endpoint on the actual host.
2. A selected authenticated endpoint with expected session/cookie
   and CSRF policy.
3. Database and cache connectivity through a real selected operation.
4. A bounded queue delivery and a scheduler invocation if enabled.
5. Error reporting, process restart, logging redaction and
   slow-request cancellation.
6. Rollback to a retained generation without losing data integrity.

Do not declare release certification solely because this checklist
passes. Foundation PR #20 has separate sustained host-performance,
security and lifecycle gates.

Next: :doc:`hosting`, :doc:`releases` and
:doc:`operations`.
