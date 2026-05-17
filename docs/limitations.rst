Known Limitations
=================

Scope Boundaries
----------------

- DBLayer is not an ORM. It does not provide Active Record entities, relationship
  graph loading, or model lifecycle abstractions.
- DBLayer does not include a migration framework yet.

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
