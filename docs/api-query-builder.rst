API: QueryBuilder
=================

Class: ``Infocyph\DBLayer\Query\QueryBuilder``

``QueryBuilder`` is mutable and chainable. Compose clauses first, then execute
with ``get()``, ``first()``, ``update()``, ``delete()``, and related methods.
Use ``cloneBuilder()`` or ``newQuery()`` when branching logic needs isolation.

Select/Read
-----------

- ``table()``, ``from()``, ``fromSub()``
- ``select()``, ``addSelect()``, ``selectRaw()``, ``selectWindow()``
- ``get()``, ``first()``, ``find()``, ``firstWhere()``, ``exists()``
- ``value()``, ``pluck()``, ``count()``, ``min()``, ``max()``, ``avg()``, ``sum()``, ``aggregate()``

Filters
-------

- ``where()``, ``orWhere()``
- ``whereIn()``, ``whereNotIn()``
- ``whereBetween()``, ``whereNotBetween()``
- ``whereNull()``, ``whereNotNull()``
- ``whereRaw()``, ``whereExists()``, ``whereNested()``

Join and Set Operations
-----------------------

- ``join()``, ``leftJoin()``, ``rightJoin()``, ``crossJoin()``, ``joinComplex()``
- ``union()``, ``unionAll()``

CTE
---

- ``with()``
- ``withRecursive()``

Writes
------

- ``insert()``, ``insertGetId()``, ``insertIgnore()``, ``insertReturning()``
- ``update()``, ``delete()``, ``truncate()``
- ``upsert()``, ``upsertReturning()``

Pagination/Streaming
--------------------

- ``paginate()``, ``simplePaginate()``, ``cursorPaginate()``
- ``chunk()``, ``chunkById()``, ``cursor()``

Other
-----

- ``lockForUpdate()``, ``sharedLock()``
- ``when()``, ``unless()``
- ``toSql()``, ``toSelectSql()``, ``toPayload()``
- ``getBindings()``, ``getComponents()``
