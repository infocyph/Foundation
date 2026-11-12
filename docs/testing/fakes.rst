.. _testing-fakes:

Using test doubles and native fakes
===================================

Foundation's ``TestKit`` offers helpers that compose the **owning
package's** fakes. They are intended to make your application tests
deterministic without making Foundation implement a second
cache/messaging/email/HTTP engine.

Fake outbound HTTP
------------------

When ``execution.communication`` is installed and configured:

.. code-block:: php

   $http = $testing->fakeHttp();

This uses a native TalkingBytes fake ``HttpClient`` and replaces the
client service for the current testing application. Configure its
fake transport according to TalkingBytes' documented testing API,
then assert your application's request behavior. Do not assume
the fake has contacted the internet.

Fake message delivery
---------------------

For a configured ``execution.messaging`` application:

.. code-block:: php

   $recording = $testing->fakeMessaging();

Foundation creates an Omnibus ``RecordingSender``, replaces
the transport registry/message bus and forwards configured routing
through the fake. Its recorded messages represent **dispatch
attempts**, not successful delivery to Redis, a database or a
downstream consumer.

To validate acknowledgment, retry, failure store, and replay,
use an integration test against the actual Omnibus transport.

Notifications and caching
-------------------------

.. code-block:: php

   $email = $testing->fakeNotifications();
   $cache = $testing->fakeCache();

The notification helper uses native TalkingBytes
``Emailer::fake()``; the cache helper selects a CacheLayer
memory store. Both are useful for business behavior tests, but
process-local test state is **not** a substitute for durable
authentication replay or session locking in production.

``fakeCache()`` can receive a native ``CacheInterface`` store
and a store name if a test needs an explicitly chosen fake.

Freeze application time
-----------------------

.. code-block:: php

   $clock = $testing->freezeTime(1_800_000_000);

This replaces Foundation's application ``ClockInterface``
binding with a ``FrozenClock``. Code using a different native
clock or direct ``time()`` calls may not observe the replacement.
Verify the actual time source when writing deadline/replay tests.

Important isolation boundaries
------------------------------

Fakes use application-level testing overrides. Create a fresh
test application or clean/reset overrides between tests that
require isolation; do not reuse a mutated application instance
across logically independent tests without an explicit fixture
cleanup policy.

Test both paths:
a unit/integration test with a fake to establish expected business
behavior, and a focused native transport test to establish correct
adapter/wire behavior.

For actual fake APIs and assertions, consult
`TalkingBytes <https://github.com/infocyph/TalkingBytes>`_,
`Omnibus <https://github.com/infocyph/Omnibus>`_,
and `CacheLayer <https://github.com/infocyph/CacheLayer>`_.

Next: :doc:`database` and :doc:`persistent-runtimes`.
