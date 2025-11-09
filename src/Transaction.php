<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

/**
 * Transaction manager with nested transaction support via savepoints
 */
class Transaction
{
    private Connection $connection;
    private int $level = 0;
    private array $savepoints = [];
    private ?float $startTime = null;
    private const MAX_TRANSACTION_TIME = 30;
    private array $callbacks = [
        'beforeBegin' => [],
        'afterCommit' => [],
        'afterRollback' => []
    ];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function begin(): void
    {
        if ($this->level === 0) {
            $this->connection->getPdo()->beginTransaction();
            $this->startTime = microtime(true);
            Events::dispatch('transaction.beginning', [new TransactionBeginning($this->connection)]);
            $this->fireCallbacks('beforeBegin');
        } else {
            // Nested transaction - use savepoint
            $savepointName = $this->createSavepoint();
            $this->connection->getPdo()->exec("SAVEPOINT {$savepointName}");
        }

        $this->level++;
    }

    public function commit(): void
    {
        if ($this->level === 0) {
            throw new TransactionException("No active transaction to commit");
        }

        $this->checkTimeout();
        $this->level--;

        if ($this->level === 0) {
            $this->connection->getPdo()->commit();
            $this->startTime = null;
            Events::dispatch('transaction.committed', [new TransactionCommitted($this->connection)]);
            $this->fireCallbacks('afterCommit');
        } else {
            // Release savepoint
            $savepointName = array_pop($this->savepoints);
            $this->connection->getPdo()->exec("RELEASE SAVEPOINT {$savepointName}");
        }
    }

    public function rollback(?int $toLevel = null): void
    {
        $toLevel = $toLevel ?? 0;

        if ($this->level === 0) {
            throw new TransactionException("No active transaction to rollback");
        }

        while ($this->level > $toLevel) {
            $this->level--;

            if ($this->level === 0) {
                $this->connection->getPdo()->rollBack();
                $this->startTime = null;
                Events::dispatch('transaction.rolledback', [new TransactionRolledBack($this->connection)]);
                $this->fireCallbacks('afterRollback');
            } else {
                // Rollback to savepoint
                $savepointName = array_pop($this->savepoints);
                $this->connection->getPdo()->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
            }
        }
    }

    public function savepoint(?string $name = null): string
    {
        if ($this->level === 0) {
            throw new TransactionException("Cannot create savepoint outside transaction");
        }

        $name = $name ?? $this->generateSavepointName();
        $this->connection->getPdo()->exec("SAVEPOINT {$name}");
        $this->savepoints[] = $name;

        return $name;
    }

    public function rollbackToSavepoint(string $name): void
    {
        $index = array_search($name, $this->savepoints);
        
        if ($index === false) {
            throw new TransactionException("Savepoint '{$name}' not found");
        }

        $this->connection->getPdo()->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    public function releaseSavepoint(string $name): void
    {
        $index = array_search($name, $this->savepoints);
        
        if ($index === false) {
            throw new TransactionException("Savepoint '{$name}' not found");
        }

        $this->connection->getPdo()->exec("RELEASE SAVEPOINT {$name}");
        unset($this->savepoints[$index]);
        $this->savepoints = array_values($this->savepoints);
    }

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

                if ($attempt === $attempts || !$this->causedByDeadlock($e)) {
                    throw $e;
                }

                // Exponential backoff
                usleep(100000 * $attempt);
            }
        }

        throw new TransactionException("Transaction failed after {$attempts} attempts");
    }

    public function level(): int
    {
        return $this->level;
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    public function getStartTime(): ?float
    {
        return $this->startTime;
    }

    public function getElapsedTime(): float
    {
        if ($this->startTime === null) {
            return 0;
        }

        return microtime(true) - $this->startTime;
    }

    public function beforeBegin(callable $callback): void
    {
        $this->callbacks['beforeBegin'][] = $callback;
    }

    public function afterCommit(callable $callback): void
    {
        $this->callbacks['afterCommit'][] = $callback;
    }

    public function afterRollback(callable $callback): void
    {
        $this->callbacks['afterRollback'][] = $callback;
    }

    private function checkTimeout(): void
    {
        if ($this->startTime !== null) {
            Security::checkTransactionTimeout($this->startTime, self::MAX_TRANSACTION_TIME);
        }
    }

    private function createSavepoint(): string
    {
        $name = $this->generateSavepointName();
        $this->savepoints[] = $name;
        return $name;
    }

    private function generateSavepointName(): string
    {
        return 'sp_' . (count($this->savepoints) + 1) . '_' . uniqid();
    }

    private function fireCallbacks(string $event): void
    {
        foreach ($this->callbacks[$event] ?? [] as $callback) {
            $callback();
        }
    }

    private function causedByDeadlock(\Throwable $e): bool
    {
        $message = $e->getMessage();
        
        return str_contains($message, 'Deadlock') ||
               str_contains($message, 'deadlock') ||
               str_contains($message, '1213') ||
               str_contains($message, '40P01'); // PostgreSQL deadlock code
    }
}
