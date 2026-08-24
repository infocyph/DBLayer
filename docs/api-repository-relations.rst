API: Advanced Repository Relations
==================================

DBLayer repository relations are explicit projections over ``TableRepository``
classes. They remain eager-only and do not introduce mutable model instances,
lazy relation properties, identity maps, or unit-of-work behavior.

Through Relations
-----------------

Use ``Relation::hasManyThrough()`` and ``Relation::hasOneThrough()`` when a
parent reaches the final repository through an intermediate repository.

.. code-block:: php

   final class Country extends TableRepository
   {
       protected static string $table = 'countries';

       protected static function relations(): array
       {
           return [
               'posts' => Relation::hasManyThrough(
                   Post::class,
                   User::class,
                   firstKey: 'country_id',
                   secondKey: 'user_id',
                   localKey: 'id',
                   secondLocalKey: 'id',
               ),
           ];
       }
   }

The key mapping is:

- parent ``localKey`` -> intermediate ``firstKey``
- intermediate ``secondLocalKey`` -> related ``secondKey``

Execution is performed in bounded repository-aware phases. The intermediate and
final ``TableRepository`` policies remain active, including global scopes,
tenancy, soft-delete visibility, casts, and configured query constraints.

Through relations support the normal eager projection surface:

.. code-block:: php

   $countries = Country::query()
       ->with('posts')
       ->get();

Nested eager loading continues from the final repository:

.. code-block:: php

   $countries = Country::query()
       ->with('posts.author')
       ->get();

Through Aggregates and Filters
------------------------------

Declared through relations participate in repository aggregate and existence
operations:

.. code-block:: php

   $countries = Country::query()
       ->withCount('posts')
       ->withSum('posts', 'score')
       ->withAvg('posts', 'score')
       ->withMin('posts', 'score')
       ->withMax('posts', 'score')
       ->get();

   $countries = Country::query()
       ->whereHas('posts')
       ->whereRelation('posts', 'published', '=', 1)
       ->get();

DBLayer does not synthesize a mutable ORM relationship object. When a portable
single correlated query is not available, it uses bounded key projection while
preserving repository policy at each repository boundary.

One Of Many
-----------

A many relation may be converted to a deterministic one-of-many projection:

.. code-block:: php

   protected static function relations(): array
   {
       $orders = Relation::hasMany(Order::class, 'user_id');

       return [
           'latest_order' => $orders->latestOfMany(),
           'oldest_order' => $orders->oldestOfMany(),
           'highest_order' => $orders->one()->ofMany('amount', 'max'),
       ];
   }

``latestOfMany()`` and ``oldestOfMany()`` use the related repository primary key
when no column is supplied. A custom column may be supplied explicitly:

.. code-block:: php

   'latest_order' => $orders->latestOfMany('created_at');

``ofMany()`` accepts ``max`` or ``min`` and an optional candidate-selection
scope:

.. code-block:: php

   'largest_paid_order' => $orders->ofMany(
       'amount',
       'max',
       static function (QueryBuilder $query): void {
           $query->where('paid', '=', 1);
       },
   );

The candidate scope is applied before winner selection. DBLayer uses the related
repository primary key as a deterministic tie breaker when the selected column
has equal values.

One-of-many relations are valid for normal ``hasMany`` relations, polymorphic
``morphMany`` relations, and through relations.

.. code-block:: php

   'latest_image' => Relation::morphMany(
       Image::class,
       name: 'imageable',
       morph: 'post',
   )->latestOfMany('created_at');

   'latest_post' => Relation::hasManyThrough(
       Post::class,
       User::class,
       firstKey: 'country_id',
       secondKey: 'user_id',
   )->latestOfMany('created_at');

Winner-First Filtering
----------------------

Existence constraints on one-of-many relations are evaluated against the
selected winner, not against every historical related row.

.. code-block:: php

   $users = User::query()
       ->whereRelation('latest_order', 'status', '=', 'paid')
       ->get();

If an older order is ``paid`` but the selected latest order is not, that user
does not match. This is intentionally different from applying the predicate to
all candidate rows before winner selection.

One-of-many aggregate projections likewise operate on the selected row only:

.. code-block:: php

   $users = User::query()
       ->withCount('latest_order')
       ->withSum('latest_order', 'amount')
       ->get();

The count is therefore ``0`` or ``1`` and other aggregate projections use the
selected row value.

Narrow Projections
------------------

One-of-many and through relations may narrow final related columns with
``select()``:

.. code-block:: php

   'latest_order' => $orders
       ->latestOfMany('created_at')
       ->select(['status', 'amount']);

DBLayer automatically selects relation keys, ordering columns, and primary-key
tie breakers internally when required. Those internal columns are removed from
the final explicitly narrowed relation payload unless the caller selected them.

Performance Boundary
--------------------

Repository relations are designed to remain bounded and predictable:

- no lazy N+1 relation access
- repository policies remain active on intermediate/final repositories
- large key sets are processed using connection-safe batch sizes
- direct same-connection existence checks use correlated ``EXISTS`` when the
  relation semantics permit it
- through, cross-connection, polymorphic, and one-of-many fallbacks use bounded
  key projection rather than pretending to be a cross-database ORM join
- arbitrary nested aggregate/filter dot paths are intentionally not expanded;
  nested eager loading is the explicit multi-hop graph mechanism
