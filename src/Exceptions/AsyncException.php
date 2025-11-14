<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to asynchronous database operations and adapters.
 */
class AsyncException extends DBException
{
    public static function adapterNotFound(string $adapterName): self
    {
        return new self("Async adapter [{$adapterName}] is not registered or available.");
    }

    public static function extensionMissing(string $extension): self
    {
        return new self("Required async extension [{$extension}] is not loaded.");
    }

    public static function promiseRejected(string $reason): self
    {
        return new self('Async promise rejected: ' . $reason);
    }

    public static function timeout(float $timeout): self
    {
        return new self('Async operation timeout after ' . $timeout . ' seconds.');
    }

    public static function coroutineError(string $message): self
    {
        return new self('Coroutine error: ' . $message);
    }

    public static function poolExhausted(int $maxConnections): self
    {
        return new self('Async connection pool exhausted (max ' . $maxConnections . ' connections).');
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self('Invalid async configuration: ' . $message);
    }
}
