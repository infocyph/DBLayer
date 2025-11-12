<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Query Exception
 *
 * Exception thrown when query operations fail.
 * Handles errors related to query building, execution, and binding.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class QueryException extends DBException
{
    /**
     * Create exception for query execution failure
     *
     * @param string $sql SQL query that failed
     * @param string $message Error message from database
     * @param string $code Database error code
     * @return self
     */
    public static function executionFailed(string $sql, string $message, string $code = ''): self
    {
        $error = "Query execution failed";
        if ($code) {
            $error .= " (Error {$code})";
        }
        $error .= ": {$message}\nSQL: {$sql}";
        
        return new self($error);
    }

    /**
     * Create exception for invalid SQL syntax
     *
     * @param string $message Syntax error details
     * @param string $sql SQL query with syntax error
     * @return self
     */
    public static function invalidSyntax(string $message, string $sql = ''): self
    {
        $error = "Invalid SQL syntax: {$message}";
        if ($sql) {
            $error .= "\nSQL: {$sql}";
        }
        
        return new self($error);
    }

    /**
     * Create exception for parameter binding errors
     *
     * @param string $parameter Parameter name or position
     * @param string $message Binding error details
     * @return self
     */
    public static function bindingError(string $parameter, string $message): self
    {
        return new self("Parameter binding error for '{$parameter}': {$message}");
    }

    /**
     * Create exception for missing required parameter
     *
     * @param string $parameter Missing parameter name
     * @return self
     */
    public static function missingParameter(string $parameter): self
    {
        return new self("Missing required query parameter: {$parameter}");
    }

    /**
     * Create exception for invalid query builder state
     *
     * @param string $message State error description
     * @return self
     */
    public static function invalidBuilderState(string $message): self
    {
        return new self("Invalid query builder state: {$message}");
    }

    /**
     * Create exception for ambiguous column reference
     *
     * @param string $column Column name
     * @return self
     */
    public static function ambiguousColumn(string $column): self
    {
        return new self("Ambiguous column reference: {$column}");
    }

    /**
     * Create exception for unknown column
     *
     * @param string $column Column name
     * @param string $table Table name
     * @return self
     */
    public static function unknownColumn(string $column, string $table = ''): self
    {
        $message = "Unknown column: {$column}";
        if ($table) {
            $message .= " in table '{$table}'";
        }
        
        return new self($message);
    }

    /**
     * Create exception for duplicate column in select
     *
     * @param string $column Duplicate column name
     * @return self
     */
    public static function duplicateColumn(string $column): self
    {
        return new self("Duplicate column in SELECT: {$column}");
    }

    /**
     * Create exception for query timeout
     *
     * @param float $timeout Timeout duration in seconds
     * @param string $sql SQL query that timed out
     * @return self
     */
    public static function timeout(float $timeout, string $sql = ''): self
    {
        $error = "Query execution timed out after {$timeout} seconds";
        if ($sql) {
            $error .= "\nSQL: {$sql}";
        }
        
        return new self($error);
    }

    /**
     * Create exception for empty query result when one expected
     *
     * @return self
     */
    public static function noResults(): self
    {
        return new self("Query returned no results when at least one was expected");
    }

    /**
     * Create exception for multiple results when one expected
     *
     * @param int $count Number of results returned
     * @return self
     */
    public static function multipleResults(int $count): self
    {
        return new self("Query returned {$count} results when only one was expected");
    }

    /**
     * Create exception for invalid operator
     *
     * @param string $operator Invalid operator
     * @return self
     */
    public static function invalidOperator(string $operator): self
    {
        return new self("Invalid query operator: {$operator}");
    }

    /**
     * Create exception for invalid join type
     *
     * @param string $type Invalid join type
     * @return self
     */
    public static function invalidJoinType(string $type): self
    {
        return new self("Invalid join type: {$type}. Supported: INNER, LEFT, RIGHT, CROSS");
    }
}
