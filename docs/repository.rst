Repository
==========

Introduction
------------

Create a repository:

.. code-block:: php

   $users = DB::repository('users');

Repository is a thin abstraction over ``QueryBuilder`` for table-centric
application code. It adds feature scopes and lifecycle hooks while preserving
access to query-level composition through optional scope callbacks.

.. contents:: On This Page
   :depth: 2
   :local:

Core Methods
------------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``create()``, ``updateById()``, ``deleteById()``
- ``firstOrCreate()``, ``updateOrCreate()``, ``upsert()``

Pattern for scoped reads:

.. code-block:: php

   $active = $users->get(fn ($q) => $q->where('active', '=', 1));

Feature Scopes
--------------

- Tenant: ``forTenant()``, ``withoutTenant()``
- Soft deletes: ``enableSoftDeletes()``, ``withTrashed()``, ``onlyTrashed()``, ``restoreById()``, ``forceDeleteById()``
- Optimistic locking: ``enableOptimisticLocking()``, ``updateByIdWithVersion()``
- Casts: ``setCasts()``
- Hooks: ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``

Soft-delete behavior:

- ``deleteById()`` writes a timestamp when soft deletes are enabled.
- ``withTrashed()`` includes deleted rows.
- ``onlyTrashed()`` limits reads to deleted rows.
- ``forceDeleteById()`` bypasses soft-delete and removes rows permanently.

Optimistic locking behavior:

- ``updateByIdWithVersion()`` updates only when expected version matches.
- Successful update increments the version column.

.. warning::

   Do not mix optimistic locking writes with blind ``updateById()`` on the same
   records unless you intentionally accept lost-update risk.

Mapping
-------

- ``map()``, ``firstMap()``
- ``mapInto(Dto::class)``, ``firstInto(Dto::class)``

``mapInto()`` and ``firstInto()`` map by constructor argument and public
property names. Missing required constructor fields produce clear exceptions.
