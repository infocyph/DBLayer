API: Connection
===============

Class: ``Infocyph\DBLayer\Connection\Connection``

``Connection`` is the instance-owned database execution boundary. Prefer it when
a DI container, worker, Fiber/interleaved runtime, or higher-level framework owns
the exact database lifecycle for one execution.

Construction and Identity
-------------------------

- ``getName()``, ``getConfig()``, ``getDatabaseName()``, ``setDatabaseName()``
- ``getDriverName()``, ``getDriver()``, ``getCapabilities()``, ``capabilities()``
- ``getTablePrefix()``, ``setTablePrefix()``

SQL Execution
-------------

- ``select()``, ``selectResultSets()``, ``scalar()``
- ``insert()``, ``update()``, ``delete()``, ``statement()``, ``unprepared()``
- ``table()``, ``query()``, ``raw()``
- ``stream()``, ``unbufferedStream()``, ``yieldRows()``

Streaming iterators remain bound to this exact connection until iteration
finishes. When the connection came from a pool lease, keep the lease alive for
the full iterator lifetime.

Transactions
------------

- ``beginTransaction()``, ``commitTransaction()``, ``rollbackTransaction()``
- ``transaction()``, ``readOnlyTransaction()``
- ``transactionLevel()``, ``managedTransactionLevel()``, ``inTransaction()``
- ``afterCommit()``
- ``hasActiveNativeTransaction()``

``afterCommit()`` is a DBLayer-managed transaction primitive. It is rejected
when the application has opened an externally owned native PDO transaction.
Cache-aware DBLayer writes follow the same ownership boundary.

Execution Budgets
-----------------

- ``setQueryTimeoutMs()``, ``getQueryTimeoutMs()``
- ``setQueryDeadlineAt()``
- ``withQueryTimeoutMs()``, ``withQueryDeadline()``
- ``withQueryCancellation()``, ``withQueryRetryPolicy()``

Timeouts, deadlines, and cancellation are cooperative around synchronous PDO
work. A successful synchronous mutation is not reported as a late elapsed-time
timeout after the write has already completed.

Query Cache Ownership
---------------------

- ``setQueryCache()``, ``queryCache()``, ``hasQueryCache()``
- ``invalidateQueryCacheTagsAfterCommit()``
- ``cacheTableTag()``

Attach a CacheLayer backend to the exact connection when cache correctness must
follow instance ownership. Structured mutations defer tag invalidation until a
DBLayer-managed top-level commit. Externally owned native transactions must own
their own cache policy.

Persistent Runtime Reuse
------------------------

- ``resetRuntimeStateForReuse()``

Pool release calls this sanitation contract before returning a connection to
idle reuse. It clears request/job-local runtime state while preserving safe
connection-owned reusable state such as prepared statement cache entries.

Optional Runwire Integration
----------------------------

- ``withRunwire()``

``withRunwire()`` borrows an already-active Runwire runtime/request/scope for
one operation. DBLayer validates ownership, composes cancellation/deadline
policy, cooperates with compatible retry sleeps, and restores the previous
binding afterward. It never starts or stops the host worker/event loop.

Methods such as ``runwireBinding()``, ``runWithRunwireBinding()``,
``cooperativeSleep()``, and pool-management hooks are marked ``@internal`` and
are not application API.
