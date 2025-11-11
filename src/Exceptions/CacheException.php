<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Cache Exception
 *
 * Exception for cache-related errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class CacheException extends DBException
{
    /**
     * Create exception for invalid cache key
     */
    public static function invalidKey(string $key): self
    {
        return new self("Invalid cache key: {$key}");
    }

    /**
     * Create exception for cache read failure
     */
    public static function readFailed(string $key): self
    {
        return new self("Failed to read from cache: {$key}");
    }

    /**
     * Create exception for cache storage full
     */
    public static function storageFull(): self
    {
        return new self('Cache storage is full');
    }

    /**
     * Create exception for unsupported strategy
     */
    public static function unsupportedStrategy(string $strategy): self
    {
        return new self("Unsupported cache strategy: {$strategy}");
    }
    /**
     * Create exception for cache write failure
     */
    public static function writeFailed(string $key): self
    {
        return new self("Failed to write to cache: {$key}");
    }
}
