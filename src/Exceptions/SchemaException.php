<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Schema Exception
 *
 * Exception thrown when schema operations fail.
 * Handles errors related to table creation, modification, and migration.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class SchemaException extends DBException
{
    /**
     * Create exception for table not found
     *
     * @param string $table Table name
     * @return self
     */
    public static function tableNotFound(string $table): self
    {
        return new self("Table not found: {$table}");
    }

    /**
     * Create exception for table already exists
     *
     * @param string $table Table name
     * @return self
     */
    public static function tableExists(string $table): self
    {
        return new self("Table already exists: {$table}");
    }

    /**
     * Create exception for column not found
     *
     * @param string $column Column name
     * @param string $table Table name
     * @return self
     */
    public static function columnNotFound(string $column, string $table): self
    {
        return new self("Column '{$column}' not found in table '{$table}'");
    }

    /**
     * Create exception for column already exists
     *
     * @param string $column Column name
     * @param string $table Table name
     * @return self
     */
    public static function columnExists(string $column, string $table): self
    {
        return new self("Column '{$column}' already exists in table '{$table}'");
    }

    /**
     * Create exception for index not found
     *
     * @param string $index Index name
     * @param string $table Table name
     * @return self
     */
    public static function indexNotFound(string $index, string $table): self
    {
        return new self("Index '{$index}' not found on table '{$table}'");
    }

    /**
     * Create exception for index already exists
     *
     * @param string $index Index name
     * @param string $table Table name
     * @return self
     */
    public static function indexExists(string $index, string $table): self
    {
        return new self("Index '{$index}' already exists on table '{$table}'");
    }

    /**
     * Create exception for foreign key constraint violation
     *
     * @param string $constraint Constraint name
     * @param string $message Violation details
     * @return self
     */
    public static function foreignKeyViolation(string $constraint, string $message): self
    {
        return new self("Foreign key constraint '{$constraint}' violation: {$message}");
    }

    /**
     * Create exception for invalid column definition
     *
     * @param string $column Column name
     * @param string $reason Reason for invalidity
     * @return self
     */
    public static function invalidColumnDefinition(string $column, string $reason): self
    {
        return new self("Invalid column definition for '{$column}': {$reason}");
    }

    /**
     * Create exception for invalid table name
     *
     * @param string $table Invalid table name
     * @return self
     */
    public static function invalidTableName(string $table): self
    {
        return new self("Invalid table name: {$table}");
    }

    /**
     * Create exception for schema modification failure
     *
     * @param string $operation Operation that failed (create, alter, drop, etc.)
     * @param string $message Error details
     * @return self
     */
    public static function modificationFailed(string $operation, string $message): self
    {
        return new self("Schema {$operation} operation failed: {$message}");
    }

    /**
     * Create exception for unsupported column type
     *
     * @param string $type Column type
     * @param string $driver Database driver name
     * @return self
     */
    public static function unsupportedColumnType(string $type, string $driver): self
    {
        return new self("Column type '{$type}' is not supported by {$driver} driver");
    }

    /**
     * Create exception for blueprint errors
     *
     * @param string $message Blueprint error details
     * @return self
     */
    public static function blueprintError(string $message): self
    {
        return new self("Blueprint error: {$message}");
    }

    /**
     * Create exception for migration errors
     *
     * @param string $migration Migration name or identifier
     * @param string $message Error details
     * @return self
     */
    public static function migrationFailed(string $migration, string $message): self
    {
        return new self("Migration '{$migration}' failed: {$message}");
    }

    /**
     * Create exception for unique constraint violation
     *
     * @param string $column Column or columns
     * @param string $table Table name
     * @return self
     */
    public static function uniqueViolation(string $column, string $table): self
    {
        return new self("Unique constraint violation on '{$column}' in table '{$table}'");
    }

    /**
     * Create exception for primary key violation
     *
     * @param string $table Table name
     * @return self
     */
    public static function primaryKeyViolation(string $table): self
    {
        return new self("Primary key constraint violation in table '{$table}'");
    }
}
