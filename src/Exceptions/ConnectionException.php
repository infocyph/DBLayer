<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to establishing or maintaining database connections.
 */
final class ConnectionException extends DBException
{
    public static function authenticationFailed(string $username, string $reason = ''): self
    {
        $message = "Authentication failed for user [{$username}]";

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    /**
     * Named config not found in pool (used by Connection\Pool).
     */
    public static function configNotFound(string $name): self
    {
        return new self("Connection configuration [{$name}] not found in pool.");
    }

    /**
     * Generic connection failure (driver name, DSN or label in $target).
     */
    public static function connectionFailed(string $target, string $reason = ''): self
    {
        $message = "Failed to connect to database [{$target}]";

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function connectionNotFound(?string $name = null): self
    {
        $suffix = $name !== null ? " [{$name}]" : '';

        return new self("Database connection{$suffix} not found.");
    }

    /**
     * Older name (kept for compatibility).
     */
    public static function driverNotSupported(string $driver): self
    {
        return new self("Database driver [{$driver}] is not supported.");
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self('Invalid connection configuration: ' . $message);
    }

    public static function lostConnection(string $reason = ''): self
    {
        $message = 'Lost connection to the database server';

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    /**
     * Reached max reconnect attempts (used by Connection::reconnect()).
     */
    public static function maxReconnectAttemptsReached(int $attempts): self
    {
        return new self("Maximum reconnection attempts ({$attempts}) reached.");
    }

    /**
     * Missing required config key (used by ConnectionConfig).
     */
    public static function missingConfigKey(string $key): self
    {
        return new self("Missing required connection config key [{$key}].");
    }

    /**
     * Required extension / package missing for the selected driver.
     */
    public static function missingExtension(string $extension): self
    {
        return new self(
          "Required database extension or package [{$extension}] is not installed or enabled."
        );
    }

    /**
     * Pool exhausted all allowed connections (used by Connection\Pool).
     */
    public static function poolExhausted(int $maxConnections): self
    {
        return new self(
          "Connection pool exhausted (max {$maxConnections} connections in use)."
        );
    }

    /**
     * Query-level failure wrapper (used by Connection::execute()).
     */
    public static function queryFailed(string $sql, string $error): self
    {
        return new self("Database query failed: {$error}. SQL: {$sql}");
    }

    public static function timeout(float $seconds): self
    {
        $formatted = sprintf('%.4f', $seconds);

        return new self('Database connection timeout after ' . $formatted . ' seconds.');
    }

    public static function transactionError(string $reason): self
    {
        return new self("Database transaction error: {$reason}");
    }

    /**
     * Canonical name used in new code.
     */
    public static function unsupportedDriver(string $driver): self
    {
        return self::driverNotSupported($driver);
    }
}
