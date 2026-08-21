Database System Monitoring
==========================

DBLayer exposes database-system inspection as an explicit, on-demand monitor.
It is separate from query telemetry and from the lightweight connection health
check. Nothing is polled automatically and normal query execution pays no
monitoring-query cost.

Basic Status
------------

``status()`` lives under the monitor surface:

.. code-block:: php

   $status = DB::monitor()->status();

For a named connection:

.. code-block:: php

   $status = DB::connection('analytics')->monitor()->status();

The common status envelope includes the canonical driver, database name,
connection state, transaction level, sticky-write state, DBLayer connection
statistics, read-replica selection information, and a driver-native ``server``
section.

Operational Sections
--------------------

.. code-block:: php

   $monitor = DB::monitor();

   $sessions = $monitor->sessions();
   $slow = $monitor->longRunningQueries(10);
   $locks = $monitor->locks();
   $tables = $monitor->tableMetrics();
   $indexes = $monitor->indexMetrics();
   $replication = $monitor->replication();
   $maintenance = $monitor->maintenance();

The five canonical database pathways remain separate:

- ``mysql`` uses MySQL Performance Schema process, data-lock, index-I/O, and
  replica metadata.
- ``mariadb`` uses MariaDB process/InnoDB lock metadata, Performance Schema
  index-I/O metrics, and MariaDB replication status.
- ``pgsql`` uses PostgreSQL ``pg_stat_*`` views and ``pg_blocking_pids()``.
- ``mssql`` uses SQL Server DMVs and catalog views.
- ``sqlite`` reports file/page/schema information; server-session, lock-wait,
  and replication sections are empty because SQLite has no equivalent server
  subsystem.

Snapshot
--------

``snapshot()`` collects the normal sections together and isolates failures by
section. This is useful when a production monitoring account can read some
engine statistics but lacks privileges for another DMV/statistics view.

.. code-block:: php

   $snapshot = DB::monitor()->snapshot(
       longRunningSeconds: 15,
   );

A failed section is set to ``null`` and recorded under ``errors``; other
sections continue collecting.

Maintenance inspection is deliberately excluded by default because some
engines can perform more work to produce it:

.. code-block:: php

   $snapshot = DB::monitor()->snapshot(
       longRunningSeconds: 15,
       includeMaintenance: true,
   );

Operational Contract
--------------------

The monitor is observation-only. It does not terminate sessions, cancel
queries, rebuild indexes, run ``VACUUM``, change server configuration, or mutate
replication state. Administrative actions remain explicit application/operator
operations.

Monitor queries use the selected connection's writer PDO deliberately so the
snapshot describes that database endpoint. They bypass DBLayer query
logging/profiling/telemetry; otherwise the monitor would add its own diagnostic
queries to the workload metrics it is inspecting.

Permissions
-----------

Detailed database statistics are permission-dependent. A restricted production
account may see fewer sessions or may be unable to query specific system views.
Prefer a least-privilege monitoring identity with only the read permissions
needed for the sections you consume. Use ``snapshot()`` when partial results are
preferable to an exception from one unavailable section.
