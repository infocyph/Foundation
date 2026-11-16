.. _cache-guide:

Caching and coordination in Infbyte
===================================

Infbyte uses **CacheLayer** for application data caching, named
stores, locks, atomic counters and invalidation. CacheLayer
is a **Foundation Core dependency**; there is no optional
``data.cache`` module to install.

For Infbyte developers, the editable settings live in
**``my-app/config/cache.php``**. Foundation's internal template
source is separate and must not be edited to configure an app.

When caching helps
------------------

Caching is useful when an operation reads an expensive,
reusable value whose freshness requirements are explicit.
Examples include bounded catalog lookups, derived reports
and stable application metadata.

Not every operation should be cached. Authentication
replay protection, financial state transitions and
distributed ownership require correct atomic or durable
coordination, not merely a faster key-value read.

Know your cache-related locations
---------------------------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Location or service
     - Meaning
     - Do not confuse with
   * - ``config/cache.php``
     - Application-selected CacheLayer store/lock policy
     - Framework's internal template sources
   * - ``storage/cache/``
     - Selected local cache data and locks
     - Shared durable state between multiple hosts
   * - ``bootstrap/cache/``
     - Development ArrayKit config/command build artifacts
     - Application data cache
   * - ``storage/releases/``
     - Generated immutable Foundation production generation
     - CacheLayer cache items
   * - ``CacheManager``
     - Lazy named CacheLayer stores and lock providers
     - Laravel facade or global process-static cache

The initial Infbyte 4 candidate ships ``config/cache.php``
even when all optional features are disabled. It defaults
to a named ``local`` store, not a distributed Redis cache.

Step 1 — inspect the default store
----------------------------------

Open **``my-app/config/cache.php``**. Its source
contains keys such as ``default``, ``prefix``,
``stores``, ``lock``, ``counters`` and
``connections``.

The current candidate sets ``default`` from
``CACHE_STORE``, falling back to ``local``.

A development local store is suitable for a single
developer machine. Do not rely on local file/memory
storage as cross-host synchronization.

Step 2 — use a native cache store
---------------------------------

From an application service with a configured
Foundation ``Application $app``, resolve the
existing Core manager and then a native
CacheLayer store:

.. code-block:: php

   use Infocyph\Foundation\Cache\CacheManager;

   $cache = $app->make(CacheManager::class)->store('local');

   $cache->set('reports:last-finished', ['id' => 42], 300);
   $report = $cache->get('reports:last-finished');

``set()`` and ``get()`` are part of CacheLayer's
PSR SimpleCache-compatible ``CacheInterface``.
The 300-second TTL is an example, not an arbitrary
guarantee of permanent freshness.

``CacheManager::store()`` constructs the configured
named store lazily. For a route that never uses
CacheLayer, don't resolve all stores eagerly.

Step 3 — memoize repeated work
------------------------------

CacheLayer also exposes ``remember()`` through
its native ``CacheInterface``:

.. code-block:: php

   $result = $cache->remember(
       'reports:daily-summary',
       static fn (): array => ['generated' => true],
       300,
   );

The resolver is a small illustrative calculation;
replace it with application-owned work only after
you define its inputs, versioning and invalidation
policy.

Never use untrusted user-controlled keys without
normalization and tenancy separation. Avoid storing
credentials or full authentication tokens as ordinary
non-expiring cache values.

Step 4 — choose a production backend
-------------------------------------

The candidate's named store examples include
``local``, ``file``, ``memory``, ``sqlite``,
``database``, ``redis`` and ``valkey``.
Listing one in config does not prove the backend
service or PHP extension is available.

.. list-table::
   :header-rows: 1
   :widths: 25 33 42

   * - Store class
     - Good fit
     - Important limitation
   * - Memory/local test
     - Isolated test or local development
     - Different workers do not share process-local state
   * - File/SQLite
     - Single-host cache and local jobs
     - Needs filesystem safety/cleanup; not automatically distributed
   * - Redis/Valkey
     - Shared application cache/coordination
     - Requires actual service, extension/client, auth and failure policy
   * - Database/PDO
     - Deliberate DB-backed cache
     - Requires configured DB connection and native schema

Use an appropriate shared atomic backend for
browser-session lock ownership, OTP replay protection,
scheduler overlap and durable workflow coordination.
A local in-memory replacement can make tests pass
while multi-process production behavior is unsafe.

Step 5 — configure locks deliberately
-------------------------------------

The Core ``CacheManager`` exposes:

.. code-block:: php

   $lockProvider = $app
       ->make(\Infocyph\Foundation\Cache\CacheManager::class)
       ->lock('auth-state');

The provider is a **native CacheLayer lock provider**.
Its acquire/lease/release semantics belong to the
selected CacheLayer driver; don't invent a Foundation
lock facade or assume a process-local mutex can
serialize different machines.

If the selected store has no eligible lock,
Foundation must reject an unsafe configuration
instead of silently replacing it with an unrelated
file lock.

A lease is finite, and an old execution that lost
ownership must not overwrite a newer generation.

Step 6 — database cache schema
------------------------------

When the selected store uses CacheLayer database
tables or a database invalidation outbox, provision
schema through the Core CLI:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install

Those commands belong to **Core CacheLayer**
integration, not ``module:schema:install data.database``.
A database-backed cache may also need a selected
DBLayer connection; feature selection and backend
provisioning must be reviewed together.

Don't create or migrate cache tables in the hot path
of the first HTTP request.

Step 7 — test cache correctness
-------------------------------

For a cache-dependent feature, include:

1. First miss computes and stores a result.
2. Subsequent hit returns the intended value.
3. TTL expiry or explicit invalidation refreshes the result.
4. Distinct users/tenants never share unauthorized data.
5. Backend failure follows an intentional fail-open/fail-closed policy.
6. Multi-worker lock/atomic counter operations behave correctly
   on the actual shared store.
7. A persistent process can handle another request without
   carrying stale request-specific state.

Foundation TestKit exposes ``fakeCache()`` for
selected application tests. A fake cache only
verifies application interactions; it cannot
certify Redis reservation or distributed lock correctness.

Performance and operational notes
---------------------------------

Caching adds serialization, network and invalidation costs.
Benchmark with representative values and real backend
latency before adding tiers or cluster invalidation.

Keep namespaces, payload size limits and compression
settings appropriate to the data. Avoid object/closure
deserialization for untrusted or durable shared stores:
the candidate's serialization policy disables both
by default.

In production, record cache hit rate, backend errors,
lock contention and whether an invalidation event can
survive worker termination. Do not claim measured
performance gains based on cache presence alone.

Common problems
---------------

.. list-table::
   :header-rows: 1
   :widths: 29 35 36

   * - Symptom
     - Likely cause
     - Safe check
   * - Cache values vanish across requests
     - Process-local store
     - Selected ``config/cache.php`` backend
   * - Cluster workers disagree
     - No shared invalidation/coordination
     - Backend and cluster configuration
   * - Lock unavailable
     - No eligible native lock provider
     - Check configured store/lock driver
   * - Database cache table missing
     - CacheLayer schema not provisioned
     - ``cache:schema:status``
   * - User sees another user's cached result
     - Key not tenant-scoped
     - Key construction and authorization boundary
   * - Cache becomes slower than queries
     - Remote round trip/serialization outweighs work
     - Profile actual backend and payload sizes

Further reading
---------------

See :doc:`../start/configuration`,
:doc:`../reference/configuration`,
:doc:`../testing/fakes` and
the native CacheLayer project:

https://github.com/infocyph/CacheLayer
