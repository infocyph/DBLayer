Explicit Relation Loading
=========================

DBLayer remains a non-ORM library. ``RelationLoader`` adds bounded,
application-invoked relation projection without lazy properties, identity
maps, dirty tracking, or hidden queries.

One and Many
------------

.. code-block:: php

   $users = DB::table('users')
       ->select(['id', 'name'])
       ->cursorPaginate(100);

   $loader = DB::relations(connection: 'primary', batchSize: 500);

   $rows = $loader->many(
       parents: $users->items(),
       parentKey: 'id',
       relatedTable: 'posts',
       relatedKey: 'user_id',
       as: 'posts',
       columns: ['id', 'user_id', 'title'],
       scope: static function ($query): void {
           $query->where('published', true)->orderBy('id');
       },
   );

Use ``one`` with the same argument shape to attach the first row or ``null``.
Use ``many`` to attach a list, including an empty list when no row matches.
The related mapping key is automatically added to an explicit projection when
needed.

Many to Many
------------

.. code-block:: php

   $users = $loader->manyToMany(
       parents: $users,
       parentKey: 'id',
       pivotTable: 'role_user',
       pivotParentKey: 'user_id',
       pivotRelatedKey: 'role_id',
       relatedTable: 'roles',
       relatedKey: 'id',
       as: 'roles',
       columns: ['id', 'name'],
   );

Pivot order is retained. Missing related rows are skipped. Duplicate parent
keys are fetched once and projected deterministically onto every parent row.
The optional scope applies to the related-table phase, not the pivot query.

Query Bounds and N+1 Diagnostics
--------------------------------

For ``one`` and ``many``, query count is:

``ceil(unique non-null parent keys / batch size)``

For ``manyToMany``, it is the bounded pivot phase plus the bounded related-key
phase. It never scales to one query per parent.

After an operation, inspect ``lastQueryCount()`` and
``lastRelatedRowCount()``. The first is the number of statements performed by
the last loader operation. For ``one``/``many``, the row count is the number of
fetched related rows; for ``manyToMany``, it is pivot rows plus related rows.
These counters are operation-local and increment only when the loader itself
executes a statement; unrelated connection activity cannot change them.

Use a batch size below the selected driver's parameter limit and below any
configured ``security.max_params``. The default is 500, which is safe
for common SQLite limits while remaining useful for MySQL and PostgreSQL.

Input Contract
--------------

- ``batchSize`` must be a positive integer.
- Parent rows and projections are associative arrays; their original order is
  retained.
- Relation keys may be scalar or ``null``. Missing/null parent keys do not
  produce a lookup.
- An empty parent list performs no query.
- When ``columns`` omits the related mapping key, DBLayer adds it to the SQL
  projection so attachment remains deterministic.
- ``one`` attaches one row or ``null``; ``many`` and ``manyToMany`` attach a
  list.
- ``one`` requires an explicit ``orderBy()`` in its scope when more than one
  related row may match and deterministic selection matters.
- ``manyToMany`` does not promise database-natural pivot ordering. Add an
  explicit ordering facility in application query design before relying on a
  particular related-row sequence.
- Scopes must mutate the supplied ``QueryBuilder``. They must not execute the
  builder or perform per-parent I/O.

Joined Projections
------------------

When the desired result is naturally one SQL rowset, use QueryBuilder's
``join``, ``leftJoin``, ``joinSub``, and aggregate helpers. That is faster than
loading parents and projecting relations afterward.

Use ``RelationLoader`` when parents are already selected, cursor-paginated, or
must preserve their shape/order. This split keeps joined projections in the SQL
builder and bounded prefetching in the relation loader instead of creating two
overlapping relation engines.

Avoiding N+1 Regressions
------------------------

- Do not call the loader inside a per-parent loop.
- Construct it once per projection operation.
- Assert ``lastQueryCount()`` in repository integration tests.
- Keep scopes set-based and indexed.
- Prefer cursor/keyset pagination for large parent collections.
- Load only required columns, but retain relation keys.
- Measure query plans and query-shape telemetry before adding indexes.
