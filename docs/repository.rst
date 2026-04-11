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

DB vs Repository: Why and What Differs
--------------------------------------

Use ``DB`` as the orchestration entrypoint and ``Repository`` as the
table-level policy layer.

- Use ``DB::table()`` when you need direct query composition, joins, ad-hoc SQL
  shaping, or low-level control per query.
- Use ``DB::repository()`` when multiple call sites must share the same table
  rules (tenant scope, soft deletes, optimistic locking, casts, hooks, default
  ordering, reusable scopes).

Practical split:

- ``DB`` owns connections, transactions, raw SQL helpers, telemetry/profiler,
  pooling, and capability checks.
- ``Repository`` owns reusable behavior for one table while still allowing
  ``QueryBuilder`` access through scoped callbacks and ``builder()``.

Entry Flow from DB
------------------

In practice, most usage enters through ``DB`` and then branches:

- ``DB`` for runtime orchestration (transactions, retries, capabilities,
  observability, pooling)
- ``DB::table()`` for ad-hoc query composition
- ``DB::repository()`` for table-level policy

This is an explicit design choice, not accidental overlap.

When Repository Is the Better Default
-------------------------------------

Use repository-first when your team repeatedly applies the same table rules:

- tenant isolation
- soft-delete visibility rules
- optimistic locking writes
- lifecycle hooks around writes
- default ordering and shared query scopes

This keeps rules in one place instead of spread across many builder chains.

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

Repository-Style App Class (Composition)
-----------------------------------

DBLayer repository is not an ORM. If you want repository-oriented naming, wrap the
repository in an app class:

.. code-block:: php

   use Infocyph\DBLayer\DB;
   use Infocyph\DBLayer\Query\Repository;

   final class UserRepository
   {
       public function __construct(private readonly Repository $repo) {}

       public static function make(int $tenantId): self
       {
           $repo = DB::repository('users')
               ->forTenant($tenantId)
               ->enableSoftDeletes()
               ->setDefaultOrder('id', 'desc');

           return new self($repo);
       }

       public function findByEmail(string $email): ?array
       {
           return $this->repo->first(
               fn ($q) => $q->where('email', '=', $email)
           );
       }

       public function allActive()
       {
           return $this->repo->get(
               fn ($q) => $q->where('active', '=', 1)
           );
       }
   }

.. note::

   ``DB::repository('UserProfiles')`` normalizes to ``user_profiles`` table
   name. This helps keep naming consistent for repository-style class names.

Laravel-Like Repository Surface (Without ORM)
----------------------------------------

If you want static repository-oriented calls while keeping pure repository style, build
on top of DBLayer's built-in ``TableRepository``:

.. code-block:: php

   use Infocyph\DBLayer\Repository\TableRepository;
   use Infocyph\DBLayer\Query\QueryBuilder;
   use Infocyph\DBLayer\Query\Repository;

   abstract class AppTableRepository extends TableRepository
   {
       protected static function configureRepository(Repository $repository): Repository
       {
           return $repository->enableSoftDeletes();
       }
   }

   final class User extends AppTableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $one = User::find(1);
   $active = User::forTenant(10)->get(static fn (QueryBuilder $q) => $q->where('active', '=', 1));
   $reportRows = User::query('reporting')->limit(20)->get();

This preserves repository semantics and avoids accidental ORM expectations.

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

Repository + QueryBuilder Together
----------------------------------

For advanced one-off queries, you can drop to builder without abandoning
repository defaults:

.. code-block:: php

   $users = DB::repository('users')
       ->forTenant($tenantId)
       ->setDefaultOrder('id', 'desc');

   $recent = $users->builder()
       ->where('last_login_at', '>=', $since)
       ->limit(50)
       ->get();

Use this sparingly for SQL-heavy reads. Keep recurring table rules in repository
methods/scopes.

Common Scenarios
----------------

1. Tenant-scoped reads and writes:
   ``DB::repository('users')->forTenant($tenantId)``.
2. Soft-delete lifecycle:
   ``enableSoftDeletes()``, ``withTrashed()``, ``restoreById()``.
3. Concurrent edit safety:
   ``enableOptimisticLocking()`` with ``updateByIdWithVersion()``.
4. DTO output mapping for service layers:
   ``mapInto()`` and ``firstInto()``.

Mapping
-------

- ``map()``, ``firstMap()``
- ``mapInto(Dto::class)``, ``firstInto(Dto::class)``

``mapInto()`` and ``firstInto()`` map by constructor argument and public
property names. Missing required constructor fields produce clear exceptions.
