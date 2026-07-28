Observability
=============

Introduction
------------

DBLayer observability is event-driven. Query and transaction events can feed
logger, profiler, telemetry export, and custom callbacks simultaneously.

Query lifecycle events:

- successful queries emit ``db.query.executed``
- failed queries emit ``db.query.failed``

.. contents:: On This Page
   :depth: 2
   :local:

Logger
------

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::select('select 1');
   DB::disableLogger();

Logger writes structured entries to file and can also forward entries to any
PSR-3 compatible logger backend. Binding values are redacted by default.

.. code-block:: php

   use Psr\Log\LoggerInterface;

   /** @var LoggerInterface $psrLogger */
   DB::enableLogger('/tmp/dblayer.log', $psrLogger);

   // Or configure backend separately:
   // DB::setPsrLogger($psrLogger);
   // DB::enableLogger('/tmp/dblayer.log');

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::logger()->setRedactBindings(true); // default
   // DB::logger()->setRedactBindings(false); // only for controlled local debugging

Profiler
--------

.. code-block:: php

   DB::enableProfiler();
   DB::table('users')->limit(1)->get();
   $stats = DB::profiler()->getStats();

Profiler captures query duration and memory deltas for local diagnosis.

Facade Listener
---------------

.. code-block:: php

   DB::listen(function (array $event): void {
       // query, bindings, time, connection, rows
   });

Use listeners for metrics adapters, custom tracing, or alerting hooks.

Telemetry
---------

.. code-block:: php

   DB::enableTelemetry();
   DB::table('sqlite_master')->select('name')->limit(1)->get();
   $snapshot = DB::telemetry();
   $otel = DB::telemetryOtel('dblayer-service');
   $report = DB::slowQueryReport([50, 90, 95, 99], 1.0);
   $shapes = DB::queryShapeReport([50, 90, 95, 99], 1.0, 20);
   $flushed = DB::flushTelemetry();

Buffers are bounded by default (query and transaction events), and can be
adjusted:

.. code-block:: php

   DB::setTelemetryBufferLimits(queryEvents: 2000, transactionEvents: 2000);
   DB::setProfilerMaxProfiles(2000);
   DB::setMaxQueryLogEntries(2000);

The facade and per-connection executor query logs retain the newest 2,000
entries by default. Passing ``null`` to their ``setMaxQueryLogEntries()`` method
restores that default.

Failed-query telemetry defaults to redacted SQL/error payloads while preserving
statement type, fingerprint, connection, duration, attempts, and exception
class metadata.

Query Shape Reports
-------------------

``queryShapeReport()`` groups parameterized statements by connection and stable
SQL fingerprint. It reports calls, successes/failures, total/mean/min/max
duration, requested percentiles, and available affected-row totals. Leading
DBLayer query comments are excluded from the fingerprint.

The method arguments are:

- ``percentiles``: list of numeric values; default ``[50, 90, 95, 99]``.
- ``minimumMs``: ``null`` for all queries or a millisecond threshold; default
  ``null``.
- ``limit``: ``null`` for all shapes or the maximum returned shapes; default
  ``20``.

Shapes are ordered by total database time so frequently repeated moderate
queries are visible alongside individually slow queries. Collection remains
bounded by ``setTelemetryBufferLimits()`` and is active only after
``enableTelemetry()``.

See ``performance-optimization`` for execution plans and the full measurement
workflow.

Telemetry Exports
-----------------

- ``telemetry()``: snapshot, does not clear buffers.
- ``flushTelemetry()``: returns payload and clears buffers.
- ``telemetryOtel()`` / ``flushTelemetryOtel()``: OpenTelemetry-like shape.

Long Query Threshold Hook
-------------------------

Register a callback once cumulative query time exceeds a threshold:

.. code-block:: php

   DB::whenQueryingForLongerThan(500.0, function (): void {
       // threshold crossed
   });

.. note::

   For production pipelines, export and clear telemetry buffers on a regular
   cadence to keep in-memory diagnostic data tight and recent.
