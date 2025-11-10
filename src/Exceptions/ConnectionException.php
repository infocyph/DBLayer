<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Connection Exception
 * 
 * Thrown when database connection errors occur.
 * Includes connection-specific error handling.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ConnectionException extends DBLayerException
{
    /**
     * Create exception for connection failure
     */
    public static function connectionFailed(string $driver, string $reason, ?\Throwable $previous = null): static
    {
        return new static(
            "Failed to connect to {$driver} database: {$reason}",
            1001,
            $previous,
            ['driver' => $driver, 'reason' => $reason]
        );
    }

    /**
     * Create exception for invalid DSN
     */
    public static function invalidDsn(string $dsn): static
    {
        return new static(
            "Invalid DSN format: {$dsn}",
            1002,
            null,
            ['dsn' => $dsn]
        );
    }

    /**
     * Create exception for missing extension
     */
    public static function missingExtension(string $extension): static
    {
        return new static(
            "Required PHP extension not loaded: {$extension}",
            1003,
            null,
            ['extension' => $extension]
        );
    }

    /**
     * Create exception for connection timeout
     */
    public static function timeout(int $seconds): static
    {
        return new static(
            "Database connection timeout after {$seconds} seconds",
            1004,
            null,
            ['timeout' => $seconds]
        );
    }

    /**
     * Create exception for lost connection
     */
    public static function lostConnection(): static
    {
        return new static(
            'Database connection was lost',
            1005
        );
    }

    /**
     * Create exception for too many connections
     */
    public static function tooManyConnections(): static
    {
        return new static(
            'Too many database connections',
            1006
        );
    }
}
