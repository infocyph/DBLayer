API: Repository
===============

Class: ``Infocyph\DBLayer\Query\Repository``

Repository wraps a table with reusable constraints and behavior toggles
(tenant scoping, soft deletes, optimistic locking, casts, hooks). It is useful
when multiple services share the same data access rules.

Read APIs
---------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``exists()``, ``count()``, ``value()``, ``pluck()``, ``groupByKey()``

Pagination/Streaming
--------------------

- ``paginate()``, ``simplePaginate()``, ``cursorPaginate()``
- ``chunk()``, ``chunkById()``, ``cursor()``, ``lazy()``

Write APIs
----------

- ``create()``, ``bulkInsert()``
- ``updateById()``, ``deleteById()``, ``forceDeleteById()``, ``restoreById()``
- ``firstOrCreate()``, ``updateOrCreate()``, ``upsert()``
- ``updateByIdWithVersion()``

Scopes and Features
-------------------

- Tenant: ``forTenant()``, ``withoutTenant()``
- Global scope: ``addGlobalScope()``, ``clearGlobalScopes()``
- Default order: ``setDefaultOrder()``, ``addDefaultOrder()``, ``clearDefaultOrders()``
- Soft deletes: ``enableSoftDeletes()``, ``disableSoftDeletes()``, ``withTrashed()``, ``withoutTrashed()``, ``onlyTrashed()``
- Optimistic locking: ``enableOptimisticLocking()``, ``disableOptimisticLocking()``
- Casts: ``setCasts()``

Hooks and Mapping
-----------------

- Hooks: ``on()``, ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``
- Mapping: ``map()``, ``firstMap()``, ``mapInto()``, ``firstInto()``
