<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to security policies (SQL injection, rate limiting, etc.).
 */
class SecurityException extends DBException
{
    public static function sqlInjectionDetected(string $pattern, string $fragment): self
    {
        return new self(
          'Potential SQL injection detected using pattern [' . $pattern . '] in fragment [' . $fragment . '].'
        );
    }

    public static function rateLimitExceeded(string $key, int $maxAttempts, int $ttlSeconds): self
    {
        return new self(
          "Rate limit exceeded for key [{$key}]: max {$maxAttempts} attempts within {$ttlSeconds} seconds."
        );
    }

    public static function unsafeQuery(string $reason): self
    {
        return new self('Unsafe query detected: ' . $reason);
    }

    public static function unsafeOperation(string $message): self
    {
        return new self('Unsafe database operation: ' . $message);
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self('Invalid security configuration: ' . $message);
    }
}
