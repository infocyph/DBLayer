Architecture
============

DBLayer is layered so each concern stays explicit: query composition,
connection lifecycle, security validation, and observability are separate
modules that cooperate through events and typed payloads.

Core Components
---------------

- ``DB`` facade: static entrypoint.
- ``TableRepository``: repository-oriented static adapter over repository/builder/facade.
- ``Connection``: PDO lifecycle, driver, execution controls.
- ``QueryBuilder``: fluent SQL builder.
- ``Repository``: table-oriented abstraction.

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
2. Builder/repository creates query payload + bindings.
3. ``Connection`` validates and executes through driver/compiler.
4. Events are emitted for logging/profiling/telemetry hooks.
5. Result processors/casts adapt output for caller usage.

Layer Lifecycles
----------------

- ``DB`` static state usually lives for the process/request lifetime.
- ``QueryBuilder`` instances are short-lived and discarded after query use.
- ``Repository`` instances are reusable per table policy context.

This lifecycle mismatch is the main reason DBLayer does not collapse these
three concepts into one class.

Driver Stack
------------

- MySQL, PostgreSQL, SQLite drivers.
- Grammar/compiler per dialect.
- ``Capabilities`` flags for feature checks.

Dialect-sensitive features (for example ``RETURNING`` or lock syntax) are
resolved through this layer, not through conditional logic in application code.

Runtime Modules
---------------

- Transactions and savepoints
- Read replicas and strategies
- Pooling and health checks
- Security validation
- Events, logger, profiler, telemetry
- Caching strategies

Design Intent
-------------

- Keep raw SQL accessible when needed.
- Keep fluent APIs predictable and composable.
- Keep infrastructure concerns opt-in (logger/profiler/telemetry).
- Keep safety checks centrally configurable.
- Keep domain/table rules reusable without forcing full ORM-style models.
