Upgrading to DBLayer 6.0
========================

DBLayer 6.0 tightens correctness contracts around tenancy, cache isolation,
transactions, lazy resource ownership, and persistent runtimes. It also raises
the direct dependency floors to ArrayKit ``^5.3`` and CacheLayer ``^4.0``.

Dependency Changes
------------------

Applications upgrading to 6.0 must be able to resolve:

- PHP ``^8.4``
- ``infocyph/arraykit`` ``^5.3``
- ``infocyph/cachelayer`` ``^4.0``
- ``psr/log`` ``^3.0.2``

Runwire remains optional in production. Install a compatible Runwire 2.1
release only when the host uses DBLayer's explicit runtime integration.

Structured SQL and Tenant Writes
--------------------------------

Aggregate function names are now structured identifiers. SQL fragments such as
parentheses, comments, or clauses are rejected from the aggregate-function
parameter. Use ordinary aggregate names such as ``COUNT`` or ``MAX`` and keep
explicit SQL expressions on the existing raw-expression path.

A tenant-scoped repository no longer accepts a payload that changes its tenant
identity. This applies after write casts and hooks as well as to create, update,
upsert, update-or-create, bulk, and optimistic write paths. Administrative
cross-tenant writes must use an explicitly unscoped repository after the
application has performed its authorization check.

Tenant-scoped ``upsert()`` also has driver-specific conflict safeguards:

- MySQL/MariaDB tenant-scoped upserts are rejected because duplicate-key
  resolution can target an unrelated unique key.
- On drivers with explicit conflict targets, the tenant column must be present
  in ``uniqueBy``.
- An explicit upsert update-column list cannot contain the tenant column.

Result Cache Identity
---------------------

Result-cache identities are versioned and include the logical database scope,
including PostgreSQL schema, database role identity, and optional
``cache_scope``. Upgrading therefore causes an intentional cold transition for
affected cached reads.

Use ``cache_scope`` when session policy such as RLS, role switching, tenant
security context, or another application-level boundary can change result
visibility without changing the normal connection identity. Do not put secrets
or credentials in ``cache_scope``.

Invalidation dependencies use the resolved physical schema/table independently
of result visibility. Connections with different default schemas therefore
share an invalidation tag for the same explicitly qualified table. Unqualified
tables still resolve within each connection's configured schema, and
``cache_dependency_scope`` can separate deployments sharing a cache backend.

Result keys rotate together with dependency identities. Warm entries carrying
the previous tag format are intentionally bypassed after upgrading, so writes
using the new tags cannot leave those old entries visible to new readers.

The connection's private in-memory result cache uses bounded instance-local
coordination. An explicitly supplied shared CacheLayer backend retains its own
lock provider and cross-process coordination.

Native Transactions
-------------------

DBLayer does not read or populate the query-result cache while an externally
owned native PDO transaction is active. ``afterCommit()`` is only meaningful
when DBLayer owns the managed transaction lifecycle and now rejects an
externally owned native transaction.

Structured write invalidation remains commit-aware for DBLayer-managed
transactions. If an application owns a native PDO transaction directly, it must
also own the corresponding commit lifecycle and cache policy.

Completed Mutation Timeouts
---------------------------

A synchronous PDO mutation that returns successfully is treated as completed
before DBLayer evaluates a late cooperative elapsed-time budget. DBLayer will
not report that completed mutation as a timeout, because doing so could cause a
caller to retry a write that already took effect.

Cancellation still applies before execution and at cooperative checkpoints.
Ordinary synchronous PDO calls are not claimed to be interruptible in the
middle of a native database call.

Streaming, Lazy Results, and Pool Leases
----------------------------------------

Prepared statements that own active streaming cursors are not reused until that
cursor is released.

Each fetch checks the currently active cancellation and deadline policy before
and after the native call, including policies introduced while an iterator is
paused. The ordinary unbound path retains these checks when a host later lends
its Runwire context.

``PoolManager::using()`` is callback-scoped and rejects a ``Traversable`` result
that would escape after its connection lease has already been released. For a
stream or another connection-bound iterator, hold an explicit
``ConnectionLease`` for the full iterator lifetime and release it in ``finally``.

Pool reuse sanitation clears request-local sticky, cancellation, deadline,
comment, transaction, and DBLayer-owned private-cache state. Native statement
timeouts are restored before the wrapper becomes idle. Caller-owned shared
CacheLayer backends are not flushed during reuse.

Worker Pool Warmup
------------------

``min_connections`` still creates lazy connection wrappers. To open database
handles explicitly after a worker has been created, call ``warmUp()`` on the
worker-local pool manager:

.. code-block:: php

   $manager = new PoolManager($pool);

   // Host-controlled readiness phase, after fork/worker creation.
   $ready = $manager->warmUp('main', target: 10);

Warmup opens distinct primary PDO handles up to the requested target and returns
them to the idle pool. It does not start a timer, worker, listener, supervisor,
or event loop. Capacity remains bounded by ``max_connections``.

Retain the pool only inside its owning process/generation. On worker replacement
or shutdown, the host should settle its work and call ``closeAll()`` on the
DBLayer pool. Never carry opened PDO handles across a fork.

Optional Runwire 2.1 Integration
--------------------------------

A host may lend its exact runtime/request/scope objects to an exclusively owned
connection:

.. code-block:: php

   $result = $connection->withRunwire(
       $runtime,
       fn () => $connection->table('users')->where('active', 1)->get(),
       $request,
       $scope,
   );

DBLayer validates request/runtime identity and process ownership, composes the
strictest active cancellation/deadline policy, uses a compatible host coroutine
scope for bounded retry sleeps, and restores the previous binding in
``finally``.

Lazy keyset/ArrayKit iteration retains the same binding for its iterator
lifetime. A completed or cancelled request cannot later resume database work.

DBLayer never calls ``Runwire::run()``, starts or stops a worker/event loop, or
takes ownership of the host's request/scope. Runwire capability support does
not make PDO or CacheLayer providers nonblocking; database and cache operations
remain synchronous unless their underlying provider says otherwise.

CacheLayer Sharing
------------------

When CacheLayer is already bound to the same Runwire runtime, DBLayer borrows
that execution context through CacheLayer's sharing API. DBLayer does not
replace another runtime's global CacheLayer binding and does not release a
host-owned binding.

Release Checklist
-----------------

Before deploying 6.0:

1. Resolve the new ArrayKit and CacheLayer floors.
2. Review tenant-scoped write payloads and hooks for tenant reassignment.
3. Configure ``cache_scope`` anywhere result visibility depends on session/RLS
   state beyond the normal connection identity.
4. Replace lazy values escaping ``PoolManager::using()`` with explicit leases.
5. For persistent workers, keep one pool per worker generation, call
   ``warmUp()`` only from host-controlled readiness when desired, and close the
   pool during host-controlled drain.
6. Run application tests against the database engines and dependency ranges
   used in production.
