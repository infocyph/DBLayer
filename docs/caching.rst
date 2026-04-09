Caching
~~~~~~~

Basic Cache
-----------

.. code-block:: php

   $cache = DB::cache();
   $cache->put('k', 'v', 60);
   $value = $cache->get('k');

File Strategy
-------------

.. code-block:: php

   $cache = DB::useFileCache(__DIR__ . '/../storage/cache');

Remember Pattern
----------------

.. code-block:: php

   $rows = $cache->remember('users:active', function () {
       return DB::table('users')->where('active', '=', 1)->get();
   }, 120);

Tags
----

.. code-block:: php

   $tagged = $cache->tags(['users', 'tenant:10']);
   $tagged->put('profile:1', ['id' => 1], 60);
