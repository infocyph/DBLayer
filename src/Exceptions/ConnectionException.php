<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to establishing or maintaining database connections.
 */
final class ConnectionException extends DBException
{
    public static function connectionFailed(string $dsn, string $reason = ''): self
    {
        $message = "Failed to connect to database [{$dsn}]";

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function driverNotSupported(string $driver): self
    {
        return new self("Database driver [{$driver}] is not supported.");
    }

    public static function authenticationFailed(string $username, string $reason = ''): self
    {
        $message = "Authentication failed for user [{$username}]";

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function lostConnection(string $reason = ''): self
    {
        $message = 'Lost connection to the database server';

        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message);
    }

    public static function timeout(float $seconds): self
    {
        return new self('Database connection timeout after ' . $seconds . ' seconds.');
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self('Invalid connection configuration: ' . $message);
    }
}
