.. _troubleshooting-guide:

Troubleshooting production failures
===================================

Begin troubleshooting by identifying **which layer owns the failure**:
application configuration (Infbyte/Foundation), generated release
identity (Foundation), native routing (Webrick), dependency resolution
(InterMix), transport/storage (native packages), or the external host.

Never disable release signature validation, authentication checks or
queue ownership merely to get traffic flowing.

Establish a safe diagnostic baseline
------------------------------------

Run read-only or bounded diagnostics from the selected deployment
environment before touching production state:

.. code-block:: bash

   php infbyte about
   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte route:list
   php infbyte optimize:report
   php infbyte maintenance:status
   php infbyte worker:status

The exact available commands may depend on the activated package
and runtime; use ``php infbyte help <command>`` to inspect
arguments rather than guessing hidden flags.

Common problems
---------------

.. list-table:: Symptoms and first checks
   :header-rows: 1
   :widths: 26 32 42

   * - Symptom
     - Possible boundary
     - Safe first action
   * - Service is not resolvable
     - Feature not selected, missing package, incorrect provider.
     - Inspect ``module:plan``, config and current Composer graph.
   * - A route returns 404 unexpectedly
     - Route source not selected or stale compiled router.
     - Inspect ``router.files``, route list and trusted build identity.
   * - Generated release is rejected
     - Manifest/digest, dependency fingerprint or stale artifact mismatch.
     - Verify external trusted digest and correct vendor graph; rebuild.
   * - Database schema appears missing
     - Unprovisioned Core/optional schema or wrong connection.
     - Run schema *status* against the selected connection first.
   * - Sessions disappear between requests
     - Process-local store, cookie or shared-state mismatch.
     - Inspect selected session driver, cookie policy and host topology.
   * - CSRF failure after login
     - Wrong middleware order, missing session or stale token.
     - Verify ``session`` before ``csrf`` and regenerate on privilege change.
   * - Email/webhook fails
     - TalkingBytes profile, TLS, signing or replay state.
     - Verify named profile and transport-specific diagnostics.
   * - Queue retries indefinitely
     - Omnibus handler/codec/acknowledgment/visibility contract.
     - Inspect failure store, reserved receipts and idempotency.
   * - Workers do not restart
     - Missing external supervisor or invisible generation token.
     - Check worker status, process supervisor and shared control store.
   * - Performance regresses after deployment
     - New middleware, backend calls, host mode, build or vendor drift.
     - Compare reproducible source/dependency/host workload and error rates.

Route or compiled graph mismatch
--------------------------------

Foundation only loads route sources listed in the application's
``router.files`` during development/build composition.
Production uses compiled Webrick descriptors; manually editing a
route source does not change the active immutable generation.

Inspect route inputs, run a new build in a controlled deployment
context and promote only after manifest/dependency verification.

A missing artifact is a reason to **stop activation**. It is not
an invitation to let a production request rebuild its own graph.

State corruption or rollback failure
------------------------------------

Stop affected writers and preserve logs, schemas and exact package
versions before recovery. For DBLayer or Omnibus data:

1. Identify the actual writer generation and data/codec version.
2. Quiesce work and snapshot database/queue/failure state.
3. Prove compatible readers or restore a coherent code/data snapshot.
4. Resume one bounded consumer and verify ACK/failure semantics.
5. Restore normal concurrency only after that probe succeeds.

Cache loss that invalidates authentication or passwordless replay
state must fail closed. A user may need a replacement login link;
a successful-looking authentication from missing state is unsafe.

Persistent-worker leaks
-----------------------

If a later request inherits another account's principal, pending
transaction, headers, cookies or temporary file path, isolate the
host worker and fix the service lifetime. Run the same sequence
under one process: success, failure/cancellation, then success.

Webrick owns HTTP request scope cleanup; Foundation owns its
application-specific scoped resources; Runwire owns host cancellation.
Do not add duplicate outer request scopes that hide leaks.

How to report an actionable issue
---------------------------------

Record the relevant stack boundaries without secrets:
Foundation/Infbyte source SHA, Composer installed package refs,
host adapter and runtime capabilities, the selected feature list,
the exact error and command, whether it reproduces after a new
trusted build, and a minimal failing test.

Avoid publishing ``.env`` values, manifest secret material,
raw bearer tokens, private customer data or database dumps.

Read :doc:`overview`, :doc:`releases`, and
:doc:`../testing/persistent-runtimes`.
