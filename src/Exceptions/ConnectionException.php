<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Connection Exception
 *
 * Exception for database connection errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ConnectionException extends DBException
{
    /**
     * Create exception for config not found
     */
    public static function configNotFound(string $name): self
    {
        return new self("Connection configuration not found: {$name}");
    }
    /**
     * Create exception for connection failure
     */
    public static function connectionFailed(string $driver, string $reason): self
    {
        return new self("Failed to connect to {$driver} database: {$reason}");
    }

    /**
     * Create exception for lost connection
     */
    public static function lostConnection(): self
    {
        return new self('Database connection lost');
    }

    /**
     * Create exception for max reconnect attempts
     */
    public static function maxReconnectAttemptsReached(): self
    {
        return new self('Maximum reconnection attempts reached');
    }

    /**
     * Create exception for missing config key
     */
    public static function missingConfigKey(string $key): self
    {
        return new self("Missing required configuration key: {$key}");
    }

    /**
     * Create exception for missing extension
     */
    public static function missingExtension(string $extension): self
    {
        return new self("Required PHP extension not found: {$extension}");
    }

    /**
     * Create exception for pool exhausted
     */
    public static function poolExhausted(int $maxConnections): self
    {
        return new self("Connection pool exhausted (max: {$maxConnections})");
    }

    /**
     * Create exception for query failed
     */
    public static function queryFailed(string $sql, string $reason): self
    {
        return new self("Query execution failed: {$reason}\nSQL: {$sql}");
    }

    /**
     * Create exception for unsupported driver
     */
    public static function unsupportedDriver(string $driver): self
    {
        return new self("Unsupported database driver: {$driver}");
    }
}
