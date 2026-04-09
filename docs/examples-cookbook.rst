Examples Cookbook
=================

Introduction
------------

This page is a practical recipe collection for common DBLayer usage patterns.
Each snippet is independent so you can copy only what you need.

Bootstrap Once
--------------

.. code-block:: php

   use Infocyph\DBLayer\DB;

   DB::addConnection([
       'driver' => 'sqlite',
       'database' => ':memory:',
   ], 'main');

   DB::setDefaultConnection('main');

Schema + Seed
-------------

.. code-block:: php

   DB::statement(
       'create table users (
           id integer primary key autoincrement,
           tenant_id integer null,
           email text not null unique,
           name text not null,
           active integer not null default 1,
           version integer not null default 1,
           deleted_at text null
       )',
   );

   DB::table('users')->insert([
       ['tenant_id' => 10, 'email' => 'a@example.test', 'name' => 'Alice', 'active' => 1],
       ['tenant_id' => 10, 'email' => 'b@example.test', 'name' => 'Bob', 'active' => 0],
       ['tenant_id' => 20, 'email' => 'c@example.test', 'name' => 'Cara', 'active' => 1],
   ]);

DB Facade Recipes
-----------------

Raw SQL Select/Scalar/Statement
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $rows = DB::select('select id, email from users where active = ?', [1]);
   $count = DB::scalar('select count(*) from users');
   DB::statement('update users set active = ? where id = ?', [0, 2]);

Transaction Boundary
~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('users')->where('id', '=', 1)->update(['name' => 'Updated']);
       DB::table('users')->insert(['email' => 'd@example.test', 'name' => 'Dina', 'active' => 1]);
   }, attempts: 2);

Connection Capabilities
~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $caps = DB::capabilities();
   $supportsReturning = DB::supportsReturning();
   $stats = DB::stats();

QueryBuilder Recipes
--------------------

Basic Filtering + Sorting
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $active = DB::table('users')
       ->select('id', 'email', 'name')
       ->where('active', '=', 1)
       ->orderBy('id', 'desc')
       ->get();

Dynamic Filters
~~~~~~~~~~~~~~~

.. code-block:: php

   $role = null;
   $search = 'ali';

   $rows = DB::table('users')
       ->when($role !== null, fn ($q) => $q->where('role', '=', $role))
       ->when($search !== null && $search !== '', function ($q) use ($search) {
           $term = '%' . $search . '%';

           return $q->whereNested(function ($inner) use ($term) {
               $inner->where('name', 'like', $term)
                   ->orWhere('email', 'like', $term);
           });
       })
       ->orderBy('id')
       ->get();

Chunking Large Reads
~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   DB::table('users')
       ->orderBy('id')
       ->chunkById(500, function (array $rows): bool {
           // process $rows
           return true;
       }, 'id');

Repository Recipes
------------------

Basic Repository Access
~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $users = DB::repository('users');
   $one = $users->find(1);
   $all = $users->all();

Tenant + Soft Deletes + Optimistic Locking
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $users = DB::repository('users')
       ->forTenant(10)                    // skip if your table has no tenant column
       ->enableSoftDeletes()
       ->enableOptimisticLocking('version');

   $created = $users->create([
       'email' => 'e@example.test',
       'name' => 'Eve',
       'active' => 1,
   ]);

   $ok = $users->updateByIdWithVersion($created['id'], ['name' => 'Eve 2'], 1);

Mapping to DTO
~~~~~~~~~~~~~~

.. code-block:: php

   final class UserDto
   {
       public function __construct(
           public int $id,
           public string $email,
           public string $name,
       ) {}
   }

   $dtos = DB::repository('users')->mapInto(UserDto::class);

TableModel Recipes
------------------

Minimal TableModel Class
~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   use Infocyph\DBLayer\Model\TableModel;
   use Infocyph\DBLayer\Query\QueryBuilder;
   use Infocyph\DBLayer\Query\Repository;

   final class User extends TableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';

       protected static function configureRepository(Repository $repository): Repository
       {
           return $repository->enableSoftDeletes();
       }

       protected static function configureQuery(QueryBuilder $query): QueryBuilder
       {
           return $query->where('active', '=', 1);
       }
   }

Model-Like Calls
~~~~~~~~~~~~~~~~

.. code-block:: php

   $one = User::find(1);                      // repository dispatch
   $rows = User::query()->limit(20)->get();   // query dispatch
   $stats = User::stats();                    // DB facade dispatch

Per-Call Connection Override
~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $reportRows = User::query('reporting')->get();
   $reportCount = User::sqlScalar('select count(*) from users', [], 'reporting');

Observability Recipes
---------------------

Telemetry Snapshot + Flush
~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   DB::enableTelemetry();
   DB::table('users')->limit(1)->get();

   $snapshot = DB::telemetry();      // read only
   $flushed = DB::flushTelemetry();  // read + clear

Listener for Query Events
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   DB::listen(function (array $event): void {
       // $event: query, bindings, time, connection, rows
   });

Caching Recipe
--------------

.. code-block:: php

   $cache = DB::cache();

   $activeUsers = $cache->remember('users:active', function (): array {
       return DB::table('users')->where('active', '=', 1)->get();
   }, 120);

Connection + Replica Recipe
---------------------------

.. code-block:: php

   DB::addConnection([
       'driver' => 'mysql',
       'database' => 'app_db',
       'username' => 'app_user',
       'password' => 'secret',
       'sticky' => true,
       'read_strategy' => 'round_robin',
       'read' => [
           ['host' => 'replica1.internal'],
           ['host' => 'replica2.internal'],
       ],
       'write' => [
           ['host' => 'primary.internal'],
       ],
   ], 'main');

Security Recipe
---------------

.. code-block:: php

   use Infocyph\DBLayer\Security\Security;
   use Infocyph\DBLayer\Security\SecurityMode;

   Security::setMode(SecurityMode::STRICT);

Choosing the Right Recipe
-------------------------

- Prefer ``DB::table()`` for one-off query shape.
- Prefer ``DB::repository()`` for repeated table rules.
- Prefer ``TableModel`` when your app wants class-based static ergonomics.
- Use ``DB`` directly for transactions, capabilities, observability, and raw SQL.

Related Pages
-------------

- ``choosing-api``
- ``table-model``
- ``query-builder``
- ``repository``
- ``transactions``
