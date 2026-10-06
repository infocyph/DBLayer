API: Pooling
============

Classes:

- ``Infocyph\DBLayer\Connection\Pool``
- ``Infocyph\DBLayer\Connection\PoolManager``
- ``Infocyph\DBLayer\Connection\ConnectionLease``

DBLayer pooling is process-local. One pool belongs to one PHP process/worker
generation; it is not a cross-worker connection pool.

Pool
----

- ``addConfig()``
- ``getConnection()``, ``releaseConnection()``, ``removeConnection()``
- ``warmUp()``
- ``healthCheck()``, ``getStats()``, ``resetStats()``
- ``closeAll()``

``min_connections`` creates lazy wrappers rather than eagerly opened PDO
handles. ``warmUp()`` is the explicit host-controlled readiness operation for
opening distinct primary handles. It is synchronous, respects
``max_connections``, and does not install a timer or own worker lifecycle.

PoolManager
-----------

- ``checkout()``
- ``using()``
- ``get()``, ``release()``
- ``warmUp()``
- ``getPool()``

``checkout()`` is the preferred API for persistent, interleaved, Fiber-based,
or DI-owned runtimes because it returns an explicit ownership token.

``using()`` is callback-scoped. It releases the lease when the callback
returns and rejects a ``Traversable`` result that would escape after release.
For streams, cursors, lazy iteration, or another deferred connection-bound
result, use ``checkout()`` and retain the lease until iteration completes.

ConnectionLease
---------------

- ``connection()``
- ``name()``
- ``isReleased()``
- ``release()``

A lease represents exactly one checkout generation. Access after release,
double release, stale release, and release through a bare connection while an
active tokenized checkout exists are rejected.

Worker Lifecycle
----------------

Create or retain one pool per worker generation after any fork. During
host-controlled drain, settle active work and call ``Pool::closeAll()``. Never
carry opened PDO handles across a fork.

Before an eligible connection returns to idle reuse, the pool invokes
``Connection::resetRuntimeStateForReuse()``. If sanitation fails, an active
iterator remains, the connection is unhealthy, or lifetime limits are exceeded,
the wrapper is discarded instead of reused.
