.. _events-and-jobs:

Events, jobs and message handlers in Infbyte
============================================

Infbyte uses the optional **Omnibus** library for
application events, jobs, messages and handler
middleware. Foundation manages which integrations
the application selects and how work enters
and leaves a runtime execution scope.

A fresh Core-only Infbyte 4 candidate has no
``config/messaging.php``. This file is published
**inside the application** only when selecting
``execution.messaging``.

Why distinguish events from jobs?
---------------------------------

An **event** describes something that happened.
A **job/message** requests an operation to be
processed by a registered handler.

An ordinary synchronous event listener executes
as part of its invoking operation. A queued
message is subject to a configured Omnibus
transport, codec, worker, retry and failure
policy. Do not label a synchronous dispatch
as durable or asynchronous automatically.

Step 1 — enable the feature
---------------------------

From the Infbyte application project root:

.. code-block:: bash

   php infbyte module:plan execution.messaging
   php infbyte module:install execution.messaging
   php infbyte module:enable execution.messaging
   php infbyte module:doctor execution.messaging

Inspect the published application file
``my-app/config/messaging.php``. The package
is optional and installation does not
implicitly activate all messaging capabilities.

Step 2 — generate a job and handler
------------------------------------

A compatible Foundation 4 app offers starting
generators:

.. code-block:: bash

   php infbyte create:job GenerateReport
   php infbyte create:handler GenerateReport
   php infbyte create:job-middleware AuditJob

These create application-owned classes in the
paths reported by the generator. Do not assume
Foundation scans arbitrary ``app/Jobs/`` or
``app/Messaging/`` directories at boot:
**registration is explicit**.

Step 3 — register the selected handler
--------------------------------------

In your application's ``config/messaging.php``,
the relevant mapping is structured like this:

.. code-block:: php

   'handlers' => [
       App\Jobs\GenerateReportJob::class =>
           App\Messaging\Handlers\GenerateReportHandler::class,
   ],

This is an **illustrative fragment**, not the
complete configuration. Both application classes
must exist and implement the selected native
Foundation/Omnibus contracts. Handler type
and constructor signatures should be taken
from the generator and owning package.

The message route uses an explicit transport,
queue name and delay:

.. code-block:: php

   'routes' => [
       App\Jobs\GenerateReportJob::class => [
           'transport' => 'sync',
           'queue' => 'reports',
           'delay_seconds' => 0.0,
       ],
   ],

The ``sync`` transport executes the selected
handler immediately. It does **not** survive
a process crash as a queued message.
The ``reports`` queue here is an application
example.

Step 4 — dispatch using the native bus
--------------------------------------

In an application service with a configured
Foundation ``Application $app``:

.. code-block:: php

   use Infocyph\Omnibus\MessageBus;

   $bus = $app->make(MessageBus::class);
   $bus->dispatch(new App\Jobs\GenerateReportJob($reportId));

``App\Jobs\GenerateReportJob`` is a
**developer-created** message class and the
``$reportId`` comes from authorized business
logic. Avoid storing unbounded blobs, open
database connections, request objects or
secrets in message payloads.

Omnibus owns dispatch/handler execution.
Foundation owns the selected application
graph and execution cleanup.

Step 5 — register application events
-------------------------------------

The ``listeners`` section in the published
``config/messaging.php`` maps an event class
to ordered invokable listener service classes.

When an application has registered the native
event service, dispatch is through Omnibus:

.. code-block:: php

   use Infocyph\Omnibus\Event\EventDispatcher;

   $events = $app->make(EventDispatcher::class);
   $events->dispatch(new App\Events\OrderPaidEvent($orderId));

Again, the event class and its listeners are
application-defined, not shipped by Infbyte.

An event listener implementing native
Omnibus ``ShouldQueue`` follows the message
bus; an ordinary listener runs synchronously.
Do not expect general message middleware to
wrap a synchronous listener automatically.

Step 6 — handler and job middleware
------------------------------------

``handler_middleware`` is Omnibus-owned and
surrounds general native message handlers.

``job_middleware`` is Foundation's application
adapter for messages implementing
``Infocyph\Foundation\Messaging\Job``.
It exposes Foundation ``JobContext`` rather
than raw Omnibus envelopes.

Choose the relevant contract; adding a class
to ``job_middleware`` does not make every
generic message an Infbyte job.

For persistent/forked hosts, prefer declarative
class-name bindings in the application
configuration. A captured closure containing
a previous request's principal is unsafe.

Step 7 — send work asynchronously
----------------------------------

To make a job durable, you must choose and
configure a real transport and explicit
serialization codecs; ``sync`` and
process-local ``memory`` do not provide
restart-safe queue semantics.

For an Omnibus SQL-backed transport,
also select ``data.database``, provision
the applicable native schema, and configure
a durable failure store. Ensure a separate
worker process is supervised.

A queued job may be retried after partial
completion, so effects must have an
idempotency strategy. Don't rely on the
queue visibility period as an exactly-once
transaction guarantee.

See :doc:`queues-workers` and
:doc:`../tutorials/durable-queue`.

Step 8 — test and troubleshoot
------------------------------

Use the selected native
``TestKit::fakeMessaging()`` recording
sender for isolated dispatch assertions.
Then test the real selected backend's
receive/ack/retry semantics independently.

Minimum behavioral coverage:

* Unknown handler or invalid route fails visibly.
* Synchronous handler receives a valid message.
* Queued message survives an actual worker restart.
* Handler failure records appropriate retry/failure state.
* Duplicate delivery cannot duplicate an irreversible effect.
* Per-message scope releases authorization/DB state.
* A stale or cancelled worker stops claiming new work.

Inspect and trace failures using the
application's configured worker and
``queue:failed`` CLI commands. Don't
disable retries or error handling merely
to make a test pass.

Read the native Omnibus documentation for
codecs, transport-specific retry and
handler contracts:

https://github.com/infocyph/Omnibus

For the Infbyte application integration, see :doc:`queues-workers`
(in addition to the Omnibus native manual).
