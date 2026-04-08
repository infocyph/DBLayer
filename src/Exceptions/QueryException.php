<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to query building, compilation, or execution.
 */
final class QueryException extends DBException
{
    /**
     * When the number of bound parameters does not match placeholders.
     */
    public static function bindingCountMismatch(
        string $sql,
        int $expected,
        int $given,
    ): self {
        $message = "Binding count mismatch for SQL [{$sql}]: expected {$expected}, got {$given}.";

        return new self($message);
    }

    /**
     * Error while building or compiling the query.
     */
    public static function buildingFailed(string $message): self
    {
        return new self('Failed to build query: ' . $message);
    }

    /**
     * Error while executing a query against the database.
     *
     * @param string      $sql   The SQL statement that failed.
     * @param string      $error Error message from the driver.
     * @param string|null $code  Optional driver error code.
     */
    public static function executionFailed(
        string $sql,
        string $error,
        ?string $code = null,
    ): self {
        $message = 'Query execution failed: ' . $error . ' [SQL: ' . $sql . ']';

        if ($code !== null && $code !== '') {
            $message .= ' [code: ' . $code . ']';
        }

        return new self($message);
    }

    /**
     * Invalid LIMIT value.
     *
     * Used by QueryBuilder::limit(), forPage(), chunk(), cursor(), etc.
     */
    public static function invalidLimit(int $limit): self
    {
        return new self(
            "Invalid LIMIT value [{$limit}]. LIMIT must be a positive integer for pagination.",
        );
    }

    /**
     * Invalid OFFSET value.
     *
     * Used by QueryBuilder::offset().
     */
    public static function invalidOffset(int $offset): self
    {
        return new self(
            "Invalid OFFSET value [{$offset}]. OFFSET must be a non-negative integer.",
        );
    }

    /**
     * Invalid ORDER BY direction.
     *
     * Used by QueryBuilder::orderBy().
     */
    public static function invalidOrderDirection(string $direction): self
    {
        return new self(
            "Invalid ORDER BY direction [{$direction}]. Use 'asc' or 'desc'.",
        );
    }

    /**
     * Invalid parameter passed into the query builder.
     */
    public static function invalidParameter(string $name, string $reason): self
    {
        return new self("Invalid query parameter [{$name}]: {$reason}");
    }
}
