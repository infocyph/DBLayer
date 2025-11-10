<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Schema Exception
 * 
 * Thrown when schema-related errors occur.
 * Handles table and column definition errors.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class SchemaException extends DBLayerException
{
    /**
     * Create exception for table already exists
     */
    public static function tableExists(string $table): static
    {
        return new static(
            "Table '{$table}' already exists",
            4001,
            null,
            ['table' => $table]
        );
    }

    /**
     * Create exception for table not found
     */
    public static function tableNotFound(string $table): static
    {
        return new static(
            "Table '{$table}' does not exist",
            4002,
            null,
            ['table' => $table]
        );
    }

    /**
     * Create exception for column already exists
     */
    public static function columnExists(string $column, string $table): static
    {
        return new static(
            "Column '{$column}' already exists in table '{$table}'",
            4003,
            null,
            ['column' => $column, 'table' => $table]
        );
    }

    /**
     * Create exception for column not found
     */
    public static function columnNotFound(string $column, string $table): static
    {
        return new static(
            "Column '{$column}' does not exist in table '{$table}'",
            4004,
            null,
            ['column' => $column, 'table' => $table]
        );
    }

    /**
     * Create exception for invalid column type
     */
    public static function invalidColumnType(string $type): static
    {
        return new static(
            "Invalid column type: {$type}",
            4005,
            null,
            ['type' => $type]
        );
    }

    /**
     * Create exception for index already exists
     */
    public static function indexExists(string $index, string $table): static
    {
        return new static(
            "Index '{$index}' already exists on table '{$table}'",
            4006,
            null,
            ['index' => $index, 'table' => $table]
        );
    }

    /**
     * Create exception for index not found
     */
    public static function indexNotFound(string $index, string $table): static
    {
        return new static(
            "Index '{$index}' does not exist on table '{$table}'",
            4007,
            null,
            ['index' => $index, 'table' => $table]
        );
    }

    /**
     * Create exception for foreign key constraint failure
     */
    public static function foreignKeyConstraint(string $constraint, string $table): static
    {
        return new static(
            "Foreign key constraint '{$constraint}' violation on table '{$table}'",
            4008,
            null,
            ['constraint' => $constraint, 'table' => $table]
        );
    }

    /**
     * Create exception for operation not supported
     */
    public static function operationNotSupported(string $operation, string $driver): static
    {
        return new static(
            "Operation '{$operation}' is not supported by {$driver} driver",
            4009,
            null,
            ['operation' => $operation, 'driver' => $driver]
        );
    }

    /**
     * Create exception for migration not found
     */
    public static function migrationNotFound(string $migration): static
    {
        return new static(
            "Migration '{$migration}' not found",
            4010,
            null,
            ['migration' => $migration]
        );
    }
}
