.. _tutorial-release-rollback:

Tutorial: deploy and roll back a trusted release
================================================

Foundation 4 production deployment is a **verified generation
activation**, not a filesystem copy followed by an HTTP request
that compiles its own route graph.

One Foundation generation contains the web, CLI, worker and
scheduler artifacts together. Native Webrick/InterMix artifacts,
normalized config and the installed dependency fingerprint must
match the selected release; runtime workers must load an
**externally trusted manifest digest**.

This runbook describes an **unpublished Foundation 4 candidate**.
The public Infbyte starter still requires Foundation 3. Do not
treat these steps as a ready-to-run production Infbyte 4 install
until the compatible skeleton and external host have been tested.

When to use this runbook
------------------------

Use it before a planned deployment involving a config/provider/route
change, optional feature, vendor upgrade, new database schema, or
persistent-host worker generation.

Determine upfront whether the change affects *code only* or also
changes retained state: SQL rows, sessions, OTP/passkey credentials,
cache replay markers, object storage, queues, codecs and key material.

A rolling deployment that keeps old/new generations alive briefly
requires **both** readers to understand retained data during the
overlap window. A source pointer rollback is not a data rollback.

Step 1 — capture the release bill of materials
----------------------------------------------

From the **build environment**, preserve:

* exact Foundation and application source commit IDs;
* Composer-installed dependency identities and application lockfile;
* PHP 8.4/8.5 target version and required selected extensions;
* application configuration and feature topology without secret values;
* host adapter and worker topology (FPM, native Runwire or an external
  supported driver);
* currently active generation and last verified restore point;
* target database/messaging/cache services and schema versions.

This makes it possible to identify whether a failure is a code
regression, dependency upgrade, host difference, or data
compatibility problem.

Do not use a claimed equivalent Composer graph when the actual
installed dependency lock differs. Current-version green tests
do not automatically prove migration from the historic version.

Step 2 — prepare a compatible retained-state change
----------------------------------------------------

Before code promotion, inspect database and messaging state:

.. code-block:: bash

   php infbyte migrate:status
   php infbyte migrate --pretend
   php infbyte module:schema:status execution.messaging

The last command is relevant only for a selected messaging feature.
Review native DBLayer migration syntax, Omnibus codecs and any
new Core auth/browser-session schema.

For a breaking state change:

1. Quiesce affected writers and persistent workers or use a
   verified compatible expand/contract migration strategy.
2. Snapshot/backup databases and the selected durable queue,
   failure-store, cache replay state and object storage.
3. Record backup checksums and practice a **restore in isolation**.
4. Ensure encryption keys and fallback readers are available
   during the expected rolling window.
5. Confirm the old generation can read data that might be written
   by the new one, or plan a controlled data rollback.

A successful SQL backup alone is not enough if worker queues,
upload objects or passwordless replay markers live elsewhere.

Step 3 — validate the selected application
------------------------------------------

In the candidate's build/deploy environment:

.. code-block:: bash

   php infbyte about
   php infbyte config:validate
   php infbyte config:validate --production
   php infbyte module:list
   php infbyte app:ready

``app:ready`` checks configured application policy and selected
capability/package readiness. It does not measure actual traffic
capacity or prove that every remote system will remain healthy.

Check only enabled features; a minimal stateless route should not
load an unrelated database connection or notification client.

Step 4 — compile the immutable generation
-----------------------------------------

Build from the reviewed source and installed package graph:

.. code-block:: bash

   php infbyte optimize
   php infbyte optimize:report

Foundation stages one generation and verifies it before atomic
activation. Build/clear/prune operations are serialized through
a release-root lock. A failing build must leave the prior
verified generation active.

The output includes a ``foundation.php`` manifest,
``config.php`` snapshot and native Webrick/InterMix artifacts.
Production does not scan ``routes/*.php``,
``bootstrap/providers.php`` or ``config/*.php``
on every request.

Never manually edit or repair generated PHP files as a workaround
for a manifest mismatch.

Step 5 — establish an external trust anchor
-------------------------------------------

The deploying service must independently record the trusted
SHA-256 identity of the generated ``foundation.php``
manifest **during a controlled build or promotion**. It then
supplies the selected generation and expected digest to actual
web/worker/scheduler/CLI processes through process environment
or equivalent trusted launch configuration.

The documented environment names are:

.. code-block:: text

   INFOCYPH_FOUNDATION_RELEASE_ROOT
   INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256

Do not read the expected checksum from the same writable runtime
directory and call that trusted. A digest generated and consumed
solely by an attacker-writable artifact directory offers no
independent integrity guarantee.

A missing, malformed, mismatched or stale release identity must
fail **closed**, not trigger a source bootstrap fallback.

Step 6 — launch the right HTTP host
-----------------------------------

**Ordinary PHP/FPM:** configure the web server document root
to the Infbyte ``public/`` directory and send requests through
``public/index.php``. Webrick's SAPI path owns HTTP
adaptation/emission without requiring a managed Runwire listener.

**Runwire selected host:** Runwire owns the listener/worker
lifecycle and native host capabilities. Webrick still owns
HTTP execution/response semantics. Don't start a second network
listener inside an external server that already owns one.

Use a dry run to inspect environment-sensitive runtime selection:

.. code-block:: bash

   php infbyte serve --dry-run --json

The command reports selection without publishing a generation
or binding a listener. Availability depends on installed native
extensions and host drivers; an explicitly unavailable runtime
should fail rather than pretend it is running.

Step 7 — coordinate workers and schedules
-----------------------------------------

Before redirecting traffic, ensure background consumers use a
compatible generation and message payload format.

Foundation's cooperative controls include:

.. code-block:: bash

   php infbyte runtime:reload
   php infbyte worker:restart
   php infbyte schedule:interrupt

These commands signal graceful transition. They **do not spawn**
new processes. An external supervisor, container orchestrator or
host process manager must replace stopped workers and restart
schedules when appropriate.

Old in-use generations hold leases; pruning should skip them
until their processes exit. Do not delete a generation still
held by active workers.

Step 8 — verify the actual new host
-----------------------------------

Check a small but meaningful set of operations on the real target:

.. code-block:: bash

   php infbyte optimize:report
   php infbyte maintenance:status
   php infbyte worker:status
   php infbyte schedule:list

Then exercise a public endpoint, an authorized endpoint,
an invalid request, a selected database/cache action,
an email/webhook path where enabled and a single bounded queue
delivery when messaging is active.

Record current process generation identity and error counts,
not just that a configuration report printed successfully.
Confirm TLS termination/proxy headers, response emission,
CSRF/session behavior and selected native host mode.

A docs build, in-process HTTP test or quick health response
is **not** sustained host-performance certification.
The Foundation 4 release plan requires separate paired
FPM/Runwire, workers/concurrency, baseline and dependency
qualification evidence.

Step 9 — perform a controlled rollback if needed
------------------------------------------------

If a candidate fails:

1. Stop accepting new incompatible writers.
2. Preserve failure evidence, error logs and actual loaded
   generation references without leaking secrets.
3. Quiesce affected workers and snapshot any *new* writes.
4. Decide whether retained state is readable by the older
   generation. If not, use the rehearsed coherent restore
   or forward-compatible recovery procedure.
5. Atomically reactivate the **retained verified generation**.
6. Supply its **own** externally trusted manifest digest to
   newly launched processes.
7. Restart/reload host workers, scheduled jobs and CLI services
   using that generation.
8. Run representative reads, writes, idempotent messages,
   authentication and rollback-specific data checks.
9. Resume full traffic only after correctness and recovery
   are confirmed.

A pointer rollback without compatible data is not a successful
recovery. Never let an old code release write to newer data
formats that it does not understand.

Step 10 — close the rollout with evidence
-----------------------------------------

Record the exact app/source SHA, native package versions,
dependency fingerprint, retained-data snapshot, external
trust digest source, host driver and verification results.

Evaluate separately:

* source-only runtime behavior versus the approved baseline;
* behavior after the independent dependency upgrade;
* security and lifecycle regressions;
* session/auth/queue/data rollback safety;
* sustained performance across required hosts and concurrency.

Do not merge/tag Foundation 4 based only on passing
documentation or smoke checks.

Incident diagnostic table
-------------------------

.. list-table::
   :header-rows: 1
   :widths: 29 37 34

   * - Symptom
     - Possible cause
     - First safe action
   * - New code not visible
     - Old active generation or unregistered route input
     - Check release identity and compiled route list
   * - Manifest rejected
     - Wrong external SHA or vendor/source mismatch
     - Stop activation; inspect trust chain and rebuild
   * - Only one host fails
     - Missing extension, FPM env, proxy/header differences
     - Compare actual host capabilities/configuration
   * - Worker consumes old payloads incorrectly
     - Codec/schema compatibility gap
     - Quiesce and inspect retained queue state
   * - Login sessions vanish after rollout
     - Store or cookie policy differs
     - Verify selected shared store and session rotation
   * - Reverting code does not recover
     - Newer retained state not backward readable
     - Restore or migrate coherent code/data snapshot
   * - Throughput drops despite green tests
     - Host-mode/dep lock drift or extra middleware work
     - Run paired controlled qualification, not guesses

Related documentation
---------------------

See :doc:`../deployment/overview`,
:doc:`../deployment/releases`,
:doc:`../deployment/hosting`,
:doc:`../deployment/troubleshooting`,
:doc:`../testing/persistent-runtimes`,
`Foundation retained-state rehearsals
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-retained-data-rehearsal.md>`_
and the
`host certification procedure
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/foundation-4-host-benchmark-certification.md>`_.
