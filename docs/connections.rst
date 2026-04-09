Connections, Replicas, and Pooling
===================================

Connection Access
-----------------

.. code-block:: php

   $conn = DB::connection();       // default
   $fresh = DB::freshConnection(); // uncached

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
       'read' => [
           ['host' => 'replica1.internal', 'weight' => 1],
           ['host' => 'replica2.internal', 'weight' => 3],
       ],
       'write' => [
           ['host' => 'primary.internal'],
       ],
   ], 'main');

Read Strategies
---------------

- ``random``
- ``round_robin``
- ``least_latency``
- ``weighted``

Replica telemetry:

.. code-block:: php

   $info = DB::connection('main')->getReadReplicaInfo();

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
