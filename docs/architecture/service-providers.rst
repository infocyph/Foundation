.. _service-providers:

Application service providers and dependency injection
======================================================

An Infbyte application uses **InterMix** to build its native dependency
injection graph. A provider declares the services available to a selected
runtime, then may perform limited process-level initialization after the
graph is finalized. Foundation owns the application lifecycle and public
``ServiceProviderInterface``; InterMix owns registration, resolution,
scope and compiled-container behavior.

This guide starts with files inside **your Infbyte 4 application**
on ``feature/infbyte-4``. That branch is an unpublished
development candidate, not the public Foundation 3 starter.

Why an application provider is useful
-------------------------------------

Use a provider when an application needs to register a reusable
service or choose a runtime-specific implementation. Typical
examples include a stateless business service, a named
configuration value, a transport adapter or an optional integration.

Do not create providers for every controller merely because
a generator makes it possible. Prefer the simplest native
InterMix registration model that matches the service's
lifetime and actual dependencies.

The provider class belongs in an application-owned
directory such as ``my-app/app/Providers/``; it is
**not** under Foundation's ``src/Application/``.

Start from the actual Infbyte bootstrap
---------------------------------------

The Infbyte 4 candidate ships
**``my-app/bootstrap/providers.php``**:

.. code-block:: php

   <?php

   declare(strict_types=1);

   return [
       'common' => [],
       'web' => [],
       'cli' => [],
       'worker' => [],
       'scheduler' => [],
   ];

This is a streamlined representation of the actual starter's
empty group lists; its file contains commented class examples.
Every runtime includes ``common`` plus the selected
runtime's group.

.. list-table::
   :header-rows: 1
   :widths: 24 40 36

   * - Provider group
     - Loaded for
     - Example use
   * - ``common``
     - Every selected application graph
     - Immutable app-wide services/config values
   * - ``web``
     - Web HTTP graph only
     - Route-specific authorization/HTTP adapters
   * - ``cli``
     - Command execution graph only
     - Operational command dependencies
   * - ``worker``
     - Background/Omnibus execution graph
     - Job handlers or selected worker components
   * - ``scheduler``
     - Scheduling graph
     - Schedule-time services

A web request should not pay to construct an unrelated
queue receiver. A CLI command should not need a
browser session by default. A process singleton
that stores a request principal can leak user state
across persistent Runwire executions; that is
a lifetime bug, not a convenient cache.

Create a simple provider
------------------------

Create ``my-app/app/Providers/ClockProvider.php``.
This is a complete example, grounded in the native
Foundation/InterMix provider contract:

.. literalinclude:: ../examples/infbyte-clock-provider.php.txt
   :language: php
   :linenos:

``ClockProvider`` reads the configured
``app.timezone`` value if present, otherwise
defaults to UTC, then registers it under
``app.timezone`` using InterMix
``ContainerBuilder::value()``.

The candidate's default ``config/app.php`` does
**not** include an ``app.timezone`` field by
default. Add it to your application's config only
if the application actually needs it.

The example is PHP 8.4 syntax checked by
documentation CI. Its end-to-end behavior must
still be verified in the selected application fixture.

Register the provider explicitly
--------------------------------

Now edit **``my-app/bootstrap/providers.php``**
and add the class to the ``common`` group:

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

Creating a class file **does not** automatically
register it. After changing service composition,
rebuild the chosen application graph and
production generation when appropriate.

Register only in ``web`` when the provider is
specific to HTTP requests; use ``worker``
when only message delivery needs it. Always
account for the fact that ``common`` is included
in each runtime graph.

Read the value from an application
----------------------------------

From a booted compatible Infbyte application:

.. code-block:: php

   $timezone = $app->make('app.timezone');

   if (!is_string($timezone)) {
       throw new \LogicException(
           'The configured timezone must be a string.',
       );
   }

``Application::make(string $id): mixed`` resolves
the native InterMix service from the selected
graph. That lookup requires the provider to
have contributed the definition during build.

Using a service ID in more than one runtime
requires the service to be registered in each
relevant graph, usually through the
``common`` group if it is process-safe.

Understand the provider lifecycle
---------------------------------

The exact public contract is:

.. code-block:: php

   public function contribute(
       ContainerBuilder $builder,
       FoundationBuildContext $context,
   ): void;

   public function boot(Application $app): void;

The above is a **signature excerpt**, not
a standalone class. You may extend the
provided ``ServiceProvider`` base class to
inherit its no-op ``boot()`` method, as the
``ClockProvider`` example does.

``contribute()`` executes during composition,
before the runtime graph has been sealed.
It is where ``value()``, ``factory()``,
``autowire()`` and other native InterMix
definitions belong.

``boot()`` runs after composition for
process-level initialization using **already
registered services**. Never mutate service
definitions in ``boot()`` or try to rewire a
compiled graph from a request handler.

Do not perform unbounded network calls or a
database migration inside every provider boot.
A provider that eagerly dials an optional
backend makes unrelated Core-only routes
unnecessarily slow and fragile.

Know the scope of a dependency
------------------------------

.. list-table::
   :header-rows: 1
   :widths: 26 35 39

   * - Service state
     - Typical lifetime
     - Important constraint
   * - Immutable settings/value
     - Process reusable
     - Safe to share if it cannot mutate user state
   * - Current HTTP request/principal
     - Request scope
     - Must not survive another request
   * - DBLayer transaction/connection
     - Selected execution scope
     - Clean before pool reuse
   * - Auth challenge or browser session
     - Request/execution scope
     - Must not become a global singleton
   * - Message handler context
     - Job scope
     - Clear on acknowledgment/retry/failure
   * - Native HTTP client with mutable cookies
     - Execution-scoped where configured
     - Do not reuse across unrelated accounts

InterMix owns the actual scope/lifetime definitions.
Foundation's built-in DBLayer connection, for example,
is registered with an execution-scoped lifetime.
Do not override that selection to a process singleton
merely to reduce object construction overhead.

An immutable client factory may be reusable while
the stateful client it builds is not. Optimize
after profiling rather than choosing a global
lifetime for every service.

Optional native integrations
----------------------------

An application provider should integrate the
package that actually owns the mechanism:

* Webrick: route, HTTP middleware and response.
* DBLayer: native connections and transaction state.
* CacheLayer: named stores and atomic coordination.
* TalkingBytes: HTTP, email and webhook transports.
* Omnibus: messages, handlers, transports and consumers.
* Pathwise: filesystem operator and safe transfer.

Only enable the corresponding selected feature in
the Infbyte project's ``config/modules.php``
when needed. Merely registering a service ID
does not install a compatible native package or
make its backend available.

How to test a provider
----------------------

In a test application fixture, verify:

1. The provider is present only in the intended runtime groups.
2. ``Application::make('app.timezone')`` resolves when registered.
3. A different runtime does not accidentally load a web-only provider.
4. A missing/invalid dependency fails predictably.
5. A persistent worker creates fresh state for execution-scoped services.
6. The production compiled resolver matches source-level definitions.

A source-level class that passes PHP syntax
checks does not prove a compiled InterMix
resolver can instantiate it after a
dependency upgrade.

For an integration test, construct the
**Infbyte** application with its real
``base_path``, not Foundation's package root.
Run it against the exact Composer dependency
SHA and selected runtime mode.

Production compilation and troubleshooting
------------------------------------------

At build time, Foundation records service
composition in the trusted generation.
Production uses immutable InterMix artifacts:

.. code-block:: bash

   php infbyte config:validate --production
   php infbyte optimize
   php infbyte optimize:report

Editing ``bootstrap/providers.php`` after
this generation is activated does not
update its compiled service graph.

.. list-table::
   :header-rows: 1
   :widths: 28 34 38

   * - Problem
     - Likely cause
     - Check
   * - Service ID is unknown
     - Provider not registered in the runtime group
     - ``bootstrap/providers.php`` and active graph
   * - CLI cannot resolve web-only service
     - Group deliberately excludes it
     - Place only shared services in ``common``
   * - New provider works locally but not after deploy
     - Old compiled generation still active
     - Trusted release SHA and rebuild
   * - Unexpected database connection on health route
     - Eager provider or global middleware
     - Dependency graph and lazy resolution
   * - Principal leaks across worker jobs
     - Incorrect process-level singleton lifetime
     - InterMix execution scope and cleanup
   * - Provider attempts registrations in boot
     - Graph already finalized
     - Move definitions to ``contribute()``

Next steps
----------

Continue with :doc:`application-lifecycle`,
:doc:`../start/directory-structure`,
:doc:`../advanced/runtime-modes` and
:doc:`../testing/persistent-runtimes`.
For InterMix container syntax, scopes, factories
and compiled resolvers, see the native manual:
https://github.com/infocyph/InterMix/tree/main/docs/di
