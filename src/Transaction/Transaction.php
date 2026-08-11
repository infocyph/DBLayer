<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionBeginning;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionCommitted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionRolledBack;
use Infocyph\DBLayer\Events\Events;
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
    private const int BASE_BACKOFF_US = 100_000;

    /**
     * Maximum number of retry attempts for deadlocks.
     */
    private const int MAX_ATTEMPTS = 3;

    /**
     * Callbacks grouped by the transaction level that registered them.
     *
     * @var array<int,list<callable():void>>
     */
    private array $afterCommitCallbacks = [];

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
     *   in_transaction:bool,
     *   current_level:int,
     *   savepoints:int,
     *   elapsed_time:float
     * }
     */
    private array $stats = [
        'total' => 0,
        'committed' => 0,
        'rolled_back' => 0,
        'deadlocks' => 0,
        'in_transaction' => false,
        'current_level' => 0,
        'savepoints' => 0,
        'elapsed_time' => 0.0,
    ];

    public function __construct(
        /**
         * Underlying connection.
         */
        private readonly Connection $connection,
    ) {}

    /**
     * Run a callback after the surrounding top-level transaction commits.
     *
     * Callbacks registered in a nested transaction are promoted when its
     * savepoint commits and discarded if that savepoint rolls back.
     *
     * @param callable():void $callback
     */
    public function afterCommit(callable $callback): void
    {
        if ($this->level === 0) {
            $callback();

            return;
        }

        $this->afterCommitCallbacks[$this->level][] = $callback;
    }

    /**
     * Begin a new transaction or create a savepoint for nested transactions.
     */
    public function begin(): void
    {
        if ($this->level === 0) {
            $this->connection->beginNativeTransaction();
            $this->stats['total']++;
            $this->stats['in_transaction'] = true;
            $this->startedAt = microtime(true);
            Events::dispatch('db.transaction.beginning', [new TransactionBeginning($this->connection)]);
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
            return;
        }

        if ($this->level > 1) {
            $completedLevel = $this->level;
            $targetLevel = $completedLevel - 1;
            $this->releaseSavepoint($targetLevel);
            $this->level = $targetLevel;
            $this->stats['current_level'] = $this->level;
            $this->promoteAfterCommitCallbacks($completedLevel, $targetLevel);

            return;
        }

        $this->connection->commitNativeTransaction();
        $durationMs = $this->transactionDurationMs();
        $this->stats['committed']++;
        $this->level = 0;
        $this->stats['current_level'] = 0;
        $this->finishTopLevel();
        $callbacks = $this->drainAfterCommitCallbacks();
        Events::dispatch(
            'db.transaction.committed',
            [new TransactionCommitted($this->connection, $durationMs)],
        );
        $this->runAfterCommitCallbacks($callbacks);
    }

    /**
     * Execute a callback within a transaction, with deadlock retries.
     *
     * @param callable(Connection):mixed $callback
     */
    public function execute(callable $callback, int $attempts = 1): mixed
    {
        $attempts = max(1, min($attempts, self::MAX_ATTEMPTS));
        $attempt = 0;

        beginning:

        $attempt++;

        $this->begin();

        try {
            $result = $callback($this->connection);

            $this->commit();

            return $result;
        } catch (Throwable $e) {
            // A post-commit callback runs after the database is durable. Do not
            // wrap it as a rollback or retry failure once the transaction closed.
            if (!$this->inTransaction()) {
                throw $e;
            }

            try {
                $this->rollBack();
            } catch (Throwable $rollbackFailure) {
                $this->connection->disconnect();

                throw TransactionException::rollbackAlsoFailed($e, $rollbackFailure);
            }

            if ($attempt < $attempts && $this->causedByRetryableTransactionError($e)) {
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
            'total' => 0,
            'committed' => 0,
            'rolled_back' => 0,
            'deadlocks' => 0,
            'in_transaction' => $this->level > 0,
            'current_level' => $this->level,
            'savepoints' => 0,
            'elapsed_time' => 0.0,
        ];

        $this->startedAt = $this->level > 0 ? (microtime(true)) : null;
        if ($this->level === 0) {
            $this->afterCommitCallbacks = [];
        }
    }

    /**
     * Rollback the current transaction or rollback to a savepoint.
     */
    public function rollBack(): void
    {
        if ($this->level === 0) {
            return;
        }

        if ($this->level > 1) {
            $completedLevel = $this->level;
            $targetLevel = $completedLevel - 1;
            $this->rollbackToSavepoint($targetLevel);
            $this->discardAfterCommitCallbacks($completedLevel);
            $this->level = $targetLevel;
            $this->stats['current_level'] = $this->level;

            return;
        }

        $this->connection->rollBackNativeTransaction();
        $durationMs = $this->transactionDurationMs();
        $this->discardAfterCommitCallbacks(1);
        $this->stats['rolled_back']++;
        $this->level = 0;
        $this->stats['current_level'] = 0;
        $this->finishTopLevel();
        Events::dispatch(
            'db.transaction.rolled_back',
            [new TransactionRolledBack($this->connection, $durationMs)],
        );
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
     * Determine if the given exception is a retryable transaction conflict.
     */
    private function causedByRetryableTransactionError(Throwable $e): bool
    {
        $driver = $this->connection->getDriverName();

        return DriverProfile::causedByRetryableTransactionError($driver, $e);
    }

    /**
     * Create a savepoint for a given nesting level.
     */
    private function createSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (!$supportsSavepoints) {
            throw TransactionException::failed(
                new \LogicException('Nested transactions require driver savepoint support.'),
            );
        }

        $this->connection->statement('SAVEPOINT trans_' . $level);
    }

    private function discardAfterCommitCallbacks(int $fromLevel): void
    {
        foreach (array_keys($this->afterCommitCallbacks) as $level) {
            if ($level >= $fromLevel) {
                unset($this->afterCommitCallbacks[$level]);
            }
        }
    }

    /**
     * @return list<callable():void>
     */
    private function drainAfterCommitCallbacks(): array
    {
        if ($this->afterCommitCallbacks === []) {
            return [];
        }

        ksort($this->afterCommitCallbacks);
        $callbacks = array_merge(...array_values($this->afterCommitCallbacks));
        $this->afterCommitCallbacks = [];

        return $callbacks;
    }

    /**
     * Finalize stats for a completed top-level transaction.
     */
    private function finishTopLevel(): void
    {
        if ($this->startedAt !== null) {
            $this->stats['elapsed_time'] += microtime(true) - $this->startedAt;
        }

        $this->startedAt = null;
        $this->stats['in_transaction'] = false;
    }

    private function promoteAfterCommitCallbacks(int $fromLevel, int $toLevel): void
    {
        $callbacks = $this->afterCommitCallbacks[$fromLevel] ?? [];
        unset($this->afterCommitCallbacks[$fromLevel]);

        if ($callbacks !== []) {
            $this->afterCommitCallbacks[$toLevel] = [
                ...($this->afterCommitCallbacks[$toLevel] ?? []),
                ...$callbacks,
            ];
        }
    }

    /**
     * Release a savepoint for a given nesting level.
     */
    private function releaseSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (!$supportsSavepoints) {
            throw TransactionException::failed(
                new \LogicException('Nested transactions require driver savepoint support.'),
            );
        }

        $this->connection->statement('RELEASE SAVEPOINT trans_' . $level);
    }

    /**
     * Rollback to a savepoint for a given nesting level.
     */
    private function rollbackToSavepoint(int $level): void
    {
        $supportsSavepoints = $this->connection->getCapabilities()->supportsSavepoints;

        if (!$supportsSavepoints) {
            throw TransactionException::failed(
                new \LogicException('Nested transactions require driver savepoint support.'),
            );
        }

        $this->connection->statement('ROLLBACK TO SAVEPOINT trans_' . $level);
    }

    /**
     * @param list<callable():void> $callbacks
     */
    private function runAfterCommitCallbacks(array $callbacks): void
    {
        $firstFailure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure instanceof Throwable) {
            throw $firstFailure;
        }
    }

    /**
     * Get elapsed transaction duration in milliseconds for current top-level tx.
     */
    private function transactionDurationMs(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }

        return (microtime(true) - $this->startedAt) * 1_000.0;
    }
}
