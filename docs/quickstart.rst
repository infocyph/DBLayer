Quick Start
===========

This section builds a minimal flow: connect, write, read, and query with
conditions. It uses SQLite in-memory so you can run it without external setup.

1. Configure a Connection
-------------------------

.. code-block:: php

   <?php
   declare(strict_types=1);

   use Infocyph\DBLayer\DB;

   require __DIR__ . '/../vendor/autoload.php';

   DB::addConnection([
       'driver' => 'sqlite',
       'database' => ':memory:',
   ]);

2. Create Schema and Seed Data
------------------------------

.. code-block:: php

   DB::statement(
       'create table users (
           id integer primary key autoincrement,
           email text not null unique,
           name text not null,
           active integer not null default 1
       )',
   );

   DB::table('users')->insert([
       ['email' => 'alice@example.test', 'name' => 'Alice', 'active' => 1],
       ['email' => 'bob@example.test', 'name' => 'Bob', 'active' => 0],
   ]);

3. Read with Query Builder
--------------------------

.. code-block:: php

   $active = DB::table('users')
       ->select('id', 'email', 'name')
       ->where('active', '=', 1)
       ->orderBy('id')
       ->get();

4. Write with Transaction Safety
--------------------------------

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('users')->insert([
           'email' => 'carol@example.test',
           'name' => 'Carol',
           'active' => 1,
       ]);
   });

5. Read with Repository API
---------------------------

.. code-block:: php

   $users = DB::repository('users');
   $one = $users->find(1);

6. Pick the Right API for New Work
----------------------------------

Use this rule of thumb:

- enter through ``DB`` first, then decide if you stay on facade methods or
  branch to builder/repository
- ``DB`` for infrastructure concerns (transactions, retries, telemetry, pooling).
- ``DB::table()`` / ``QueryBuilder`` for ad-hoc SQL shape.
- ``DB::repository()`` for reusable table policies.

.. code-block:: php

   DB::transaction(function (): void {
       $users = DB::repository('users');
       $firstActive = $users->first(fn ($q) => $q->where('active', '=', 1));

       if ($firstActive !== null) {
           DB::table('users')
               ->where('id', '=', $firstActive['id'])
               ->update(['name' => 'Updated in TX']);
       }
   });

What To Do Next
---------------

- Move to ``choosing-api`` for a full scenario matrix.
- Move to ``configuration`` for multi-connection and security options.
- Move to ``connections`` for replica and pooling behavior.
- Move to ``query-builder`` and ``repository`` for advanced usage patterns.
