API: TableRepository
====================

Class: ``Infocyph\DBLayer\Repository\TableRepository``

``TableRepository`` provides static repository ergonomics without introducing
Active Record state. Static dispatch is intentionally limited to:

1. Repository methods
2. Repository-aware fluent query methods / QueryBuilder shaping

Infrastructure does not fall through to the ``DB`` facade. Use ``DB`` or the
explicit ``connection()``, ``transaction()``, and raw SQL helpers instead.

Repository-Aware Query Boundary
-------------------------------

``query()`` returns ``RepositoryQuery``. Fluent QueryBuilder operations are
replayed through repository terminal methods so casts, named scopes, tenancy,
soft-delete policy, cache configuration and repository result processing stay
consistent.

``builder()`` / ``rawQuery()`` return the underlying QueryBuilder escape hatch.
They keep repository query constraints that were already applied, but terminal
results are intentionally raw QueryBuilder results and therefore skip
repository result casting/projection.

.. code-block:: php

   $rows = User::query()
       ->where('active', '=', 1)
       ->get();                       // repository-processed Collection

   $raw = User::builder()
       ->where('active', '=', 1)
       ->get();                       // raw QueryBuilder rows

Definition Metadata
-------------------

``TableRepository`` supports declarative table policy metadata:

- ``protected static string $table`` (required)
- ``protected static ?string $connection = null``
- ``protected static string $primaryKey = 'id'``
- ``protected static array $defaults = []``
- ``protected static array $creatable = []``
- ``protected static array $updatable = []``
- ``protected static bool $timestamps = false``
- ``protected static string $createdAt = 'created_at'``
- ``protected static string $updatedAt = 'updated_at'``

Empty create/update allowlists mean unrestricted repository writes. Once an
allowlist is declared, unexpected caller attributes throw
``UnwritableAttributeException`` instead of being silently discarded.
System-managed timestamps are added after caller allowlist validation.

Casts
-----

Define casts with ``casts()``. Existing scalar/callable casts remain supported,
with additional repository-aware behavior for backed enums, immutable dates and
explicit bidirectional custom casts implementing
``Infocyph\DBLayer\Repository\Casts\AttributeCast``.

.. code-block:: php

   protected static function casts(): array
   {
       return [
           'active' => 'boolean',
           'metadata' => 'json',
           'published_at' => 'immutable_datetime',
           'status' => PostStatus::class,
           'token' => new TokenCast(),
       ];
   }

Backed-enum reads return enum cases; repository writes persist the backed scalar
value. ``AttributeCast`` has separate ``get()`` and ``set()`` paths so custom
casts do not need to guess whether they are handling a read or write.

Named Global Scopes
-------------------

Declare reusable named scopes with ``globalScopes()``:

.. code-block:: php

   protected static function globalScopes(): array
   {
       return [
           'published' => static function (QueryBuilder $query): void {
               $query->whereNotNull('published_at');
           },
       ];
   }

Scopes can be disabled for one repository query without mutating class/global
state:

.. code-block:: php

   $all = Post::query()
       ->withoutGlobalScope('published')
       ->get();

   $all = Post::query()
       ->withoutGlobalScopes()
       ->get();

``configureQuery()`` remains a compatibility/default-query hook and now runs
through the repository constraint pipeline for both direct repository terminals
and fluent queries. Prefer named ``globalScopes()`` when selective removal is
needed.

Relations
---------

Relations are declarative and eager-only. They never create model objects or
lazy property queries.

Supported definitions:

- ``Relation::belongsTo(...)``
- ``Relation::hasOne(...)``
- ``Relation::hasMany(...)``
- ``Relation::belongsToMany(...)``

.. code-block:: php

   protected static function relations(): array
   {
       return [
           'user' => Relation::belongsTo(
               User::class,
               foreignKey: 'user_id',
           ),
           'comments' => Relation::hasMany(
               Comment::class,
               foreignKey: 'post_id',
               localKey: 'post_id',
           ),
       ];
   }

   $posts = Post::query()
       ->with('user', 'comments')
       ->get();

Constrained eager loading is explicit:

.. code-block:: php

   $posts = Post::query()
       ->with([
           'comments' => static function (QueryBuilder $query): void {
               $query->where('approved', '=', 1);
           },
       ])
       ->get();

Related rows are fetched in bounded batches through the related
``TableRepository``. Related repository scopes and casts therefore remain in
force. There is no N+1 lazy-loading path.

Relation Counts
---------------

``withCount()`` adds relation counts without hydrating related rows:

.. code-block:: php

   $posts = Post::query()
       ->withCount('comments')
       ->get();

   $posts = Post::query()
       ->withCount([
           'comments as pending_comments_count' => static function (QueryBuilder $query): void {
               $query->where('approved', '=', 0);
           },
       ])
       ->get();

Direct relations use grouped aggregate queries. Many-to-many counting projects
pivot keys and validates related keys through the related repository so related
repository policy is not bypassed.

Core Methods
------------

- ``repository(?string $connection = null)`` / ``repo(...)``
- ``query(?string $connection = null)``
- ``builder(?string $connection = null)`` / ``rawQuery(...)``
- ``connection(?string $connection = null)``
- ``transaction(callable $callback, int $attempts = 1, ?string $connection = null)``
- ``sqlSelect(..., ?string $connection = null)``
- ``sqlStatement(..., ?string $connection = null)``
- ``sqlScalar(..., ?string $connection = null)``

Repository pagination through ``TableRepository`` preserves repository row
casts for length-aware and simple pagination. Explicit relation projections are
also attached to those page items.

Customization Hooks
-------------------

- ``configureRepository(Repository $repository): Repository``
- ``configureQuery(QueryBuilder $query): QueryBuilder``
- ``casts(): array``
- ``globalScopes(): array``
- ``relations(): array``

Use ``configureRepository()`` for repository policies such as soft deletes,
tenancy, optimistic locking, cache configuration, lifecycle hooks and default
ordering.

.. code-block:: php

   protected static function configureRepository(Repository $repository): Repository
   {
       return $repository
           ->enableSoftDeletes()
           ->setDefaultOrder('id', 'desc');
   }

Connection Pattern
------------------

Set a class default connection and override it explicitly per call when needed:

.. code-block:: php

   final class User extends TableRepository
   {
       protected static string $table = 'users';
       protected static ?string $connection = 'main';
   }

   $defaultRows = User::query()->get();
   $reportRows = User::query('reporting')->get();
   $reportCount = User::sqlScalar('select count(*) from users', [], 'reporting');

Infrastructure Access
---------------------

Infrastructure stays explicit:

.. code-block:: php

   $stats = DB::stats('main');
   $caps = User::connection()->getCapabilities();
   $rows = User::sqlSelect('select 1');

Non-ORM Scope
-------------

``TableRepository`` does not provide entity instances, identity maps, dirty
tracking, unit-of-work, ``save()``, lazy relation properties, implicit relation
loading, or HTTP/API resource serialization. Rows remain arrays or explicitly
mapped DTOs. Repository relations are deterministic eager projections only.
