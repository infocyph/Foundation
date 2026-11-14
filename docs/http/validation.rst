.. _validation-guide:

Validating HTTP input in an Infbyte application
===============================================

An Infbyte route must treat incoming path, query,
JSON, form and uploaded-file data as **untrusted**.
The optional ``security.validation`` feature
composes Foundation's ``FormRequest`` adapter
with the native **ReqShield** validation engine.

Your application-owned configuration belongs at
**``my-app/config/validation.php``** after the
selected feature publishes it. A new Core-only
Infbyte candidate does **not** ship that file.

When validation helps
---------------------

Use input validation before persisting
account details, creating a job, sending
an external request or moving an uploaded
file. Define the expected fields and
types for each operation instead of
silently passing raw HTTP input to
native database or messaging services.

Validation is not authorization,
SQL parameter binding or HTML output
escaping. Those are independent concerns.

Step 1 — select the optional validator
--------------------------------------

Run from the Infbyte application project root:

.. code-block:: bash

   php infbyte module:plan security.validation
   php infbyte module:install security.validation
   php infbyte module:enable security.validation
   php infbyte module:doctor security.validation

ReqShield owns rule syntax and execution.
Foundation selects the validation profile
and integrates the current Webrick request.
Installing the library alone is not the
same as activating a runtime feature.

Step 2 — inspect your application config
----------------------------------------

After feature publication, open
``my-app/config/validation.php``. The
current template selects:

* ``fail_fast`` — stop rule processing
  when the configured policy requests it.
* ``database_connection`` — lazily selected
  DBLayer connection for database rules.
* ``defaults`` — unknown-field policy,
  nesting, locale, casts and rule limits.
* ``schemas`` / ``extend`` — explicit
  named application schema contracts.
* ``overrides`` — per-schema profile policy.

The template defaults
``defaults.allow_unknown`` to ``true``,
``strip_unknown`` to ``false`` and
``strict`` to ``false``. These are
explicit choices, not a promise that
unknown input is safe to persist.

Select an appropriate policy for each endpoint:
accept unknown fields, strip them or reject
them. Always pass only intended, validated
values to your application operation.

Step 3 — create a request validator
-----------------------------------

A compatible Foundation 4 CLI exposes
starting generators:

.. code-block:: bash

   php infbyte create:request StoreAccount
   php infbyte create:rule ValidVatNumber

Create an application class under
``my-app/app/Http/Requests/``; it is not
a class located in the Foundation source tree.

A complete PHP example of the verified
``FormRequest`` contract:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Requests;

   use Infocyph\Foundation\Validation\FormRequest;
   use Infocyph\Webrick\Request\Request;

   final class StoreAccountRequest extends FormRequest
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

The base class requires the protected
``rules(Request $request): array`` method.
It uses the native ``ValidatorFactory`` and
ReqShield result; there is no alternate
Laravel-style Form Request runtime.

Step 4 — obtain typed validated values
--------------------------------------

Inside a configured HTTP handler with
a current Webrick ``Request`` and a
composed ``StoreAccountRequest`` instance:

.. code-block:: php

   $values = $storeAccountRequest->validated($request);

``validated()`` performs validation,
throws on failure, then returns the
ReqShield ``typed()`` values.

If you need to inspect a result without
throwing:

.. code-block:: php

   $result = $storeAccountRequest->validate($request);

   if (!$result->passes()) {
       // Map the native issues through the selected HTTP boundary.
   }

These are **contextual snippets**: your
application must supply the actual validator
instance and request. They do not create a
preinstalled ``POST /accounts`` endpoint.

**Efficiency:** don't call ``validate()``
and then ``validated()`` on the same
request path just to obtain values.
That performs validation twice. Prefer
one validation call, then pass the
verified typed result downstream.

Step 5 — distinguish input from business policy
------------------------------------------------

An email passing ReqShield's ``email``
rule is a syntactically valid input value.
It does **not** prove the user owns it,
that the account exists, or that the
current principal may modify another
user's profile.

For an authenticated write:

1. Establish the identity using selected
   Foundation auth middleware.
2. Authorize the intended operation
   and selected resource.
3. Validate and normalize submitted data.
4. Run the bounded domain/persistence operation.
5. Return an appropriate native Webrick
   response or JsonDispatch envelope.

Do not take ``$request->all()`` after
successful validation and persist the
raw map, because the output may ignore
your casts, sanitizers and field policy.

Step 6 — database-backed validation
------------------------------------

ReqShield rules such as database
``exists`` or ``unique`` require
the selected DBLayer connection and
``data.database`` feature. Foundation
should resolve that connection lazily;
an endpoint without database rules
doesn't need to open SQL connectivity.

Do not replace race-sensitive unique
database constraints with a single
pre-insert ``unique`` validation check.
Concurrent requests can both pass a
check; enforce atomic uniqueness through
the owning database constraint/transaction.

Step 7 — map failures safely
----------------------------

A ReqShield ``ValidationResult``
contains structured issues. Foundation's
JsonDispatch response factory can map
those issues to your chosen application
response envelope without revalidation.

Prefer meaningful HTTP status codes for
public JSON APIs. Do not serialize full
exception traces, secrets or unrelated
private fields as validation output.

When validation must reject an HTTP
upload, combine input rules with
Webrick request size limits and native
Pathwise untrusted-data policy.

Step 8 — test success and failure
---------------------------------

Suppose your application defines a
``POST /accounts`` endpoint and binds
``StoreAccountRequest`` to it. A
contextual Foundation HTTP TestKit call:

.. code-block:: php

   $response = $testing->http()->post('/accounts', [
       'email' => 'not-an-email',
       'name' => 'Example',
   ]);

   $response->assertStatus(422);

The route and its 422 response are
**application-defined**; they are
not present in a fresh Infbyte 4
candidate. Test invalid/missing values,
unknown fields, type casts and
security-sensitive database rules.

On a validation failure, assert that
no record was inserted, queue message
published or outbound request sent.

Step 9 — keep validation performant
-----------------------------------

A validator can process nested objects,
wildcard rules, localization and
database batching. Use the minimum
schema needed for the operation and
bound nested depth, field counts,
payload bytes and wildcard expansion.

If only one inexpensive route field
is needed, avoid enabling a broad
database-aware schema for the entire
application. Keep request-specific
data out of persistent global state.

The native ReqShield limiter/template
defaults include upper bounds for
depth and fields, but they should be
reviewed for the actual application's
input size and accepted document
formats before production deployment.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 28 36 36

   * - Symptom
     - Likely cause
     - Safe check
   * - ``config/validation.php`` missing
     - Feature not installed/published
     - ``module:doctor security.validation``
   * - Unexpected field persists
     - Raw request input reused
     - Use validated typed values only
   * - Every request opens DB
     - Database-backed rule/feature selected too broadly
     - Profile the actual rule plan
   * - 422 response leaks data
     - Raw input/errors serialized
     - Use safe JsonDispatch failure mapping
   * - Two requests create duplicates
     - ``unique`` validation used without DB constraint
     - Add native atomic uniqueness
   * - Validator state leaks across requests
     - Mutable request data stored in singleton
     - Keep validator request-scoped/stateless

Related
-------

For a longer end-to-end walkthrough, read
:doc:`../tutorials/validate-an-api-request`,
:doc:`../security/authorization`,
:doc:`../testing/http` and
the native ReqShield reference:

https://github.com/infocyph/ReqShield
