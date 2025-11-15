<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionBeginning;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionCommitted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionRolledBack;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\TransactionException;

/**
 * Transaction Manager
 *
 * Manages database transactions with:
 * - Nested transaction support via savepoints
 * - Automatic deadlock retry
 * - Transaction timeout detection
 * - Event callbacks (before begin, after commit, after rollback)
 * - Transaction statistics
 *
 * @package Infocyph\DBLayer\Transaction
 * @author Hasan
 */
final class Transaction
{
    /**
     * Event callbacks.
     *
     * @var array{
     *   beforeBegin: list<callable>,
     *   afterCommit: list<callable>,
     *   afterRollback: list<callable>
     * }
     */
    private array $callbacks = [
      'beforeBegin' => [],
      'afterCommit' => [],
      'afterRollback' => [],
    ];

    /**
     * Database connection.
     */
    private readonly Connection $connection;

    /**
     * Current transaction level (0 = no transaction).
     */
    private int $level = 0;

    /**
     * Maximum transaction time in seconds.
     */
    private int $maxTransactionTime = 30;

    /**
     * Savepoint stack.
     *
     * @var list<string>
     */
    private array $savepoints = [];

    /**
     * Transaction start time (top-level only).
     */
    private ?float $startTime = null;

    /**
     * Transaction statistics.
     *
     * @var array{
     *   total:int,
     *   committed:int,
     *   rolled_back:int,
     *   deadlocks:int,
     *   timeouts:int
     * }
     */
    private array $stats = [
      'total'       => 0,
      'committed'   => 0,
      'rolled_back' => 0,
      'deadlocks'   => 0,
      'timeouts'    => 0,
    ];

    /**
     * Create a new transaction manager.
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Register an after commit callback.
     */
    public function afterCommit(callable $callback): void
    {
        $this->callbacks['afterCommit'][] = $callback;
    }

    /**
     * Register an after rollback callback.
     */
    public function afterRollback(callable $callback): void
    {
        $this->callbacks['afterRollback'][] = $callback;
    }

    /**
     * Register a before begin callback.
     */
    public function beforeBegin(callable $callback): void
    {
        $this->callbacks['beforeBegin'][] = $callback;
    }

    /**
     * Begin a transaction (supports nesting via savepoints).
     */
    public function begin(): void
    {
        if ($this->level === 0) {
            // Fire before-begin callbacks.
            $this->fireCallbacks('beforeBegin');

            // Start actual transaction.
            $this->connection->beginTransaction();
            $this->startTime = microtime(true);
            $this->stats['total']++;

            // Dispatch typed event.
            Events::dispatch(
              TransactionBeginning::class,
              [new TransactionBeginning($this->connection)]
            );
        } else {
            // Nested transaction → use savepoint.
            $savepointName = $this->createSavepoint();
            $this->connection
              ->getPdo()
              ->exec("SAVEPOINT {$savepointName}");
        }

        $this->level++;
    }

    /**
     * Clear all callbacks.
     */
    public function clearCallbacks(): void
    {
        $this->callbacks = [
          'beforeBegin' => [],
          'afterCommit' => [],
          'afterRollback' => [],
        ];
    }

    /**
     * Commit the current transaction (or release savepoint for nested).
     */
    public function commit(): void
    {
        if ($this->level === 0) {
            throw TransactionException::notActive();
        }

        $this->checkTimeout();
        $this->level--;

        if ($this->level === 0) {
            // Commit actual transaction.
            $this->connection->commit();
            $elapsed          = $this->startTime !== null ? microtime(true) - $this->startTime : 0.0;
            $this->startTime  = null;
            $this->stats['committed']++;

            // Dispatch typed event.
            Events::dispatch(
              TransactionCommitted::class,
              [new TransactionCommitted($this->connection, $elapsed)]
            );

            // Fire after-commit callbacks.
            $this->fireCallbacks('afterCommit');
        } else {
            // Release savepoint.
            $savepointName = array_pop($this->savepoints);
            if ($savepointName !== null) {
                $this->connection
                  ->getPdo()
                  ->exec("RELEASE SAVEPOINT {$savepointName}");
            }
        }
    }

    /**
     * Execute a callback within a transaction with optional deadlock retry.
     *
     * @template T
     * @param callable(Connection):T $callback
     * @return T
     */
    public function execute(callable $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->begin();

            try {
                $result = $callback($this->connection);
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->rollback();

                // Check if we should retry.
                if ($attempt === $attempts || !$this->causedByDeadlock($e)) {
                    throw $e;
                }

                $this->stats['deadlocks']++;

                // Exponential-ish backoff: 100ms, 200ms, 300ms...
                usleep(100_000 * $attempt);
            }
        }

        // Should be unreachable, but keeps static analysers happy.
        throw new TransactionException("Transaction failed after {$attempts} attempts");
    }

    /**
     * Get the underlying connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get elapsed transaction time (top-level only).
     */
    public function getElapsedTime(): float
    {
        if ($this->startTime === null) {
            return 0.0;
        }

        return microtime(true) - $this->startTime;
    }

    /**
     * Get maximum transaction time (seconds).
     */
    public function getMaxTime(): int
    {
        return $this->maxTransactionTime;
    }

    /**
     * Get transaction start time (or null).
     */
    public function getStartTime(): ?float
    {
        return $this->startTime;
    }

    /**
     * Get transaction statistics (including derived fields).
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
          'in_transaction' => $this->inTransaction(),
          'current_level'  => $this->level,
          'savepoints'     => count($this->savepoints),
          'elapsed_time'   => $this->getElapsedTime(),
        ]);
    }

    /**
     * Check if currently in a transaction.
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
     * Release a named savepoint.
     */
    public function releaseSavepoint(string $name): void
    {
        $index = array_search($name, $this->savepoints, true);

        if ($index === false) {
            throw TransactionException::savepointNotFound($name);
        }

        $this->connection
          ->getPdo()
          ->exec("RELEASE SAVEPOINT {$name}");

        unset($this->savepoints[$index]);
        $this->savepoints = array_values($this->savepoints);
    }

    /**
     * Reset per-transaction statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
          'total'       => 0,
          'committed'   => 0,
          'rolled_back' => 0,
          'deadlocks'   => 0,
          'timeouts'    => 0,
        ];
    }

    /**
     * Execute with automatic retry on deadlock.
     *
     * @template T
     * @param callable(Connection):T $callback
     * @return T
     */
    public function retry(callable $callback, int $maxAttempts = 3): mixed
    {
        return $this->execute($callback, $maxAttempts);
    }

    /**
     * Rollback the transaction (optionally to a specific nesting level).
     */
    public function rollback(?int $toLevel = null): void
    {
        $toLevel ??= 0;

        if ($this->level === 0) {
            throw TransactionException::notActive();
        }

        while ($this->level > $toLevel) {
            $this->level--;

            if ($this->level === 0) {
                // Rollback actual transaction.
                $this->connection->rollBack();
                $elapsed         = $this->startTime !== null ? microtime(true) - $this->startTime : 0.0;
                $this->startTime = null;
                $this->stats['rolled_back']++;

                // Dispatch typed event.
                Events::dispatch(
                  TransactionRolledBack::class,
                  [new TransactionRolledBack($this->connection, $elapsed)]
                );

                // Fire after-rollback callbacks.
                $this->fireCallbacks('afterRollback');
            } else {
                // Rollback to savepoint.
                $savepointName = array_pop($this->savepoints);
                if ($savepointName !== null) {
                    $this->connection
                      ->getPdo()
                      ->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
                }
            }
        }
    }

    /**
     * Rollback to a specific named savepoint (keeps level as-is).
     */
    public function rollbackToSavepoint(string $name): void
    {
        $index = array_search($name, $this->savepoints, true);

        if ($index === false) {
            throw TransactionException::savepointNotFound($name);
        }

        $this->connection
          ->getPdo()
          ->exec("ROLLBACK TO SAVEPOINT {$name}");

        // Remove savepoints created after this one.
        $this->savepoints = array_slice($this->savepoints, 0, $index + 1);
    }

    /**
     * Create a savepoint and return its name.
     */
    public function savepoint(?string $name = null): string
    {
        if ($this->level === 0) {
            throw TransactionException::notActive();
        }

        $name = $name ?? $this->generateSavepointName();

        $this->connection
          ->getPdo()
          ->exec("SAVEPOINT {$name}");

        $this->savepoints[] = $name;

        return $name;
    }

    /**
     * Set maximum transaction time.
     */
    public function setMaxTime(int $seconds): void
    {
        $this->maxTransactionTime = $seconds;
    }

    /**
     * Determine if exception was caused by a deadlock.
     */
    private function causedByDeadlock(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Deadlock')
          || str_contains($message, 'deadlock')
          || str_contains($message, '1213')   // MySQL deadlock
          || str_contains($message, '40P01')  // PostgreSQL deadlock
          || str_contains($message, '40001'); // SQLSTATE serialization / deadlock
    }

    /**
     * Check transaction timeout for top-level transaction.
     */
    private function checkTimeout(): void
    {
        if ($this->startTime === null) {
            return;
        }

        $elapsed = microtime(true) - $this->startTime;

        if ($elapsed > $this->maxTransactionTime) {
            $this->stats['timeouts']++;
            throw TransactionException::timeout($elapsed, $this->maxTransactionTime);
        }
    }

    /**
     * Create a new auto-generated savepoint name (not executed).
     */
    private function createSavepoint(): string
    {
        $name             = $this->generateSavepointName();
        $this->savepoints[] = $name;

        return $name;
    }

    /**
     * Fire callbacks for an event key.
     */
    private function fireCallbacks(string $event): void
    {
        foreach ($this->callbacks[$event] ?? [] as $callback) {
            $callback();
        }
    }

    /**
     * Generate a unique savepoint name.
     */
    private function generateSavepointName(): string
    {
        return 'sp_' . ($this->level + 1) . '_' . uniqid('', false);
    }
}
