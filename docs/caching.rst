Caching
~~~~~~~

DBLayer uses ``infocyph/cachelayer`` 3.x. Cache initialization is lazy, so
ordinary database-only request paths do not allocate a cache adapter. The
default is ``Cache::memory('dblayer')``.

Cache Topology
--------------

``DB::cache()`` returns the configured CacheLayer instance. Inject any
CacheLayer 3 topology explicitly:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\DBLayer\DB;

   DB::setCache(Cache::file('dblayer', __DIR__ . '/../storage/cache'));

   DB::setCache(Cache::tiered([
       ['driver' => 'memory', 'namespace' => 'application-l1'],
       ['driver' => 'file', 'namespace' => 'application-l2', 'dir' => '/var/cache/app'],
   ]));

Adapter selection, storage, locking, stampede protection, metrics, TTL, and tag
versions remain CacheLayer responsibilities. DBLayer does not provide
adapter-specific configuration shortcuts.

Direct CacheLayer Usage
-----------------------

.. code-block:: php

   $cache = DB::cache();

   $activeUsers = $cache->remember(
       'users.active',
       fn (): array => DB::table('users')->where('active', '=', 1)->get(),
       ttl: 120,
       tags: ['users'],
   );

   $cache->invalidateTag('users');
   $metrics = $cache->exportMetrics();

Query Result Cache
------------------

The preferred database-integrated form is opt-in on a QueryBuilder:

.. code-block:: php

   $activeUsers = DB::table('users')
       ->where('active', '=', 1)
       ->cacheFor(120)
       ->cacheTags('users')
       ->get();

   $dashboard = DB::table('users')
       ->where('active', '=', 1)
       ->cacheKey('dashboard.active-users')
       ->cacheFor(new DateInterval('PT2M'))
       ->get();

   $fresh = DB::table('users')->cacheFor(120)->withoutCache()->get();

Automatic identities include the logical connection, driver, database,
result mode, compiled SQL fingerprint, and normalized scalar bindings. They do
not contain credentials, TLS material, or physical replica indexes.

Tags and Invalidation
---------------------

Builder reads receive conservative tags for their source and joined tables.
Repository reads also add record and tenant tags where applicable. CacheLayer
3 permits only letters, digits, ``_``, ``.``, and ``-`` in tags, so DBLayer's
internal tags use forms such as ``table.users`` and
``table.users.id.42``. Caller tags are validated exactly and rejected when they
are empty, longer than 64 bytes, or outside the documented alphabet; DBLayer
does not silently rewrite caller identity.

Successful QueryBuilder writes invalidate table tags through ``afterCommit()``.
A rollback, rolled-back savepoint, or failed transaction retry therefore does
not evict valid cached data.

Consistency Bypasses
--------------------

Shared result caching is bypassed for active transactions, sticky
read-after-write state, locking reads, cursors, streaming, lazy/chunked reads,
unsupported resource bindings, and raw query fragments without explicit tags.
Queries with CTE, UNION, derived-table, nested, or subquery dependencies also
bypass shared caching unless the caller supplies complete explicit tags.
These paths continue to execute normally; they simply do not read or populate
the shared result cache.

Raw write helpers cannot infer table dependencies reliably. After an explicit
raw mutation, call ``DB::invalidateCacheTags()`` with the application-owned tags
that cover the write. Structured QueryBuilder and schema DDL mutations schedule
their table-tag invalidation automatically after commit.

Repository Policy
-----------------

Repositories expose the same opt-in policy:

.. code-block:: php

   $user = DB::repository('users')
       ->forTenant(10)
       ->cacheFor(120)
       ->find(42);

The repository adds table, tenant, and primary-key record tags while retaining
the transaction and sticky-read consistency rules above.
