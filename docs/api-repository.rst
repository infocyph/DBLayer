API: Repository
===============

Classes:

- ``Infocyph\DBLayer\Query\Repository``
- ``Infocyph\DBLayer\Query\ConnectionRepository``

``Repository`` wraps a table with reusable constraints and behavior toggles
(tenant scoping, soft deletes, optimistic locking, casts, hooks). It is useful
when multiple services share the same data access rules.

``ConnectionRepository`` is the instance-first base for DI containers and
execution-scoped runtimes. It derives the connection-bound ``Executor`` and a
stateless ``ResultProcessor`` from the supplied ``Connection`` so higher layers
do not need the static ``DB`` facade merely to construct repositories.

For API selection guidance, see ``choosing-api`` and ``api-table-repository``.

Construction
------------

The low-level ``Repository`` constructor accepts all collaborators explicitly:

.. code-block:: php

   public function __construct(
       Connection $connection,
       Executor $executor,
       ResultProcessor $results,
   )

For normal instance-owned application repositories, extend
``ConnectionRepository`` instead:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Query\ConnectionRepository;

   final class UserRepository extends ConnectionRepository
   {
       protected function table(): string
       {
           return 'users';
       }
   }

   $users = new UserRepository($connection);

Its constructor is:

.. code-block:: php

   public function __construct(
       Connection $connection,
       ?Executor $executor = null,
       ?ResultProcessor $results = null,
   )

Explicit overrides remain available for specialized consumers, while the common
path only needs to own the exact ``Connection`` for the current execution.

Read APIs
---------

- ``all()``, ``get()``, ``first()``, ``find()``, ``findMany()``
- ``exists()``, ``count()``, ``value()``, ``pluck()``, ``groupByKey()``

Pagination/Streaming
--------------------

- ``paginate()``, ``simplePaginate()``, ``cursorPaginate()``
- ``chunk()``, ``chunkById()``, ``lazy()``, ``lazyById()``
- ``cursor()``, ``stream()``, ``unbufferedStream()``

Write APIs
----------

- ``create()``, ``bulkInsert()``
- ``updateById()``, ``deleteById()``, ``forceDeleteById()``, ``restoreById()``
- ``firstOrCreate()``, ``updateOrCreate()``, ``upsert()``
- ``updateByIdWithVersion()``

``create()`` returns the freshly reloaded persisted row when DBLayer can locate
it; otherwise it returns the normalized submitted payload. ``findMany()`` uses
driver-aware batches and preserves requested order and duplicates with typed
key identity.

``upsert()`` uses the driver's native/dialect-aware mutation path. Prefer it to
application-level SELECT-then-INSERT/UPDATE flows when true upsert semantics are
required.

Optimistic Conditional Writes
-----------------------------

``updateByIdWithVersion()`` is DBLayer's generic compare-and-swap style update
primitive for versioned rows:

.. code-block:: php

   $updated = $users->updateByIdWithVersion(
       id: $id,
       values: ['credential_record' => $record],
       expectedVersion: $revision,
       versionColumn: 'revision',
   );

The update succeeds only when the primary key and expected version both match;
a successful write advances the version. Application-specific concurrency
semantics remain in the higher layer: DBLayer supplies the conditional write,
not MFA-, passkey-, or domain-specific policy APIs.

Do not replace a required conditional write with a read-before-write ``save()``
flow. The read and write would be separate race windows.

Query Result Cache
------------------

Repository reads can opt into ``cacheFor()``. They use the query cache owned by
the repository's exact connection and receive database-scoped table tags plus
tenant and primary-key record tags where applicable. Successful structured
mutations schedule invalidation after the owning connection's successful
 top-level commit.

Scopes and Features
-------------------

- Tenant: ``forTenant()``, ``withoutTenant()``
- Global scope: ``addGlobalScope()``, ``clearGlobalScopes()``
- Default order: ``setDefaultOrder()``, ``addDefaultOrder()``, ``clearDefaultOrders()``
- Soft deletes: ``enableSoftDeletes()``, ``disableSoftDeletes()``, ``withTrashed()``, ``withoutTrashed()``, ``onlyTrashed()``
- Optimistic locking: ``enableOptimisticLocking()``, ``disableOptimisticLocking()``
- Casts: ``setCasts()``

``setCasts()`` accepts the built-in names ``int``/``integer``,
``float``/``double``/``real``, ``bool``/``boolean``, ``string``,
``json``/``array``, and ``datetime``, or a callable. Boolean casts understand
native booleans plus the case-insensitive database forms ``1``/``0``,
``t``/``f``, ``true``/``false``, ``yes``/``no``, and ``on``/``off``.
Raw connection and query-builder reads are intentionally not normalized.

Hooks and Mapping
-----------------

- Hooks: ``on()``, ``beforeCreate()``, ``afterCreate()``, ``beforeUpdate()``, ``afterUpdate()``, ``beforeDelete()``, ``afterDelete()``
- Mapping: ``map()``, ``firstMap()``, ``mapInto()``, ``firstInto()``

Create hooks cover ``create()`` and individual ``bulkInsert()`` rows. Update
hooks cover explicit repository update and optimistic-update paths; delete hooks
cover soft and force deletion. ``upsert()`` and ``restoreById()`` do not emit
those generic hooks.
