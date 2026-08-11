<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Manager
 *
 * Manages multiple transaction instances across connections.
 * Provides factory methods and global transaction management.
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
     * Get number of active (top-level) transactions.
     */
    public function activeCount(): int
    {
        return $this->getGlobalStats()['active_transactions'];
    }

    /**
     * Register a callback that runs after the connection's top-level commit.
     *
     * @param callable():void $callback
     */
    public function afterCommit(Connection $connection, callable $callback): void
    {
        $this->forConnection($connection)->afterCommit($callback);
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
     * Clear all transaction instances and stats.
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
        $this->finalizeTransaction(
            $connection,
            static function (Transaction $transaction): void {
                $transaction->commit();
            },
        );
    }

    /**
     * Commit all active transactions.
     *
     * Uses commit() so global stats stay consistent.
     */
    public function commitAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $this->commit($transaction->getConnection());
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

        if (!isset($this->transactions[$hash])) {
            $this->transactions[$hash] = new Transaction($connection);
        }

        return $this->transactions[$hash];
    }

    /**
     * Get global statistics.
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
            'total_transactions' => 0,
            'active_transactions' => 0,
            'total_commits' => 0,
            'total_rollbacks' => 0,
            'total_deadlocks' => 0,
        ];

        foreach ($this->transactions as $transaction) {
            $current = $transaction->getStats();
            $stats['total_transactions'] += $current['total'];
            $stats['total_commits'] += $current['committed'];
            $stats['total_rollbacks'] += $current['rolled_back'];
            $stats['total_deadlocks'] += $current['deadlocks'];

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
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }|array{}
     */
    public function getStats(Connection $connection): array
    {
        $hash = spl_object_id($connection);

        if (!isset($this->transactions[$hash])) {
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

        if (!isset($this->transactions[$hash])) {
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

        if (!isset($this->transactions[$hash])) {
            return 0;
        }

        return $this->transactions[$hash]->level();
    }

    /**
     * Reset global and per-connection statistics.
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
        $this->finalizeTransaction(
            $connection,
            static function (Transaction $transaction): void {
                $transaction->rollBack();
            },
        );
    }

    /**
     * Rollback all active transactions.
     *
     * Uses rollback() so global stats stay consistent.
     */
    public function rollbackAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $this->rollback($transaction->getConnection());
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

    /**
     * @param callable(Transaction):void $operation
     */
    private function finalizeTransaction(Connection $connection, callable $operation): void
    {
        $transaction = $this->forConnection($connection);
        $operation($transaction);
    }
}
