<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Query Exception
 * 
 * Thrown when query execution errors occur.
 * Includes SQL query context for debugging.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class QueryException extends DBLayerException
{
    /**
     * The SQL query that caused the exception
     */
    protected string $sql = '';

    /**
     * The query bindings
     */
    protected array $bindings = [];

    /**
     * Create a new query exception
     */
    public function __construct(
        string $message,
        string $sql = '',
        array $bindings = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->sql = $sql;
        $this->bindings = $bindings;

        $context = [
            'sql' => $sql,
            'bindings' => $bindings,
        ];

        parent::__construct($message, $code, $previous, $context);
    }

    /**
     * Get the SQL query
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get the query bindings
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Create exception for syntax error
     */
    public static function syntaxError(string $sql, string $error, ?\Throwable $previous = null): static
    {
        return new static(
            "SQL syntax error: {$error}",
            $sql,
            [],
            2001,
            $previous
        );
    }

    /**
     * Create exception for execution error
     */
    public static function executionFailed(string $sql, array $bindings, string $error, ?\Throwable $previous = null): static
    {
        return new static(
            "Query execution failed: {$error}",
            $sql,
            $bindings,
            2002,
            $previous
        );
    }

    /**
     * Create exception for missing table
     */
    public static function tableNotFound(string $table, string $sql): static
    {
        return new static(
            "Table '{$table}' not found",
            $sql,
            [],
            2003
        );
    }

    /**
     * Create exception for missing column
     */
    public static function columnNotFound(string $column, string $table, string $sql): static
    {
        return new static(
            "Column '{$column}' not found in table '{$table}'",
            $sql,
            [],
            2004
        );
    }

    /**
     * Create exception for duplicate entry
     */
    public static function duplicateEntry(string $key, string $value, string $sql): static
    {
        return new static(
            "Duplicate entry '{$value}' for key '{$key}'",
            $sql,
            [],
            2005
        );
    }

    /**
     * Create exception for constraint violation
     */
    public static function constraintViolation(string $constraint, string $sql): static
    {
        return new static(
            "Constraint violation: {$constraint}",
            $sql,
            [],
            2006
        );
    }

    /**
     * Get formatted exception message with SQL
     */
    public function getFullMessage(): string
    {
        $message = $this->getMessage();
        
        if (!empty($this->sql)) {
            $message .= "\nSQL: {$this->sql}";
        }

        if (!empty($this->bindings)) {
            $message .= "\nBindings: " . json_encode($this->bindings);
        }

        return $message;
    }
}
