.. _requests-responses:

HTTP requests and responses
===========================

Webrick owns the HTTP request and response contracts used by Infbyte.
Foundation does not expose a separate request facade or replace Webrick's
single native response writer.

Read incoming data
------------------

A Webrick ``Request`` supports query parameters, parsed input, JSON,
headers, cookies and route parameters. In an HTTP handler:

.. code-block:: php

   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;

   function showInput(Request $request): Response
   {
       return Response::json([
           'page' => $request->query('page', 1),
           'input' => $request->all(),
       ]);
   }

Treat all client input as untrusted. Validate before persisting or
acting on it. Large uploads should use size, type, storage-policy and
body limits rather than blindly buffering or trusting client filenames.

Construct a response
--------------------

Webrick responses are immutable; methods returning changed headers
produce new responses:

.. code-block:: php

   use Infocyph\Webrick\Response\Response;

   $response = Response::json(['status' => 'ok'], 200)
       ->withHeader('Cache-Control', 'no-store');

   $redirect = Response::redirect('/login', 302);

Webrick also supports text, XML/HTML, file attachments, streaming,
content negotiation, conditional requests and signed URL behavior.
Foundation does not bundle a templating engine.

Framework-level JSON resources
------------------------------

Foundation 4 provides ``JsonResource`` and a
``JsonDispatchResponseFactory`` integration for application envelopes
and validation failures. These are distinct from Webrick's general
``Response::json()`` helper.

For the native API, see
`Webrick requests
<https://github.com/infocyph/Webrick/blob/main/docs/guides/requests.rst>`_
and `Webrick responses
<https://github.com/infocyph/Webrick/blob/main/docs/guides/responses.rst>`_.
For framework envelopes, read the
`Foundation JSON resource contract
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/json-responses.md>`_.

See also :doc:`validation`.
