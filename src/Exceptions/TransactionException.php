<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to transactions and savepoints.
 */
final class TransactionException extends DBException
{
    public static function beginFailed(string $message): self
    {
        return new self('Failed to begin transaction: ' . $message);
    }

    public static function commitFailed(string $message): self
    {
        return new self('Failed to commit transaction: ' . $message);
    }

    public static function rollBackFailed(string $message): self
    {
        return new self('Failed to roll back transaction: ' . $message);
    }

    public static function deadlockDetected(string $message = ''): self
    {
        $base = 'Transaction deadlock detected';

        if ($message !== '') {
            $base .= ': ' . $message;
        }

        return new self($base);
    }

    public static function savepointError(string $savepoint, string $operation): self
    {
        return new self("Savepoint operation [{$operation}] failed for savepoint [{$savepoint}].");
    }

    public static function savepointNotFound(string $savepoint): self
    {
        return new self("Savepoint [{$savepoint}] does not exist.");
    }

    public static function nestedTransactionError(int $level, string $message): self
    {
        return new self("Nested transaction error at level {$level}: {$message}");
    }

    public static function isolationLevelError(string $level, string $reason): self
    {
        return new self("Invalid isolation level [{$level}]: {$reason}");
    }

    public static function lockWaitTimeout(string $resource): self
    {
        return new self("Lock wait timeout exceeded while waiting for resource [{$resource}].");
    }

    public static function maxNestingLevelExceeded(int $maxLevel): self
    {
        return new self("Maximum transaction nesting level [{$maxLevel}] exceeded.");
    }

    public static function readOnlyViolation(): self
    {
        return new self('Transaction attempted write in read-only mode.');
    }

    /**
     * No active transaction when commit/rollback/savepoint is attempted.
     */
    public static function notActive(): self
    {
        return new self('No active transaction is currently in progress.');
    }

    /**
     * Transaction exceeded configured time limit.
     */
    public static function timeout(float $elapsedSeconds, int $maxSeconds): self
    {
        $elapsed = sprintf('%.4f', $elapsedSeconds);

        return new self(
          "Transaction timeout after {$elapsed} seconds (max allowed: {$maxSeconds} seconds)."
        );
    }
}
