<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Cache Exception
 *
 * Exception thrown when cache operations fail.
 * Handles errors related to cache storage, retrieval, and invalidation.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class CacheException extends DBException
{
    /**
     * Create exception for cache write failure
     *
     * @param string $key Cache key that failed to write
     * @param string $reason Failure reason
     * @return self
     */
    public static function writeFailed(string $key, string $reason): self
    {
        return new self("Failed to write cache key '{$key}': {$reason}");
    }

    /**
     * Create exception for cache read failure
     *
     * @param string $key Cache key that failed to read
     * @param string $reason Failure reason
     * @return self
     */
    public static function readFailed(string $key, string $reason): self
    {
        return new self("Failed to read cache key '{$key}': {$reason}");
    }

    /**
     * Create exception for cache delete failure
     *
     * @param string $key Cache key that failed to delete
     * @param string $reason Failure reason
     * @return self
     */
    public static function deleteFailed(string $key, string $reason): self
    {
        return new self("Failed to delete cache key '{$key}': {$reason}");
    }

    /**
     * Create exception for invalid cache key
     *
     * @param string $key Invalid cache key
     * @return self
     */
    public static function invalidKey(string $key): self
    {
        return new self("Invalid cache key: '{$key}'");
    }

    /**
     * Create exception for cache directory issues
     *
     * @param string $path Directory path
     * @param string $issue Issue description (not writable, doesn't exist, etc.)
     * @return self
     */
    public static function directoryIssue(string $path, string $issue): self
    {
        return new self("Cache directory issue at '{$path}': {$issue}");
    }

    /**
     * Create exception for serialization errors
     *
     * @param string $message Serialization error details
     * @return self
     */
    public static function serializationError(string $message): self
    {
        return new self("Cache serialization error: {$message}");
    }

    /**
     * Create exception for deserialization errors
     *
     * @param string $message Deserialization error details
     * @return self
     */
    public static function deserializationError(string $message): self
    {
        return new self("Cache deserialization error: {$message}");
    }

    /**
     * Create exception for cache strategy not found
     *
     * @param string $strategy Requested strategy name
     * @return self
     */
    public static function strategyNotFound(string $strategy): self
    {
        return new self("Cache strategy not found: {$strategy}");
    }

    /**
     * Create exception for tag operation errors
     *
     * @param string $operation Operation name (flush, invalidate, etc.)
     * @param string $message Error details
     * @return self
     */
    public static function tagOperationFailed(string $operation, string $message): self
    {
        return new self("Cache tag operation '{$operation}' failed: {$message}");
    }

    /**
     * Create exception for expired cache
     *
     * @param string $key Cache key that expired
     * @return self
     */
    public static function expired(string $key): self
    {
        return new self("Cache key expired: '{$key}'");
    }
}
