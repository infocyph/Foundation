.. _http-testing:

Test HTTP endpoints in an Infbyte application
=============================================

Infbyte tests live in **your project's ``tests/`` directory**.
Foundation's ``TestKit`` runs real Webrick routes through
the configured in-process web application. It is not a
separate fake router or a replacement for external host tests.

This chapter follows the Infbyte 4 **development candidate**.
It uses the existing ``/api/health`` endpoint that is actually
registered by ``my-app/routes/api.php``.

What the HTTP test covers
-------------------------

The in-process client checks the native router,
selected middleware, controller/handler dispatch,
response mapping, and application-scope cleanup.

It does **not** open TCP sockets, verify TLS termination,
test reverse-proxy configuration or guarantee native
Runwire throughput. Those belong to separate host tests.

Step 1 — create a test application
----------------------------------

Create ``my-app/tests/Feature/HealthEndpointTest.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;
   use Infocyph\Foundation\Testing\TestKit;

   it('serves Infbyte health through Webrick', function (): void {
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

The ``base_path`` points to **this Infbyte checkout**,
not Foundation's repository root. The test selects the web
runtime and leaves optional features inactive because the
health route does not need them.

This is the same real API pattern exercised in the
candidate's application verification work. A CLI-only
``Foundation::cli()`` graph cannot provide HTTP route
dispatch.

Step 2 — understand the response assertions
--------------------------------------------

The native ``TestResponse`` provides:

.. list-table::
   :header-rows: 1
   :widths: 40 60

   * - Assertion/accessor
     - What it verifies
   * - ``assertStatus(200)``
     - Exact HTTP transport status
   * - ``assertHeader('Content-Type')``
     - Response header presence or expected value
   * - ``assertJson(['status' => 'ok'])``
     - JSON contains the asserted fragment
   * - ``assertJsonPath('data.id', 42)``
     - Dot-path exists and has the exact value
   * - ``status()`` / ``body()`` / ``json()``
     - Inspect response state without constructing a new response
   * - ``response()``
     - Access the underlying Webrick response

``assertJsonPath`` is appropriate for an application
envelope that actually contains the requested key.
The default ``/api/health`` route returns
``{"status":"ok"}`` without a JsonDispatch ``data``
wrapper. Do **not** assert ``data.status`` on that route.

Step 3 — test a route you added yourself
----------------------------------------

If you followed :doc:`../start/first-application`
and registered ``/api/welcome``, add a second test
inside your ``tests/Feature/`` project tree:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;
   use Infocyph\Foundation\Testing\TestKit;

   it('returns the application welcome fields', function (): void {
       $app = Foundation::web([
           'base_path' => dirname(__DIR__, 2),
           '_config_cache' => false,
           'app' => [
               'env' => 'testing',
               'capabilities' => [],
           ],
       ])->boot();

       $app->make(TestKit::class)->http()
           ->get('/api/welcome')
           ->assertStatus(200)
           ->assertJson([
               'message' => 'Welcome to Infbyte',
               'framework' => 'Infbyte',
           ]);
   });

This test will fail until you have **created the handler
and route**. A docs tutorial is not evidence that the
starter already ships that endpoint.

Step 4 — test invalid requests
------------------------------

For your own ``POST /accounts`` route you might need
validation, authentication and a selected database.
First configure those features in your Infbyte app;
then test both success and failure, including
side effects.

A contextual HTTP call looks like:

.. code-block:: php

   $response = $testing->http()
       ->withHeaders(['Accept' => 'application/json'])
       ->post('/accounts', ['email' => 'invalid']);

   $response->assertStatus(422);

``/accounts`` is not a route in the initial candidate,
and ``422`` requires your route to enforce the intended
validation policy. This demonstrates TestKit's public
method surface, not a preinstalled fixture.

For a failed write, assert **no database record, job
dispatch or outgoing notification** occurred. Merely
checking a 422 response does not prove the boundary
prevented a side effect.

Step 5 — isolate application state
----------------------------------

A test may use in-memory CacheLayer storage, native
TalkingBytes HTTP fakes, Omnibus recording senders
or a frozen Foundation application clock. Those are
valid for focused unit/integration tests **when the
corresponding feature is selected**.

They do not establish shared durability, Redis lease
ownership, production SMTP delivery or multi-worker
coordination.

Persistent hosts require a second class of test:
send a successful request, a failed request and a
different successful request through the **same**
execution process, verifying no principal,
session, transaction or request-local resources leak
between them.

See :doc:`persistent-runtimes`.

Step 6 — run representative production host checks
---------------------------------------------------

After the application test passes, verify a compiled
release and start the selected real host:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report
   php infbyte app:ready

For ordinary nginx/Apache PHP-FPM, serve the
application from ``public/index.php`` with an
**externally trusted** Foundation release manifest.
For Runwire, use the selected native host and current
extension requirements.

A passing in-process TestKit run is not proof of a
correct FPM FastCGI environment, trusted proxy,
TLS peer identity or host memory/resource behavior.
Those are separate acceptance gates.

Common mistakes
---------------

.. list-table::
   :header-rows: 1
   :widths: 31 34 35

   * - Problem
     - Consequence
     - Correction
   * - Test bootstraps Foundation's source directory
     - Wrong route/provider/config graph
     - Use the actual Infbyte project ``base_path``
   * - Test uses CLI graph for HTTP
     - No web kernel
     - Select ``Foundation::web()``
   * - Expecting a JsonDispatch envelope on all routes
     - False negatives and wrong public contract
     - Assert the response actually emitted by the route
   * - Fake broker proves delivery
     - Durable acknowledgment not verified
     - Test selected real Omnibus transport separately
   * - Tests only happy paths
     - Auth and side-effect regressions
     - Include invalid/unauthorized/repeated requests
   * - Reused persistent state
     - Cross-user leakage
     - Verify scope cleanup across executions

Related
-------

Continue with :doc:`../start/first-application`,
:doc:`../http/routing`,
:doc:`../tutorials/test-an-application` and
:doc:`persistent-runtimes`.
