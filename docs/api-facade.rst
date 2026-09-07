API: DB Facade
==============

Class: ``Infocyph\DBLayer\DB``

The facade is the process-level convenience/orchestration API. It resolves
named connections, exposes raw SQL helpers, and proxies infrastructure controls
such as pooling, telemetry, timeouts, retry wrappers, and CacheLayer setup.

It is not required as the runtime ownership boundary. DI containers,
persistent workers, Fiber/interleaved runtimes, and higher-level frameworks may
own ``Connection``, ``PoolManager``, and ``ConnectionRepository`` instances
directly so execution-local state never needs to live in the static facade.

Role in the API
---------------

- ``DB``: process-level convenience and shared infrastructure orchestration.
- ``Connection``: exact connection lifecycle and execution-local runtime state.
- ``QueryBuilder``: SQL composition surface.
- ``Repository``: reusable table policy surface.
- ``ConnectionRepository``: instance-first repository base for scoped runtimes.

``DB`` is not a replacement for repository policies, and higher-level runtimes
do not need to register their execution-owned connections in ``DB`` merely to
use builders, repositories, transactions, or query caching.

Facade-Oriented Applications
----------------------------

Many conventional applications intentionally use a mix of:

- ``TableRepository`` subclasses for static class-based repository workflows
- ``DB::table()`` for query builder flows
- ``DB::repository()`` for table-oriented app services
- ``DB::transaction()`` for write consistency boundaries
- ``DB::poolManager()``/``DB::withPooledConnection()`` for facade-managed pooling

This remains a supported and convenient style.

Execution-Scoped Applications
-----------------------------

A framework/runtime that already owns execution scope should normally hold the
exact DBLayer objects for that scope:

.. code-block:: php

   $connection = new Connection($config, 'main');
   $repository = new UserRepository($connection); // extends ConnectionRepository

   $connection->transaction(function () use ($repository): void {
       // scoped work
   });

For pooled persistent runtimes, own a ``ConnectionLease`` for the execution and
release it at the same execution cleanup boundary. Do not mirror DBLayer's pool,
transaction manager, query cache, or connection reset logic in the higher layer.

Connection Methods
------------------

- ``addConnection()``, ``connection()``, ``freshConnection()``, ``reconnect()``, ``disconnect()``
- ``setDefaultConnection()``, ``getDefaultConnection()``, ``hasConnection()``, ``getConnections()``, ``purge()``
- ``setSecurityDefaults()``, ``hardenProduction()``

Raw SQL Methods
---------------

- ``select()``, ``selectOne()``, ``selectResultSets()``, ``scalar()``, ``explain()``
- ``insert()``, ``update()``, ``delete()``, ``statement()``, ``unprepared()``, ``batch()``
- ``stream()``, ``unbufferedStream()``, ``yieldRows()``

Builder and Repository
----------------------

- ``table()``
- ``repository()``
- ``relations()`` (creates an explicit bounded ``RelationLoader``)
- ``schema()`` (creates a driver-aware ``SchemaManager``)
- ``raw()``

Both ``relations(connection, batchSize)`` and ``schema(connection)`` resolve
only the requested named connection and are instantiated on demand. Migration
and seed runners are explicit objects rather than mandatory process-global
facade state.

Transactions
------------

- ``beginTransaction()``, ``commit()``, ``rollBack()``
- ``transaction()``, ``readOnlyTransaction()``, ``transactionLevel()``, ``transactionStats()``

The same transaction engine is available on explicit ``Connection`` instances.
Use one ownership model per execution instead of switching between an
execution-owned connection and a separately registered static connection.

Execution Controls
------------------

- ``withQueryTimeout()``
- ``withQueryDeadline()``
- ``withQueryCancellation()``
- ``withQueryRetryPolicy()``

Observability and Utility
-------------------------

- ``enableLogger()``, ``disableLogger()``, ``logger()``, ``setPsrLogger()``
- ``enableProfiler()``, ``disableProfiler()``, ``profiler()``
- ``enableTelemetry()``, ``disableTelemetry()``, ``telemetry()``, ``telemetryOtel()``
- ``flushTelemetry()``, ``flushTelemetryOtel()``, ``slowQueryReport()``, ``queryShapeReport()``
- ``setMaxQueryLogEntries()``, ``setProfilerMaxProfiles()``, ``setTelemetryBufferLimits()``
- ``listen()``, ``whenQueryingForLongerThan()``
- ``stats()``, ``health()``, ``capabilities()``, ``supportsReturning()``, ``supportsJson()``, ``supportsWindowFunctions()``
- ``pool()``, ``poolManager()``, ``withPooledConnection()``
- ``cache()``, ``setCache()``
- ``resetRuntimeState()``

Query Cache Ownership
---------------------

``DB::setCache()`` remains the facade convenience for facade-managed queries.
Instance-owned runtimes should attach the selected CacheLayer backend to the
exact ``Connection`` with ``Connection::setQueryCache()``. QueryBuilder reads
and structured-write invalidation then remain bound to that exact connection
rather than depending on static facade lookup.

Long-Running Worker Note
------------------------

If a long-running worker intentionally uses the static facade, call
``DB::resetRuntimeState()`` between logical requests/jobs to clear facade query
logs, telemetry/profiler buffers, listeners, and threshold monitors while
preserving registered connection configurations.

If the worker instead uses explicit scoped connections/leases, release those
objects through the scope cleanup path. ``Pool`` performs DBLayer's native
``resetRuntimeStateForReuse()`` sanitation before a connection returns to idle
reuse; do not duplicate that reset policy in application code.

Query logs, profiler samples, and telemetry events use bounded in-memory
retention by default. Pass an explicit positive limit when a workload needs a
different bound. For query logs and profiler samples, passing ``null`` restores
the safe default; omitted telemetry-limit arguments leave their current values
unchanged.
