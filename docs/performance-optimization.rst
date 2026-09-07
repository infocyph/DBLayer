Performance Optimization
========================

Purpose
-------

DBLayer provides opt-in tools for measuring query cost and expressing efficient
query shapes. It does not guess indexes, change server settings, or run
maintenance automatically. Those decisions depend on production data
distribution, write volume, database version, and the complete workload.

The reliable optimization loop is:

1. Capture the slow parameterized query shape.
2. Inspect its native execution plan.
3. Verify row estimates, actual rows, loops, sort/hash behavior, and I/O.
4. Change one query, index, statistics, partitioning, or runtime-lifecycle decision.
5. Repeat the plan and an end-to-end benchmark with equivalent results.
6. Keep the change only when sustained application throughput improves without
   correctness or operational regressions.

.. contents:: On This Page
   :depth: 2
   :local:

Execution Plans
---------------

Builder plans retain the builder's bindings:

.. code-block:: php

   $query = DB::table('orders')
       ->select('customer_id', 'total_amount')
       ->where('order_date', '>=', '2024-01-01')
       ->where('order_date', '<', '2024-02-01');

   // Planning only. The underlying SELECT is not executed.
   $plan = $query->explain();

Raw parameterized SQL is also supported:

.. code-block:: php

   $plan = DB::explain(
       'select * from orders where customer_id = ?',
       [$customerId],
       connection: 'reporting',
   );

``explain()`` returns database-native rows rather than flattening away
driver-specific plan information. The raw helper accepts SELECT statements
only; obvious write, DDL, and control statements are rejected before plan
compilation.

Driver Options
~~~~~~~~~~~~~~

All execution-plan options are booleans. Their default and possible values are
``false`` or ``true``:

.. list-table::
   :header-rows: 1

   * - Driver
     - Default plan
     - ``analyze``
     - ``buffers``
     - ``verbose``
   * - PostgreSQL
     - ``EXPLAIN (FORMAT JSON)``
     - Supported
     - Supported when ``analyze=true``
     - Supported
   * - MySQL
     - ``EXPLAIN FORMAT=JSON``
     - Supported by compatible server versions
     - Rejected
     - Rejected
   * - MariaDB
     - ``EXPLAIN FORMAT=JSON``
     - Uses ``ANALYZE FORMAT=JSON``
     - Rejected
     - Rejected
   * - SQLite
     - ``EXPLAIN QUERY PLAN``
     - Rejected
     - Rejected
     - Rejected

PostgreSQL example:

.. code-block:: php

   $plan = $query->explain(
       analyze: true,
       buffers: true,
       verbose: true,
   );

.. warning::

   ``analyze=true`` executes the SELECT and waits for it to finish. It can
   consume substantial CPU, I/O, memory, locks, and connection time. Use a
   representative safe environment, a query timeout, and production-like
   parameters. Never apply it automatically in an HTTP request.

Plans are connection-specific. A replica can have different statistics, data
freshness, configuration, and hardware from the writer. Inspect the same named
connection and environment that serves the workload being diagnosed.

Aggregate Before Joining
------------------------

Joining raw detail rows and aggregating afterward can create a large
intermediate result. Aggregate the selective detail range first when that shape
preserves the required semantics:

.. code-block:: php

   $orderStats = DB::table('orders')
       ->select('customer_id')
       ->selectRaw('count(*) as total_orders')
       ->selectRaw('sum(total_amount) as total_spent')
       ->selectRaw('max(order_date) as last_order_date')
       ->where('order_date', '>=', $start)
       ->where('order_date', '<', $end)
       ->groupBy('customer_id');

   $customers = DB::table('customers')
       ->select('customers.customer_id', 'customers.email')
       ->selectRaw('coalesce(order_stats.total_orders, 0) as total_orders')
       ->selectRaw('coalesce(order_stats.total_spent, 0) as total_spent')
       ->leftJoinSub(
           $orderStats,
           'order_stats',
           'customers.customer_id',
           '=',
           'order_stats.customer_id',
       )
       ->where('customers.status', '=', 'active')
       ->orderBy('total_spent', 'desc')
       ->get();

Available methods:

- ``joinSub()``: ``type`` may be ``inner``, ``left``, or ``right``.
- ``leftJoinSub()``: fixed ``left`` join.
- ``rightJoinSub()``: fixed ``right`` join.

Each accepts a ``QueryBuilder``, a builder callback, or a raw SQL string. Raw
SQL accepts an explicit bindings list and remains subject to
``security.raw_sql_policy``. Builder and callback forms are preferred.

Bindings are emitted in SQL clause order—CTE, SELECT, FROM, JOIN, WHERE, HAVING,
then UNION—regardless of the order in which fluent methods were called.

Preserve Semantics
~~~~~~~~~~~~~~~~~~

A predicate on the nullable side of a ``LEFT JOIN`` changes its behavior when
placed in the outer ``WHERE`` clause. For example:

.. code-block:: sql

   left join orders on customers.customer_id = orders.customer_id
   where orders.order_date >= ?

excludes customers without matching orders and therefore behaves like an inner
join for that predicate. Moving the date predicate into an aggregated joined
subquery retains customers with zero matching orders. Confirm which result the
application actually requires before treating the rewrite as equivalent.

Index Design
------------

DBLayer does not infer or create indexes automatically from observed queries.
After validating the production query shape, declare the selected index
explicitly through ``Blueprint::index()``/``unique()`` in an application
migration.

Practical ordering rules:

- Put equality predicates first.
- Follow with range or ordered columns in the sequence used by the query.
- Add a unique tie-breaker for cursor pagination.
- Use included/covering columns only when reduced heap/table access outweighs
  index size and write amplification.
- Consider a partial index for a stable, selective predicate when the database
  supports it.

The correct column order depends on access shape:

- Repeated per-customer date lookup commonly favors
  ``(customer_id, order_date)``.
- Scanning and aggregating one date range across all customers commonly favors
  ``(order_date, customer_id)``.

Do not create both automatically. Compare native plans, buffers/I/O, write
cost, index size, and sustained application throughput.

Statistics, Partitioning, and Maintenance
-----------------------------------------

The database administrator or migration/deployment layer owns:

- ``ANALYZE`` and statistics-target changes
- autovacuum/statistics policy
- indexes and partial/covering indexes
- table partitioning and partition lifecycle
- bloat measurement and evidence-driven ``REINDEX``
- server/session memory and parallel-worker limits

Avoid routine ``enable_seqscan=off``. It is a diagnostic experiment, not a
production optimization. Avoid copying a large ``work_mem`` value from an
example: PostgreSQL can allocate it per sort/hash operation and per parallel
worker, so concurrency can multiply memory consumption.

Partitioning helps only when partition pruning, retention, maintenance, or
write distribution justifies its operational complexity. It is not a default
replacement for a well-designed index.

Query-Shape Telemetry
---------------------

Telemetry is disabled by default and adds no fingerprinting work until enabled:

.. code-block:: php

   DB::enableTelemetry();

   // Execute representative application work...

   $report = DB::queryShapeReport(
       percentiles: [50, 90, 95, 99],
       minimumMs: 5.0,
       limit: 20,
   );

``percentiles`` accepts values from ``0`` through ``100``; out-of-range values
are clamped. ``minimumMs`` is either ``null`` for all buffered queries or a
duration threshold in milliseconds. ``limit`` is either ``null`` for every
shape or a positive maximum number of shapes; non-positive integers are
normalized to ``1``.

Shapes are sorted by total database time, then maximum duration. Each row
contains:

- ``fingerprint``, ``statement``, ``connection``, and representative ``sql``
- ``calls``, ``success_count``, and ``failure_count``
- ``total_time_ms``, ``mean_time_ms``, ``min_time_ms``, and ``max_time_ms``
- requested ``percentiles``
- ``rows_affected_total`` and ``rows_affected_samples`` for statements whose
  affected-row count is available

Leading DBLayer query comments and whitespace differences are removed before
fingerprinting. Bound parameter values do not affect a prepared statement's
shape. Literal values embedded directly in raw SQL remain part of the
fingerprint; use bindings for both safety and useful aggregation.

The report covers the bounded in-process telemetry buffer, not the entire
database fleet. Export telemetry regularly and use native systems such as
PostgreSQL ``pg_stat_statements`` or the corresponding MySQL monitoring tools
for durable, server-wide evidence.

Connection Lifecycle and Pooling
--------------------------------

Connection lifecycle is a performance decision only after it is a correctness
decision. Persistent runtimes may compare three strategies:

- construct/open/use/disconnect per execution
- keep a dedicated warm connection owned by a long-lived component
- reuse connections through ``PoolManager`` tokenized leases

DBLayer includes ``RuntimeLifecycleBench`` specifically to measure these paths.
Compare full open/query/disconnect cost against checkout/query/release, warm
selects, and prepared-statement reuse before enabling pooling.

Pooling is most relevant to long-running workers and persistent hosts. Typical
PHP-FPM request lifecycles often gain little from a userland pool because the
process/request lifecycle already bounds reuse differently.

When pooling is selected:

- each active ``ConnectionLease`` belongs to exactly one execution scope
- release through the lease rather than a stale bare connection reference
- let DBLayer run ``resetRuntimeStateForReuse()`` before idle reuse
- preserve prepared-statement cache reuse unless measurements show otherwise
- monitor total/active/idle connection counts under sustained concurrency

Do not add application-level locks around ``PoolManager`` as a substitute for
checkout ownership. Pool state is process-local and the lease token is the
intended ownership primitive.

Query Result Cache Performance
------------------------------

Query-result caching is also opt-in. Compare disabled, miss, and hit paths with
the same query and result shape. For scoped runtimes, attach the selected
CacheLayer backend to the exact connection with ``setQueryCache()`` rather than
routing cache correctness through a static facade lookup.

Use memory CacheLayer for DBLayer microbenchmarks. Networked/shared CacheLayer
backends belong in integration/load tests where serialization, transport,
server latency, tag invalidation, and concurrency are all present.

A cache hit that returns stale or cross-topology data is not a performance win.
When independent database deployments share CacheLayer infrastructure, isolate
their CacheLayer namespace/logical connection identity deliberately.

Benchmark Acceptance
--------------------

Plan cost is evidence, not the final performance result. Compare:

- equivalent result rows and semantics
- warm and cold-cache behavior
- database execution time and application fetch/serialization time
- rows examined versus rows returned
- buffer/cache hits and physical reads
- CPU, memory, locks, connection occupancy, and temporary spills
- connection creation versus checkout/release lifecycle cost
- prepared-statement reuse benefit
- query-cache disabled/miss/hit/invalidation cost
- sustained successful RPS/RPM across realistic concurrency
- stable memory and connection count across repeated persistent executions
- write throughput after adding or enlarging indexes

Reject an optimization that improves one isolated plan while reducing complete
application throughput, changing results, exhausting memory/connections, or
moving unacceptable cost into writes and maintenance.
