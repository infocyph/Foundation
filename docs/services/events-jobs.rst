.. _events-and-jobs:

Events, jobs and handlers
=========================

Infbyte can compose the optional **Omnibus** messaging engine for
events and jobs. Omnibus owns dispatch, handler execution, middleware,
retries and serialization. Foundation owns application registration,
selected capability policy and execution-scope cleanup.

Install the messaging feature
-----------------------------

.. code-block:: bash

   php infbyte module:plan execution.messaging
   php infbyte module:install execution.messaging
   php infbyte create:job GenerateReport
   php infbyte create:handler GenerateReport

A generated class is not auto-discovered. Register the message-to-handler
mapping explicitly in ``config/messaging.php``.

Dispatch through native services
--------------------------------

From an application service with access to the Foundation runtime:

.. code-block:: php

   use Infocyph\Omnibus\Event\EventDispatcher;
   use Infocyph\Omnibus\MessageBus;

   $bus = $app->make(MessageBus::class);
   $events = $app->make(EventDispatcher::class);

   $bus->dispatch(new App\Jobs\GenerateReportJob($id));
   $events->dispatch(new App\Events\OrderPaidEvent($orderId));

The ``App\Jobs\GenerateReportJob`` and ``App\Events\OrderPaidEvent``
classes are **application-defined examples**, not classes shipped by Foundation.
They require matching definitions and handlers in the application.

Handler middleware and scope
----------------------------

Omnibus handler middleware applies to general Omnibus handlers.
Foundation's ``JobMiddleware`` adapter applies when a message
implements Foundation's ``Job`` contract. Work is executed in
an appropriate per-message Foundation scope, so request/job state
is cleaned before another message.

For message types, retries, events and transport details, see the
`Omnibus library <https://github.com/infocyph/Omnibus>`_
and `Foundation messaging guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/messaging.md>`_.

Continue with :doc:`queues-workers`.
