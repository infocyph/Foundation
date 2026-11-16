.. _resources-and-logging:

JSON resources and application logging
======================================

Infbyte applications expose structured JSON through Webrick and log
operational events through **PSR-3**. Foundation provides application
resource and logging contracts, but neither requires an ORM, a
global facade or a custom response writer.

The examples below target the **Infbyte 4 development candidate**.
Application paths refer to **``my-app/``**, not Foundation's
source repository.

Create an application JSON resource
-----------------------------------

Create ``my-app/app/Http/Resources/AccountResource.php``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Http\Resources;

   use Infocyph\Foundation\Http\Resource\JsonResource;

   final class AccountResource extends JsonResource
   {
       /** @return array{id: int, email: string} */
       public function resolve(): array
       {
           /** @var array{id: int, email: string} $account */
           $account = $this->resource;

           return [
               'id' => $account['id'],
               'email' => $account['email'],
           ];
       }
   }

This **application-created** resource expects a validated array with the
documented keys. It must not expose hidden credentials, API tokens or
private account fields. ``JsonResource`` implements
``JsonSerializable``; its ``resolve()`` method maps the
application data to a representation, not an Active Record model.

For a supported starting class, use:

.. code-block:: bash

   php infbyte create:resource Account

The CLI generator creates application source but does not register a
route or turn every response into a resource envelope. Register your
handler through Webrick, then return a selected resource/native
response using the owning API.

Use a native response when the HTTP status/header matters
---------------------------------------------------------

A handler may return Webrick's immutable response:

.. code-block:: php

   use Infocyph\Webrick\Response\Response;

   $response = Response::json(['status' => 'created'], 201)
       ->withHeader('Cache-Control', 'no-store');

Foundation's optional ``JsonDispatchResponseFactory``
can produce configured envelopes for application resources and
ReqShield errors, but **the default Infbyte ``/api/health``**
returns plain ``{"status":"ok"}``, not a universal
``data.status`` wrapper. Test the actual selected endpoint contract.

Where logging configuration belongs
-----------------------------------

A new Core-only Infbyte candidate does **not** ship
``config/logging.php``. Foundation supplies Core defaults
through its internal template. To customize the application,
copy the installed template **into your Infbyte checkout**:

.. code-block:: bash

   cp vendor/infocyph/foundation/resources/config/logging.php config/logging.php

The copy is now application-owned. Do not change the vendor copy.
The available drivers are ``null``, ``file`` (JSON Lines),
and ``error_log``. The default is ``null`` and the default
minimum level is ``warning``.

For an application-owned environment:

.. code-block:: text

   LOG_DRIVER=file
   LOG_LEVEL=warning
   LOG_PATH=storage/logs/infbyte.jsonl

The path resolves relative to the **Infbyte project**. Its parent
directory must be writable by the selected PHP process, and the host
must rotate, retain and protect the records.

Use the PSR-3 logger
--------------------

Resolve ``Psr\Log\LoggerInterface`` from a booted application
or inject the same contract into a typed service:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Services;

   use Psr\Log\LoggerInterface;

   final readonly class AuditReporter
   {
       public function __construct(
           private LoggerInterface $logger,
       ) {}

       public function reportCompleted(int $reportId): void
       {
           $this->logger->info(
               'Report completed',
               ['report_id' => $reportId],
           );
       }
   }

Register this **application-owned service** through the appropriate
InterMix provider. The logging driver is selected by your
``config/logging.php`` override. A ``null`` driver
intentionally discards records; a successful ``info()``
call does not prove any log was persisted.

Redaction and exception safety
------------------------------

Foundation's built-in logger normalizes and redacts configured
context keys recursively. The Core redaction list covers
authorization, cookie, password, secret and token fragments.
Add application-specific sensitive key fragments in
``logging.redact`` as needed.

Redaction of **context keys** is not permission to put a password
or access token in a free-text message. Never log raw credentials,
full request bodies, session cookies or unbounded exception
traces. Exception messages and traces are disabled by default
because they may contain user input, SQL values or secrets.

HTTP exception reporting and client rendering are **separate**.
It is valid to record a redacted operational failure while returning
a safe 4xx/5xx response without an internal stack trace.

Performance and operations
--------------------------

Keep verbose diagnostics off the hot path. When a command does
not need a logger, do not eagerly build large log context objects.
The built-in JSON logger appends one JSON object per line; choose
file permissions, write throughput and rotation appropriate to
the actual host. Aggregation/transport is the deployment's decision.

For persistent workers, log only safe identifiers such as
request/operation IDs, worker generation and error category.
Do not retain a previous user's context in a singleton.

Test resource output for allowed fields, selected envelope,
status code, and absence of secret fields. Test logging with
a recording PSR-3 implementation and a real file/error-log
integration separately.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 27 36 37

   * - Symptom
     - Likely cause
     - Check
   * - No log file created
     - ``null`` driver or below minimum level
     - Application ``config/logging.php``
   * - Cannot append file
     - Wrong relative root or file permissions
     - Infbyte working directory and process user
   * - Missing JSON fields
     - Redaction or resource projection
     - Selected context/serialization policy
   * - Secret visible in log
     - Sensitive data embedded in message text
     - Sanitized event structure and redaction policy
   * - Client sees stack trace
     - Debug/error renderer misconfigured
     - ``APP_DEBUG`` and Webrick boundary

Next: :doc:`../http/requests-responses`,
:doc:`../tutorials/json-api-responses` and
:doc:`../deployment/operations`.
