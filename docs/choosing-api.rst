Choosing DB, QueryBuilder, and Repository
=========================================

Introduction
------------

DBLayer intentionally exposes three layers:

- ``DB``: process-level orchestration and infrastructure controls.
- ``QueryBuilder``: per-query SQL composition.
- ``Repository``: reusable table-level rules and behavior.

You usually use all three in one application, but for different reasons.

Entry Path (How Most Apps Work)
-------------------------------

Most codebases enter through ``DB`` first, then branch:

1. stay on ``DB`` for infra concerns (transaction boundaries, retries,
   connection capabilities, telemetry/profiler/pooling), or
2. move to ``DB::table()`` for ad-hoc SQL composition, or
3. move to ``DB::repository()`` for reusable table rules.

This is the normal and intended flow in DBLayer.

Quick Decision Matrix
---------------------

.. list-table::
   :header-rows: 1

   * - You Need
     - Use
     - Why
   * - Start/commit transactions, manage connections, inspect capabilities
     - ``DB``
     - Infrastructure concerns live at the facade layer.
   * - Compose a one-off complex query (joins, CTEs, custom select/having)
     - ``QueryBuilder``
     - Maximum query-shaping flexibility.
   * - Reuse tenant/soft-delete/hooks/default-order rules across services
     - ``Repository``
     - Centralized table policy avoids duplicated filters.
   * - Raw SQL execution with bindings
     - ``DB::select()``, ``DB::statement()`` and related helpers
     - Fluent builder is optional; raw SQL stays first-class.
   * - Long-running table scan with stable pagination
     - ``QueryBuilder::chunkById()`` or ``Repository::chunkById()``
     - Keyset chunking is safer than offset paging under writes.

Mental Model
------------

- ``DB`` answers: "How should this run?"
- ``QueryBuilder`` answers: "What SQL should be emitted?"
- ``Repository`` answers: "What table rules must always apply?"

If the question is about operational behavior (timeouts, retries, transactions,
telemetry), start at ``DB``. If it is about SQL shape, start at builder. If it
is about consistent table policies, start at repository.

Practical Scenarios
-------------------

1. Health/Readiness check
~~~~~~~~~~~~~~~~~~~~~~~~~

Use the facade:

.. code-block:: php

   $ok = DB::ping();
   $version = DB::version();

2. API list endpoint with dynamic filters
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use builder for ad-hoc query shape:

.. code-block:: php

   $rows = DB::table('users')
       ->select('id', 'email', 'name')
       ->when($onlyActive, fn ($q) => $q->where('active', '=', 1))
       ->when($role !== null, fn ($q) => $q->where('role', '=', $role))
       ->orderBy('id', 'desc')
       ->forPage($page, 20)
       ->get();

3. Multi-tenant table access used in many services
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use repository so tenant rules are centralized:

.. code-block:: php

   $users = DB::repository('users')->forTenant($tenantId);
   $active = $users->get(fn ($q) => $q->where('active', '=', 1));

4. Soft-delete + restore workflow
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use repository feature toggles:

.. code-block:: php

   $users = DB::repository('users')->enableSoftDeletes();
   $users->deleteById($id);
   $users->restoreById($id);

5. Cross-table reporting query
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use builder for joins and aggregate shaping:

.. code-block:: php

   $rows = DB::table('orders as o')
       ->join('users as u', 'o.user_id', '=', 'u.id')
       ->select('u.email')
       ->selectRaw('count(*) as orders_count')
       ->groupBy('u.email')
       ->having('orders_count', '>=', 5)
       ->get();

6. Transaction boundary with retry attempts
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use facade transaction orchestration; repository/builder calls can live inside:

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
       DB::table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
   }, attempts: 3);

7. Capability-aware write path
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use facade capabilities to branch behavior:

.. code-block:: php

   if (DB::supportsReturning()) {
       $row = DB::table('users')->insertReturning(['email' => $email]);
   } else {
       DB::table('users')->insert(['email' => $email]);
   }

8. Large table backfill job
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use keyset streaming from builder or repository:

.. code-block:: php

   DB::table('events')
       ->orderBy('id')
       ->chunkById(1000, function (array $rows): bool {
           // process rows
           return true;
       }, 'id');

Model-Style App Repository Pattern
----------------------------------

If you want model-like naming in your app, wrap DBLayer repository with
composition:

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

       public function findActiveByEmail(string $email): ?array
       {
           return $this->repo->first(
               fn ($q) => $q->where('active', '=', 1)->where('email', '=', $email)
           );
       }
   }

This keeps domain naming explicit without turning DBLayer into an ORM model
system.

Laravel-Like Static Model Facade (Still Repo Style)
---------------------------------------------------

If you want a class that *feels* like a model API (``User::find()``,
``User::create()``, ``User::query()``), you can build one on top of repository.
This is still not ORM behavior; it is repository delegation.

DBLayer includes this base class directly:

- ``Infocyph\DBLayer\Model\TableModel``

.. code-block:: php

   use Infocyph\DBLayer\Model\TableModel;
   use Infocyph\DBLayer\Query\Repository;

   abstract class AppTableModel extends TableModel
   {
       protected static function configureRepository(Repository $repository): Repository
       {
           return $repository->enableSoftDeletes();
       }
   }

   final class User extends AppTableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $one = User::find(1);
   $recent = User::query()->orderBy('id', 'desc')->limit(20)->get();
   $tenantActive = User::forTenant(10)->get(fn ($q) => $q->where('active', '=', 1));

What this gives:

- model-like class ergonomics
- table and connection mapping in one class
- full repository features (tenant scope, soft deletes, optimistic locking,
  hooks, casts)

What this intentionally does not give:

- ORM relations/identity map/dirty tracking/unit-of-work

TableModel Scenarios
--------------------

Read-Model Connection Split
~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   final class UserReadModel extends TableModel
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'reporting';
   }

Policy Method Pattern
~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   final class User extends TableModel
   {
       protected static string $table = 'users';

       public static function activeForTenant(int $tenantId)
       {
           return static::forTenant($tenantId)
               ->get(fn ($q) => $q->where('active', '=', 1));
       }
   }

One-Off Query Shape
~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $recentEmails = User::query()
       ->select('email')
       ->where('created_at', '>=', $since)
       ->orderBy('id', 'desc')
       ->limit(100)
       ->pluck('email');

Common Pitfalls
---------------

- Treating ``DB`` and ``Repository`` as interchangeable abstractions. They have
  different responsibilities.
- Repeating tenant/soft-delete filters manually in every builder query instead
  of centralizing them in a repository.
- Using offset paging for long-running jobs where ``chunkById()`` would be
  safer.
- Mixing optimistic-locking writes with blind updates on the same rows.

Related Guides
--------------

- See ``query-builder`` for SQL composition patterns.
- See ``repository`` for policy features and lifecycle hooks.
- See ``transactions`` for retry and nested transaction behavior.
- See ``connections`` for replicas, sticky reads, and pooling.
