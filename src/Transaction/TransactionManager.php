<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;

/**
 * Transaction Manager
 *
 * Manages multiple transaction instances across connections.
 * Provides factory methods and global transaction management.
 *
 * @package Infocyph\DBLayer\Transaction
 * @author Hasan
 */
final class TransactionManager
{
    /**
     * Global statistics.
     *
     * @var array{
     *   total_transactions:int,
     *   active_transactions:int,
     *   total_commits:int,
     *   total_rollbacks:int,
     *   total_deadlocks:int
     * }
     */
    private array $globalStats = [
      'total_transactions'  => 0,
      'active_transactions' => 0,
      'total_commits'       => 0,
      'total_rollbacks'     => 0,
      'total_deadlocks'     => 0,
    ];

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
        return $this->globalStats['active_transactions'];
    }

    /**
     * Begin a transaction on a specific connection.
     */
    public function begin(Connection $connection): void
    {
        $transaction = $this->forConnection($connection);

        if (!$transaction->inTransaction()) {
            $this->globalStats['active_transactions']++;
        }

        $transaction->begin();
    }

    /**
     * Clear all transaction instances and stats.
     */
    public function clear(): void
    {
        $this->transactions = [];
        this->resetStats();
    }

    /**
     * Commit transaction on a specific connection.
     */
    public function commit(Connection $connection): void
    {
        $transaction = $this->forConnection($connection);
        $transaction->commit();

        if (!$transaction->inTransaction()) {
            $this->globalStats['active_transactions']--;
            $this->globalStats['total_commits']++;
        }
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

        try {
            return $transaction->execute($callback, $attempts);
        } catch (\Throwable $e) {
            $stats = $transaction->getStats();
            $this->globalStats['total_deadlocks'] += $stats['deadlocks'] ?? 0;

            throw $e;
        }
    }

    /**
     * Get or create a Transaction wrapper for the given connection.
     */
    public function forConnection(Connection $connection): Transaction
    {
        $hash = spl_object_id($connection);

        if (!isset($this->transactions[$hash])) {
            $this->transactions[$hash] = new Transaction($connection);
            $this->globalStats['total_transactions']++;
        }

        return $this->transactions[$hash];
    }

    /**
     * Get global statistics.
     */
    public function getGlobalStats(): array
    {
        return $this->globalStats;
    }

    /**
     * Get statistics for a specific connection.
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
        $this->globalStats = [
          'total_transactions'  => 0,
          'active_transactions' => 0,
          'total_commits'       => 0,
          'total_rollbacks'     => 0,
          'total_deadlocks'     => 0,
        ];

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

        if (!$transaction->inTransaction()) {
            $this->globalStats['active_transactions']--;
            $this->globalStats['total_rollbacks']++;
        }
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
}
