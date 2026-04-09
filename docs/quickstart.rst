Quick Start
===========

Configure a connection:

.. code-block:: php

   <?php
   declare(strict_types=1);

   use Infocyph\DBLayer\DB;

   require __DIR__ . '/../vendor/autoload.php';

   DB::addConnection([
       'driver' => 'sqlite',
       'database' => ':memory:',
   ]);

Create schema and seed:

.. code-block:: php

   DB::statement('create table users (id integer primary key autoincrement, email text, name text, active integer)');
   DB::table('users')->insert([
       ['email' => 'alice@example.test', 'name' => 'Alice', 'active' => 1],
       ['email' => 'bob@example.test', 'name' => 'Bob', 'active' => 0],
   ]);

Query:

.. code-block:: php

   $active = DB::table('users')
       ->select('id', 'email', 'name')
       ->where('active', '=', 1)
       ->orderBy('id')
       ->get();

Repository:

.. code-block:: php

   $users = DB::repository('users');
   $one = $users->find(1);
