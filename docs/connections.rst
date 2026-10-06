Connections, Replicas, and Pooling
===================================

Introduction
------------

This guide covers direct connection ownership, read/write split behavior,
replica selection strategies, pooled connection lifecycle, and external proxies.

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

Selection happens when a read PDO is opened or reconnected. Subsequent queries
reuse that handle; strategies do not redistribute every query. Round-robin and
health state belong to each ``Connection`` instance, not a shared cluster-wide
scheduler.

When connection attempts fail, DBLayer deprioritizes those replicas for
``read_health_cooldown`` seconds. It tries alternative replicas and falls back
to the write PDO if read connection establishment fails. Default query recovery
allows one reconnect retry for connection errors on reads outside transactions;
writes are not replayed automatically. Multiple ``write`` entries are selected
randomly when connecting, without health-aware writer failover or primary
promotion. Cluster election and fencing belong to the database infrastructure.

.. _external-database-proxies:

External Proxies and Database Listeners
---------------------------------------

DBLayer uses the normal PDO driver and the proxy/listener's ``host`` and
``port``. Keep ``driver`` matched to the backend SQL dialect. Proxy credentials,
TLS, routing, backend pool sizes, and high availability are configured by the
deployment owner; DBLayer does not provision or manage these services.

.. list-table:: Pooling and routing deployment options
   :header-rows: 1
   :widths: 15 22 28 35

   * - Database
     - Pooling option
     - Routing / HA option
     - DBLayer connection
   * - PostgreSQL
     - PgBouncer
     - Pgpool-II
     - ``driver=pgsql`` with the PostgreSQL-facing host and port.
   * - MySQL
     - ProxySQL backend pooling
     - ProxySQL or a compatible MaxScale deployment
     - ``driver=mysql`` with the MySQL-facing host and port.
   * - MariaDB
     - ProxySQL backend pooling
     - MaxScale or ProxySQL
     - ``driver=mariadb`` with the MariaDB/MySQL-facing host and port.
   * - Microsoft SQL Server
     - PDO_SQLSRV / ODBC pooling
     - Always On availability-group listener
     - ``driver=mssql`` with the listener host, port, and database.
   * - SQLite
     - Application-side connection reuse
     - No network proxy; file locking and transactions
     - ``driver=sqlite`` with a file path in ``database``; no network endpoint.

The columns describe deployment roles. ProxySQL is a routing proxy even when
used primarily for pooling. Always On is database-engine high availability,
with listener-based routing, rather than a Pgpool-II-style middleware service.
SQLite WAL is a concurrency option, not a pooler or HA mechanism. Performance,
licensing, and supported topology comparisons depend on the selected product
version and workload.

These paths describe expected protocol compatibility. Standard driver and
physical-replica integration tests do not certify every proxy version, pooling
mode, routing policy, or listener topology. Validate the intended deployment.

DBLayer can also connect directly to database servers and provide its own
process-local pool and replica routing. Connecting through middleware adds
the deployment's configured pooling/routing capabilities; it does not give
DBLayer ownership of the middleware or its cluster lifecycle.

For a proxy that owns read/write routing, use its single endpoint and leave
``read`` and ``write`` unset. If the deployment exposes separate writer and
reader endpoints, put those in ``write`` and ``read`` respectively. DBLayer's
sticky routing only chooses its own write PDO; consistency through that PDO
still depends on the proxy's transaction and read-after-write policy.

PgBouncer Pooling Modes
~~~~~~~~~~~~~~~~~~~~~~~

Session pooling preserves a backend session for each connected client.
Transaction pooling changes backend ownership between transactions and requires
workload-specific validation. DBLayer uses native prepared statements by
default, even when its statement cache is disabled. Protocol-level prepared
statements require a compatible PgBouncer version and nonzero
``max_prepared_statements``. SQL-level ``PREPARE``/``DEALLOCATE`` are a separate
restriction.

Session ``SET``/``RESET``, session advisory locks, temporary tables with session
lifetime, and ``LISTEN`` cannot assume session affinity in transaction pooling.
DBLayer's PostgreSQL statement timeout uses session ``SET statement_timeout``;
read-session policy and startup ``schema``/``search_path`` also need validation
with the proxy's supported parameter handling. Client-side deadline and
cancellation checks do not guarantee interruption of a blocking PDO call.
PgBouncer statement pooling disallows multi-statement transactions, so it does
not support DBLayer workloads that require them. See the
`PgBouncer feature map <https://www.pgbouncer.org/features.html>`_.

SQL Server Listeners and Driver Pooling
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

DBLayer emits ``ApplicationIntent`` from ``application_intent``; read PDOs
always request ``ReadOnly``. Read-only routing still requires a readable
secondary and routing configuration on the availability group. The current
DSN builder does not expose ``MultiSubnetFailover`` or a ``ConnectionPooling``
setting; arbitrary config keys do not enable those connection-string options.

Microsoft's PHP driver uses ODBC connection pooling. It is enabled by default
on Windows; Linux/macOS require ODBC pooling configuration. This driver-level
reuse is separate from DBLayer's pool and SQL Server's internal worker threads.
See `Microsoft connection pooling
<https://learn.microsoft.com/en-us/sql/connect/php/connection-pooling-microsoft-drivers-for-php-for-sql-server>`_
and `Always On connection options
<https://learn.microsoft.com/en-us/sql/connect/php/connection-options>`_.

SQLite File Connections
~~~~~~~~~~~~~~~~~~~~~~~

The application pool can reuse connections to the same file. Enable WAL
explicitly through application/deployment initialization if the workload needs
it; DBLayer does not enable WAL automatically. WAL permits readers alongside a
writer, but still permits only one writer at a time. Configure bounded lock
waiting and transaction lifetimes accordingly. Independent ``:memory:`` PDO
connections contain independent databases, so they cannot be treated as one
shared pooled database. See `SQLite WAL <https://www.sqlite.org/wal.html>`_.

Proxy routing and pooling details are documented by
`Pgpool-II <https://www.pgpool.net/docs/latest/en/html/intro-whatis.html>`_,
`ProxySQL <https://proxysql.com/documentation/>`_, and
`MaxScale listeners <https://mariadb.com/docs/maxscale/reference/maxscale-listeners>`_.

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

Each pool belongs to its owning PHP process/worker. It reuses application-side
connections and can coexist with an external proxy's backend pool. Pool limits
do not apply across all workers: budget the sum of their capacities against
proxy and database limits. Each pooled ``Connection`` can hold both a write PDO
and a read PDO, while ``warmUp()`` opens primary handles only. Session-mode
proxies can retain backend connections for as long as these client handles stay
open.

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

``min_connections`` creates lazy connection objects within the pool-wide
maximum. PDO handles normally open on first use. Persistent hosts that want an
explicit readiness warmup can open distinct primary handles after worker/fork
creation:

.. code-block:: php

   $ready = $manager->warmUp('main', target: 10);

``warmUp()`` is synchronous and host-invoked. It opens distinct handles up to
the requested target, returns them to the idle pool, and respects
``max_connections``. It does not start a background timer or automatically
replenish capacity after expiry. A host that wants periodic reconciliation must
invoke its own bounded maintenance/readiness policy.

Healthy handles remain available for reuse after a lease is released. Retain
the pool manager only inside the owning worker generation and close the pool
during host-controlled drain or replacement. Never carry opened PDO handles
across a fork.

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

Runwire Runtime Composition
---------------------------

Runwire integration is optional and instance-oriented. The host lends its
already active runtime/request/scope to an exclusively owned connection:

.. code-block:: php

   $result = $connection->withRunwire(
       $runtime,
       fn () => $connection->table('users')->where('active', 1)->get(),
       $request,
       $scope,
   );

DBLayer validates process and request/runtime identity, composes cancellation
and the earliest deadline with existing query controls, and restores the prior
binding in ``finally``. When a compatible coroutine scope is present, bounded
retry/backoff sleeps cooperate with that scope.

Lazy keyset and ArrayKit collection paths retain the exact binding for their
iterator lifetime. A completed/cancelled request cannot resume database work.

DBLayer does not start or stop Runwire workers, listeners, supervisors, or event
loops. Runwire integration also does not make synchronous PDO calls nonblocking.
The host remains responsible for worker lifecycle, pool ownership, and drain.

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

- ``max_connections`` bounds pooled ``Connection`` instances across configured
  names in one pool; it is not a global limit on native database sessions.
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
