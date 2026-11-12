.. _tutorial-json-api:

Tutorial: build predictable JSON APIs
=====================================

A JSON endpoint needs a defined input contract, authorization boundary,
representation, HTTP status, and error format. Infbyte relies on
**Webrick** for HTTP semantics and provides optional **Foundation
JsonDispatch** integration for application-specific envelopes.

This guide targets **Foundation 4 development contracts**.
The published Infbyte starter on ``main`` still uses Foundation 3,
so its example routes do not validate a Foundation 4 deployment.

What should the endpoint return?
--------------------------------

Start with an explicit response contract rather than a controller
implementation. For a small public health route, the native Webrick
response may be sufficient:

.. code-block:: php

   use Infocyph\Webrick\Response\Response;

   $response = Response::json(['status' => 'ok'], 200);

For a business API requiring consistent success/failure envelopes,
Foundation's ``JsonResource`` and
``JsonDispatchResponseFactory`` can express a representation and
application-specific issue codes.

These are two related but **not interchangeable** layers:

* Webrick's ``Response::json()`` owns HTTP output.
* Foundation's JSON resource controls application object mapping.
* JsonDispatch's response factory applies the application's chosen
  envelope and media-type policy.
* ReqShield validation results can be mapped into that policy
  without a second validation pass.

Do not assume every JSON endpoint should create a resource class;
use one when you need a reusable stable application representation.

Step 1 — define a resource
--------------------------

A Foundation 4 resource is not an ORM model. It maps an existing
application object to a serializable representation.

Generate a starting file:

.. code-block:: bash

   php infbyte create:resource Account

The following complete class demonstrates the source-level
``JsonResource::resolve()`` contract:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Resources;

   use Infocyph\Foundation\Http\Resource\JsonResource;

   final class AccountResource extends JsonResource
   {
       /** @return array<string, mixed> */
       public function resolve(): array
       {
           return [
               'id' => $this->resource->id(),
               'email' => $this->resource->email(),
           ];
       }
   }

``$this->resource`` is an **application-defined account object**
that supplies ``id()`` and ``email()``. It is not an
automatically discovered DBLayer model.

Expose only fields that are authorized for the caller. Resource
mapping is a data representation mechanism, not permission checking.
A secret field omitted from one resource can still leak through
unreviewed exception logging or another endpoint.

Step 2 — create a successful envelope
-------------------------------------

In a context with a composed Foundation application, resolve the
existing typed factory:

.. code-block:: php

   use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;

   $responses = $app->make(JsonDispatchResponseFactory::class);
   $reply = $responses->success(new \App\Http\Resources\AccountResource($account));

This is a contextual snippet: ``$app`` and ``$account``
must come from the application's runtime and authorized domain logic.

The factory's output must be returned through the configured Webrick
HTTP handler. The application should not separately emit headers or
echo JSON in its controller. Webrick retains control over the
response write, negotiation and middleware pipeline.

Step 3 — model failure cases explicitly
---------------------------------------

For a controlled domain rejection, Foundation exposes an issue type
with a stable application code:

.. code-block:: php

   use Infocyph\Foundation\Http\JsonDispatch\Issue;

   $reply = $responses->fail([
       new Issue('ACCOUNT_INVALID', 'Invalid account'),
   ], 422);

That code is an application example. A client should be able to
interpret the public issue code without inspecting raw exceptions,
SQL queries or internal class names.

Native-mode responses preserve semantic HTTP 4xx/5xx status codes.
Foundation also supports an explicitly configured error-tunneling
policy in which a semantic error may be represented inside an
envelope using a different transport status. Do **not** assume that
this alternate policy is always enabled.

A failed ReqShield validation can be translated with the factory's
``failureFromValidation()`` contract; it does not rerun the
validator. See :doc:`validate-an-api-request` for the validation
path and the one-validation-per-request rule.

Step 4 — design the endpoint's execution path
---------------------------------------------

An authorized resource read generally follows:

.. code-block:: text

   Webrick route + optional middleware
      -> principal resolution if needed
      -> resource-specific authorization
      -> minimal domain query / service
      -> JsonResource
      -> JsonDispatch or native Response
      -> Webrick response emission

The route can return a tiny immutable structure without constructing
a request object if it does not need one. Authentication,
database storage, caching, or session state should be resolved
only if selected by that endpoint.

For a write endpoint, validate input before domain mutation.
Make retry/idempotency requirements explicit when clients might
repeat the request after network failure.

Step 5 — verify the wire contract
---------------------------------

Foundation's ``TestKit`` dispatches through the native Webrick
HTTP integration boundary. If your application defines a
``GET /accounts/42`` route, a *contextual* response assertion
might look like:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);
   $response = $testing->http()->get('/accounts/42');

   $response
       ->assertStatus(200)
       ->assertJsonPath('data.email', 'user@example.test');

This assumes the selected JsonDispatch success envelope uses
``data.email``. The route, fixture and status are application-owned;
the current Infbyte starter does **not** ship an accounts endpoint.

Test at least these distinct outcomes:

1. A valid authorized representation with correct data and status.
2. A missing route and a method mismatch.
3. A malformed input or rejected domain operation.
4. Missing authentication / insufficient permission.
5. An unexpected exception with redacted public output.
6. The appropriate content type and response media-type/version policy.
7. Persistence failures without duplicate partial side effects.
8. Repeated requests through one persistent host, verifying cleanup.

Step 6 — apply HTTP performance discipline
------------------------------------------

Avoid serializing whole database row objects, secrets, or payloads that
the client does not need. Use DBLayer selection/pagination mechanisms
to bound database result size and ``ResourceCollection`` for
iterable resource mapping. Foundation's pagination integration maps
native DBLayer paginator metadata; cursor tokens remain opaque.

Prefer streaming through Webrick's native response facilities when a
selected endpoint truly needs large incremental output; do not turn
every small API into a complex streaming flow.

Cache validators, compression and CORS belong to the selected Webrick
middleware/representation policy. Do not manually add a duplicate
emitter or rebuild these policies inside every controller.

Common mistakes
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 36 36

   * - Mistake
     - Risk
     - Correct boundary
   * - Resource maps secret data
     - Unauthorized disclosure
     - Enforce authorization and allow-list output fields
   * - Controller echoes JSON directly
     - Duplicate or broken response emission
     - Return through native Webrick response
   * - Every error is HTTP 200
     - Clients cannot distinguish transport success
     - Preserve semantic status unless explicitly configured
   * - Validation runs twice
     - Duplicated work and backend calls
     - Reuse the native ReqShield result
   * - Huge unbounded collection
     - Memory and latency spikes
     - Pagination/streaming with bounded outputs
   * - Tests only check happy path
     - Regressed auth or error contracts
     - Cover negative and repeated execution cases

Related guides
--------------

See :doc:`../services/resources-logging`,
:doc:`../http/requests-responses`,
:doc:`../http/controllers`,
:doc:`../testing/http`, and the
`Foundation JsonDispatch contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/json-responses.md>`_.
