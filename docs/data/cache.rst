.. _cache-guide:

Caching and coordination
========================

Foundation includes **CacheLayer** as Core infrastructure. CacheLayer owns
stores, memoization, tiers, counters, locks, invalidation and backend
semantics. Foundation owns selection and integration policy.

Activate only what you need
---------------------------

CacheLayer is a required Foundation 4 package, but a production
application still selects its cache capability explicitly.
Installing optional DBLayer or Redis packages does not activate
unrelated cache services automatically.

Use the application ``config/cache.php`` configuration for stores,
counters and locks. Do not create a generic Foundation cache facade
when the application can depend on native CacheLayer contracts.

Cache and runtime compilation are different
-------------------------------------------

Application data caching is separate from two build-time mechanisms:

* ArrayKit's ``single`` or ``sharded`` development config cache;
* Webrick/InterMix's generated production route and service artifacts.

These caches are **not interchangeable**. A stale immutable release
must fail validation rather than falling back to a mutable cache file.

Counters and locks
------------------

A requested lock must use the configured coordination driver or
an eligible native lock from the selected store. Foundation does not
silently fall back to an unrelated file lock. Use distributed,
atomic operations for auth replay protection, session locking,
scheduled-job overlap and durable messaging where required.

Database-backed cache schemas
-----------------------------

For selected CacheLayer database capabilities:

.. code-block:: bash

   php infbyte cache:schema:status
   php infbyte cache:schema:install

Run schema provisioning outside HTTP handlers. Do not mistake
a process-local test store for durable shared state in PHP/FPM
or persistent workers.

See the `CacheLayer manual
<https://github.com/infocyph/CacheLayer>`_ and
`Foundation configuration guide
<https://github.com/infocyph/Foundation/blob/feature/foundation-3/docs/configuration.md>`_.
