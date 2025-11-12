<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Transaction Exception
 *
 * Exception thrown when transaction operations fail.
 * Handles errors related to transaction management, commits, and rollbacks.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class TransactionException extends DBException
{
    /**
     * Create exception when no transaction is active
     *
     * @return self
     */
    public static function notActive(): self
    {
        return new self("No active transaction. Cannot commit or rollback.");
    }

    /**
     * Create exception when transaction already active
     *
     * @return self
     */
    public static function alreadyActive(): self
    {
        return new self("Transaction already active. Cannot start a new transaction.");
    }

    /**
     * Create exception for transaction commit failure
     *
     * @param string $reason Failure reason
     * @return self
     */
    public static function commitFailed(string $reason): self
    {
        return new self("Failed to commit transaction: {$reason}");
    }

    /**
     * Create exception for transaction rollback failure
     *
     * @param string $reason Failure reason
     * @return self
     */
    public static function rollbackFailed(string $reason): self
    {
        return new self("Failed to rollback transaction: {$reason}");
    }

    /**
     * Create exception for transaction timeout
     *
     * @param float $elapsed Elapsed time in seconds
     * @param int $max Maximum allowed time in seconds
     * @return self
     */
    public static function timeout(float $elapsed, int $max): self
    {
        return new self(
            "Transaction timed out after {$elapsed} seconds. " .
            "Maximum transaction time is {$max} seconds."
        );
    }

    /**
     * Create exception for deadlock detection
     *
     * @param string $message Deadlock details
     * @return self
     */
    public static function deadlockDetected(string $message = ''): self
    {
        $error = "Deadlock detected during transaction";
        if ($message) {
            $error .= ": {$message}";
        }
        
        return new self($error);
    }

    /**
     * Create exception for savepoint errors
     *
     * @param string $savepoint Savepoint name
     * @param string $operation Operation that failed (create, rollback, release)
     * @return self
     */
    public static function savepointError(string $savepoint, string $operation): self
    {
        return new self("Failed to {$operation} savepoint '{$savepoint}'");
    }

    /**
     * Create exception when savepoint not found
     *
     * @param string $savepoint Savepoint name
     * @return self
     */
    public static function savepointNotFound(string $savepoint): self
    {
        return new self("Savepoint not found: {$savepoint}");
    }

    /**
     * Create exception for nested transaction errors
     *
     * @param int $level Current nesting level
     * @param string $message Error details
     * @return self
     */
    public static function nestedTransactionError(int $level, string $message): self
    {
        return new self("Nested transaction error at level {$level}: {$message}");
    }

    /**
     * Create exception for isolation level errors
     *
     * @param string $level Isolation level
     * @param string $reason Error reason
     * @return self
     */
    public static function isolationLevelError(string $level, string $reason): self
    {
        return new self("Failed to set isolation level '{$level}': {$reason}");
    }

    /**
     * Create exception for lock wait timeout
     *
     * @param string $resource Resource being locked
     * @return self
     */
    public static function lockWaitTimeout(string $resource): self
    {
        return new self("Lock wait timeout exceeded for resource: {$resource}");
    }

    /**
     * Create exception for max nesting level exceeded
     *
     * @param int $maxLevel Maximum allowed nesting level
     * @return self
     */
    public static function maxNestingLevelExceeded(int $maxLevel): self
    {
        return new self("Maximum transaction nesting level ({$maxLevel}) exceeded");
    }

    /**
     * Create exception for read-only transaction violation
     *
     * @return self
     */
    public static function readOnlyViolation(): self
    {
        return new self("Cannot perform write operations in read-only transaction");
    }
}
