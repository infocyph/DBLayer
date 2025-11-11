<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Security Exception
 *
 * Exception for security violations and threats.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class SecurityException extends DBException
{
    /**
     * Create exception for confirmation required
     */
    public static function confirmationRequired(string $operation): self
    {
        return new self("Dangerous operation requires confirmation: {$operation}");
    }

    /**
     * Create exception for dangerous query
     */
    public static function dangerousQuery(string $sql): self
    {
        return new self('Dangerous query pattern detected');
    }

    /**
     * Create exception for IN clause too large
     */
    public static function inClauseTooLarge(int $count, int $max): self
    {
        return new self("IN clause too large: {$count} items (max: {$max})");
    }

    /**
     * Create exception for invalid column name
     */
    public static function invalidColumnName(string $column): self
    {
        return new self("Invalid column name: {$column}");
    }

    /**
     * Create exception for invalid table name
     */
    public static function invalidTableName(string $table): self
    {
        return new self("Invalid table name: {$table}");
    }

    /**
     * Create exception for invalid token
     */
    public static function invalidToken(): self
    {
        return new self('Invalid security token');
    }

    /**
     * Create exception for query too long
     */
    public static function queryTooLong(int $length, int $max): self
    {
        return new self("Query too long: {$length} characters (max: {$max})");
    }

    /**
     * Create exception for rate limit exceeded
     */
    public static function rateLimitExceeded(int $limit, string $period): self
    {
        return new self("Rate limit exceeded: {$limit} queries per {$period}");
    }
    /**
     * Create exception for SQL injection detected
     */
    public static function sqlInjectionDetected(): self
    {
        return new self('Possible SQL injection detected');
    }

    /**
     * Create exception for transaction timeout
     */
    public static function transactionTimeout(float $elapsed, int $max): self
    {
        return new self("Transaction timeout: " . round($elapsed, 2) . "s (max: {$max}s)");
    }
}
