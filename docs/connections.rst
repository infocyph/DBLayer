Connections, Replicas, and Pooling
===================================

Introduction
------------

This guide covers direct connection ownership, read/write split behavior,
replica selection strategies, and pooled connection lifecycle.

.. contents:: On This Page
   :depth: 2
   :local:

Connection Access
-----------------

Facade-managed applications can resolve named connections through ``DB``:

.. code-block:: php

   $conn = DB::connection();       // registered/cached facade connection
   $fresh = DB::freshConnection(); // new independent connection

Use ``freshConnection()`` when you explicitly need a new underlying connection
instance and do not want facade connection reuse.

Execution-scoped runtimes can own ``Connection`` objects directly instead of
registering execution state in the static facade:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Connection;
   use Infocyph\DBLayer\Connection\ConnectionConfig;

   $config = ConnectionConfig::fromArray([
       'driver' => 'sqlite',
       'database' => __DIR__ . '/../storage/app.sqlite',
   ]);

   $connection = new Connection($config, 'main');
   $rows = $connection->table('users')->get();

Higher-level runtimes should own the connection for exactly one execution scope
and release or disconnect it from that scope's cleanup boundary.

Connection Runtime Methods
--------------------------

Runtime controls are available on ``Connection`` instances:

- lifecycle hooks: ``beforeConnect()``, ``afterConnect()``, ``beforeReconnect()``,
  ``afterReconnect()``, ``onConnectionFailure()``
- query comment context: ``setQueryCommentContext()``,
  ``mergeQueryCommentContext()``, ``clearQueryCommentContext()``,
  ``getQueryCommentContext()``
- execution helpers: ``setFetchMode()``, ``withoutQueryEvents()``,
  ``stream()``, ``unbufferedStream()``, ``yieldRows()``, ``readOnlyTransaction()``
- query cache ownership: ``setQueryCache()``, ``queryCache()``, ``hasQueryCache()``
- managed transaction callbacks: ``afterCommit()``

``stream()`` avoids ``fetchAll()`` but native client buffering remains
driver-dependent. ``unbufferedStream()`` makes the stronger bounded-memory
choice explicit: MySQL disables buffered queries for the generator lifetime,
PostgreSQL fetches through a transaction-scoped server cursor, and SQLite uses
incremental ``fetch()``. A MySQL unbuffered generator occupies its connection;
consume or close it before issuing another statement on that connection.

Read/Write Split
----------------

.. code-block:: php

   DB::addConnection([
       'driver' => 'mysql',
       'database' => 'app_db',
       'username' => 'app_user',
       'password' => 'secret',
       'sticky' => true,
       'read_strategy' => 'round_robin',
       'read_latency_ttl' => 15,
       'read_probe_sample_size' => 0,
       'read_session_read_only' => false,
       'read' => [
           ['host' => 'replica1.internal', 'weight' => 1],
           ['host' => 'replica2.internal', 'weight' => 3],
       ],
       'write' => [
           ['host' => 'primary.internal'],
       ],
   ], 'main');

In this setup:

- Writes use the write channel.
- Reads use replicas when available.
- With ``sticky=true``, reads switch to write PDO after a write on that
  connection to reduce read-after-write inconsistency windows.
- For SQLite read replicas, DBLayer applies ``PRAGMA query_only = ON`` on read
  handles to prevent accidental writes through read PDO.

.. note::

   Sticky read-after-write behavior is execution-scope consistency state. It
   must not leak from one request/job/Fiber execution into another.

Read Strategies
---------------

- ``random``
- ``round_robin``
- ``least_latency``
- ``weighted``

Replica telemetry:

.. code-block:: php

   $info = DB::connection('main')->getReadReplicaInfo();

Strategy Behavior Summary
-------------------------

- ``random``: random healthy replica selection.
- ``round_robin``: deterministic rotation.
- ``least_latency``: probe healthy replicas and choose fastest response.
  Winner is cached for ``read_latency_ttl`` seconds.
  ``read_probe_sample_size`` can bound first-pass probes for large pools.
- ``weighted``: weighted random using per-replica ``weight``.

``read_session_read_only=true`` enables the vendor session command for MySQL
and PostgreSQL read handles. SQLite read handles always receive
``PRAGMA query_only = ON``; the option is therefore not accepted for SQLite.

When a replica fails, DBLayer applies cooldown-based suppression before retry.

Using Multiple Database Connections
-----------------------------------

Use named connections for operational separation:

.. code-block:: php

   DB::addConnection([...], 'primary');
   DB::addConnection([...], 'reporting');

   $users = DB::table('users', 'primary')->get();
   $events = DB::table('user_events', 'reporting')->get();

Connection selection also applies to the opt-in modules:

.. code-block:: php

   $schema = DB::schema('primary');
   $relations = DB::relations('reporting', batchSize: 500);

   $runner = new MigrationRunner(
       connection: DB::connection('primary'),
       migrations: $compiledMigrations,
   );

Each module retains only the resolved connection it receives. Schema changes,
migration ledgers, relation queries, and table prefixes therefore remain
isolated by named connection.

Pooling
-------

The static facade provides a convenience wrapper:

.. code-block:: php

   DB::poolManager([
       'max_connections' => 10,
       'idle_timeout' => 60,
       'max_lifetime' => 3600,
       'health_check_interval' => 30,
   ]);

   DB::withPooledConnection(function ($pooled) {
       return $pooled->select('select 1');
   }, 'main');

For persistent, interleaved, Fiber-based, or DI-owned runtimes, use the
instance-oriented lease API so ownership is explicit:

.. code-block:: php

   use Infocyph\DBLayer\Connection\Pool;
   use Infocyph\DBLayer\Connection\PoolManager;

   $pool = new Pool([
       'min_connections' => 1,
       'max_connections' => 10,
   ]);
   $pool->addConfig('main', $config);

   $manager = new PoolManager($pool);
   $lease = $manager->checkout('main');

   try {
       $connection = $lease->connection();
       $result = $connection->scalar('select 1');
   } finally {
       $lease->release();
   }

``checkout()`` returns a ``ConnectionLease`` containing the ownership token for
that checkout generation. A stale lease, double release, or a bare
``PoolManager::release()`` attempt against an active tokenized checkout is
rejected. ``ConnectionLease::connection()`` also rejects access after release.

For callback-scoped work, ``PoolManager::using()`` performs checkout and release
with the same tokenized ownership semantics:

.. code-block:: php

   $value = $manager->using(
       'main',
       static fn ($connection) => $connection->scalar('select 42'),
   );

``PoolManager::get()``/``release()`` remain convenience APIs for strictly scoped
legacy callers. Prefer ``checkout()`` for persistent or interleaved execution
models because a bare ``Connection`` reference does not itself express checkout
generation ownership.

Release Sanitation
------------------

Before a pooled connection becomes idle again, DBLayer calls
``Connection::resetRuntimeStateForReuse()``. Reuse sanitation protects the next
execution from request/job-local state, including managed/raw transaction state,
after-commit callbacks, sticky-write state, query comment context, deadlines,
cancellation checks, and savepoint/transaction bookkeeping.

If sanitation fails, or an opened connection is unhealthy or beyond its maximum
lifetime, the pool removes it instead of returning it to idle reuse.

Prepared-statement cache state remains connection-owned so a healthy pooled
connection can retain the warm reuse benefit. Do not add a second higher-level
reset layer that clears DBLayer internals indiscriminately; extend DBLayer's
reuse contract if new connection-scoped mutable state is introduced.

Operational Notes
-----------------

- ``max_connections`` bounds total open pooled connections across configured names.
- ``idle_timeout`` evicts idle connections.
- ``max_lifetime`` rotates old connections.
- ``health_check_interval`` controls probe cadence.
- A zero value disables the corresponding idle timeout, lifetime rotation, or
  scheduled health probe. Negative values are invalid.
- Health probes run only against idle, already-opened connections; a borrowed
  connection is never interrupted by ``SELECT 1``.
- A checked-out connection belongs to one active execution until its lease is
  released. Do not share one leased connection concurrently across executions.

Use pool stats to tune these settings under real workload:

.. code-block:: php

   $stats = $manager->getPool()->getStats();

.. warning::

   Oversizing ``max_connections`` can overwhelm downstream databases. Tune
   against real database limits and application query concurrency.

Pool Limitations and Fit
------------------------

- PHP-FPM request lifecycles usually do not benefit much from userland pooling.
- Long-running workers and persistent runtimes can benefit from PDO and prepared
  statement reuse.
- Pool health checks are interval-based and batched, not full scans on every
  acquire path.
- Pooling is not automatically faster. Benchmark create/use/disconnect against
  checkout/use/release and warm prepared-statement reuse in the target runtime
  before enabling it by default.
