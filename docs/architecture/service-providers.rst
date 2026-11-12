.. _service-providers:

Service providers and dependency injection
==========================================

Providers register application services with the native InterMix builder.
Foundation 4 supplies an application-facing
``ServiceProviderInterface`` and a ``ServiceProvider`` base class.

The following example is grounded in the **current Foundation 4** provider
contract. It requires a compatible Infbyte 4 application for an end-to-end
runtime test.

Define an application service
-----------------------------

Place this example under
``app/Providers/ClockProvider.php`` in a compatible application.

.. literalinclude:: ../examples/infbyte-clock-provider.php.txt
   :language: php
   :linenos:

The example contributes a stable value through InterMix
``ContainerBuilder::value()``. It is syntax-checked with PHP 8.4
in the documentation CI job. Runtime resolution remains unverified
against the unpublished Infbyte 4 skeleton.

Register by runtime
-------------------

In ``bootstrap/providers.php``, include the provider class in the
appropriate group. The grouping mechanism is real and required:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use App\Providers\ClockProvider;

   return [
       'common' => [ClockProvider::class],
       'web' => [],
       'cli' => [],
       'worker' => [],
       'scheduler' => [],
   ];

For any runtime, Foundation includes ``common`` and that runtime's
group. A web-only provider belongs in ``web`` instead. Creating a class
on disk does not register it.

Composition versus boot
-----------------------

``contribute(ContainerBuilder $builder, FoundationBuildContext $context)``
adds definitions **before** finalization; this is where
``value()``, ``autowire()``, and other InterMix definition operations
belong.

``boot(Application $app)`` is a later hook for process initialization
of services that have already been defined. It must **not** mutate the
immutable runtime graph. Foundation's ``ServiceProvider`` base
implements a no-op ``boot()`` by default.

Lifetimes and production performance
------------------------------------

Use process-wide singletons only for immutable or process-safe services.
Request-local, job-local and command-local data must not be captured by
singletons. Do not eagerly open database or network connections merely
because a provider is present.

Production compilation finalizes native InterMix containers. There is no
second mutable Foundation dependency-injection manager.

Read the `InterMix DI guide
<https://github.com/infocyph/InterMix/tree/main/docs/di>`_
for scopes, lifetimes, compiled resolvers and production load.

See also :doc:`application-lifecycle` and
:doc:`../start/configuration`.
