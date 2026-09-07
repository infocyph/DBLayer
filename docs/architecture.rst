Architecture
============

DBLayer is layered so each concern stays explicit: query composition,
connection lifecycle, security validation, caching, pooling, and observability
are separate modules with clear ownership boundaries.

Core Components
---------------

- ``DB``: optional process-level static convenience/orchestration facade.
- ``Connection``: exact PDO lifecycle, transaction engine, execution controls,
  sticky-read state, statement cache, and instance-owned query-cache boundary.
- ``Pool``: process-local reusable connection storage and sanitation.
- ``PoolManager`` / ``ConnectionLease``: explicit pooled checkout ownership.
- ``ReplicaSelector``: read strategy, health cooldown, weighted selection, and latency state.
- ``QueryBuilder``: fluent SQL builder and structured mutation surface.
- ``Repository``: table-oriented policy abstraction.
- ``ConnectionRepository``: instance-first repository base for scoped runtimes.
- ``TableRepository``: repository-oriented static adapter for facade-style applications.
- ``RelationLoader``: explicit, bounded relation projection over parent arrays.
- ``SchemaManager``: opt-in driver-aware DDL boundary.
- ``MigrationRunner``: console/deploy-time migration execution over an explicit manifest.

Responsibility Boundaries
-------------------------

These components are intentionally separate:

- ``DB`` may own process-level registrations and shared convenience state when
  the application chooses the facade model.
- ``Connection`` owns the mutable database runtime state for one exact
  connection instance: managed transactions/savepoints, sticky-write state,
  deadlines/cancellation, query comments, executor, statement cache, and the
  optional CacheLayer-backed result cache.
- ``Pool`` owns reusable connections and DBLayer's native reset/health/lifetime
  policy before reuse.
- ``PoolManager`` owns checkout bookkeeping; ``ConnectionLease`` proves the
  active checkout generation for persistent/interleaved runtimes.
- ``QueryBuilder`` owns per-query mutable state (clauses, bindings, SQL payload).
- ``Repository`` owns per-table policy state (tenant scope, soft deletes,
  optimistic locking, casts, hooks, default ordering).

Higher-level frameworks should compose these objects, not reimplement a second
transaction manager, connection pool, result-cache invalidator, retry runtime,
schema engine, or migration engine around them.

Runtime Ownership Models
------------------------

DBLayer supports two deliberate entry models.

Facade model
~~~~~~~~~~~~

Conventional applications may use ``DB`` as the process/request gateway:

1. register named configuration with ``DB``;
2. resolve ``DB::connection()``, ``DB::table()``, or ``DB::repository()``;
3. use facade transaction/cache/pool/observability helpers.

Instance-owned model
~~~~~~~~~~~~~~~~~~~~

DI containers, persistent workers, Fiber/interleaved runtimes, and higher-level
frameworks can own the exact DBLayer objects for one execution:

1. construct or lease one ``Connection`` for the execution;
2. build queries directly from that connection;
3. construct ``ConnectionRepository`` subclasses from the same connection;
4. attach an optional CacheLayer backend with ``Connection::setQueryCache()``;
5. release/disconnect the connection at the execution cleanup boundary.

The instance-owned model does not require registering execution-local
connections in the static ``DB`` facade.

Data Flow
---------

A structured instance-owned request typically flows as follows:

1. Application/runtime resolves the exact ``Connection`` for the execution.
2. Builder/repository creates a typed ``CompiledQuery`` with bindings and provenance.
3. ``Connection`` applies generated/raw validation and executes through the driver.
4. Opt-in cache reads use the cache attached to that exact connection.
5. Structured writes schedule table-tag invalidation on that connection's
   ``afterCommit()`` queue.
6. Events are emitted for configured logging/profiling/telemetry hooks.
7. Result processors/casts adapt output for caller usage.
8. At scope cleanup, a dedicated connection disconnects or a pooled lease is
   released through DBLayer's native sanitation path.

Cache Ownership
---------------

Query-result caching is connection-owned. A direct ``Connection`` can receive a
CacheLayer backend through ``setQueryCache()`` and QueryBuilder result reads no
longer require a static ``DB::cache()`` lookup.

Structured mutations invalidate through the exact owning connection and defer
invalidation until successful top-level commit. This preserves rollback and
savepoint correctness and allows two independent connection instances to share
one deliberate CacheLayer backend without sharing static DB connection state.

CacheLayer still owns adapter/storage/TTL/tag implementation. DBLayer owns the
database dependency tags and transaction-aware invalidation semantics.

Pool Ownership and Reuse
------------------------

``PoolManager::checkout()`` returns a ``ConnectionLease`` rather than relying on
a bare connection reference to express ownership. The lease protects pooled
reuse against stale or double release and is the preferred API for persistent
or interleaved runtimes.

Before an owned connection returns to idle reuse, ``Pool`` calls
``Connection::resetRuntimeStateForReuse()``. Execution-local transaction,
sticky, deadline, cancellation, query-comment, after-commit, and savepoint
state must not cross that boundary. Prepared statements remain connection-owned
so healthy warm pool reuse can retain its intended performance benefit.

Canonical Query Pipeline
------------------------

Every structured builder operation follows one compilation path:

``QueryBuilder → QueryPayload → driver compiler → CompiledQuery → Connection``.

MySQL, MariaDB, PostgreSQL, Microsoft SQL Server, and SQLite each provide a
dedicated dialect compiler, while ``Capabilities`` flags decide whether
returning, insert-ignore, upsert, and related semantics are available.
``Executor`` only coordinates batching and portable read-back; it is not a
second compiler, event dispatcher, or security boundary.

Dialect-sensitive features (for example ``RETURNING``/``OUTPUT``, locking,
pagination, or upsert syntax) are resolved through this layer, not through
conditional logic in application code.

Driver Path Separation
----------------------

Built-in engines have five canonical pathways: ``mysql``, ``mariadb``,
``pgsql``, ``mssql``, and ``sqlite``. Aliases such as ``psql`` and ``sqlsrv``
normalize to their canonical pathway before connection creation.

MySQL and MariaDB share only internal PDO-MySQL protocol primitives. They do
not resolve to the same concrete driver, compiler, capabilities, timeout/explain
behavior, or schema dialect. Schema DDL follows the same separation through one
resolved engine-specific dialect. Microsoft SQL Server likewise owns its T-SQL
compiler, parameter ceiling, savepoint behavior, connection options, and schema
dialect instead of adding ``mssql`` switches throughout the generic builder.

Runtime Modules
---------------

- Transactions and savepoints
- Read replicas and strategies
- Tokenized pooling and health checks
- Security validation
- Events, logger, profiler, telemetry
- Instance-owned CacheLayer-backed query-result caching
- Schema compilation, migration ledger/leases, and explicit seeding
- Bounded relation prefetching

Design Intent
-------------

- Keep raw SQL accessible when needed.
- Keep fluent APIs predictable and composable.
- Keep execution ownership explicit for persistent runtimes.
- Keep the static facade optional rather than mandatory for correctness.
- Keep infrastructure concerns opt-in where possible.
- Keep schema, migration, and relation classes outside normal query paths until
  explicitly resolved.
- Keep safety checks centrally configurable.
- Keep domain/table rules reusable without forcing full ORM-style models.
- Keep application/domain concurrency policy above DBLayer while providing
  generic native upsert and optimistic conditional-write primitives below it.
