Events and Helpers
==================

Events
------

.. code-block:: php

   use Infocyph\DBLayer\Events\Events;

   $listener = static function (): void {};
   Events::listen('custom.event', $listener);
   Events::dispatch('custom.event');
   Events::forget('custom.event', $listener);

Built-in database event names:

- ``db.query.executed``
- ``db.query.executing``
- ``db.transaction.beginning``
- ``db.transaction.committed``
- ``db.transaction.rolled_back``

Helpers
-------

From ``src/helpers.php``:

- DB helpers: ``db()``, ``db_table()``, ``db_select()``, ``db_transaction()``
- Data helpers: ``data_get()``, ``data_set()``
- Utility helpers: ``collect()``, ``retry()``, ``rescue()``, ``blank()``, ``filled()``, ``now()``
