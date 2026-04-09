Observability
=============

Logger
------

.. code-block:: php

   DB::enableLogger('/tmp/dblayer.log');
   DB::select('select 1');
   DB::disableLogger();

Profiler
--------

.. code-block:: php

   DB::enableProfiler();
   DB::table('users')->limit(1)->get();
   $stats = DB::profiler()->getStats();

Facade Listener
---------------

.. code-block:: php

   DB::listen(function (array $event): void {
       // query, bindings, time, connection, rows
   });

Telemetry
---------

.. code-block:: php

   DB::enableTelemetry();
   DB::table('sqlite_master')->select('name')->limit(1)->get();
   $snapshot = DB::telemetry();
   $otel = DB::telemetryOtel('dblayer-service');
   $report = DB::slowQueryReport([50, 90, 95, 99], 1.0);
   $flushed = DB::flushTelemetry();
