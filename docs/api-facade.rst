API: DB Facade
==============

Class: ``Infocyph\DBLayer\DB``

The facade is the orchestration layer. It resolves named connections, exposes
raw SQL helpers, and proxies advanced runtime controls (pooling, telemetry,
timeouts, and retry policy wrappers).

Role in the 3-Layer API
-----------------------

- ``DB``: orchestration and infrastructure controls.
- ``QueryBuilder``: SQL composition surface.
- ``Repository``: reusable table policy surface.

``DB`` is not a replacement for repository policies. It is the shared gateway
that creates builder/repository instances and owns connection/runtime behavior.

Most applications use a mix of:

- ``TableRepository`` subclasses for static class-based repository workflows
- ``DB::table()`` for query builder flows
- ``DB::repository()`` for table-oriented app services
- ``DB::transaction()`` for write consistency boundaries

Connection Methods
------------------

- ``addConnection()``, ``connection()``, ``freshConnection()``, ``reconnect()``, ``disconnect()``
- ``setDefaultConnection()``, ``getDefaultConnection()``, ``hasConnection()``, ``getConnections()``, ``purge()``
- ``setSecurityDefaults()``, ``hardenProduction()``

Raw SQL Methods
---------------

- ``select()``, ``selectOne()``, ``selectResultSets()``, ``scalar()``
- ``insert()``, ``update()``, ``delete()``, ``statement()``, ``unprepared()``, ``batch()``
- ``stream()``, ``yieldRows()``

Builder and Repository
----------------------

- ``table()``
- ``repository()``
- ``raw()``

Transactions
------------

- ``beginTransaction()``, ``commit()``, ``rollBack()``
- ``transaction()``, ``readOnlyTransaction()``, ``transactionLevel()``, ``transactionStats()``

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
- ``flushTelemetry()``, ``flushTelemetryOtel()``, ``slowQueryReport()``
- ``setMaxQueryLogEntries()``, ``setProfilerMaxProfiles()``, ``setTelemetryBufferLimits()``
- ``listen()``, ``whenQueryingForLongerThan()``
- ``stats()``, ``health()``, ``capabilities()``, ``supportsReturning()``, ``supportsJson()``, ``supportsWindowFunctions()``
- ``pool()``, ``poolManager()``, ``withPooledConnection()``
- ``cache()``, ``useFileCache()``
- ``resetRuntimeState()`` (useful for long-running workers between requests/jobs)

Long-Running Worker Note
------------------------

For worker runtimes (RoadRunner/Swoole/Octane-style loops), call
``DB::resetRuntimeState()`` between logical requests/jobs to clear query logs,
telemetry/profiler buffers, listeners, and threshold monitors while preserving
registered connection configurations.
