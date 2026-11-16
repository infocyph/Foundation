.. _tutorial-validate-api:

Tutorial: validate an API request
=================================

Use this walkthrough when a route accepts **untrusted input** and you want
one predictable validation boundary before any database, message, filesystem,
or authentication side effect.

This guide targets the **Foundation 4 development branch**, not the currently
published Infbyte 3 starter. It shows the source-verified
``Infocyph\Foundation\Validation\FormRequest`` contract and native
ReqShield rule language; a complete HTTP integration test still requires
the compatible Infbyte 4 skeleton.

What you will build
-------------------

Imagine an endpoint receiving a JSON registration request containing an
email address. Your goals are to reject missing or malformed values,
obtain validated data, avoid inconsistent normalization and return a
safe error response without starting an unnecessary database query.

The path through the system is:

.. code-block:: text

   Webrick request
       -> selected routing/middleware
       -> Foundation FormRequest adapter
       -> ReqShield validation result
       -> application handler
       -> native Webrick or JsonDispatch response

Foundation is the application integration point; ReqShield owns validation
rules, sanitizers, casts, messages and execution mechanics.

Prerequisites and feature selection
-----------------------------------

Select the optional Foundation 4 validation feature and check the plan
before mutation:

.. code-block:: bash

   php infbyte module:list
   php infbyte module:plan security.validation
   php infbyte module:install security.validation
   php infbyte module:doctor security.validation

The feature selects ReqShield. Merely installing the Composer package is
not equivalent to an activated production capability. For a generated
starting class in a compatible application, use:

.. code-block:: bash

   php infbyte create:request StoreUser
   php infbyte create:rule ValidVatNumber

Do not run Foundation 4 feature commands unmodified against the public
Foundation 3-based Infbyte application.

Step 1 — create the request-validation class
--------------------------------------------

A Foundation 4 ``FormRequest`` is an abstract, stateless request adapter.
Its contract requires ``rules(Request $request): array``, while
``validate()`` and ``validated()`` are supplied by the base class.

A complete source-level class looks like this:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Requests;

   use Infocyph\Foundation\Validation\FormRequest;
   use Infocyph\Webrick\Request\Request;

   final class StoreUserRequest extends FormRequest
   {
       /** @return array<string, mixed> */
       protected function rules(Request $request): array
       {
           return [
               'email' => 'required|email|max:255',
               'name' => 'required|string|max:100',
           ];
       }
   }

The method is intentionally pure: it returns a schema, not an already
executed validation result or an HTTP response. Foundation's
``ValidatorFactory`` builds the ReqShield validator on demand. The
current ``FormRequest`` also offers ``input()`` and ``options()``
hooks when an application needs intentional request shaping or a
per-request validation profile.

Why this is useful: request-specific validation stays close to the
boundary, but actual validation mechanics remain independently testable
in ReqShield. The same rule engine can serve CLI, worker and web
use cases without reimplementing field rules in controllers.

Step 2 — obtain only validated values
-------------------------------------

Inside the **request-handling context**, resolve or inject your
application-defined validator and supply the current Webrick
``Request``. The following is a *contextual invocation snippet*
because binding a handler and request validator depends on your
application route configuration:

.. code-block:: php

   $result = $storeUserRequest->validate($request);

   if (!$result->passes()) {
       // Translate issues through the selected HTTP error boundary.
   }

   $values = $storeUserRequest->validated($request);

``validate()`` returns a native ReqShield
``ValidationResult``. ``validated()`` throws on a failed
validation and returns the typed result after ``throw()->typed()``.
Do **not** pass ``$request->all()`` into persistence after a
successful check and assume it is the sanitized/typed output. Use the
verified value set.

Choose one validation call per request path when possible: calling
``validate()`` and then ``validated()`` in the same path
performs validation again. For a fail-fast handler prefer
``$storeUserRequest->validated($request)`` once; use
``validate()`` once when you need to inspect structured failures.

Step 3 — distinguish input rules from authorization
----------------------------------------------------

Validation answers whether supplied data meets the input contract.
It does **not** grant permission to change an account or access a
specific resource. A valid account ID might still belong to a
different principal.

For an authenticated endpoint:

1. Resolve an authenticated principal using selected auth middleware.
2. Enforce route/resource authorization.
3. Validate the submitted shape and types.
4. Perform the authorized operation using validated values.
5. Translate validation or domain failures to the configured
   safe response boundary.

The precise order can vary with the endpoint's security policy;
never use a valid field format as a substitute for authorization.

Step 4 — emit a safe failure response
-------------------------------------

Foundation can adapt ReqShield issues through its current
JsonDispatch response factory. That layer maps validation failures
without running the validator again. Webrick owns the actual response
emission and error semantics. Do not serialize sensitive request
fields, exceptions, full database errors or credentials into public
validation responses.

In native HTTP mode preserve meaningful 4xx statuses; do not turn every
validation error into a 200 response unless an explicitly selected
envelope policy requires an alternate transport status.

Step 5 — test both successful and unsuccessful input
----------------------------------------------------

Using Foundation's HTTP TestKit with an application-owned
``POST /accounts`` route:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $response = $testing->http()->post('/accounts', [
       'email' => 'not-an-email',
       'name' => 'Example',
   ]);

   $response->assertStatus(422);

This is a **route-dependent illustration**: the endpoint, its failure
status and response envelope must be configured by the application.
The assertion is not evidence that Infbyte's current published starter
already has an ``/accounts`` route. Test a successful normalized
value separately, and verify no account or queue side effects occur
when validation fails.

For persistent-host behavior, run success, invalid input and success
through the same execution process; ensure no request-local
validator data, principal or previous input leaks between requests.

Common mistakes and their cost
------------------------------

.. list-table::
   :header-rows: 1
   :widths: 27 38 35

   * - Mistake
     - Consequence
     - Better approach
   * - Revalidating in multiple layers
     - Repeated rule parsing and potential duplicate DB checks
     - Validate once; pass typed values downstream
   * - Using raw values after validation
     - Sanitization and typed-cast rules become ineffective
     - Use native ``typed()`` results
   * - Treating format as permission
     - Cross-tenant or cross-account access
     - Perform principal and resource authorization
   * - Enabling database-backed rules everywhere
     - Unnecessary connections and latency
     - Select only needed rules and DBLayer feature
   * - Trusting upload metadata
     - Unsafe file handling
     - Validate content, size and Pathwise trust policy
   * - Exposing validation internals
     - Sensitive data appears in responses/logs
     - Use configured error mapping and redaction

Next steps
----------

Read :doc:`../http/validation`, :doc:`../http/requests-responses`,
:doc:`../security/authorization` and the
`ReqShield rules and typed-result documentation
<https://github.com/infocyph/ReqShield>`_.
