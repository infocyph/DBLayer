API: QueryBuilder
=================

Class: ``Infocyph\DBLayer\Query\QueryBuilder``

``QueryBuilder`` is mutable and chainable. Compose clauses first, then execute
with ``get()``, ``first()``, ``update()``, ``delete()``, and related methods.
Use ``cloneBuilder()`` or ``newQuery()`` when branching logic needs isolation.

For API selection guidance, see ``choosing-api`` and ``api-table-repository``.

Select/Read
-----------

- ``table()``, ``from()``, ``fromSub()``, ``as()``
- ``select()``, ``addSelect()``, ``addSelectAs()``, ``selectRaw()``, ``selectWindow()``
- ``get()``, ``first()``, ``find()``, ``firstWhere()``, ``exists()``
- ``collect()``, ``lazyCollection()``
- ``value()``, ``pluck()``, ``count()``, ``min()``, ``max()``, ``avg()``, ``sum()``, ``aggregate()``
- ``explain()``

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
- ``joinAs()``, ``leftJoinAs()``, ``rightJoinAs()``, ``crossJoinAs()``, ``joinComplexAs()``
- ``joinSub()``, ``leftJoinSub()``, ``rightJoinSub()``
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

``truncate()`` preserves identity state and never cascades. Cache invalidation
is deferred until the outer transaction commits.

Pagination/Streaming
--------------------

- ``paginate()``, ``simplePaginate()``, ``cursorPaginate()``
- ``chunk()``, ``chunkById()``, ``lazyById()``
- ``cursor()``, ``stream()``, ``unbufferedStream()``

``cursorPaginate()`` retains existing ordering and appends the supplied unique
column as its final tie-breaker. Returned ``next_cursor`` and
``previous_cursor`` tokens are opaque and query-bound. It supports at most eight
order columns. In joined queries, colliding qualified result columns must use
``addSelectAs()`` so every cursor value maps to one output key.

Caching
-------

- ``cacheFor()``, ``cacheTags()``, ``cacheKey()``, ``withoutCache()``

Caching is opt-in. ``cacheKey()`` adds caller identity but never replaces the
compiled SQL and binding identity.

Other
-----

- ``lockForUpdate()``, ``sharedLock()``
- ``when()``, ``unless()``
- ``toSql()``, ``toSelectSql()``, ``toPayload()``
- ``getBindings()``, ``getComponents()``
