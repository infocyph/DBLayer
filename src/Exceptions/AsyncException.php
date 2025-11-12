<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Async Exception
 *
 * Exception thrown when asynchronous operations fail.
 * Handles errors related to async adapters, promises, and coroutines.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class AsyncException extends DBException
{
    /**
     * Create exception for adapter not found
     *
     * @param string $adapterName The requested adapter name
     * @return self
     */
    public static function adapterNotFound(string $adapterName): self
    {
        return new self("Async adapter not found: {$adapterName}");
    }

    /**
     * Create exception for missing async extension
     *
     * @param string $extension Required extension name (swoole, amp, reactphp)
     * @return self
     */
    public static function extensionMissing(string $extension): self
    {
        return new self(
            "Required async extension not installed: {$extension}. " .
            "Please install the extension to use async features."
        );
    }

    /**
     * Create exception for promise rejection
     *
     * @param string $reason Rejection reason
     * @return self
     */
    public static function promiseRejected(string $reason): self
    {
        return new self("Promise rejected: {$reason}");
    }

    /**
     * Create exception for timeout
     *
     * @param float $timeout Timeout duration in seconds
     * @return self
     */
    public static function timeout(float $timeout): self
    {
        return new self("Async operation timed out after {$timeout} seconds");
    }

    /**
     * Create exception for coroutine errors
     *
     * @param string $message Error details
     * @return self
     */
    public static function coroutineError(string $message): self
    {
        return new self("Coroutine error: {$message}");
    }

    /**
     * Create exception for pool exhaustion
     *
     * @param int $maxConnections Maximum connection limit
     * @return self
     */
    public static function poolExhausted(int $maxConnections): self
    {
        return new self(
            "Async connection pool exhausted. Maximum connections ({$maxConnections}) reached."
        );
    }

    /**
     * Create exception for invalid adapter configuration
     *
     * @param string $message Configuration error details
     * @return self
     */
    public static function invalidConfiguration(string $message): self
    {
        return new self("Invalid async adapter configuration: {$message}");
    }
}
