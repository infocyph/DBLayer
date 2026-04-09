Repository
==========

Create a repository:

.. code-block:: php

   $users = DB::repository('users');

Core Methods
------------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``create()``, ``updateById()``, ``deleteById()``
- ``firstOrCreate()``, ``updateOrCreate()``, ``upsert()``

Feature Scopes
--------------

- Tenant: ``forTenant()``, ``withoutTenant()``
- Soft deletes: ``enableSoftDeletes()``, ``withTrashed()``, ``onlyTrashed()``, ``restoreById()``, ``forceDeleteById()``
- Optimistic locking: ``enableOptimisticLocking()``, ``updateByIdWithVersion()``
- Casts: ``setCasts()``
- Hooks: ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``

Mapping
-------

- ``map()``, ``firstMap()``
- ``mapInto(Dto::class)``, ``firstInto(Dto::class)``
