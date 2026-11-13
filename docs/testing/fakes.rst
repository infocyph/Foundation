.. _testing-fakes:

Using native fakes in an Infbyte test
=====================================

A fake records or replaces an **external dependency** so a
test can focus on your application's decisions. Infbyte's
``TestKit`` composes fakes from CacheLayer,
TalkingBytes and Omnibus; it is not a second cache,
mail or queue implementation.

Create and configure the **Infbyte application** under test.
Use fresh application instances when an overridden service
must not affect the next case.

The actual TestKit API
----------------------

``Infocyph\Foundation\Testing\TestKit`` supplies:

.. list-table::
   :header-rows: 1
   :widths: 37 33 30

   * - Method
     - Native object
     - Selected integration
   * - ``fakeCache()``
     - CacheLayer CacheInterface
     - Foundation Core
   * - ``fakeHttp()``
     - TalkingBytes HttpClient
     - ``execution.communication`` when used by app
   * - ``fakeMessaging()``
     - Omnibus RecordingSender
     - ``execution.messaging``
   * - ``fakeNotifications()``
     - TalkingBytes Emailer
     - Native email integration when selected
   * - ``freezeTime()``
     - Foundation FrozenClock
     - Core selected clock
   * - ``database()``
     - DatabaseTestManager
     - ``data.database``
   * - ``files()``
     - Flysystem FilesystemOperator
     - ``data.filesystem``
   * - ``auth()``
     - AuthServices
     - Selected Core auth configuration

These methods use the **actual application service graph**.
Optional helpers cannot create a working native backend
where the selected package/feature is absent.

A quick cache example
---------------------

From a booted application fixture:

.. code-block:: php

   use Infocyph\Foundation\Testing\TestKit;

   $testing = $app->make(TestKit::class);

   $cache = $testing->fakeCache();
   $cache->set('report:status', 'ready', 120);

   $value = $cache->get('report:status');

``fakeCache()`` replaces the selected CacheLayer store
with a native in-memory implementation. You can supply
your own compatible ``CacheInterface`` and name to
``fakeCache($store, $name)`` for a specific configured
store.

An in-memory fake is useful for verifying your
business logic. It cannot prove distributed locking,
durable replay markers or Redis/Valkey failure behavior.

Stub external HTTP safely
-------------------------

When ``execution.communication`` is selected,
TestKit exposes:

.. code-block:: php

   $fakeClient = $testing->fakeHttp();

The return type is native
``Infocyph\TalkingBytes\Http\HttpClient``.
The helper replaces the selected
``HttpClient::class`` service with a fake
client built from a TalkingBytes
``FakeHttpTransport``.

For a deterministic response, configure the native
transport using TalkingBytes' actual fake API before
triggering your application service. Do not invent
a Foundation ``Http::fake()`` facade.

Verify URL, method, headers and body construction
without contacting the real upstream service.
Be especially careful not to print tokens,
cookies or authentication headers when asserting
an HTTP request.

A fake transport does **not** verify TLS peer identity,
certificate validity, redirects, DNS pinning,
response size or upstream availability. A separate
sandbox integration test should cover those properties.

Record Omnibus messages
-----------------------

When ``execution.messaging`` is selected:

.. code-block:: php

   $recording = $testing->fakeMessaging();

This replaces the configured
``TransportRegistry``, ``MessageBus`` and
event dispatcher bindings for the testing
application. Routing still uses the actual
selected application message map.

Dispatch a developer-owned message:

.. code-block:: php

   use Infocyph\Omnibus\MessageBus;

   $bus = $testing->messaging();
   $envelope = $bus->dispatch($applicationMessage);

``$applicationMessage`` is a message object defined
by your application and registered in its
``config/messaging.php``. The sample is a contextual
snippet, not a complete queue setup.

The ``RecordingSender`` records attempted sends.
It does not prove that a real Redis broker or SQL
queue persisted, reserved, acknowledged or retried
a message. Test the native transport and failure
store separately for those behaviors.

For every redelivery-capable job, test idempotency:
a handler can finish a database write and crash
before the transport acknowledges it.

Fake mail without sending it
----------------------------

To replace the selected native email transport:

.. code-block:: php

   $emailer = $testing->fakeNotifications();

This is a native TalkingBytes ``Emailer::fake()``.
Use the application's
``config/notifications.php`` sender policy and
the native email fake assertions to verify
subject, recipient, sender and body construction.

The Core-only Infbyte project ships
``config/notifications.php`` but its default
sender uses a ``null`` transport. A null
transport and a fake both avoid actual delivery;
neither verifies SMTP or a recipient inbox.

Real acceptance tests should separately verify
SMTP credentials, STARTTLS, sender permission,
bounce/retry handling, and that security-sensitive
notifications reach a controlled recipient.

Control the application clock
-----------------------------

A time-sensitive flow can replace Foundation's
selected clock:

.. code-block:: php

   $clock = $testing->freezeTime(1_800_000_000);

This overrides Foundation's own
``ClockInterface`` binding for the current test.
It does not universally intercept PHP ``time()``
or every native library's clock.

When testing OTP replay or session expiry,
make sure the selected mechanism uses the
frozen clock (or its native test clock) and
actually advances the intended time state.
A fake clock is no proof of atomic cross-host
replay consumption.

No accidental production fakes
------------------------------

Fakes are **testing overrides**, not a deployment
configuration. Never call
``Application::replaceForTesting()`` from
ordinary production routes to avoid a failing
database, queue or mail server.

The fake's lifetime can outlive a single assertion
within the same application fixture. Use a new
application fixture or explicitly reset the
override between logically independent tests.

This matters especially for persistent
Runwire workers: a leftover fake HTTP client,
principal or in-memory recording sender should
not leak into an unrelated request/job.

Plan your integration test pyramid
----------------------------------

Use a native fake to verify what your **application
attempted** to do:

* Fake HTTP: request shape, URL selection and headers.
* Fake mail: template, sender/recipient and delivery policy.
* Recording Omnibus: message type, routing and dispatch attempts.
* In-memory CacheLayer: key/TTL/invalidation decisions.
* Frozen time: time-dependent branching in selected app services.

Then test real dependencies where correctness
depends on transport properties:

* DBLayer transactional commit and rollback.
* CacheLayer shared lock ownership and lease loss.
* Omnibus reservation, ack, retry and failure storage.
* TalkingBytes real TLS, SMTP and webhook signature validation.
* Pathwise physical file containment, uploads and cleanup.
* Runwire persistent-host scope cleanup and cancellation.

Do not disable a production security guard because
a fake cannot reproduce it. Keep both test layers.

Troubleshooting
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Problem
     - Reason
     - Action
   * - Fake HTTP helper unavailable
     - Selected TalkingBytes dependency absent
     - Enable/verify ``execution.communication``
   * - Fake message bus rejects message
     - Handler/route missing in actual app config
     - Inspect ``config/messaging.php``
   * - Test sees previous case's response
     - Reused fake or application override
     - New isolated fixture or explicit reset
   * - Local lock test passes, multi-host fails
     - Process-local memory implementation
     - Test real shared lock backend and lease
   * - Email test passes, user gets no mail
     - Fake/null transport used in delivery acceptance
     - Test actual configured SMTP sender
   * - Time-dependent test still uses wall clock
     - Native library bypasses Foundation clock
     - Select its supported native test clock

For a complete application test fixture, see
:doc:`overview`, :doc:`http`,
:doc:`database` and
:doc:`persistent-runtimes`.
