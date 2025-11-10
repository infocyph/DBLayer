<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Transaction Exception
 * 
 * Thrown when transaction-related errors occur.
 * Handles transaction state and rollback scenarios.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class TransactionException extends DBLayerException
{
    /**
     * Create exception for begin transaction failure
     */
    public static function beginFailed(string $reason, ?\Throwable $previous = null): static
    {
        return new static(
            "Failed to begin transaction: {$reason}",
            3001,
            $previous,
            ['reason' => $reason]
        );
    }

    /**
     * Create exception for commit failure
     */
    public static function commitFailed(string $reason, ?\Throwable $previous = null): static
    {
        return new static(
            "Failed to commit transaction: {$reason}",
            3002,
            $previous,
            ['reason' => $reason]
        );
    }

    /**
     * Create exception for rollback failure
     */
    public static function rollbackFailed(string $reason, ?\Throwable $previous = null): static
    {
        return new static(
            "Failed to rollback transaction: {$reason}",
            3003,
            $previous,
            ['reason' => $reason]
        );
    }

    /**
     * Create exception for no active transaction
     */
    public static function noActiveTransaction(): static
    {
        return new static(
            'No active transaction to commit or rollback',
            3004
        );
    }

    /**
     * Create exception for nested transaction not supported
     */
    public static function nestedNotSupported(): static
    {
        return new static(
            'Nested transactions are not supported by this driver',
            3005
        );
    }

    /**
     * Create exception for transaction already active
     */
    public static function alreadyActive(): static
    {
        return new static(
            'Transaction already active',
            3006
        );
    }

    /**
     * Create exception for deadlock detected
     */
    public static function deadlock(?\Throwable $previous = null): static
    {
        return new static(
            'Deadlock detected during transaction',
            3007,
            $previous
        );
    }

    /**
     * Create exception for savepoint not found
     */
    public static function savepointNotFound(string $name): static
    {
        return new static(
            "Savepoint '{$name}' not found",
            3008,
            null,
            ['savepoint' => $name]
        );
    }
}
