<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Manager
 *
 * Manages Transaction wrappers per connection:
 * - One Transaction instance per Connection
 * - Global statistics aggregated across connections
 * - Helper methods to begin / commit / rollback / execute
 */
final class TransactionManager
{
    /**
     * Transaction instances keyed by connection object id.
     *
     * @var array<int,Transaction>
     */
    private array $transactions = [];

    /**
     * Get number of connections currently in a transaction.
     */
    public function activeCount(): int
    {
        $count = 0;

        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Begin a transaction on a specific connection.
     */
    public function begin(Connection $connection): void
    {
        $transaction = $this->forConnection($connection);
        $transaction->begin();
    }

    /**
     * Clear all transaction instances and reset statistics.
     *
     * Typically used in tests.
     */
    public function clear(): void
    {
        $this->resetStats();
        $this->transactions = [];
    }

    /**
     * Commit transaction on a specific connection.
     */
    public function commit(Connection $connection): void
    {
        $transaction = $this->forConnection($connection);
        $transaction->commit();
    }

    /**
     * Commit all active transactions.
     */
    public function commitAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $transaction->commit();
            }
        }
    }

    /**
     * Execute callback in a transaction on given connection.
     *
     * @template T
     * @param callable(Connection):T $callback
     * @return T
     */
    public function execute(Connection $connection, callable $callback, int $attempts = 1): mixed
    {
        $transaction = $this->forConnection($connection);

        return $transaction->execute($callback, $attempts);
    }

    /**
     * Get or create a Transaction wrapper for the given connection.
     */
    public function forConnection(Connection $connection): Transaction
    {
        $hash = spl_object_id($connection);

        if (! isset($this->transactions[$hash])) {
            $this->transactions[$hash] = new Transaction($connection);
        }

        return $this->transactions[$hash];
    }

    /**
     * Get global statistics aggregated across all connections.
     *
     * @return array{
     *   total_transactions:int,
     *   active_transactions:int,
     *   total_commits:int,
     *   total_rollbacks:int,
     *   total_deadlocks:int
     * }
     */
    public function getGlobalStats(): array
    {
        $stats = [
          'total_transactions'  => 0,
          'active_transactions' => 0,
          'total_commits'       => 0,
          'total_rollbacks'     => 0,
          'total_deadlocks'     => 0,
        ];

        foreach ($this->transactions as $transaction) {
            $tStats = $transaction->getStats();

            $stats['total_transactions']  += $tStats['total'] ?? 0;
            $stats['total_commits']       += $tStats['committed'] ?? 0;
            $stats['total_rollbacks']     += $tStats['rolled_back'] ?? 0;
            $stats['total_deadlocks']     += $tStats['deadlocks'] ?? 0;

            if ($transaction->inTransaction()) {
                $stats['active_transactions']++;
            }
        }

        return $stats;
    }

    /**
     * Get statistics for a specific connection.
     *
     * @return array{
     *   total:int,
     *   committed:int,
     *   rolled_back:int,
     *   deadlocks:int,
     *   timeouts:int,
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }|array<string,mixed>
     */
    public function getStats(Connection $connection): array
    {
        $hash = spl_object_id($connection);

        if (! isset($this->transactions[$hash])) {
            return [];
        }

        return $this->transactions[$hash]->getStats();
    }

    /**
     * Check if a connection has an active transaction.
     */
    public function inTransaction(Connection $connection): bool
    {
        $hash = spl_object_id($connection);

        if (! isset($this->transactions[$hash])) {
            return false;
        }

        return $this->transactions[$hash]->inTransaction();
    }

    /**
     * Get transaction nesting level for a connection.
     */
    public function level(Connection $connection): int
    {
        $hash = spl_object_id($connection);

        if (! isset($this->transactions[$hash])) {
            return 0;
        }

        return $this->transactions[$hash]->level();
    }

    /**
     * Reset per-connection statistics.
     *
     * Does not drop Transaction instances.
     */
    public function resetStats(): void
    {
        foreach ($this->transactions as $transaction) {
            $transaction->resetStats();
        }
    }

    /**
     * Rollback transaction on a specific connection.
     */
    public function rollback(Connection $connection): void
    {
        $transaction = $this->forConnection($connection);
        $transaction->rollback();
    }

    /**
     * Rollback all active transactions.
     */
    public function rollbackAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $transaction->rollback();
            }
        }
    }

    /**
     * Get total number of tracked connections.
     */
    public function totalCount(): int
    {
        return count($this->transactions);
    }
}
