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

Native PDO Transaction Ownership
--------------------------------

DBLayer-managed transactions and externally owned native PDO transactions have
different ownership contracts. If application code begins a transaction directly
on the underlying PDO handle, DBLayer does not own that commit lifecycle.

While an externally owned native transaction is active:

- query-result caching is bypassed;
- ``Connection::afterCommit()`` is rejected because DBLayer cannot know when the
  external owner will commit;
- cache-aware structured DBLayer writes are rejected rather than pretending that
  invalidation can be deferred safely.

Use ``DB::transaction()`` or ``Connection::transaction()`` when DBLayer should
own commit-aware cache invalidation and after-commit callbacks. If the application
owns the native PDO transaction, it must also own the corresponding cache and
commit side-effect policy.

Retry Attempts
--------------

Use retry attempts for transient failures:

.. code-block:: php

   DB::transaction(function (): void {
       // critical write path
   }, attempts: 3);

When retries are enabled, write logic must be idempotent or safely repeatable.
Prefer transaction-level retry for deadlocks/serialization failures over
retrying standalone non-idempotent statements.

After-Commit Callbacks
----------------------

Use ``DB::afterCommit()`` to defer side effects until the surrounding
top-level transaction commits successfully:

.. code-block:: php

   DB::transaction(function ($connection): void {
       $connection->table('orders')->insert(['reference' => 'order-42']);

       DB::afterCommit(function (): void {
           // Publish an event, invalidate a cache entry, or notify another system.
       });
   });

Callbacks registered outside a transaction run immediately. Nested callbacks
are promoted when their savepoint commits and discarded when their savepoint
rolls back. If a retry attempt rolls back, its callbacks are discarded before
the next attempt starts.

After-commit callbacks run only after the database commit is durable. If one
fails, DBLayer still runs the remaining callbacks and rethrows the first
failure; the already committed database write cannot be rolled back. Design
callbacks to be idempotent and send operational failures to your retry or
monitoring system.

Read-Only Transactions
----------------------

.. code-block:: php

   DB::readOnlyTransaction(function ($connection): int {
       return (int) $connection->scalar('select count(*) from reports');
   });

Driver behavior:

- PostgreSQL: best-effort ``SET TRANSACTION READ ONLY``.
- MySQL/MariaDB: best-effort ``SET TRANSACTION READ ONLY``.
- SQLite: safe no-op (no transaction-scoped read-only toggle).

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
