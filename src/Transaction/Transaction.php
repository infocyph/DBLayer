<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Exceptions\TransactionException;
use Throwable;

/**
 * Transaction manager with nesting, savepoints and deadlock retry semantics.
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

    private Connection $connection;

    /**
     * Current nesting level.
     */
    private int $level = 0;

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
        } else {
            $this->createSavepoint($this->level);
        }

        $this->level++;
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

        if ($this->level === 0) {
            $this->connection->commit();
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
                $this->backoff($attempt);
                goto beginning;
            }

            throw TransactionException::failed($e);
        }
    }

    /**
     * Get current transaction nesting level.
     */
    public function level(): int
    {
        return $this->level;
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

        if ($this->level === 0) {
            $this->connection->rollBack();
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

        $this->connection->statement('SAVEPOINT trans_' . $level);
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

        $this->connection->statement('RELEASE SAVEPOINT trans_' . $level);
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

        $this->connection->statement('ROLLBACK TO SAVEPOINT trans_' . $level);
    }
}
