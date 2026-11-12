.. _validation-guide:

Request validation
==================

Foundation 4 composes application validation through its ``FormRequest``
adapter. The underlying validation engine is **ReqShield**, which owns
schema/rule mechanics, sanitization, typed values and database batching.

Select the validation feature
-----------------------------

A compatible Foundation 4 application selects the optional
``security.validation`` feature:

.. code-block:: bash

   php infbyte module:plan security.validation
   php infbyte module:install security.validation
   php infbyte create:request StoreUser
   php infbyte create:rule ValidVatNumber

These are Foundation 4 feature identifiers, not commands to run against
the existing Foundation 3 skeleton unchanged.

Validate a Webrick request
--------------------------

The Foundation 4 ``FormRequest`` class requires a
``rules(Request $request): array`` implementation and calls ReqShield
through ``ValidatorFactory``. For example, a class in a compatible
application can define:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Requests;

   use Infocyph\Foundation\Validation\FormRequest;
   use Infocyph\Webrick\Request\Request;

   final class StoreUserRequest extends FormRequest
   {
       protected function rules(Request $request): array
       {
           return [
               'email' => 'required|email|max:255',
           ];
       }
   }

The request is supplied by the HTTP execution boundary, not invented
by application code. ``FormRequest::validate()`` returns a
ReqShield ``ValidationResult``; ``validated()`` throws on failure
and returns typed data. Do not use unchecked raw input to write records.

Failure mapping and performance
-------------------------------

Foundation can map validation failures through its HTTP/JsonDispatch
response boundary without implementing another validator. ReqShield
owns reusable schema compilation and database-backed rule behavior.

Prefer targeted rules and explicitly selected capability topology.
Sanitization does not replace authorization or escaping at an output
boundary. Uploaded filenames and metadata require specific
security validation before storage.

For the full rule reference, see
`ReqShield <https://github.com/infocyph/ReqShield>`_
and the `Foundation validation guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/http-and-capabilities.md>`_.

See :doc:`requests-responses`.
