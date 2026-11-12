.. _testing-overview:

Testing Infbyte applications
============================

A reliable framework application needs more than a passing function
test: it must verify the **selected runtime**, route/middleware
integration, optional capabilities, data and security boundaries,
and cleanup after failures.

Foundation 4 offers ``TestKit`` for application-level integration
helpers. It does not implement a replacement HTTP router, queue,
database engine or outbound HTTP transport.

Pick a test type
----------------

.. list-table:: Test layers
   :header-rows: 1
   :widths: 23 40 37

   * - Layer
     - Verify
     - Dependencies
   * - Pure unit
     - Input transformation and business rules.
     - No Foundation runtime required.
   * - HTTP integration
     - Route, middleware, response, error and scope cleanup.
     - Foundation web graph and Webrick native request.
   * - Native service integration
     - Real DBLayer queries, Omnibus handler, Pathwise or TalkingBytes behavior.
     - Explicit feature and test backend.
   * - Persistent runtime
     - Per-execution isolation and host-specific cancel/restart behavior.
     - Reusable application, explicit host topology.
   * - Deployment/release
     - Generated artifacts, trusted manifest and retained state across upgrades.
     - External environment and actual deployment graph.

Keep fast deterministic unit tests separate from slow database/network
and host-certification tests. Neither category substitutes for the other.

Obtain TestKit
--------------

In a test with an already constructed Foundation **web** application,
resolve ``TestKit`` from the runtime's container:

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);

This is a **contextual snippet**, not a complete executable test:
``$app`` must be an actual test application built with appropriate
project configuration. The public API accepts no magical global
``test()`` container or automatically inferred current user.

Choose deliberate test resources
--------------------------------

* Use test-only DB connections/schemas, never production databases.
* Use native fakes when testing *your application's behavior*; use real
  native integrations when validating an adapter or protocol contract.
* Ensure time- and lock-dependent tests control the clock and exercise
  failure/expiry cases.
* Reset test overrides or create an independent application when test
  isolation requires it; never carry one test's principal into another.
* Verify both successful and failing executions: cleanup paths are part
  of the framework contract.

If an optional module is not selected, its test helper may fail
rather than silently emulating production capabilities. This is the
correct signal to review application setup.

How to run tests
----------------

The Foundation repository uses its own PHPForge/Pest/PHPUnit and CI
contracts; an Infbyte **consumer application** should choose its own
test command from ``composer.json`` and installed dev dependencies.
Do not assume a Laravel command such as ``artisan test`` exists.

For HTTP assertions, continue with :doc:`http`. For fakes, see
:doc:`fakes`, and for database testing, see :doc:`database`.
