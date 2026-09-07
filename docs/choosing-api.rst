Choosing DB, Connection, QueryBuilder, and Repository
=====================================================

Introduction
------------

DBLayer exposes complementary layers rather than one mandatory entrypoint:

- ``DB``: optional process-level static orchestration/convenience facade.
- ``Connection``: exact database runtime instance and execution-state boundary.
- ``QueryBuilder``: per-query SQL composition.
- ``Repository``: reusable table-level rules and behavior.
- ``ConnectionRepository``: instance-first repository base for scoped runtimes.

``SchemaManager``, ``MigrationRunner``, ``SeedRunner``, and ``RelationLoader``
remain explicit opt-in modules alongside these layers.

Choose the Ownership Model First
--------------------------------

Facade-oriented application
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``DB`` as the entrypoint when process/request-level static orchestration is
intentional and convenient. Typical flows are ``DB::table()``,
``DB::repository()``, ``DB::transaction()``, and the facade's observability,
cache, and pool helpers.

Execution-scoped application
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use explicit ``Connection`` ownership when a framework/runtime already owns a
request, job, coroutine, or Fiber execution scope. Build queries from the exact
connection, construct ``ConnectionRepository`` subclasses from it, and own a
``ConnectionLease`` when pooling is selected.

Do not register an execution-owned connection in the static facade merely to
obtain a QueryBuilder, ResultProcessor, transaction manager, or query cache.
DBLayer exposes instance-native paths for those concerns.

Quick Decision Matrix
---------------------

.. list-table::
   :header-rows: 1

   * - You Need
     - Use
     - Why
   * - Process-level named connection registry and convenience orchestration
     - ``DB``
     - Static facade is concise when its lifecycle matches the application.
   * - One exact connection owned by a request/job/Fiber scope
     - ``Connection``
     - Keeps mutable transaction/sticky/deadline/cache state execution-owned.
   * - Persistent pooled connection with explicit checkout ownership
     - ``PoolManager::checkout()`` / ``ConnectionLease``
     - Lease token proves the active reuse generation and prevents stale release.
   * - Compose a one-off complex query (joins, CTEs, custom select/having)
     - ``QueryBuilder``
     - Maximum query-shaping flexibility.
   * - Reuse tenant/soft-delete/hooks/default-order rules
     - ``Repository``
     - Centralized table policy avoids duplicated filters.
   * - Build a repository directly from an owned connection
     - ``ConnectionRepository``
     - Derives DBLayer's executor/result processor without static facade state.
   * - Raw SQL execution with bindings
     - ``Connection`` or ``DB`` raw helpers
     - Raw SQL remains first-class in either ownership model.
   * - Long-running table scan with stable pagination
     - ``QueryBuilder::chunkById()`` or ``Repository::chunkById()``
     - Keyset chunking is safer than offset paging under writes.
   * - Create/alter schema or execute deployment manifests
     - ``SchemaManager``, ``MigrationRunner``, ``SeedRunner``
     - DDL and deployment work remains explicit and outside ordinary queries.

Mental Model
------------

- ``DB`` answers: "Do I want process-level static convenience?"
- ``Connection`` answers: "Which exact database runtime owns this execution?"
- ``QueryBuilder`` answers: "What SQL should be emitted?"
- ``Repository`` answers: "What table rules must always apply?"
- ``PoolManager``/``ConnectionLease`` answer: "Who owns this pooled connection
  reuse generation?"

Practical Scenarios
-------------------

1. Conventional facade application
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $rows = DB::table('users')
       ->where('active', '=', 1)
       ->orderBy('id', 'desc')
       ->limit(20)
       ->get();

2. Scoped runtime with direct connection
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $connection = new Connection($config, 'main');

   $rows = $connection->table('users')
       ->where('active', '=', 1)
       ->get();

The host runtime owns ``$connection`` until its execution cleanup boundary.

3. Scoped repository
~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   final class UserRepository extends ConnectionRepository
   {
       protected function table(): string
       {
           return 'users';
       }
   }

   $users = (new UserRepository($connection))
       ->forTenant($tenantId)
       ->enableSoftDeletes();

   $active = $users->get(
       static fn ($q) => $q->where('active', '=', 1),
   );

4. Persistent pooled execution
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $lease = $poolManager->checkout('main');

   try {
       $connection = $lease->connection();
       $rows = $connection->table('events')->limit(100)->get();
   } finally {
       $lease->release();
   }

Use ``PoolManager::using()`` when callback-scoped ownership is sufficient.

5. Transaction boundary
~~~~~~~~~~~~~~~~~~~~~~~

Facade model:

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
       DB::table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
   }, attempts: 3);

Instance-owned model uses the transaction engine on the exact ``Connection``.
Keep all work in that transaction bound to the same connection instance.

6. Optimistic conditional update
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use repository versioned writes rather than application-level read-before-write
logic:

.. code-block:: php

   $updated = $users->updateByIdWithVersion(
       $id,
       ['payload' => $newPayload],
       $expectedRevision,
       'revision',
   );

Higher layers own the meaning of the revision conflict; DBLayer owns the atomic
conditional write primitive.

7. Query result caching
~~~~~~~~~~~~~~~~~~~~~~~

Facade applications can configure cache through ``DB::setCache()``. Scoped
runtimes should attach the backend to the exact connection:

.. code-block:: php

   $connection->setQueryCache($cache);

   $row = $connection->table('users')
       ->where('id', '=', $id)
       ->cacheFor(60)
       ->first();

Structured write invalidation follows the same exact connection and runs after
successful top-level commit.

8. Large table backfill job
~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $connection->table('events')
       ->orderBy('id')
       ->chunkById(1000, function (array $rows): bool {
           // process rows
           return true;
       }, 'id');

For row-by-row handling with the same bounded query lifetime:

.. code-block:: php

   foreach ($connection->table('events')->lazyById(1000, 'id', $checkpoint) as $event) {
       // process and persist $event['id'] as the next restart checkpoint
   }

Use ``unbufferedStream()`` only when a single occupied connection is acceptable.
MySQL disables client buffering; PostgreSQL uses server-cursor fetch batches.

Static TableRepository Surface
------------------------------

If you intentionally want a Laravel-like static repository surface, DBLayer's
``TableRepository`` remains available:

.. code-block:: php

   use Infocyph\DBLayer\Repository\TableRepository;

   final class User extends TableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $one = User::find(1);
   $recent = User::query()->orderBy('id', 'desc')->limit(20)->get();

This is repository delegation, not ORM identity-map/unit-of-work behavior.

Common Pitfalls
---------------

- Assuming the static ``DB`` facade is mandatory when the host already owns an
  execution-scoped connection lifecycle.
- Building a second pool, transaction manager, query cache, retry engine, or
  schema layer in the host instead of composing DBLayer's native mechanisms.
- Repeating tenant/soft-delete filters manually instead of centralizing them in
  a repository.
- Using ``PoolManager::get()``/bare references as the normal persistent-runtime
  ownership API instead of tokenized ``checkout()`` leases.
- Mixing optimistic-locking writes with blind updates on the same rows.
- Replacing native ``upsert()``/conditional update semantics with
  SELECT-before-write application code.

Related Guides
--------------

- See ``connections`` for replicas, leases, pooling, and reuse sanitation.
- See ``caching`` for connection-owned query-cache semantics.
- See ``repository`` and ``api-repository`` for repository policy and
  ``ConnectionRepository``.
- See ``table-repository`` for static class-based repository usage.
- See ``query-builder`` for SQL composition patterns.
- See ``schema-migrations`` for DDL, migration, and seeding contracts.
- See ``transactions`` for retry and nested transaction behavior.
