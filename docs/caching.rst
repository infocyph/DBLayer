Caching
~~~~~~~

DBLayer caching is powered by ``infocyph/cachelayer`` 2.x.
``DB::cache()`` returns a CacheLayer cache instance (PSR-6 + PSR-16 style API).
CacheLayer is instantiated only when one of DBLayer's cache methods is called,
so database-only request paths do not initialize a cache adapter.

Basic Cache
-----------

.. code-block:: php

   $cache = DB::cache();
   $cache->set('k', 'v', 60);
   $value = $cache->get('k');

TTL is in seconds. Use ``null`` for adapter default.

File Cache Adapter
------------------

.. code-block:: php

   $cache = DB::useFileCache(__DIR__ . '/../storage/cache');

Use file adapter when you need durable local cache without external services.

Remember Pattern
----------------

.. code-block:: php

   $rows = $cache->remember('users.active', function () {
       return DB::table('users')->where('active', '=', 1)->get();
   }, 120);

The resolver return value is cached as-is, including ``null``.
Resolver TTL and tag options are evaluated on a cache miss; cache hits return
the stored value without repeating unused option normalization.

Tags
----

.. code-block:: php

   $cache->setTagged('profile.1', ['id' => 1], ['users', 'tenant.10'], 60);

   // Later: invalidate all items for a tag
   $cache->invalidateTag('users');

Operational Metrics
-------------------

CacheLayer exposes adapter-level metrics:

.. code-block:: php

   $metrics = DB::cache()->exportMetrics();
