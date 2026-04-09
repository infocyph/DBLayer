Observability
=============

Introduction
------------

DBLayer observability is event-driven. Query and transaction events can feed
logger, profiler, telemetry export, and custom callbacks simultaneously.

.. contents:: On This Page
   :depth: 2
   :local:

Logger
------

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::select('select 1');
   DB::disableLogger();

Logger writes structured entries to file and is intended for operational
troubleshooting.

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
   $flushed = DB::flushTelemetry();

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

   For production pipelines, prefer exporting and clearing telemetry buffers on
   a regular cadence to avoid unbounded in-memory growth.
