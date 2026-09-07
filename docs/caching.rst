Caching
~~~~~~~

DBLayer uses ``infocyph/cachelayer`` ``^3.4``. Query-result caching is opt-in
and ordinary database-only paths do not need to initialize a cache adapter.

The important runtime rule is ownership: a ``QueryBuilder`` uses the cache
attached to its exact ``Connection`` instance. Result reads and post-commit
invalidation therefore do not depend on process-static ``DB`` registration.

Instance-Owned Cache
--------------------

For DI containers, scoped runtimes, workers, Fibers, or other execution-owned
connection lifecycles, attach the CacheLayer backend directly to the connection:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Connection\ConnectionConfig;

   $connection = new Connection(
       ConnectionConfig::fromArray([
           'driver' => 'sqlite',
           'database' => __DIR__ . '/../storage/app.sqlite',
       ]),
       'main',
   );

   $connection->setQueryCache(
       Cache::file('dblayer-query', __DIR__ . '/../storage/cache'),
   );

   $users = $connection->table('users')
       ->where('active', '=', 1)
       ->cacheFor(120)
       ->get();

``setQueryCache()`` binds or clears the backend for that exact connection.
``queryCache()`` lazily creates a private in-memory backend when a direct
``Connection`` user opts into ``cacheFor()`` without supplying one explicitly.

Facade Cache Topology
---------------------

The static facade remains a convenience API for applications that intentionally
use process-level ``DB`` orchestration:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\DBLayer\DB;

   DB::setCache(Cache::file('dblayer', __DIR__ . '/../storage/cache'));

   DB::setCache(Cache::tiered([
       ['driver' => 'memory', 'namespace' => 'application-l1'],
       ['driver' => 'file', 'namespace' => 'application-l2', 'dir' => '/var/cache/app'],
   ]));

Adapter selection, storage, locking, stampede protection, metrics, TTL, and tag
versions remain CacheLayer responsibilities. DBLayer does not duplicate
adapter-specific configuration.

For execution-scoped runtimes, prefer explicit ``Connection`` ownership over
using ``DB::cache()`` as the correctness boundary.

Direct CacheLayer Usage
-----------------------

CacheLayer can still be used directly for application-owned cache entries:

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

The database-integrated form is opt-in on ``QueryBuilder``:

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

Automatic result identities include the logical connection, driver, database,
result mode, compiled SQL fingerprint, and normalized scalar bindings. They do
not contain credentials, TLS material, or physical replica indexes.

When multiple independent database deployments deliberately share the same
CacheLayer backend, give those deployments distinct CacheLayer namespaces and/or
logical DBLayer connection identities. Do not rely on credentials or physical
replica addresses to partition result-cache entries.

Tags and Invalidation
---------------------

Builder reads receive conservative tags for their source and joined tables.
Repository reads also add record and tenant tags where applicable. DBLayer's
internal tags are database-scoped hashes, while caller tags are validated
exactly and rejected when empty, longer than 64 bytes, or outside CacheLayer's
documented tag alphabet.

Structured writes schedule invalidation on the exact owning connection with
``Connection::afterCommit()``. Invalidation runs after the successful top-level
commit. A rollback, rolled-back savepoint, or failed transaction retry therefore
does not evict valid cached data.

Two connection instances may intentionally share the same CacheLayer backend.
When they identify the same database/table dependency, a structured write from
one instance invalidates cached results populated by the other. When they use
separate backends, invalidation remains isolated to the backend attached to the
writing connection.

Consistency Bypasses
--------------------

Result caching is bypassed for active managed transactions, sticky
read-after-write state, locking reads, cursors, streaming, lazy/chunked reads,
unsupported resource bindings, and raw query fragments without explicit tags.
Queries with CTE, UNION, derived-table, nested, or subquery dependencies also
bypass caching unless the caller supplies complete explicit tags.

These paths continue to execute normally; they simply do not read or populate
the result cache.

Raw write helpers cannot infer table dependencies reliably. Facade users can
call ``DB::invalidateCacheTags()`` with application-owned tags after explicit
raw mutations. Instance-owned runtimes should invalidate through the exact
CacheLayer backend they attached to the connection. Structured QueryBuilder and
schema DDL mutations schedule their table-tag invalidation automatically after
commit.

Repository Policy
-----------------

Repositories expose the same opt-in policy:

.. code-block:: php

   $user = DB::repository('users')
       ->forTenant(10)
       ->cacheFor(120)
       ->find(42);

Instance-first repositories inherit the cache from their exact connection:

.. code-block:: php

   final class UserRepository extends \Infocyph\DBLayer\Query\ConnectionRepository
   {
       protected function table(): string
       {
           return 'users';
       }
   }

   $users = (new UserRepository($connection))->cacheFor(120);

The repository adds table, tenant, and primary-key record tags while retaining
the transaction and sticky-read consistency rules above.
