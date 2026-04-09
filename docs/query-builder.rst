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
- Window helper: ``selectWindow()``
- Upsert returning: ``upsertReturning()``

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

   $rows = DB::table('orders as o')
       ->join('users as u', 'o.user_id', '=', 'u.id')
       ->select('u.email')
       ->selectRaw('sum(o.amount) as total_amount')
       ->selectRaw('count(*) as order_count')
       ->groupBy('u.email')
       ->having('order_count', '>=', 3)
       ->orderBy('total_amount', 'desc')
       ->get();

Pagination and Streaming
------------------------

- ``paginate()``
- ``simplePaginate()``
- ``cursorPaginate()``
- ``chunk()`` / ``chunkById()``
- ``cursor()``

Prefer ``chunkById()`` over offset-based chunking for large or changing tables.
It is more stable when rows are inserted/deleted during iteration.

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
