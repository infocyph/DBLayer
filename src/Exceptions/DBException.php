<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use Exception;

/**
 * Base Database Exception
 *
 * Base exception class for all database-related errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class DBException extends Exception
{
    /**
     * Create a new database exception
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for invalid configuration
     */
    public static function invalidConfiguration(string $reason): self
    {
        return new self("Invalid database configuration: {$reason}");
    }

    /**
     * Create exception for missing dependency
     */
    public static function missingDependency(string $dependency): self
    {
        return new self("Missing required dependency: {$dependency}");
    }

    /**
     * Create exception for unsupported operation
     */
    public static function unsupportedOperation(string $operation): self
    {
        return new self("Unsupported operation: {$operation}");
    }
}
