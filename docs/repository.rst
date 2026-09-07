Repository
==========

Introduction
------------

Repository is a thin table-centric abstraction over ``QueryBuilder``. It adds
reusable policy such as tenant scope, soft deletes, optimistic locking, casts,
hooks, default ordering, and query-result cache policy while preserving direct
builder access for advanced SQL composition.

DBLayer supports both facade-created repositories and instance-owned
repositories.

Facade Repository
-----------------

.. code-block:: php

   $users = DB::repository('users');

Use this when your application intentionally uses the process-level ``DB``
facade as its connection/orchestration boundary.

Instance-Owned Repository
-------------------------

For DI containers, workers, Fiber/interleaved runtimes, or higher-level
frameworks that already own an exact ``Connection``, extend
``ConnectionRepository``:

.. code-block:: php

   use Infocyph\DBLayer\Query\ConnectionRepository;

   final class UserRepository extends ConnectionRepository
   {
       protected function table(): string
       {
           return 'users';
       }
   }

   $users = new UserRepository($connection);

``ConnectionRepository`` derives the connection-bound ``Executor`` and a
stateless ``ResultProcessor`` internally. The higher layer therefore does not
need ``DB::resultProcessor()`` or any other static facade state just to build a
repository.

Use the same exact connection for the repository, transaction boundary, query
cache, and execution cleanup lifecycle.

When Repository Is the Better Default
-------------------------------------

Use repository-first when multiple call sites must apply the same rules:

- tenant isolation
- soft-delete visibility rules
- optimistic locking writes
- lifecycle hooks around writes
- default ordering and shared query scopes
- connection-owned query-cache policy

This keeps table rules in one place instead of duplicating filters and
read-before-write logic across application adapters.

.. contents:: On This Page
   :depth: 2
   :local:

Core Methods
------------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``create()``, ``updateById()``, ``deleteById()``
- ``firstOrCreate()``, ``updateOrCreate()``, ``upsert()``
- ``updateByIdWithVersion()``
- ``cacheFor()`` for opt-in table/tenant/record-tagged reads

``findMany()`` deduplicates database lookup keys, applies driver/security
parameter ceilings, and restores the caller's requested identifier order (and
duplicates) when the primary key is selected. Its internal identity encoding
keeps integer ``1`` distinct from string ``"1"``; database coercion can still
make both values resolve to the same physical row on permissive schemas.

``create()`` first attempts to reload the persisted row by submitted primary
key, normalized ``lastInsertId()``, or submitted attributes. If read-back is
not possible, it returns the cast, tenant-enriched submitted payload. Callers
that require database defaults must ensure the row can be uniquely reloaded.

Pattern for scoped reads:

.. code-block:: php

   $active = $users->get(
       static fn ($q) => $q->where('active', '=', 1),
   );

Native Upsert
-------------

Use ``upsert()`` for driver-aware insert-or-update semantics instead of
application-level SELECT-then-INSERT/UPDATE flows:

.. code-block:: php

   $users->upsert(
       [['email' => $email, 'name' => $name]],
       ['email'],
       ['name'],
   );

DBLayer owns dialect/capability handling for the mutation. Higher layers should
not duplicate an upsert abstraction unless they are adding domain policy rather
than SQL mechanics.

Optimistic Conditional Writes
-----------------------------

For rows that require compare-and-swap style persistence, use
``updateByIdWithVersion()``:

.. code-block:: php

   $updated = $users->updateByIdWithVersion(
       id: $id,
       values: ['payload' => $payload],
       expectedVersion: $revision,
       versionColumn: 'revision',
   );

The write matches both primary key and expected version and advances the version
on success. This is the generic lower-level primitive for application-level
concurrency policy such as profile revisions, token records, MFA factors, or
passkey credential records.

DBLayer should not gain domain-specific CAS APIs for those use cases; the host
owns domain semantics and DBLayer owns the conditional database mutation.

.. warning::

   Do not replace a required conditional write with a SELECT followed by a blind
   update. The two operations create a lost-update race window.

Feature Scopes
--------------

- Tenant: ``forTenant()``, ``withoutTenant()``
- Soft deletes: ``enableSoftDeletes()``, ``withTrashed()``, ``onlyTrashed()``, ``restoreById()``, ``forceDeleteById()``
- Optimistic locking: ``enableOptimisticLocking()``, ``updateByIdWithVersion()``
- Casts: ``setCasts()``
- Hooks: ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``

Hook coverage is intentionally explicit: ``create()`` and each ``bulkInsert()``
row run create hooks; ``updateById()``, ``updateOrCreate()``'s update branch,
and optimistic updates run update hooks exactly once; ``deleteById()`` and
``forceDeleteById()`` run delete hooks. ``upsert()`` and ``restoreById()`` bypass
these hooks because they are dedicated bulk/state-transition operations.

Built-in casts are ``int``/``integer``, ``float``/``double``/``real``,
``bool``/``boolean``, ``string``, ``json``/``array``, and ``datetime``.
Boolean casts normalize native booleans and common database representations:
``1``, ``t``, ``true``, ``yes``, and ``on`` are true; ``0``, ``f``,
``false``, ``no``, ``off``, and an empty string are false. Matching is
case-insensitive and ignores surrounding whitespace. ``null`` remains
``null``. Raw ``Connection`` and ``QueryBuilder`` results retain PDO-native
value types.

Query Cache Policy
------------------

Repository reads opt into the CacheLayer-backed query cache with ``cacheFor()``.
The repository uses the cache attached to its exact connection and adds table,
tenant, and primary-key record tags where applicable.

Structured repository writes flow through QueryBuilder mutation semantics and
schedule tag invalidation after the successful top-level commit. Rollback does
not evict valid cached data.

Repository + QueryBuilder Together
----------------------------------

For advanced one-off queries, drop to builder without abandoning repository
defaults:

.. code-block:: php

   $users = (new UserRepository($connection))
       ->forTenant($tenantId)
       ->setDefaultOrder('id', 'desc');

   $recent = $users->builder()
       ->where('last_login_at', '>=', $since)
       ->limit(50)
       ->get();

Keep recurring table rules in repository methods/scopes and SQL shape in the
builder.

Static TableRepository Surface
------------------------------

If you intentionally want static repository-oriented calls while keeping
repository semantics, use ``TableRepository``:

.. code-block:: php

   use Infocyph\DBLayer\Repository\TableRepository;

   final class User extends TableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $one = User::find(1);
   $active = User::forTenant(10)->get(
       static fn ($q) => $q->where('active', '=', 1),
   );

This preserves repository semantics and does not introduce ORM identity maps,
dirty tracking, or a unit of work.

Mapping
-------

- ``map()``, ``firstMap()``
- ``mapInto(Dto::class)``, ``firstInto(Dto::class)``

``mapInto()`` and ``firstInto()`` map by constructor argument and public
property names. Missing required constructor fields produce clear exceptions.
