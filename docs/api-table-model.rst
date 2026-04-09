API: TableModel
===============

Class: ``Infocyph\DBLayer\Model\TableModel``

``TableModel`` provides model-like static ergonomics while staying pure
repository style. It delegates calls by priority:

1. Repository methods
2. QueryBuilder methods
3. DB facade methods (with connection auto-injection when supported)

Because dispatch is repository/query-first, use ``sqlSelect()`` for raw SQL
reads to avoid ambiguity with QueryBuilder ``select()``.

Required/Optional Static Properties
-----------------------------------

- ``protected static string $table`` (required)
- ``protected static ?string $connection = null`` (optional)

Core Methods
------------

- ``repository()`` / ``repo()``
- ``query()`` / ``builder()``
- ``connection()``
- ``transaction()``
- ``sqlSelect()``, ``sqlStatement()``, ``sqlScalar()``

Customization Hooks
-------------------

- ``configureRepository(Repository $repository): Repository``
- ``configureQuery(QueryBuilder $query): QueryBuilder``

These hooks let subclasses set reusable defaults such as soft deletes, tenant
scope, optimistic locking, and default ordering.

Example
-------

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

   $one = User::find(1);
   $rows = User::where('active', '=', 1)->limit(20)->get();
   $stats = User::stats();

Non-ORM Scope
-------------

``TableModel`` is intentionally not an ORM model implementation. It does not
provide relationship mapping, unit-of-work, or dirty-state tracking.
