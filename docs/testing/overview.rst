.. _testing-overview:

Testing Infbyte applications from unit to production
====================================================

An Infbyte test suite should prove both business behavior and the
**selected runtime contract**. The application owns its tests under
``my-app/tests/``. Foundation offers a native ``TestKit``
over Webrick, CacheLayer, Omnibus, TalkingBytes and other selected
integrations; it does not replace those packages' behavior.

This guide targets the **Infbyte 4 development candidate**. The
published Infbyte ``main`` starter still uses Foundation 3. The
candidate's default health route is
``GET /api/health``, which is a useful minimal integration fixture.

Choosing the right test
-----------------------

.. list-table::
   :header-rows: 1
   :widths: 26 36 38

   * - Test layer
     - What you prove
     - What it does not prove
   * - Pure unit
     - A function/domain service handles expected and rejected input
     - Framework boot, database, host, network
   * - HTTP integration
     - Webrick routing, selected middleware, handler and response
     - External sockets, TLS and proxy configuration
   * - Native DBLayer
     - Queries, transactions, migration and dialect behavior
     - All production database versions/replicas
   * - Native Omnibus
     - Send/receive, ack/retry, codec and failure store
     - Full rolling-upgrade compatibility by itself
   * - Fake TalkingBytes
     - Correct application request or email construction
     - Actual DNS/TLS/provider delivery
   * - Persistent host
     - Request/job state isolation, cancellation and resource cleanup
     - Unselected host adapters
   * - Deployment rehearsal
     - Verified manifest, selected dependency graph and retained-state rollback
     - Automatically suitable performance on every host

Keep fast deterministic tests separate from expensive real-backend and
native-host tests. A green unit suite is not a substitute for an actual
production-shape integration test.

Where your tests belong
-----------------------

Use the application's existing structure:

.. code-block:: text

   my-app/
   ├── app/
   ├── config/
   ├── routes/
   │   └── api.php
   └── tests/
       ├── Feature/
       │   └── HealthEndpointTest.php
       └── Unit/

``Tests\`` is mapped to ``tests/`` by the candidate
``composer.json``. Your application chooses its own test command
and dev dependencies. The candidate currently installs PHPForge
for its QA workflow; there is no Laravel ``artisan test`` command.

Check the actual installed tooling and tests instead of assuming a
Composer ``test`` script exists:

.. code-block:: bash

   composer show --locked infocyph/phpforge
   php infbyte --version

In CI, execute the configured Pest/PHPForge commands from
the application's workflow, not undocumented aliases.

An end-to-end HTTP fixture
--------------------------

Create **``my-app/tests/Feature/HealthEndpointTest.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;
   use Infocyph\Foundation\Testing\TestKit;

   it('serves the registered Infbyte health endpoint', function (): void {
       $app = Foundation::web([
           'base_path' => dirname(__DIR__, 2),
           '_config_cache' => false,
           'app' => [
               'env' => 'testing',
               'capabilities' => [],
           ],
       ])->boot();

       $testing = $app->make(TestKit::class);

       $testing->http()->get('/api/health')
           ->assertStatus(200)
           ->assertJson(['status' => 'ok']);
   });

This is a complete **Infbyte project fixture**, not a
Foundation-package unit test. The route actually exists in the
Infbyte 4 candidate's ``routes/api.php``.

The path calculation uses the **Infbyte application root** two
directories above ``tests/Feature/``. It does not point to
the Foundation repository or vendor directory.

The candidate already has a compatible TestKit health smoke in
its development QA. Re-run tests against the exact installed
Foundation SHA; an older green commit does not certify a moving
dependency.

Negative paths are part of correctness
--------------------------------------

For each protected/write route test:

1. A valid request returns the documented representation.
2. Missing or invalid input is rejected before any mutation.
3. A guest cannot access a protected resource.
4. A signed-in principal still cannot access another account's resource.
5. Browser write requests reject a missing or stale CSRF token.
6. An exception leaves no request principal, session lock or
   DBLayer transaction for the next execution.
7. Retried work is idempotent or explicitly compensated.

An HTTP status code alone is insufficient for a write path.
Also assert that database records, sent emails and queued messages
did **not** change after a rejected request.

Choosing a test resource
------------------------

Infbyte Core has a named CacheLayer store and a small,
dependency-light HTTP graph. Test the Core-only application
without adding every optional module merely for convenience.

An optional integration requires its selected feature:

* ``data.database`` for DBLayer connection and migration testing.
* ``data.filesystem`` for Pathwise disks/uploads.
* ``security.validation`` for ReqShield validation.
* ``execution.messaging`` for Omnibus message delivery.
* ``execution.communication`` for TalkingBytes HTTP profiles.

The fact that a package is installed in ``vendor/``
does not automatically enable a Foundation feature.
The actual application uses
``config/modules.php`` and ``config/app.php`` to select
its capability topology.

For tests using a real database, isolate credentials and
schema. Avoid pointing local test configuration at
production data and never run destructive migration/reset
commands against a shared environment.

Time, locks and retries
-----------------------

TestKit can replace the selected Foundation clock:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);
   $clock = $testing->freezeTime(1_800_000_000);

This is a **contextual test excerpt**. It affects the configured
Foundation clock service in this test application; native components
calling PHP ``time()`` directly may not observe that override.
Test the actual source of time before assuming a deadline has frozen.

For lock and replay behavior, test two competing executions and
lost ownership. A single-threaded unit test cannot establish
atomicity across different PHP/FPM processes or worker hosts.

Persistent runtime isolation
----------------------------

A long-lived host can serve request A, failed request B and
request C within the same process. Verify each execution begins
with correct scoped state and releases:

* current principal and browser/auth sessions;
* mutable HTTP clients and cookies;
* DBLayer transactions, query deadline and pooled connection lease;
* pending Omnibus delivery and cancellation state;
* temporary file and upload resources.

Do not retain an application-wide singleton that stores
request-specific values to make tests simpler.

What a green TestKit result means
---------------------------------

The in-process Webrick test exercises the same selected
application route and middleware integration. It does
**not** bind a socket or prove TLS/proxy behavior, native
Runwire restart/recycle semantics, or an externally
trusted production release.

Before a release, run the separate native-host and
production-generation QA workflows against the exact
dependency graph and targeted host. For durable data,
also rehearse retention, migration and rollback.

Typical failures
----------------

.. list-table::
   :header-rows: 1
   :widths: 30 34 36

   * - Symptom
     - Likely cause
     - Check
   * - HTTP TestKit refuses to initialize
     - CLI instead of web graph
     - ``Foundation::web()`` and app root
   * - Health route returns 404
     - Wrong router files or base path
     - ``config/router.php`` and ``routes/api.php``
   * - Optional helper cannot resolve
     - Feature not enabled
     - ``config/modules.php`` and active capability
   * - Test passes only once
     - Shared mutable fixture/clock or service override
     - Recreate fixture or reset state per test
   * - Fake queue works but production loses messages
     - No durable native transport verified
     - Omnibus broker/receipt/failure-store tests
   * - Host fails with green in-process HTTP
     - TLS/proxy/release/adapter discrepancy
     - Exact generation and live host smoke checks

Next: :doc:`http`, :doc:`fakes`,
:doc:`database`, :doc:`persistent-runtimes`
and :doc:`../tutorials/test-an-application`.
