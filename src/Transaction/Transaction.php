<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Throwable;

/**
 * Transaction wrapper with nesting, savepoints and deadlock retry semantics.
 *
 * Designed to:
 *  - Work standalone via Connection::transaction()
 *  - Cooperate with TransactionManager for global stats
 */
final class Transaction
{
    /**
     * Base backoff in microseconds for deadlock retries.
     */
    private const BASE_BACKOFF_US = 100_000;

    /**
     * Maximum number of retry attempts for deadlocks.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Underlying connection.
     */
    private Connection $connection;

    /**
     * Current nesting level.
     */
    private int $level = 0;

    /**
     * Timestamp when the top-level transaction started (microtime).
     */
    private ?float $startedAt = null;

    /**
     * Stats for this connection.
     *
     * @var array{
     *   total:int,
     *   committed:int,
     *   rolled_back:int,
     *   deadlocks:int,
     *   timeouts:int,
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }
     */
    private array $stats = [
      'total'           => 0,
      'committed'       => 0,
      'rolled_back'     => 0,
      'deadlocks'       => 0,
      'timeouts'        => 0,
      'in_transaction'  => false,
      'current_level'   => 0,
      'savepoints'      => 0,
      'elapsed_time'    => 0.0,
    ];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Begin a new transaction or create a savepoint for nested transactions.
     */
    public function begin(): void
    {
        if ($this->level === 0) {
            $this->connection->beginTransaction();
            $this->stats['total']++;
            $this->stats['in_transaction'] = true;
            $this->startedAt               = microtime(true);
        } else {
            $this->createSavepoint($this->level);
            $this->stats['savepoints']++;
        }

        $this->level++;
        $this->stats['current_level'] = $this->level;
    }

    /**
     * Commit the current transaction or release a savepoint.
     */
    public function commit(): void
    {
        if ($this->level === 0) {
            // Should not happen, but be defensive.
            return;
        }

        $this->level--;
        $this->stats['current_level'] = $this->level;

        if ($this->level === 0) {
            $this->connection->commit();
            $this->stats['committed']++;
            $this->finishTopLevel();
        } else {
            $this->releaseSavepoint($this->level);
        }
    }

    /**
     * Execute a callback within a transaction, with deadlock retries.
     *
     * @param  callable(Connection):mixed  $callback
     */
    public function execute(callable $callback, int $attempts = 1): mixed
    {
        $attempts = max(1, min($attempts, self::MAX_ATTEMPTS));
        $attempt  = 0;

        beginning:

        $attempt++;

        $this->begin();

        try {
            $result = $callback($this->connection);

            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            if ($attempt < $attempts && $this->causedByDeadlock($e)) {
                $this->stats['deadlocks']++;
                $this->backoff($attempt);
                goto beginning;
            }

            throw TransactionException::failed($e);
        }
    }

    /**
     * Get underlying connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get stats for this transaction wrapper.
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
     * }
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Check if there is an active transaction.
     */
    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    /**
     * Get current transaction nesting level.
     */
    public function level(): int
    {
        return $this->level;
    }

    /**
     * Reset stats for this transaction wrapper.
     */
    public function resetStats(): void
    {
        $this->stats = [
          'total'           => 0,
          'committed'       => 0,
          'rolled_back'     => 0,
          'deadlocks'       => 0,
          'timeouts'        => 0,
          'in_transaction'  => $this->level > 0,
          'current_level'   => $this->level,
          'savepoints'      => 0,
          'elapsed_time'    => 0.0,
        ];

        $this->startedAt = $this->level > 0 ? (microtime(true)) : null;
    }

    /**
     * Rollback the current transaction or rollback to a savepoint.
     */
    public function rollBack(): void
    {
        if ($this->level === 0) {
            return;
        }

        $this->level--;
        $this->stats['current_level'] = $this->level;

        if ($this->level === 0) {
            $this->connection->rollBack();
            $this->stats['rolled_back']++;
            $this->finishTopLevel();
        } else {
            $this->rollbackToSavepoint($this->level);
        }
    }

    /**
     * Backoff with a simple linear backoff (attempt * BASE_BACKOFF_US).
     */
    private function backoff(int $attempt): void
    {
        $delay = self::BASE_BACKOFF_US * max(1, $attempt);

        usleep($delay);
    }

    /**
     * Determine if the given exception was caused by a deadlock.
     */
    private function causedByDeadlock(Throwable $e): bool
    {
        $driver = $this->connection->getDriverName();

        return DriverProfile::causedByDeadlock($driver, $e);
    }

    /**
     * Create a savepoint for a given nesting level.
     */
    private function createSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (! $supportsSavepoints) {
            return;
        }

        $this->connection->statement('SAVEPOINT trans_'.$level);
    }

    /**
     * Finalize stats for a completed top-level transaction.
     */
    private function finishTopLevel(): void
    {
        if ($this->startedAt !== null) {
            $this->stats['elapsed_time'] += microtime(true) - $this->startedAt;
        }

        $this->startedAt              = null;
        $this->stats['in_transaction'] = false;
    }

    /**
     * Release a savepoint for a given nesting level.
     */
    private function releaseSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (! $supportsSavepoints) {
            return;
        }

        $this->connection->statement('RELEASE SAVEPOINT trans_'.$level);
    }

    /**
     * Rollback to a savepoint for a given nesting level.
     */
    private function rollbackToSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (! $supportsSavepoints) {
            return;
        }

        $this->connection->statement('ROLLBACK TO SAVEPOINT trans_'.$level);
    }
}
