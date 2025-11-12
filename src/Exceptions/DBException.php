<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use RuntimeException;

/**
 * Database Exception
 *
 * Base exception class for all DBLayer exceptions.
 * Provides a foundation for creating specific database-related exceptions.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class DBException extends RuntimeException
{
    /**
     * Create a general database exception
     *
     * @param string $message Error message
     * @param int $code Error code (default: 0)
     * @return self
     */
    public static function create(string $message, int $code = 0): self
    {
        return new self($message, $code);
    }

    /**
     * Create exception for database errors
     *
     * @param string $message Error details
     * @return self
     */
    public static function databaseError(string $message): self
    {
        return new self("Database error: {$message}");
    }

    /**
     * Create exception for unsupported operations
     *
     * @param string $operation The unsupported operation name
     * @return self
     */
    public static function unsupportedOperation(string $operation): self
    {
        return new self("Unsupported operation: {$operation}");
    }

    /**
     * Create exception for invalid configuration
     *
     * @param string $message Configuration error details
     * @return self
     */
    public static function invalidConfiguration(string $message): self
    {
        return new self("Invalid configuration: {$message}");
    }
}
