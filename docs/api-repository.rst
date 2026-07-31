API: Repository
===============

Class: ``Infocyph\DBLayer\Query\Repository``

Repository wraps a table with reusable constraints and behavior toggles
(tenant scoping, soft deletes, optimistic locking, casts, hooks). It is useful
when multiple services share the same data access rules.

For API selection guidance, see ``choosing-api`` and ``api-table-repository``.

Read APIs
---------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``exists()``, ``count()``, ``value()``, ``pluck()``, ``groupByKey()``

Pagination/Streaming
--------------------

- ``paginate()``, ``simplePaginate()``, ``cursorPaginate()``
- ``chunk()``, ``chunkById()``, ``lazy()``, ``lazyById()``
- ``cursor()``, ``stream()``, ``unbufferedStream()``

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

``setCasts()`` accepts the built-in names ``int``/``integer``,
``float``/``double``/``real``, ``bool``/``boolean``, ``string``,
``json``/``array``, and ``datetime``, or a callable. Boolean casts understand
native booleans plus the case-insensitive database forms ``1``/``0``,
``t``/``f``, ``true``/``false``, ``yes``/``no``, and ``on``/``off``.
Raw connection and query-builder reads are intentionally not normalized.

Hooks and Mapping
-----------------

- Hooks: ``on()``, ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``
- Mapping: ``map()``, ``firstMap()``, ``mapInto()``, ``firstInto()``
