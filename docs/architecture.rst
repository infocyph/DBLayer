Architecture
============

DBLayer is layered so each concern stays explicit: query composition,
connection lifecycle, security validation, and observability are separate
modules that cooperate through events and typed payloads.

Core Components
---------------

- ``DB`` facade: static entrypoint.
- ``TableRepository``: repository-oriented static adapter over repository and builder APIs.
- ``Connection``: PDO lifecycle and execution controls.
- ``ReplicaSelector``: read strategy, health cooldown, weighted selection, and latency state.
- ``QueryBuilder``: fluent SQL builder.
- ``Repository``: table-oriented abstraction.
- ``RelationLoader``: explicit, bounded relation projection over parent arrays.
- ``SchemaManager``: opt-in driver-aware DDL boundary.
- ``MigrationRunner``: console/deploy-time migration execution over an explicit manifest.

Responsibility Boundaries
-------------------------

These components are intentionally separate:

- ``DB`` is process-level orchestration state (connection registry, transaction
  entrypoints, pooling, telemetry/profiler wiring).
- ``QueryBuilder`` is per-query mutable state (clauses, bindings, SQL payload).
- ``Repository`` is per-table policy state (tenant scope, soft deletes,
  optimistic locking, casts, hooks, default ordering).

Keeping them distinct prevents a single "god class" that mixes infrastructure
and table business rules.

Data Flow
---------

Typical request flow:

1. Application code calls ``DB::table()`` or ``DB::repository()``.
2. Builder/repository creates a typed ``CompiledQuery`` with bindings and provenance.
3. ``Connection`` applies generated/raw validation and executes through the driver.
4. Opt-in cache reads use CacheLayer ``remember()``; writes schedule tag invalidation after commit.
5. Events are emitted for logging/profiling/telemetry hooks.
6. Result processors/casts adapt output for caller usage.

Layer Lifecycles
----------------

- ``DB`` static state usually lives for the process/request lifetime.
- ``QueryBuilder`` instances are short-lived and discarded after query use.
- ``Repository`` instances are reusable per table policy context.

This lifecycle mismatch is the main reason DBLayer does not collapse these
three concepts into one class.

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
- Pooling and health checks
- Security validation
- Events, logger, profiler, telemetry
- Caching strategies
- Schema compilation, migration ledger/leases, and explicit seeding
- Bounded relation prefetching

Design Intent
-------------

- Keep raw SQL accessible when needed.
- Keep fluent APIs predictable and composable.
- Keep infrastructure concerns opt-in (logger/profiler/telemetry).
- Keep schema, migration, and relation classes outside normal query paths until
  explicitly resolved.
- Keep safety checks centrally configurable.
- Keep domain/table rules reusable without forcing full ORM-style models.
