Query Builder
=============

Basic Select
------------

.. code-block:: php

   $rows = DB::table('users')
       ->select('id', 'name', 'email')
       ->where('active', '=', 1)
       ->orderBy('id', 'desc')
       ->limit(20)
       ->get();

Writes
------

.. code-block:: php

   $id = DB::table('users')->insertGetId([
       'name' => 'Alice',
       'email' => 'alice@example.test',
   ]);

   DB::table('users')->where('id', '=', $id)->update(['name' => 'Alice Updated']);

Advanced SQL
------------

- CTE: ``with()``, ``withRecursive()``
- Subquery source: ``fromSub()``
- Window helper: ``selectWindow()``
- Upsert returning: ``upsertReturning()``

Pagination and Streaming
------------------------

- ``paginate()``
- ``simplePaginate()``
- ``cursorPaginate()``
- ``chunk()`` / ``chunkById()``
- ``cursor()``

Locks
-----

- ``lockForUpdate()``
- ``sharedLock()``
