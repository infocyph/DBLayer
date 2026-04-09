Transactions
============

Closure Transaction
-------------------

.. code-block:: php

   DB::transaction(function (): void {
       DB::table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
       DB::table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
   });

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

Introspection
-------------

- ``DB::transactionLevel()``
- ``DB::transactionStats()``
