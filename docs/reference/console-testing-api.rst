.. _console-testing-api-reference:

Console, scheduling and testing API reference
=============================================

Infbyte's CLI and test helpers are built on Foundation 4 contracts,
not on a Laravel Artisan interface. Registration and runtime selection
remain explicit. This reference lists inspected public methods; the
currently published Infbyte 3 starter has not yet been integrated
against all Foundation 4 entrypoints.

Command handlers
----------------

An application command implements
``Infocyph\Foundation\Command\CommandHandlerInterface``:

.. code-block:: php

   <?php

   declare(strict_types=1);

   namespace App\Command;

   use Infocyph\Foundation\Command\CommandContext;
   use Infocyph\Foundation\Command\CommandDefinition;
   use Infocyph\Foundation\Command\CommandHandlerInterface;

   final class HelloCommand implements CommandHandlerInterface
   {
       public static function define(CommandDefinition $command): void
       {
           $command->name('hello')->description('Example command');
       }

       public function run(CommandContext $context): int
       {
           return 0;
       }
   }

This is a complete **interface implementation**, not a registered CLI
command. Add its class explicitly to ``routes/console.php`` in a
compatible application. Returning ``0`` signals success; use a
non-zero exit code for a controlled failure.

``CommandDefinition`` owns metadata and execution policy:
``name()``, ``description()``, ``group()``,
``alias()``, ``argument()``, ``option()``,
``runtime()``, ``scope()``, ``execution()``,
``capability()`` and metadata inspection methods.

``CommandContext`` provides:

* ``input(): ParsedInput``
* ``io(): CommandIO``
* ``executionId(): ExecutionId``
* ``definition(): CommandDefinition``
* ``descriptor(): CommandDescriptor``

``ParsedInput`` offers
``argument(int $index, ?string $default = null): ?string``,
``option(string $name, ?string $default = null): ?string``,
``flag(string $name): bool``,
``values(string $name): array`` and
``hasOption(string $name): bool``.

Prefer these typed contracts over directly reading ``$argv``.
Global CLI controls and command metadata may be inspected without
booting a web application.

Scheduler
---------

``Infocyph\Foundation\Scheduling\Schedule`` has:

* ``command(string $command): ScheduledCommand`` — add a command
  to the schedule, returning its configuration builder.
* ``add(ScheduledCommand $command): self`` — add a prepared entry.
* ``entries(): array`` — inspect registered entries.

``ScheduledCommand`` exposes these checked scheduling methods:

.. list-table::
   :header-rows: 1
   :widths: 52 48

   * - Method
     - Purpose
   * - ``everyMinute()``, ``hourly()``, ``dailyAt(string $time)``
     - Common recurrence definitions
   * - ``cron(string $expression)``, ``timezone(string|DateTimeZone $timezone)``
     - Native cron and timezone
   * - ``key(string $key)``
     - Stable unique schedule identity
   * - ``onOneServer(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0)``
     - Cross-scheduler ownership with configured CacheLayer coordination
   * - ``withoutOverlap(bool $enabled = true, float $leaseSeconds = 300.0, float $waitSeconds = 0.0)``
     - Prevent overlapping runs of one entry
   * - ``arguments(array $arguments)``
     - Arguments supplied to the command
   * - ``timeout(float $seconds)``, ``memoryLimit(int $megabytes)``
     - Bounded execution policy

``onOneServer()`` and ``withoutOverlap()`` do not
magically create a shared lock service. They require an actual
selected coordination backend appropriate to the deployment.

Testing: TestKit
----------------

In a configured Foundation web test application:
``$testing = $app->make(TestKit::class)``.

``Infocyph\Foundation\Testing\TestKit`` provides:

.. list-table::
   :header-rows: 1
   :widths: 47 53

   * - Method
     - Result/use
   * - ``http(): HttpTestClient``
     - Native in-process Webrick HTTP test client
   * - ``auth(): AuthServices``
     - Configured authentication test access
   * - ``database(): DatabaseTestManager``
     - DBLayer transactional/fixture testing helper
   * - ``cache(): CacheManager``
     - Selected native CacheLayer access
   * - ``fakeCache(?CacheInterface $store = null, ?string $name = null)``
     - Override with native CacheLayer fake store
   * - ``fakeHttp(?FakeHttpTransport $transport = null)``
     - Fake native TalkingBytes client
   * - ``fakeMessaging(): RecordingSender``
     - Recording Omnibus sender
   * - ``fakeNotifications(): Emailer``
     - Fake native TalkingBytes email transport
   * - ``freezeTime(?int $timestamp = null): FrozenClock``
     - Replace selected Foundation application clock
   * - ``files(): FilesystemOperator``
     - Selected filesystem testing integration
   * - ``messaging(): MessageBus``
     - Native Omnibus bus
   * - ``sessions(): SessionManager``
     - Configured authentication-session manager

Optional helpers require their selected feature and compatible
backend. Fakes test *your code's interaction* with a selected
dependency; they do not certify a real broker or SMTP response.

HTTP TestKit methods
--------------------

The checked ``HttpTestClient`` API includes
``get()``, ``post()``, ``put()``,
``patch()``, ``delete()``, ``request()``,
and ``withHeaders()``.

A ``TestResponse`` supports
``assertStatus()``, ``assertHeader()``,
``assertJson()``, ``assertJsonPath()``,
``body()``, ``json()``, ``response()``
and ``status()``.

A contextual HTTP example:

.. code-block:: php

   $testing = $app->make(\Infocyph\Foundation\Testing\TestKit::class);

   $response = $testing->http()->get('/api/health');
   $response->assertStatus(200);

The route must exist in the selected application fixture. The
current Infbyte 3 skeleton supplies a health route, but that does
not prove Foundation 4 consumer integration.

Source anchors
--------------

* `CommandHandlerInterface
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Command/CommandHandlerInterface.php>`_
* `Schedule
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Scheduling/Schedule.php>`_
* `TestKit
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Testing/TestKit.php>`_
* `TestResponse
  <https://github.com/infocyph/Foundation/blob/feature/foundation-3/src/Testing/TestResponse.php>`_

See :doc:`../console/commands`, :doc:`../console/scheduling`,
:doc:`../testing/http`, and :doc:`../tutorials/test-an-application`.
