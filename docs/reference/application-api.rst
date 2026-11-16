.. _application-api-reference:

Application and provider API reference
======================================

The **Foundation 4 development** public API creates explicit application
graphs for Infbyte. This reference summarizes the signatures checked in
the Foundation source; it is *not* an Infbyte 4 consumer integration test.

Use this when writing an application bootstrap, a service provider, or an
application service needing a runtime-bound dependency. Do not invent
global facades or automatically share an HTTP graph with a worker.

Runtime factory
---------------

The public factory is ``Infocyph\Foundation\Foundation``.

.. list-table::
   :header-rows: 1
   :widths: 34 32 34

   * - Method
     - Return
     - Meaning
   * - ``web(array $config = [])``
     - ``Application``
     - Webrick HTTP graph, web-only adapters
   * - ``cli(array $config = [])``
     - ``Application``
     - Command graph; no implicit web stack
   * - ``worker(array $config = [])``
     - ``Application``
     - Worker execution graph
   * - ``scheduler(array $config = [])``
     - ``Application``
     - Scheduled execution graph
   * - ``preset(RuntimeMode $runtime, FoundationPreset $preset, array $config = [])``
     - ``Application``
     - Apply a selected preset to the requested runtime

For example, from a development/build bootstrap in a **compatible**
application:

.. code-block:: php

   <?php

   declare(strict_types=1);

   use Infocyph\Foundation\Foundation;

   $app = Foundation::web(['base_path' => dirname(__DIR__)]);
   $app->boot();

Choose the matching actual runtime entrypoint rather than creating
all four graphs per request. In production, use the trusted
``FoundationReleaseBootstrap`` path rather than replaying
mutable source composition.

Application
-----------

``Infocyph\Foundation\Application\Application`` is the narrow
application-runtime object. Its checked public methods include:

.. list-table::
   :header-rows: 1
   :widths: 35 65

   * - Method
     - Purpose
   * - ``create(array $config, RuntimeMode $runtimeMode): self``
     - Source-level factory behind the public runtime facade
   * - ``boot(): self`` / ``booted(): bool``
     - Complete/inspect application boot
   * - ``make(string $id): mixed``
     - Resolve a service from the current native container
   * - ``has(string $id): bool``
     - Test whether an identifier is registered
   * - ``config(): ConfigRepository``
     - Read the assembled application configuration
   * - ``providers(): ServiceRegistry``
     - Access registered provider topology
   * - ``execution(): ExecutionScope``
     - Run work under the appropriate execution boundary
   * - ``runtimeMode(): RuntimeMode``
     - Get the explicitly selected runtime
   * - ``runningInWeb()/runningInCli()/runningInWorker()/runningInScheduler()``
     - Runtime-mode checks; no SAPI guessing
   * - ``http(): HttpKernel`` / ``handle(Request $request): Response``
     - Web HTTP execution; requires a web graph
   * - ``container(): Container`` / ``runtime(): ContainerInterface``
     - Access Foundation's composed/native container
   * - ``replaceForTesting(string $id, object $service): void``
     - Testing override, **not** runtime reconfiguration
   * - ``basePath(string $path = '')`` and directory-specific path helpers
     - Resolve application-owned locations

Path helpers include ``appPath()``, ``bootstrapPath()``,
``cachePath()``, ``configPath()``, ``databasePath()``,
``logsPath()``, ``publicPath()``, ``resourcesPath()``,
``routesPath()``, ``sessionsPath()``, ``storagePath()``
and ``uploadsPath()``.

Do **not** treat ``has()`` as proof that a selected remote
database, queue, mail server or session store is operational.
Runtime configuration and actual backend readiness are separate.

Service provider contracts
--------------------------

Application provider classes implement
``Infocyph\Foundation\Application\ServiceProviderInterface``.
The contract has exactly two methods:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Providers;

   use Infocyph\Foundation\Application\Application;
   use Infocyph\Foundation\Application\FoundationBuildContext;
   use Infocyph\Foundation\Application\ServiceProviderInterface;
   use Infocyph\InterMix\DI\ContainerBuilder;

   final class ExampleProvider implements ServiceProviderInterface
   {
       public function contribute(
           ContainerBuilder $builder,
           FoundationBuildContext $context,
       ): void {
           $builder->value('example.label', 'example');
       }

       public function boot(Application $app): void
       {
           // Process initialization, after graph composition.
       }
   }

The ``ServiceProvider`` abstract base supplies an empty
``boot()`` if an application only needs ``contribute()``.
Provider definitions are sealed before boot. Boot-time mutation of
service registrations is prohibited by the compiled graph contract.

In a compatible skeleton, ``bootstrap/providers.php`` groups
provider classes under ``common``, ``web``, ``cli``,
``worker`` and ``scheduler``. Foundation includes
``common`` plus only the selected runtime group.

Lifetimes and performance
-------------------------

Define immutable process-safe services as reusable where appropriate;
make mutable user/request/job resources scoped. The native InterMix
container owns its resolution semantics and production compilation.
A provider should not eagerly start mail clients, SQL queries or
queue consumers for an unrelated route.

Source anchors
--------------

* `Foundation runtime factory
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Foundation.php>`_
* `Application public methods
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Application/Application.php>`_
* `Provider interface
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Application/ServiceProviderInterface.php>`_

See :doc:`../architecture/service-providers` and
:doc:`../architecture/application-lifecycle`.
