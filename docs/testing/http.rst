.. _http-testing:

HTTP integration testing
========================

Foundation's HTTP test client creates **native Webrick requests** and
dispatches through the Foundation web application's normal HTTP
kernel and middleware. It is useful for verifying controller output,
routing, authorization decisions and request cleanup without opening
a public listening socket.

Set up a web application
------------------------

This example assumes a configured web application is available to
the test and the expected route has been explicitly registered.
Use the same build/configuration fixtures as other web integration
tests in your application. A plain ``Foundation::cli()`` application
cannot provide an HTTP test client.

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);
   $response = $testing->http()->get('/api/health');

   $response
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

The ``/api/health`` endpoint is defined in the current published
Infbyte 3 skeleton. For Foundation 4, this snippet is an API illustration
until the corresponding downstream skeleton and route have been verified.

Submit input and headers
------------------------

The current ``HttpTestClient`` supports
``get()``, ``post()``, ``put()``, ``patch()``,
``delete()``, ``request()`` and ``withHeaders()``.

.. code-block:: php

   $response = $testing->http()
       ->withHeaders(['Authorization' => 'Bearer test'])
       ->post('/accounts', ['email' => 'user@example.test']);

   $response->assertStatus(201);

This requires your application to define a compatible
``POST /accounts`` route and to configure its auth test driver.
The token text is **illustrative**, not a pre-authorized credential.

``HttpTestClient`` supplies a JSON ``Accept`` header and
``localhost`` host by default. Headers from a call to
``withHeaders()`` are applied to the client clone, leaving other
tests' client settings unchanged.

Response assertions
-------------------

``TestResponse`` includes:

* ``assertStatus(int)`` for exact HTTP status.
* ``assertHeader(string, ?string)`` for presence or exact value.
* ``assertJson(array)`` for a contained JSON fragment.
* ``assertJsonPath(string, mixed)`` for an exact dot-path value.
* ``json()``, ``body()``, ``response()`` and ``status()``
  for further inspection.

Example for a route with a JSON envelope:

.. code-block:: php

   $response->assertJsonPath('data.email', 'user@example.test');

This is a **strict** value comparison. Use ``assertJson()`` to
check partial nested fragments without requiring every response key.

Test negative paths too
-----------------------

Write tests for:

1. Unknown routes and wrong HTTP methods.
2. Missing or malformed request fields.
3. Authentication/authorization failures.
4. CSRF rejection for selected browser routes.
5. A middleware exception followed by another successful request.
6. Correct response headers, content types and error envelopes.

If a fake HTTP request passes but a deployed host fails, investigate
headers/proxy normalization, trusted release identity, body sizes
and the selected native adapter; do not treat in-process tests as
full host certification.

Related: :doc:`../http/validation`,
:doc:`../security/authentication`, and :doc:`persistent-runtimes`.
