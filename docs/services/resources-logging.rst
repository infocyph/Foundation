.. _resources-and-logging:

JSON resources and structured logging
=====================================

Foundation 4 provides application-level JSON resource composition and
PSR-3 logging policy. Webrick remains the owner of native HTTP responses
and exception rendering; an application's logger remains a PSR-3 service.

JSON resources
--------------

Extend ``Infocyph\Foundation\Http\Resource\JsonResource`` for
an application representation:

.. code-block:: php

   use Infocyph\Foundation\Http\Resource\JsonResource;

   final class AccountResource extends JsonResource
   {
       public function resolve(): array
       {
           return [
               'id' => $this->resource->id(),
               'email' => $this->resource->email(),
           ];
       }
   }

The resource's ``$this->resource`` is an application-owned object.
The example assumes it has ``id()`` and ``email()`` methods.

.. code-block:: bash

   php infbyte create:resource Account

Foundation's ``JsonDispatchResponseFactory`` maps resources and
ReqShield validation issues to application response envelopes. It
does not re-run validation or introduce an Active Record ORM.

Logging
-------

Foundation resolves ``Psr\Log\LoggerInterface``.
The built-in logger supports ``null``, JSON Lines ``file``
and ``error_log`` drivers. Applications can provide their own
PSR-3 logger binding.

Logging context redaction is recursive and follows configured
sensitive-key matching. Exception messages and traces can contain
secrets; keep them disabled unless a reviewed policy permits them.

Logging and exception-to-response rendering are independent operations.
Avoid constructing verbose diagnostics on every hot path. In CLI work,
logging services remain lazy until a selected operation needs them.

Review the `JSON resource contracts
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/json-responses.md>`_
and `structured logging
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/logging.md>`_.

For an end-to-end JSON API response and error-handling walkthrough,
see :doc:`../tutorials/json-api-responses`.
