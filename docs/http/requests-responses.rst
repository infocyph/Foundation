.. _requests-responses:

Requests and responses in an Infbyte application
================================================

Infbyte's HTTP layer uses native **Webrick Request
and Response** objects. Foundation composes the
application graph and selected error/resource
integration; it does not provide a replacement
Laravel-style request or response facade.

Controllers and routes belong under the
**Infbyte project** ``app/`` and ``routes/``
directories. Webrick owns the HTTP adaptation,
content negotiation, response emission and
streaming mechanics.

When to read a request object
-----------------------------

The actual sample controller's
``SystemController::health()`` accepts no
request. That's intentional: a constant
health result doesn't need query input,
cookies, headers, a database or a
request-scoped service.

Accept a Webrick ``Request`` when
your operation genuinely needs
untrusted client data or HTTP context.

Step 1 — read query values safely
---------------------------------

Create a developer-owned controller under
``my-app/app/Http/Controllers/SearchController.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Controllers;

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   final readonly class SearchController
   {
       public static function index(Request $request): Response
       {
           $page = $request->int('page', 1);

           return Response::json([
               'page' => max(1, $page),
           ]);
       }
   }

Register it in **``my-app/routes/api.php``**:

.. code-block:: php

   use App\Http\Controllers\SearchController;

   $router->get('/api/search', SearchController::index(...));

This endpoint is **not preinstalled**. Webrick
converts the query parameter through the
typed ``Request::int()`` helper, but
you still decide acceptable limits and
business semantics.

Try on the selected local listener:

.. code-block:: bash

   curl -i 'http://127.0.0.1:8000/api/search?page=3'

Use the bound address actually reported
by ``php infbyte serve``.

Step 2 — inspect native request input
-------------------------------------

A source-verified Webrick ``Request`` exposes:

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Meaning
   * - ``query('page', 1)``
     - Read an optional query parameter
   * - ``input('email')``
     - Get parsed submitted input
   * - ``all()``
     - Merge configured query/parsed-body input
   * - ``only(['email', 'name'])`` / ``except(['token'])``
     - Pick or exclude keys for a specific boundary
   * - ``int('page', 1)``, ``boolean('active')``, ``string('name')``
     - Typed helper with a chosen default
   * - ``header('Accept')``
     - Get a header by name
   * - ``getCookieParams()``
     - Read native cookie parameter data
   * - ``hasFile('avatar')``
     - Detect uploaded file field
   * - ``routeIs('accounts.*')``
     - Test current named route pattern when configured
   * - ``getMethod()``, ``getUri()``
     - Inspect HTTP method and native URI

Webrick also exposes native PSR-style
header/body/URI methods and helpers for
content negotiation, languages and
trusted proxy classification.

**None of these helpers authorize a
client-provided value**. A typed
integer from a query can still refer
to another tenant's resource.

Step 3 — validate a body before effects
---------------------------------------

For application writes, select
``security.validation`` and use
Foundation ``FormRequest`` and native
ReqShield. The application can
validate the Webrick request once,
then pass the typed values to its
business service.

.. code-block:: php

   $values = $storeAccountRequest->validated($request);

This is a **contextual snippet**:
``$storeAccountRequest`` must be
an application-defined validator,
and ``$request`` comes from the
selected HTTP execution boundary.

Do not call ``$request->all()`` after
validation and assume it is the same
typed, sanitized/filtered result.

For a complete application request class
and negative test, see
:doc:`validation` and
:doc:`../tutorials/validate-an-api-request`.

Step 4 — construct a JSON response
----------------------------------

Webrick owns response creation:

.. code-block:: php

   use Infocyph\Webrick\Response\Response;

   $reply = Response::json(['status' => 'ok'], 200);

An API write that created a resource may
use HTTP 201; invalid input generally
maps to a safe 4xx response under the
selected application envelope policy.

There is no need to echo JSON directly
or call a second response emitter.
Webrick handles the actual HTTP
response write.

Step 5 — use immutable headers
------------------------------

Webrick Response supports immutable
``withHeader()`` and ``withStatus()``.
Create a new response value instead
of expecting in-place mutation:

.. code-block:: php

   $reply = Response::json(['updated' => true], 200)
       ->withHeader('Cache-Control', 'no-store');

For protected data, ensure caching policy
doesn't allow one user's sensitive
response to be served to another.
Content negotiation, validators and
compression are native Webrick
middleware responsibilities.

Step 6 — choose the right response kind
---------------------------------------

.. list-table::
   :header-rows: 1
   :widths: 33 34 33

   * - Need
     - Native Webrick API
     - Important consideration
   * - JSON resource
     - ``Response::json()``
     - Avoid secret fields and unbounded arrays
   * - Plain text
     - ``Response::plaintext()``
     - Select meaningful content type/status
   * - Redirect
     - ``Response::redirect()``
     - Validate destination and avoid open redirects
   * - Empty response
     - ``Response::noContent()``
     - No body for a no-content status
   * - Attachment/download
     - ``Response::attachment()``
     - Authorize file and bound transfer
   * - Streaming output
     - ``Response::stream()``
     - Avoid retaining request-scoped state after execution

For large file transfer, use the
selected Pathwise and Webrick file
policies. Do not open arbitrary
user-supplied filesystem paths.

Step 7 — use application JSON resources
---------------------------------------

Foundation 4 provides
``Infocyph\Foundation\Http\Resource\JsonResource``
and ``JsonDispatchResponseFactory``
for reusable application representations
and structured error envelopes.

This integration is **separate** from
``Response::json()`` and doesn't
turn Infbyte into an Active Record ORM.

A reusable resource is appropriate
when multiple endpoints must expose
an authorized, stable set of fields.
Keep DBLayer queries and permission
checks in application services,
then map the resulting authorized
domain object to JSON.

Read :doc:`../tutorials/json-api-responses`
for the full resource/error process.

Step 8 — content negotiation and proxies
----------------------------------------

Webrick can select a representation based
on a request's ``Accept`` header and
configured middleware. It can also
emit conditional-cache headers,
compressed bodies, and CORS policy.

If your reverse proxy terminates TLS,
configure only actual trusted proxy
networks and header flags. Treating
arbitrary client-provided forwarded
headers as authoritative can change
the perceived host, secure scheme,
origin policy and signed URL checks.

When using signed URLs or browser
CSRF/origin validation, confirm the
external URL policy matches the
actual host configuration.

Step 9 — test success and error results
---------------------------------------

From a configured Infbyte web TestKit:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $testing->http()->get('/api/health')
       ->assertStatus(200)
       ->assertJson(['status' => 'ok']);

This route exists in the candidate.
For the developer-created search
route, test page 1, page 3, empty
values and excessive input.

For a protected write, also test
missing authentication, insufficient
permission, rejected validation
and failure after starting a
transaction. A 200 response is not
enough evidence for secure behavior.

Step 10 — performance and lifecycle
------------------------------------

Avoid parsing a large JSON body or
constructing an expensive resource
when the handler doesn't need it.
Use bounded payload, upload and
response sizes.

Streaming must respect the selected
host's cancellation and cleanup policy.
Do not lazily access a released
``BrowserSession`` or request-scoped
transaction inside a stream producer
after the handler's scope has ended.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 36 35

   * - Symptom
     - Likely cause
     - Safe check
   * - Query returns unexpected type
     - Unvalidated client value/default
     - Typed helper plus ReqShield schema
   * - JSON includes extra private fields
     - Raw model/entity serialized
     - Application resource allow-list and authorization
   * - Header update disappears
     - Original immutable response returned
     - Return value from ``withHeader()``
   * - Redirect points to wrong domain
     - External URL/proxy policy incorrect
     - Trusted proxy/host settings
   * - Download exposes a file
     - Unchecked path or missing access policy
     - Pathwise disk/download authorization
   * - Large response exhausts memory
     - Unbounded buffer instead of bounded native streaming
     - Payload and host limits

Next steps
----------

See :doc:`controllers`,
:doc:`middleware`,
:doc:`../services/resources-logging`
and the native Webrick request/response
guides:

https://github.com/infocyph/Webrick/blob/main/docs/guides/requests.rst

https://github.com/infocyph/Webrick/blob/main/docs/guides/responses.rst
