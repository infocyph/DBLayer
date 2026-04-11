API: TableRepository
====================

Class: ``Infocyph\DBLayer\Repository\TableRepository``

``TableRepository`` provides repository-oriented static ergonomics while staying pure
repository style. It delegates calls by priority:

1. Repository methods
2. QueryBuilder methods
3. DB facade methods (with connection auto-injection when supported)

For scenario-oriented usage patterns, see ``table-repository``.

Because dispatch is repository/query-first, use ``sqlSelect()`` for raw SQL
reads to avoid ambiguity with QueryBuilder ``select()``.

Required/Optional Static Properties
-----------------------------------

- ``protected static string $table`` (required)
- ``protected static ?string $connection = null`` (optional)

Core Methods
------------

- ``repository(?string $connection = null)`` / ``repo(...)``
- ``query(?string $connection = null)`` / ``builder(...)``
- ``connection(?string $connection = null)``
- ``transaction(callable $callback, int $attempts = 1, ?string $connection = null)``
- ``sqlSelect(..., ?string $connection = null)``,
  ``sqlStatement(..., ?string $connection = null)``,
  ``sqlScalar(..., ?string $connection = null)``

Customization Hooks
-------------------

- ``configureRepository(Repository $repository): Repository``
- ``configureQuery(QueryBuilder $query): QueryBuilder``

These hooks let subclasses set reusable defaults such as soft deletes, tenant
scope, optimistic locking, and default ordering.

Scope Pattern
-------------

- ``configureRepository()`` for reusable table policies.
- ``configureQuery()`` for reusable query shape defaults.

.. code-block:: php

   protected static function configureRepository(Repository $repository): Repository
   {
       return $repository->forTenant(10)->enableSoftDeletes();
   }

   protected static function configureQuery(QueryBuilder $query): QueryBuilder
   {
       return $query->where('active', '=', 1);
   }

Connection Pattern
------------------

Set class default connection in static property, and override per-call when
needed:

.. code-block:: php

   final class User extends TableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $defaultRows = User::query()->get();
   $reportRows = User::query('reporting')->get();
   $reportCount = User::sqlScalar('select count(*) from users', [], 'reporting');

DB Interface Access
-------------------

You can call DB facade methods through ``TableRepository`` static dispatch:

.. code-block:: php

   $stats = User::stats();                // forwarded to DB::stats()
   $caps = User::capabilities();          // forwarded to DB::capabilities()
   $rows = User::sqlSelect('select 1');   // explicit raw SQL helper

For raw SQL, prefer ``sqlSelect/sqlStatement/sqlScalar`` to avoid ambiguity with
QueryBuilder ``select()``.

Example
-------

.. code-block:: php

   use Infocyph\DBLayer\Repository\TableRepository;
   use Infocyph\DBLayer\Query\Repository;

   final class User extends TableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';

       protected static function configureRepository(Repository $repository): Repository
       {
           return $repository->enableSoftDeletes()->setDefaultOrder('id', 'desc');
       }
   }

   $one = User::find(1);
   $rows = User::where('active', '=', 1)->limit(20)->get();
   $stats = User::stats();

Non-ORM Scope
-------------

``TableRepository`` is intentionally not an ORM implementation. It does not
provide relationship mapping, unit-of-work, or dirty-state tracking.
