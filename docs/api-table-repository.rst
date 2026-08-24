API: TableRepository
====================

Class: ``Infocyph\DBLayer\Repository\TableRepository``

``TableRepository`` provides static repository ergonomics without introducing
Active Record state. Rows remain arrays or explicitly mapped DTOs and repository
behavior stays table/query oriented.

Static dispatch is intentionally limited to:

1. Repository methods.
2. Repository-aware fluent query methods / QueryBuilder shaping.

Infrastructure does not fall through to the ``DB`` facade. Use ``DB`` or the
explicit ``connection()``, ``transaction()``, and raw SQL helpers instead.

Repository-Aware Query Boundary
-------------------------------

``query()`` returns ``RepositoryQuery``. Fluent QueryBuilder operations are
recorded and replayed through repository terminals so repository constraints,
casts, tenancy, soft-delete policy, timestamps, write policy, lifecycle hooks,
and relation projection stay consistent.

``builder()`` / ``rawQuery()`` are explicit raw QueryBuilder escape hatches.
They receive the repository constraints present when the builder is created,
but their terminal result/mutation behavior is QueryBuilder behavior and does
not provide repository result projection or write-policy guarantees.

.. code-block:: php

   $rows = User::query()
       ->where('active', '=', 1)
       ->get();                       // repository-processed Collection

   $raw = User::builder()
       ->where('active', '=', 1)
       ->get();                       // raw QueryBuilder rows

Use ``raw()`` / ``builder()`` when bypassing repository semantics is deliberate,
not as the normal repository workflow.

Compiled Definition Metadata
----------------------------

``TableRepository`` compiles immutable declarative metadata into one
``RepositoryDefinition`` per repository class. Repeated ``query()`` and
``repository()`` calls reuse that definition instead of rebuilding casts,
scopes, and relation declarations on every call.

Runtime query state is never stored in the definition. Tenant values, query
constraints, disabled scopes, transaction state, and loaded relation results
remain instance/request local, making the definition cache safe for long-running
workers.

Supported static metadata:

- ``protected static string $table`` (required)
- ``protected static ?string $connection = null``
- ``protected static string $primaryKey = 'id'``
- ``protected static int $perPage = 15``
- ``protected static int $maxRelationDepth = 3``
- ``protected static array $defaults = []``
- ``protected static array $creatable = []``
- ``protected static array $updatable = []``
- ``protected static bool $timestamps = false``
- ``protected static string $createdAt = 'created_at'``
- ``protected static string $updatedAt = 'updated_at'``

``definition()`` exposes the compiled definition for tooling/introspection.
``flushDefinition()`` invalidates only the calling repository class and is
intended for tests or deliberately dynamic bootstrap configuration. Normal
applications should treat repository metadata as immutable after bootstrap.

Write Policy, Defaults, and Timestamps
--------------------------------------

``$defaults`` are merged into create payloads. ``$creatable`` and ``$updatable``
are separate caller-facing write policies. Empty allowlists mean unrestricted
repository writes; once an allowlist is declared, unexpected attributes throw
``UnwritableAttributeException`` rather than being silently discarded.

Repository-managed timestamps are applied after caller-policy validation, so a
caller does not need timestamp columns in ``$creatable`` / ``$updatable``.
Custom names are supported with ``$createdAt`` and ``$updatedAt``.

.. code-block:: php

   final class Post extends TableRepository
   {
       protected static string $table = 'posts';
       protected static string $primaryKey = 'post_id';

       protected static bool $timestamps = true;
       protected static string $createdAt = 'created_on';
       protected static string $updatedAt = 'updated_on';

       protected static array $defaults = ['status' => 'draft'];
       protected static array $creatable = ['user_id', 'title', 'status'];
       protected static array $updatable = ['title', 'status'];
   }

Pagination Defaults
-------------------

``$perPage`` is used when a repository pagination method omits its page size.
The default is shared across direct and fluent repository entry points:

.. code-block:: php

   $page = Post::paginate();
   $page = Post::query()->paginate();
   $page = Post::query()->simplePaginate();
   $page = Post::query()->cursorPaginate();

Normal, simple, and cursor paginator items preserve repository casts and explicit
relation projections. An explicit page size always overrides ``$perPage``.

Casts
-----

Define casts with ``casts()``. Cast metadata is compiled once per repository
class. Supported repository casts include:

- ``int`` / ``integer``
- ``float`` / ``double`` / ``real``
- ``bool`` / ``boolean``
- ``string``
- ``json`` / ``array``
- ``object``
- ``date``
- ``immutable_date`` / ``date_immutable``
- ``datetime``
- ``immutable_datetime`` / ``datetime_immutable``
- ``decimal:n`` for a fixed scale
- PHP backed-enum class names
- existing one-way callables
- explicit bidirectional ``AttributeCast`` implementations

.. code-block:: php

   protected static function casts(): array
   {
       return [
           'active' => 'boolean',
           'metadata' => 'json',
           'settings' => 'object',
           'amount' => 'decimal:2',
           'scheduled_on' => 'immutable_date',
           'published_at' => 'immutable_datetime',
           'status' => PostStatus::class,
           'token' => new TokenCast(),
       ];
   }

``decimal:n`` preserves ordinary decimal-string precision while normalizing and
rounding to the configured scale. Backed-enum reads return enum cases and writes
persist their backed scalar value. ``AttributeCast`` exposes separate ``get()``
and ``set()`` paths for directional transformations.

Named Global Scopes
-------------------

Declare removable named scopes with ``globalScopes()``:

.. code-block:: php

   protected static function globalScopes(): array
   {
       return [
           'published' => static function (QueryBuilder $query): void {
               $query->whereNotNull('published_at');
           },
       ];
   }

Query-local removal never mutates class/global state:

.. code-block:: php

   $all = Post::query()
       ->withoutGlobalScope('published')
       ->get();

   $all = Post::query()
       ->withoutGlobalScopes()
       ->get();

``configureQuery()`` remains a compatibility/default-query hook and is routed
through the repository constraint pipeline for direct and fluent reads. Prefer
named scopes when selective removal is required.

Relations
---------

Relations are declarative and eager-only. DBLayer does not expose lazy relation
properties, relation-backed entity objects, an identity map, or implicit N+1
queries.

Supported relation definitions:

- ``Relation::belongsTo(...)``
- ``Relation::hasOne(...)``
- ``Relation::hasMany(...)``
- ``Relation::belongsToMany(...)``
- ``Relation::morphOne(...)``
- ``Relation::morphMany(...)``
- ``Relation::morphTo(...)``
- ``Relation::morphToMany(...)``
- ``Relation::morphedByMany(...)``

Polymorphic relations always use explicit discriminator values/maps. A database
value is never interpreted as an arbitrary PHP class name.

.. code-block:: php

   protected static function relations(): array
   {
       return [
           'user' => Relation::belongsTo(User::class, 'user_id'),

           'comments' => Relation::hasMany(
               Comment::class,
               foreignKey: 'post_id',
           ),

           'tags' => Relation::belongsToMany(
               Tag::class,
               pivot: 'post_tag',
               foreignPivotKey: 'post_id',
               relatedPivotKey: 'tag_id',
           )->withPivot('assigned_by', 'priority')->asPivot('assignment'),

           'images' => Relation::morphMany(
               Image::class,
               name: 'imageable',
               morph: 'post',
           ),
       ];
   }

   $posts = Post::query()
       ->with('user', 'comments', 'tags', 'images')
       ->get();

Relation definitions can also constrain their normal query or narrow related
columns with ``constrain()`` / ``select()``.

Constrained Eager Loading
-------------------------

.. code-block:: php

   $posts = Post::query()
       ->with([
           'comments' => static function (QueryBuilder $query): void {
               $query->where('approved', '=', 1);
           },
       ])
       ->get();

Related rows are fetched in bounded batches through the related
``TableRepository``. Related repository scopes, casts, tenancy, soft-delete
policy, and cache configuration therefore remain in force.

Nested Eager Loading
--------------------

Nested eager relation paths are explicit and depth-bounded:

.. code-block:: php

   $posts = Post::query()
       ->with('comments.author')
       ->get();

``$maxRelationDepth`` prevents accidentally unbounded relation graphs. Nested
projection also supports ``morphTo`` targets as long as each mapped target
contains the requested nested relation path.

Pivot Projection
----------------

Many-to-many and polymorphic many-to-many relations may expose only explicitly
requested pivot attributes:

.. code-block:: php

   'tags' => Relation::belongsToMany(
       Tag::class,
       'post_tag',
       'post_id',
       'tag_id',
   )->withPivot('assigned_by', 'priority')->asPivot('assignment');

Each related row then receives an ``assignment`` array containing only those
pivot columns. Parent/related pivot keys are used internally but are not exposed
unless explicitly selected as pivot attributes.

Polymorphic Inverse Relations
-----------------------------

``morphTo()`` requires a strict map:

.. code-block:: php

   'subject' => Relation::morphTo(
       typeColumn: 'subject_type',
       idColumn: 'subject_id',
       morphMap: [
           'post' => Post::class,
           'comment' => Comment::class,
       ],
   );

Unknown discriminator values are rejected rather than converted into class
names.

Relation Aggregates
-------------------

Repository queries can project aggregates without hydrating relation graphs:

- ``withCount()``
- ``withExists()``
- ``withSum()``
- ``withAvg()``
- ``withMin()``
- ``withMax()``
- ``withAggregate()``

.. code-block:: php

   $posts = Post::query()
       ->withCount('comments', 'tags')
       ->withExists('comments')
       ->withSum('comments', 'score')
       ->withAvg('comments', 'score')
       ->get();

Aliases and constraints are supported. ``withCount()`` / ``withExists()`` also
accept the ``relation as alias`` form used by constrained projections.

Direct relations use grouped aggregate reads. Pivot and polymorphic relations
use bounded key/related-value projection while preserving related repository
policy. Library-generated aggregate expressions do not depend on consumer raw
SQL policy.

Aggregates intentionally accept direct declared relation names. Nested eager
loading is the supported multi-hop relation graph mechanism; DBLayer does not
materialize arbitrary multi-hop aggregate key sets in PHP simply to emulate an
ORM dot-path API.

Relation Existence Filters
--------------------------

Direct declared relations support:

- ``whereHas()``
- ``whereDoesntHave()``
- ``whereRelation()``

.. code-block:: php

   $posts = Post::query()
       ->whereRelation('comments', 'score', '>=', 10)
       ->get();

   $posts = Post::query()
       ->whereHas('comments', static function (QueryBuilder $query): void {
           $query->where('approved', '=', 1);
       })
       ->get();

For ordinary same-connection direct relations, DBLayer uses a correlated
``EXISTS`` query. Cross-connection, pivot, and polymorphic variants use bounded
key projection because a portable correlated cross-connection query is not
available. Related repository policy is preserved in both strategies.

Like aggregate projections, existence filters intentionally target a direct
declared relation name rather than silently expanding arbitrary multi-hop
relation paths.

Repository-Aware Fluent Mutations
---------------------------------

``RepositoryQuery`` exposes repository-semantic mutations:

- ``insert()``
- ``insertGetId()``
- ``update()``
- ``delete()``
- ``restore()``
- ``forceDelete()``
- ``upsert()``

.. code-block:: php

   Post::query()
       ->where('status', '=', 'draft')
       ->update(['status' => 'published']);

   Post::query()
       ->where('published_at', '<', $cutoff)
       ->delete();

These operations preserve repository write allowlists, casts, tenant/default
policy where applicable, timestamps, soft deletes, and operation lifecycle
hooks.

QueryBuilder mutation variants without a repository-semantic equivalent are
intentionally blocked on ``RepositoryQuery``. Examples include raw returning
variants, ``insertIgnore()``, and ``truncate()``. Choose ``raw()`` / ``builder()``
explicitly if policy bypass is intended.

Soft-Delete Query Modes
-----------------------

Repository queries provide query-local soft-delete visibility controls:

.. code-block:: php

   Post::query()->withTrashed()->get();
   Post::query()->onlyTrashed()->get();
   Post::query()->withoutTrashed()->get();

``restore()`` and ``forceDelete()`` operate through repository semantics rather
than falling through to raw builder mutations.

Operation Lifecycle and After Commit
------------------------------------

The existing repository ``beforeCreate`` / ``afterCreate``, ``beforeUpdate`` /
``afterUpdate``, and ``beforeDelete`` / ``afterDelete`` hooks remain available.
``TableQueryRepository`` additionally exposes explicit set-based operation hooks:

- ``beforeBulkInsert`` / ``afterBulkInsert``
- ``beforeBulkUpdate`` / ``afterBulkUpdate``
- ``beforeBulkDelete`` / ``afterBulkDelete``
- ``beforeUpsert`` / ``afterUpsert``
- ``beforeRestore`` / ``afterRestore``
- ``beforeForceDelete`` / ``afterForceDelete``

These are operation events, not fabricated per-row model events. One set-based
SQL mutation produces one operation hook pair regardless of affected row count.

``afterCommit()`` registers callbacks for successful repository writes. Inside a
managed transaction the callback runs only after the top-level commit. Nested
savepoint commits promote callbacks to their parent transaction level; rollback
discards callbacks from the rolled-back level. Outside a transaction the
callback runs immediately after the successful operation.

.. code-block:: php

   protected static function configureRepository(Repository $repository): Repository
   {
       $repository->enableSoftDeletes();

       if ($repository instanceof TableQueryRepository) {
           $repository->afterCommit(
               static function (string $operation, array $context): void {
                   // enqueue or publish after durable commit
               },
           );
       }

       return $repository;
   }

Pruning
-------

Pruning is an explicit service, not an implicit model lifecycle:

.. code-block:: php

   $deleted = Post::pruner()->prune(
       static function (RepositoryQuery $query): void {
           $query->where('published_at', '<', $cutoff);
       },
       chunkSize: 1000,
   );

Candidate primary keys are selected through repository-aware query policy in
bounded keyset batches. Each batch is then deleted with one repository mutation,
not an N-query ``deleteById()`` loop.

Normal pruning follows configured soft-delete behavior. ``force: true`` performs
permanent deletion. To force-delete already soft-deleted candidates, include
``withTrashed()`` in the pruning scope explicitly.

Core Methods
------------

- ``definition()`` / ``flushDefinition()``
- ``repository(?string $connection = null)`` / ``repo(...)``
- ``query(?string $connection = null)``
- ``builder(?string $connection = null)`` / ``rawQuery(...)``
- ``connection(?string $connection = null)``
- ``pruner(?string $connection = null)``
- ``transaction(callable $callback, int $attempts = 1, ?string $connection = null)``
- ``sqlSelect(..., ?string $connection = null)``
- ``sqlStatement(..., ?string $connection = null)``
- ``sqlScalar(..., ?string $connection = null)``

Customization Hooks
-------------------

- ``configureRepository(Repository $repository): Repository``
- ``configureQuery(QueryBuilder $query): QueryBuilder``
- ``casts(): array``
- ``globalScopes(): array``
- ``relations(): array``

``casts()``, ``globalScopes()``, and ``relations()`` are declarative metadata and
are compiled once per repository class. Their returned definitions should not
capture request-local mutable state. Scope callbacks themselves execute against
each fresh query, so they may resolve current runtime context when invoked.

Use ``configureRepository()`` for runtime repository policies such as soft
deletes, tenancy, optimistic locking, caching, lifecycle hooks, and default
ordering.

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

``TableRepository`` intentionally does not provide:

- entity/model instances
- identity maps or unit-of-work state
- dirty tracking
- ``save()`` / implicit persistence
- lazy relationship properties
- automatic/default eager relation loading
- instance accessors/mutators tied to mutable model state
- API-resource / HTTP serialization concerns

Rows remain arrays or explicitly mapped DTOs. Relations are deterministic eager
projections, repository mutations remain explicit, and presentation concerns stay
outside the database layer.
