<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Connection Exception
 *
 * Exception thrown when database connection errors occur.
 * Handles connection failures, lost connections, and configuration errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ConnectionException extends DBException
{
    /**
     * Create exception for connection configuration not found
     *
     * @param string $name Connection name that was not found
     * @return self
     */
    public static function configNotFound(string $name): self
    {
        return new self("Connection configuration not found: {$name}");
    }

    /**
     * Create exception for connection failure
     *
     * @param string $driver Database driver name (mysql, pgsql, sqlite)
     * @param string $reason Failure reason
     * @return self
     */
    public static function connectionFailed(string $driver, string $reason): self
    {
        return new self("Failed to connect to {$driver} database: {$reason}");
    }

    /**
     * Create exception for lost connection
     *
     * @return self
     */
    public static function lostConnection(): self
    {
        return new self('Database connection lost');
    }

    /**
     * Create exception when maximum reconnection attempts reached
     *
     * @param int $maxAttempts Maximum number of attempts made (default: 3)
     * @return self
     */
    public static function maxReconnectAttemptsReached(int $maxAttempts = 3): self
    {
        return new self("Maximum reconnection attempts ({$maxAttempts}) reached");
    }

    /**
     * Create exception for missing configuration key
     *
     * @param string $key Missing configuration key name
     * @return self
     */
    public static function missingConfigKey(string $key): self
    {
        return new self("Missing required configuration key: {$key}");
    }

    /**
     * Create exception for missing PHP extension
     *
     * @param string $extension Missing extension name (pdo_mysql, pdo_pgsql, etc.)
     * @return self
     */
    public static function missingExtension(string $extension): self
    {
        return new self(
            "Required PHP extension not installed: {$extension}. " .
            "Please install the extension to use this database driver."
        );
    }

    /**
     * Create exception for pool exhaustion
     *
     * @param int $maxConnections Maximum connection pool size
     * @return self
     */
    public static function poolExhausted(int $maxConnections): self
    {
        return new self(
            "Connection pool exhausted. Maximum connections ({$maxConnections}) reached."
        );
    }

    /**
     * Create exception for invalid DSN
     *
     * @param string $dsn Invalid DSN string
     * @return self
     */
    public static function invalidDsn(string $dsn): self
    {
        return new self("Invalid database DSN: {$dsn}");
    }

    /**
     * Create exception for connection timeout
     *
     * @param float $timeout Timeout duration in seconds
     * @return self
     */
    public static function timeout(float $timeout): self
    {
        return new self("Database connection timed out after {$timeout} seconds");
    }

    /**
     * Create exception for unsupported driver
     *
     * @param string $driver Unsupported driver name
     * @return self
     */
    public static function unsupportedDriver(string $driver): self
    {
        return new self(
            "Unsupported database driver: {$driver}. " .
            "Supported drivers: mysql, pgsql, sqlite."
        );
    }
}
