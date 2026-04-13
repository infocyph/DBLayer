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

Event dispatcher supports:

- Exact listeners
- Wildcard listeners (for example ``db.*``)
- Queue + flush workflow
- Runtime stats

Database Event Payloads
-----------------------

Database lifecycle events carry typed objects such as ``QueryExecuted`` and
``TransactionCommitted``. These are suitable for metrics and diagnostics.

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

``collect()`` returns ``Infocyph\DBLayer\Support\Collection`` (ArrayKit-backed).

Helper Philosophy
-----------------

Helpers are convenience wrappers, not a required API surface. You can use the
fully qualified static facade methods if you prefer explicitness in large code
bases.
