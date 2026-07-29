Known Limitations
=================

Scope Boundaries
----------------

- DBLayer is not an ORM. It does not provide Active Record entities, lazy
  relationship properties, identity maps, dirty tracking, or entity lifecycle
  abstractions. Its relation loader is explicit and array-based.
- SQLite cannot add/drop foreign keys or add primary keys to existing tables
  through native ``ALTER TABLE``. DBLayer fails explicitly instead of silently
  rebuilding a table.
- SQLite cannot natively change a column definition or rename an index.
  DBLayer fails explicitly instead of using a hidden table-copy operation.
- MySQL/MariaDB cannot guarantee transactional DDL. A failed migration can
  require operator inspection and repair before retry.
- ``geography`` and ``vector`` are PostgreSQL-specific in DBLayer and require
  PostGIS/pgvector provisioning. ``geometry`` requires a native MySQL spatial
  type or PostgreSQL PostGIS. SQLite spatial extensions are not assumed.
- ``set`` is a MySQL/MariaDB-specific type. PostgreSQL and SQLite reject it
  rather than silently changing its data-integrity semantics.
- ``uuid`` and ``ulid`` schema helpers declare storage only. DBLayer does not
  generate values. PostgreSQL/MySQL database defaults are version- and
  driver-specific, while portable ULID generation belongs in the application.
- DBLayer does not create partitions or change database statistics and server
  settings automatically. Apply evidence-backed maintenance through explicit
  application migrations/deployment procedures.

Connection and Consistency Notes
--------------------------------

- Connection pooling is most useful in long-running workers and daemons. In
  classic PHP-FPM request lifecycles, pooled reuse is usually less impactful.
- Read-replica consistency is not guaranteed by default. For read-after-write
  behavior, use sticky mode, transactions, or force reads to write PDO.
- Read-only transaction mode is best-effort and driver-dependent.
  SQLite is effectively a no-op for transaction read-only flags, while
  MySQL/PostgreSQL use best-effort session/transaction commands.

Performance Notes
-----------------

- Statement cache is intentionally disabled by default. Enable it only after
  benchmark and lifecycle validation for your driver/workload.
- Query comments are useful for tracing, but still add SQL text overhead.
  Measure with comments on/off before enabling at high throughput.
