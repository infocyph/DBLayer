<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Transaction Exception
 *
 * Exception for transaction errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class TransactionException extends DBException
{
    /**
     * Create exception for already in transaction
     */
    public static function alreadyActive(): self
    {
        return new self('Transaction already active');
    }

    /**
     * Create exception for deadlock
     */
    public static function deadlock(): self
    {
        return new self('Transaction deadlock detected');
    }
    /**
     * Create exception for transaction not active
     */
    public static function notActive(): self
    {
        return new self('No active transaction');
    }

    /**
     * Create exception for savepoint not found
     */
    public static function savepointNotFound(string $name): self
    {
        return new self("Savepoint not found: {$name}");
    }

    /**
     * Create exception for transaction timeout
     */
    public static function timeout(float $elapsed, int $max): self
    {
        return new self("Transaction timeout: {$elapsed}s elapsed (max: {$max}s)");
    }
}
