<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Query Exception
 *
 * Exception for query building and execution errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class QueryException extends DBException
{
    /**
     * Create exception for execution failure
     */
    public static function executionFailed(string $sql, string $reason): self
    {
        return new self("Query execution failed: {$reason}\nSQL: {$sql}");
    }

    /**
     * Create exception for invalid binding
     */
    public static function invalidBinding(string $reason): self
    {
        return new self("Invalid query binding: {$reason}");
    }

    /**
     * Create exception for invalid limit
     */
    public static function invalidLimit(int $limit): self
    {
        return new self("Invalid limit: {$limit}. Must be a positive integer");
    }

    /**
     * Create exception for invalid offset
     */
    public static function invalidOffset(int $offset): self
    {
        return new self("Invalid offset: {$offset}. Must be a non-negative integer");
    }

    /**
     * Create exception for invalid order direction
     */
    public static function invalidOrderDirection(string $direction): self
    {
        return new self("Invalid order direction: {$direction}. Must be 'asc' or 'desc'");
    }

    /**
     * Create exception for invalid query
     */
    public static function invalidQuery(string $reason): self
    {
        return new self("Invalid query: {$reason}");
    }

    /**
     * Create exception for missing table
     */
    public static function missingTable(): self
    {
        return new self('No table specified for query');
    }
}
