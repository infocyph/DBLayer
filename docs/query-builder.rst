Query Builder
=============

Introduction
------------

The query builder is the primary SQL composition interface. It is designed to
stay explicit: you can always inspect generated SQL and bindings before
execution.

When to Use QueryBuilder
------------------------

Use QueryBuilder when query shape changes often or is naturally SQL-heavy:

- endpoint-specific filtering and sorting
- joins across multiple tables
- ad-hoc reporting queries
- CTE/subquery composition

If you keep repeating the same tenant/soft-delete/default-order rules for one
table, switch to ``repository`` and keep QueryBuilder as an escape hatch.

.. contents:: On This Page
   :depth: 2
   :local:

Basic Select
------------

.. code-block:: php

   $rows = DB::table('users')
       ->select('id', 'name', 'email')
       ->where('active', '=', 1)
       ->orderBy('id', 'desc')
       ->limit(20)
       ->get();

Use ``toSql()`` + ``getBindings()`` during debugging to verify generated SQL:

.. code-block:: php

   $query = DB::table('users')->where('active', '=', 1);
   $sql = $query->toSql();
   $bindings = $query->getBindings();

Writes
------

.. code-block:: php

   $id = DB::table('users')->insertGetId([
       'name' => 'Alice',
       'email' => 'alice@example.test',
   ]);

   DB::table('users')->where('id', '=', $id)->update(['name' => 'Alice Updated']);

For bulk conflict handling, use ``upsert()``. For read-back behavior after
upsert, use ``upsertReturning()`` and rely on capability-aware fallback.

Running SQL Queries
-------------------

For cases where fluent chaining is not required, use raw SQL helpers:

.. code-block:: php

   $rows = DB::select('select * from users where active = ?', [1]);
   $count = DB::scalar('select count(*) from users');

Dynamic Filtering Pattern
-------------------------

.. code-block:: php

   $rows = DB::table('users')
       ->select('id', 'email', 'role', 'active')
       ->when($onlyActive, fn ($q) => $q->where('active', '=', 1))
       ->when($role !== null, fn ($q) => $q->where('role', '=', $role))
       ->when($search !== null && $search !== '', function ($q) use ($search) {
           $term = '%' . $search . '%';

           return $q->whereNested(function ($inner) use ($term) {
               $inner->where('email', 'like', $term)
                   ->orWhere('name', 'like', $term);
           });
       })
       ->orderBy('id', 'desc')
       ->forPage($page, 20)
       ->get();

Advanced SQL
------------

- CTE: ``with()``, ``withRecursive()``
- Subquery source: ``fromSub()``
- Derived-table joins: ``joinSub()``, ``leftJoinSub()``, ``rightJoinSub()``
- Window helper: ``selectWindow()``
- Upsert returning: ``upsertReturning()``
- Native execution plans: ``explain()``

Example CTE:

.. code-block:: php

   $rows = DB::table('orders')
       ->with('big_orders', function ($q): void {
           $q->from('orders')->select('id', 'amount')->where('amount', '>', 1000);
       })
       ->from('big_orders')
       ->selectRaw('count(*) as c')
       ->get();

Reporting Scenario
------------------

.. code-block:: php

   $rows = DB::table('orders')
       ->join('users', 'orders.user_id', '=', 'users.id')
       ->select('users.email')
       ->selectRaw('sum(orders.amount) as total_amount')
       ->selectRaw('count(*) as order_count')
       ->groupBy('users.email')
       ->having('order_count', '>=', 3)
       ->orderBy('total_amount', 'desc')
       ->get();

For selective reporting queries, aggregate detail rows before joining them:

.. code-block:: php

   $totals = DB::table('orders')
       ->select('customer_id')
       ->selectRaw('sum(total_amount) as total_spent')
       ->where('order_date', '>=', $start)
       ->where('order_date', '<', $end)
       ->groupBy('customer_id');

   $rows = DB::table('customers')
       ->leftJoinSub(
           $totals,
           'order_totals',
           'customers.id',
           '=',
           'order_totals.customer_id',
       )
       ->where('customers.status', '=', 'active')
       ->get();

Use ``explain()`` before and after changing the query or its indexes:

.. code-block:: php

   $plan = $query->explain();

   // PostgreSQL only; executes the SELECT:
   $measured = $query->explain(analyze: true, buffers: true);

See ``performance-optimization`` for driver options, safety constraints, index
design, and benchmark acceptance.

Pagination and Streaming
------------------------

- ``paginate()``
- ``simplePaginate()``
- ``cursorPaginate()``
- ``chunk()`` / ``chunkById()``
- ``lazyById()`` / ``lazy()``
- ``cursor()`` / ``stream()`` / ``unbufferedStream()``

Prefer ``chunkById()`` over offset-based chunking for large or changing tables.
It is more stable when rows are inserted/deleted during iteration.

Composite Cursor Pagination
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use a stable business order followed by a unique tie-breaker:

.. code-block:: php

   $page = DB::table('events')
       ->where('tenant_id', '=', $tenantId)
       ->orderBy('created_at', 'desc')
       ->cursorPaginate(
           perPage: 100,
           cursor: $requestCursor,
           uniqueColumn: 'id',
           direction: 'asc',
       );

   $next = $page->nextCursor();
   $previous = $page->previousCursor();

Existing order clauses are retained. If ``id`` is absent it is appended as the
final order, preventing equal ``created_at`` values from being skipped.
Ordered columns must be selected, non-null scalar values. Cursors are bound to
the filter bindings and order definition, so changing tenant, filters, or order
invalidates the token.

Keyset speed still depends on the database index. Put equality filters first,
then the ordered cursor columns in the same sequence. The example above
normally needs ``(tenant_id, created_at, id)``. Verify production shapes with
the database's ``EXPLAIN`` output; cursor syntax cannot compensate for a
sequential scan or filesort.

For resumable jobs, ``lazyById($size, 'id', $checkpoint)`` executes bounded
keyset batches and releases each statement between batches. ``cursor()`` and
``stream()`` hold one PDO statement. ``unbufferedStream()`` adds a driver-level
bounded-memory guarantee but also occupies the connection while active.

Cursor pagination tolerates ordinary concurrent inserts and deletes, but it is
not a historical snapshot. When an export requires one fixed view of the data,
run the traversal inside an explicitly configured repeatable-read transaction.
Long snapshots retain database history and occupy a connection, so prefer
checkpointed ``lazyById()`` jobs when exact snapshot consistency is unnecessary.

Branching Queries Safely
------------------------

QueryBuilder is mutable. Use ``cloneBuilder()`` when you branch from the same
base query.

.. code-block:: php

   $base = DB::table('orders')->where('status', '=', 'paid');

   $today = $base->cloneBuilder()
       ->where('created_at', '>=', $todayStart)
       ->count();

   $thisMonth = $base->cloneBuilder()
       ->where('created_at', '>=', $monthStart)
       ->count();

Locks
-----

- ``lockForUpdate()``
- ``sharedLock()``

Lock syntax is compiled per driver. You can inspect SQL via ``toSql()`` if you
need to verify emitted dialect-specific lock clauses.

.. note::

   Use ``chunkById()`` + deterministic ordering for long-running jobs. It is
   safer than offset pagination under concurrent writes.

See Also
--------

- ``choosing-api`` for DB vs QueryBuilder vs Repository decisions.
- ``repository`` for reusable table policy features.
