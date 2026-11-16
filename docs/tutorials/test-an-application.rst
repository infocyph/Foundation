.. _tutorial-test-application:

Tutorial: test an Infbyte application
=====================================

Testing a framework application means checking more than a return value.
It must verify business logic, the selected runtime, middleware and
HTTP integration, native service behavior, persistent-process cleanup,
and production release activation where relevant.

This guide documents the **Foundation 4 development API**.
Full executable consumer fixtures require a compatible Infbyte 4
skeleton. Examples with application-defined routes are explicitly
illustrative.

Choose the right kind of test
-----------------------------

.. list-table::
   :header-rows: 1
   :widths: 24 40 36

   * - Test type
     - What it proves
     - What it does not prove
   * - Pure unit
     - Business rules, transformations, deterministic branching
     - Real HTTP host or database behavior
   * - HTTP integration
     - Webrick routing, selected middleware, response and scope cleanup
     - Real TLS/proxy/host adaptation
   * - Native service integration
     - DBLayer, Omnibus, Pathwise or TalkingBytes contract with a test backend
     - Production topology or release capacity
   * - Persistent-runtime
     - Request/job isolation and lifecycle over repeated execution
     - Every supported host driver by default
   * - Deployment/release
     - Trusted artifact, dependency identity, operation and rollback behavior
     - Sustained benchmark certification without dedicated measurement

Run fast deterministic unit tests on every change, add targeted
integration tests for selected features, and require real host
verification before release. A fake transport is valuable but cannot
prove real message acknowledgment or network failures.

Step 1 — set up a test application
----------------------------------

Create a deliberately configured Foundation web graph for HTTP tests.
Its route files, selected capabilities and database/session stores
should be test-only inputs.

Foundation has no implicit global ``test()`` facade.
With an actual configured ``Application $app``, resolve:

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);

The application must be constructed or bootstrapped by the test
fixture. Do not use a CLI runtime for HTTP tests: the four
Foundation runtime graphs are intentionally separate.

Test configuration and data must never point to the production
database or to a real customer notification recipient.

Step 2 — test a route through Webrick
-------------------------------------

For a route your application explicitly registers as
``GET /api/health``:

.. code-block:: php

   $response = $testing->http()->get('/api/health');

   $response
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

The published Infbyte 3 skeleton includes this route.
For Foundation 4 the route requires a compatible application fixture;
the call is a TestKit API illustration rather than proof that the
published starter has been migrated.

The Foundation HTTP client constructs native Webrick requests and
traverses the configured in-process web kernel, middleware and response
boundary. It does not open a public TCP listener.

Step 3 — test failures, not only successes
------------------------------------------

For an *application-defined* ``POST /accounts`` route:

.. code-block:: php

   $response = $testing->http()
       ->withHeaders(['Authorization' => 'Bearer test'])
       ->post('/accounts', ['email' => 'broken']);

   $response->assertStatus(422);

This assumes the application fixture deliberately rejects malformed
email with status 422; it is not a preinstalled Foundation route.
An arbitrary ``Bearer test`` string is not automatically an
authorized credential. Configure a test auth driver or known
principal fixture if the route requires authentication.

Useful HTTP negative cases:

1. A missing route returns a safe not-found response.
2. A registered path with a wrong method is rejected.
3. Validation failure causes **no** write or dispatch side effect.
4. An unauthorized principal cannot access another account's record.
5. A browser write without CSRF proof fails safely.
6. A downstream exception is reported without raw secrets.
7. One rejected request does not change the next request's scope.

Use ``assertHeader()``, ``assertJson()``,
``assertJsonPath()``, ``status()``, ``json()``
and ``body()`` to inspect only stable public behavior.
Do not assert volatile implementation details such as hash ordering
or stack trace content.

Step 4 — choose native fakes carefully
--------------------------------------

Foundation's ``TestKit`` composes owning package contracts rather
than copying their transport engines. For a test application with
relevant selected features:

.. code-block:: php

   $recording = $testing->fakeMessaging();
   $http = $testing->fakeHttp();
   $email = $testing->fakeNotifications();
   $cache = $testing->fakeCache();
   $clock = $testing->freezeTime(1_800_000_000);

These helpers may require configured optional packages. The
recording sender demonstrates that an application dispatched a message;
it does not prove Redis accepted, reserved or acknowledged that message.

The fake HTTP client uses TalkingBytes behavior; fake email uses a
native TalkingBytes ``Emailer::fake()``. An in-memory CacheLayer
store does not model shared replay or distributed locks.

``freezeTime()`` replaces Foundation's application clock.
Code calling PHP's global ``time()`` or a separate native clock
may not observe the frozen value. Verify which clock your service
actually depends upon.

Step 5 — test database lifecycle safely
---------------------------------------

When ``data.database`` is selected and a **dedicated test
database** is configured, Foundation's helper provides a
DBLayer-backed transaction test context:

.. code-block:: php

   $database = $testing->database();

   $database->transaction(function () use ($app): void {
       // Resolve the configured DBLayer connection and exercise the
       // application repository here.
   });

This is a contextual example: it is not a complete repository test
without an application-owned schema and assertion. Confirm that a
failed operation rolls back and no transaction state escapes the test.
Use separate fixtures for persistence across real commit/restart.

Test migrations against each relevant SQL dialect when behavior differs.
SQLite is not a universal substitute for PostgreSQL/MySQL concurrency,
lock and schema semantics.

Step 6 — exercise a persistent host across multiple units
---------------------------------------------------------

A process that handles many requests or jobs must not retain
another execution's user, browser session, transaction or HTTP
client cookies.

Run this sequence under the **same application/worker**:

.. code-block:: text

   [1] Authorized request/handler succeeds
   [2] A request/handler fails after acquiring scoped state
   [3] A cancellation or timeout path executes (when supported)
   [4] Another unrelated request/handler succeeds
   [5] Assert no principal, transaction, lock or resource leaked

Use different account identities and context IDs, then verify
the final execution cannot read any private state from earlier work.
This catches bugs that a brand-new process per test hides.

Webrick owns web request-scope entry/leave; Foundation owns cleanup
of its scoped application resources; Runwire owns native host
cancellation where the selected adapter participates.
Never add an extra universal scope just to hide a leak.

Step 7 — test the production artifact separately
------------------------------------------------

A successful test of ``Foundation::web()`` in development does
not prove that generated production classes are loadable or that
the real deployed vendor graph matches a release manifest.

For a candidate deployed application, verify:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte app:ready
   php infbyte optimize
   php infbyte optimize:report

Then start actual web/worker/scheduler processes using the
externally trusted manifest identity and run small integration
smoke tests. Host performance and retained-state rollback
certification are independent of TestKit and docs CI.

Step 8 — keep CI evidence meaningful
------------------------------------

An application should pin its own Composer application lockfile;
the Foundation *library* repository intentionally ignores its lock.
Test the exact selected PHP and vendor graph, and record failures
rather than hiding them with broad analyzer exclusions.

A reviewable failure report contains: the source commit, selected
PHP version, dependency revisions, host mode, feature topology,
test name and reproducible assertion. Redact credentials,
authorization headers, customer identities and account tokens.

Do not turn an illustrative PHP snippet into a claim that
an unpublished Foundation 4/Infbyte 4 combination has already
passed consumer integration tests.

What to test next
-----------------

Read :doc:`../testing/overview`,
:doc:`../testing/http`,
:doc:`../testing/fakes`,
:doc:`../testing/database`,
:doc:`../testing/persistent-runtimes`,
:doc:`secure-browser-session` and
:doc:`durable-queue`.
