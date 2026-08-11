Events and Array Utilities
==========================

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
- ``db.query.failed``
- ``db.transaction.beginning``
- ``db.transaction.committed``
- ``db.transaction.rolled_back``

``db.query.failed`` payload fields:

- ``sql``
- ``bindings``
- ``time``
- ``connection``
- ``attempts``
- ``error``
- ``exception``
- ``statement``
- ``fingerprint``

ArrayKit Integration
--------------------

DBLayer does not register generic global helpers. Use the explicit ``DB``
facade for database operations and ArrayKit 5 directly for general array work:

.. code-block:: php

   use Infocyph\ArrayKit\Array\DotNotation;

   $payload = [];
   DotNotation::set($payload, 'profile.name', 'Ada');
   $name = DotNotation::get($payload, 'profile.name');

``QueryBuilder::collect()`` and Repository collection methods return
``Infocyph\ArrayKit\Collection\Collection`` directly. For bounded result
pipelines, ``QueryBuilder::lazyCollection()`` returns ArrayKit's
``LazyCollection``.
