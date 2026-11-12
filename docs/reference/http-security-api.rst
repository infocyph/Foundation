.. _http-security-api-reference:

HTTP, validation and security API reference
===========================================

This page lists Foundation 4's **application-facing integration APIs**;
native HTTP parsing, routing, response emission and middleware execution
remain Webrick responsibilities. Native validation rules belong to ReqShield;
cryptography belongs to Epicrypt.

The signatures below are checked against the current Foundation 4
development source. They are not claims of end-to-end compatibility with
the currently published Infbyte 3 application skeleton.

Validation: FormRequest
-----------------------

``Infocyph\Foundation\Validation\FormRequest``:

.. list-table::
   :header-rows: 1
   :widths: 46 54

   * - Method
     - Behavior
   * - ``rules(Request $request): array``
     - Required protected rules; application declares validation schema
   * - ``validate(Request $request): ValidationResult``
     - Validate through ReqShield, return native result
   * - ``validated(Request $request): array``
     - Throw on failed validation, return typed values
   * - ``__invoke(Request $request): ValidationResult``
     - Callable request validator adapter
   * - ``input(Request $request): array``
     - Protected input selection hook; default is ``$request->all()``
   * - ``options(Request $request): array``
     - Protected profile override hook

The class is stateless. Request-specific state must not be stored
in long-lived provider singletons. For HTTP failure responses,
map the native ``ValidationResult`` once instead of
revalidating.

JsonDispatch response integration
---------------------------------

``Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory``
offers:

* ``success()`` — successful application representation.
* ``fail()`` — controlled validation or domain rejection.
* ``error()`` — error boundary.
* ``failureFromValidation()`` — ReqShield issue mapping without rerun.
* ``paginated()`` — native DBLayer paginator metadata in the response.

The response factory advertises
``SPECIFICATION_VERSION = '3.0.0'``. The application policy
is configured under ``responses.json_dispatch``. By default,
``tunnel_errors = false`` preserves native HTTP 4xx/5xx status.
The configured vendor and application version determine the
media type and version-selection headers.

The public ``Issue`` record is a JSON-serializable
``Infocyph\Foundation\Http\JsonDispatch\Issue``.
Avoid exposing exception traces or untrusted sensitive input in
an issue message.

``Infocyph\Foundation\Http\Resource\JsonResource`` maps an
application-owned object through ``resolve(): array``;
it does not provide a database model or object repository.

Authentication: AuthServices
----------------------------

The application resolves
``Infocyph\Foundation\Auth\AuthServices`` via
``Application::make(AuthServices::class)``. It exposes lazy typed
accessors; optional drivers may require explicitly selected features.

.. list-table::
   :header-rows: 1
   :widths: 38 62

   * - Accessors
     - Responsibilities
   * - ``accounts()``, ``accountProvider()``
     - Account management and selected account storage
   * - ``authenticator()``, ``sessions()``, ``tokens()``
     - Login, auth sessions and token authentication
   * - ``authorizer()``, ``gate()``, ``roles()``, ``permissions()``
     - Authorization rules and grants
   * - ``principals()``
     - Scoped current-principal context
   * - ``mfa()``, ``passkeys()``, ``oauth()``
     - Optional factors and federated/token flows
   * - ``passwordHasher()``, ``passwordVerifier()``, ``passwordPolicy()``
     - Selected security/password engines
   * - ``passwordChanges()``, ``passwordResets()``, ``passwordless()``
     - Credential lifecycle
   * - ``emailVerification()``, ``rememberMe()``
     - Verified recipient and remember-token flows
   * - ``delegation()``, ``impersonation()``, ``stepUp()``
     - Higher-assurance auth transitions
   * - ``devices()``, ``lockouts()``
     - Device and account protection state

For a service with access to ``Application $app``:

.. code-block:: php

   use Infocyph\Foundation\Auth\AuthServices;

   $auth = $app->make(AuthServices::class);

   $authorizer = $auth->authorizer();
   $sessions = $auth->sessions();

The ``sessions()`` accessor refers to **authentication**
sessions, not the cookie-backed ``BrowserSession`` on web
routes. Never cache a request principal on an application singleton.

Other application-facing seams
------------------------------

* ``Infocyph\Foundation\Session\BrowserSession`` —
  request-scoped browser data, CSRF and ID rotation.
* ``Infocyph\Foundation\Filesystem\StorageRegistry`` —
  selected Pathwise disk/policy adapter when ``data.filesystem``
  is enabled.
* ``Infocyph\Foundation\Communication\CommunicationProfiles``
  — named TalkingBytes outbound communication profiles when
  ``execution.communication`` is selected.

Absent optional features should fail according to their contracts;
they do not imply eager initialization or silently construct a fake
backend in production.

Source and practical guides
---------------------------

* `FormRequest source
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Validation/FormRequest.php>`_
* `JsonDispatch factory source
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Http/JsonDispatch/JsonDispatchResponseFactory.php>`_
* `AuthServices source
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Auth/AuthServices.php>`_
* `Webrick HTTP docs <https://github.com/infocyph/Webrick/tree/main/docs>`_

See :doc:`../tutorials/validate-an-api-request`,
:doc:`../tutorials/json-api-responses` and
:doc:`../tutorials/secure-browser-session`.
