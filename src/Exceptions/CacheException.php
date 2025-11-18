<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to DBLayer's internal caching strategies.
 */
final class CacheException extends DBException
{
    public static function deleteFailed(string $key, string $reason): self
    {
        return new self("Failed to delete cache entry [{$key}]: {$reason}");
    }

    public static function directoryIssue(string $path, string $issue): self
    {
        return new self("Cache directory issue at [{$path}]: {$issue}");
    }

    public static function invalidKey(string $key): self
    {
        return new self("Invalid cache key [{$key}].");
    }

    public static function readFailed(string $key, string $reason): self
    {
        return new self("Failed to read cache entry [{$key}]: {$reason}");
    }

    public static function serializationError(string $message): self
    {
        return new self('Cache serialization error: ' . $message);
    }
    public static function writeFailed(string $key, string $reason): self
    {
        return new self("Failed to write cache entry [{$key}]: {$reason}");
    }
}
