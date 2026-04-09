Transactions
============

Introduction
------------

DBLayer supports manual and closure transactions, including nested transaction
levels where savepoints are available for the active driver.

.. contents:: On This Page
   :depth: 2
   :local:

Closure Transaction
-------------------

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
       DB::table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
   });

If an exception is thrown inside the callback, DBLayer rolls back and rethrows.

Manual Transaction
------------------

.. code-block:: php

   DB::beginTransaction();
   try {
       DB::table('orders')->insert(['user_id' => 1, 'total' => 100]);
       DB::commit();
   } catch (Throwable $e) {
       DB::rollBack();
       throw $e;
   }

Retry Attempts
--------------

Use retry attempts for transient failures:

.. code-block:: php

   DB::transaction(function (): void {
       // critical write path
   }, attempts: 3);

When retries are enabled, write logic must be idempotent or safely repeatable.

Execution Budgets
-----------------

Combine transaction logic with query-level timeout/deadline wrappers:

.. code-block:: php

   DB::withQueryTimeout(500, function (): void {
       DB::transaction(function (): void {
           DB::select('select 1');
       });
   });

.. note::

   Timeouts and deadlines are query-execution controls, not transaction-level
   lock-time guarantees. Database engine behavior still applies.

Introspection
-------------

- ``DB::transactionLevel()``
- ``DB::transactionStats()``
