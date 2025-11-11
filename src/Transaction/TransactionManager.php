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
class TransactionManager
{
    /**
     * Global statistics
     */
    private array $globalStats = [
        'total_transactions' => 0,
        'active_transactions' => 0,
        'total_commits' => 0,
        'total_rollbacks' => 0,
        'total_deadlocks' => 0,
    ];
    /**
     * Transaction instances by connection
     */
    private array $transactions = [];

    /**
     * Get active transaction count
     */
    public function activeCount(): int
    {
        return $this->globalStats['active_transactions'];
    }

    /**
     * Begin transaction on connection
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
     * Clear all transaction instances
     */
    public function clear(): void
    {
        $this->transactions = [];
        $this->resetStats();
    }

    /**
     * Commit transaction on connection
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
     * Commit all active transactions
     */
    public function commitAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $transaction->commit();
            }
        }

        $this->globalStats['active_transactions'] = 0;
    }

    /**
     * Execute callback in transaction
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
     * Get or create transaction for connection
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
     * Get global statistics
     */
    public function getGlobalStats(): array
    {
        return $this->globalStats;
    }

    /**
     * Get statistics for specific connection
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
     * Check if connection has active transaction
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
     * Get transaction level for connection
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
     * Reset all statistics
     */
    public function resetStats(): void
    {
        $this->globalStats = [
            'total_transactions' => 0,
            'active_transactions' => 0,
            'total_commits' => 0,
            'total_rollbacks' => 0,
            'total_deadlocks' => 0,
        ];

        foreach ($this->transactions as $transaction) {
            $transaction->resetStats();
        }
    }

    /**
     * Rollback transaction on connection
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
     * Rollback all active transactions
     */
    public function rollbackAll(): void
    {
        foreach ($this->transactions as $transaction) {
            if ($transaction->inTransaction()) {
                $transaction->rollback();
            }
        }

        $this->globalStats['active_transactions'] = 0;
    }

    /**
     * Get total transaction count
     */
    public function totalCount(): int
    {
        return count($this->transactions);
    }
}
