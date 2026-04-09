TableModel Guide
================

Introduction
------------

``TableModel`` gives model-like static ergonomics while keeping DBLayer's
repository-first architecture.

It is useful when your app wants a class-centric API such as
``User::find(1)`` and ``User::query()->...`` without adopting ORM behavior.

Class: ``Infocyph\DBLayer\Model\TableModel``

When to Use TableModel
----------------------

Use ``TableModel`` when you want:

- one class per table/service boundary
- model-like static method ergonomics
- centralized table + default connection mapping
- reusable repository/query configuration hooks

Avoid it when plain ``DB::table()`` one-off queries are sufficient and you do
not need a class abstraction.

Minimal Setup
-------------

.. code-block:: php

   use Infocyph\DBLayer\Model\TableModel;
   use Infocyph\DBLayer\Query\Repository;

   final class User extends TableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';

       protected static function configureRepository(Repository $repository): Repository
       {
           return $repository->enableSoftDeletes()->setDefaultOrder('id', 'desc');
       }
   }

   $one = User::find(1);                  // Repository dispatch
   $rows = User::query()->limit(20)->get(); // QueryBuilder dispatch
   $stats = User::stats();                // DB facade dispatch

Dispatch Rules
--------------

Unknown static calls resolve by priority:

1. Repository method
2. QueryBuilder method
3. DB facade method

Because repository/query are checked first, use explicit raw SQL helpers for
DB-style raw operations:

- ``sqlSelect()``
- ``sqlStatement()``
- ``sqlScalar()``

Connection Control
------------------

Set a default connection in static property:

.. code-block:: php

   protected static ?string $connection = 'main';

Override per call when needed:

.. code-block:: php

   $defaultRows = User::query()->get();
   $reportRows = User::query('reporting')->get();
   $reportCount = User::sqlScalar('select count(*) from users', [], 'reporting');
   $reportRepoCount = User::repository('reporting')->count();

Scope Boundaries
----------------

Use hooks for clear ownership:

- ``configureRepository()``: table policy and behavior
- ``configureQuery()``: query shape defaults

.. code-block:: php

   use Infocyph\DBLayer\Query\QueryBuilder;
   use Infocyph\DBLayer\Query\Repository;

   protected static function configureRepository(Repository $repository): Repository
   {
       return $repository
           ->forTenant(10)
           ->enableSoftDeletes()
           ->enableOptimisticLocking('version');
   }

   protected static function configureQuery(QueryBuilder $query): QueryBuilder
   {
       return $query->where('active', '=', 1);
   }

Important chain behavior:

- each static call creates a fresh repository/query context
- chain in one expression when applying temporary scopes

.. code-block:: php

   // Good: temporary scope used immediately on same repository instance
   $rows = User::forTenant(42)->get();

   // Not persistent across future static calls:
   User::forTenant(42);
   $count = User::count(); // fresh context; does not reuse previous temporary call

Practical Use Cases
-------------------

Tenant-Aware Service Model
~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   final class User extends TableModel
   {
       protected static string $table = 'users';

       public static function activeForTenant(int $tenantId)
       {
           return static::forTenant($tenantId)
               ->get(fn ($q) => $q->where('active', '=', 1));
       }
   }

Read/Write Split by Model
~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   final class UserWriteModel extends TableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   final class UserReadModel extends TableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'reporting';
   }

Transactional Workflow
~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   User::transaction(function (): void {
       $user = User::find(1);

       if ($user !== null) {
           User::where('id', '=', 1)->update(['name' => 'Updated']);
       }
   }, attempts: 2);

Operational DB Access Through Model
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $health = User::health();
   $caps = User::capabilities();
   $version = User::version();

Raw SQL Through Model
~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $exists = User::sqlScalar('select count(*) from users where email = ?', ['a@example.test']);
   User::sqlStatement('update users set active = ? where id = ?', [0, 10]);
   $rows = User::sqlSelect('select id, email from users where active = ?', [1]);

Non-ORM Boundary
----------------

``TableModel`` is intentionally not ORM:

- no relationship mapping API
- no unit-of-work
- no dirty-state tracking

It is a static delegation layer over DBLayer components.

See Also
--------

- ``api-table-model`` for method reference
- ``choosing-api`` for DB vs QueryBuilder vs Repository decisions
- ``repository`` for repository capabilities used under ``TableModel``
