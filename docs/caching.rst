Caching
~~~~~~~

DBLayer cache is strategy-based. You can keep default memory caching for local
or switch to file strategy for persistence between requests/processes.

Basic Cache
-----------

.. code-block:: php

   $cache = DB::cache();
   $cache->put('k', 'v', 60);
   $value = $cache->get('k');

TTL is in seconds. ``0`` means store indefinitely (strategy permitting).

File Strategy
-------------

.. code-block:: php

   $cache = DB::useFileCache(__DIR__ . '/../storage/cache');

Use file strategy when you need durable local cache without external services.

Remember Pattern
----------------

.. code-block:: php

   $rows = $cache->remember('users:active', function () {
       return DB::table('users')->where('active', '=', 1)->get();
   }, 120);

Null return values are treated as cache miss semantics in remember-style flows.
If you need to cache null explicitly, use a sentinel value.

Tags
----

.. code-block:: php

   $tagged = $cache->tags(['users', 'tenant:10']);
   $tagged->put('profile:1', ['id' => 1], 60);

Operational Metrics
-------------------

Cache exposes hit/miss/write/delete metrics:

.. code-block:: php

   $stats = DB::cache()->getStats();
