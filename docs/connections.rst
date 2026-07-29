Connections, Replicas, and Pooling
===================================

Introduction
------------

This guide covers read/write split behavior, replica selection strategies, and
pooled connection lifecycle.

.. contents:: On This Page
   :depth: 2
   :local:

Connection Access
-----------------

.. code-block:: php

   $conn = DB::connection();       // default
   $fresh = DB::freshConnection(); // uncached

Use ``freshConnection()`` when you explicitly need a new underlying PDO
instance and do not want shared connection reuse.

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

   Sticky read-after-write behavior is request-scope consistency behavior.
   It is useful for immediate read-back of writes, but increases read load on
   the primary/write connection.

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

Operational Notes
-----------------

- ``max_connections`` bounds total open pooled connections.
- ``idle_timeout`` evicts idle connections.
- ``max_lifetime`` rotates old connections.
- ``health_check_interval`` controls probe cadence.

Use pool stats to tune these settings under real workload:

.. code-block:: php

   $stats = DB::pool()->getStats();

.. warning::

   Oversizing ``max_connections`` can overwhelm downstream databases.
   Tune against real connection limits and query concurrency.

Pool Limitations and Fit
------------------------

- PHP-FPM request lifecycles usually do not benefit much from pooling.
- Long-running workers and CLI daemons benefit more from pooled reuse.
- Pool health checks are interval-based and batched, not full scans on every
  acquire path.
