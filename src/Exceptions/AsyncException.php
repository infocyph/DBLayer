<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Async Exception
 *
 * Exception for async operation errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class AsyncException extends DBException
{
    /**
     * Create exception for adapter not available
     */
    public static function adapterNotAvailable(string $adapter): self
    {
        return new self("Async adapter not available: {$adapter}. Install required extension/package");
    }
    /**
     * Create exception for connection failed
     */
    public static function connectionFailed(string $reason): self
    {
        return new self("Async connection failed: {$reason}");
    }

    /**
     * Create exception for no adapter available
     */
    public static function noAdapterAvailable(): self
    {
        return new self('No async adapter available. Install swoole, amp, or reactphp');
    }

    /**
     * Create exception for not connected
     */
    public static function notConnected(): self
    {
        return new self('Not connected to async database');
    }

    /**
     * Create exception for pool timeout
     */
    public static function poolTimeout(float $seconds): self
    {
        return new self("Connection pool timeout after {$seconds} seconds");
    }

    /**
     * Create exception for promise error
     */
    public static function promiseError(string $reason): self
    {
        return new self("Promise error: {$reason}");
    }

    /**
     * Create exception for query failed
     */
    public static function queryFailed(string $sql, string $reason): self
    {
        return new self("Async query failed: {$reason}\nSQL: {$sql}");
    }

    /**
     * Create exception for unsupported adapter
     */
    public static function unsupportedAdapter(string $adapter): self
    {
        return new self("Unsupported async adapter: {$adapter}");
    }
}
