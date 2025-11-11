<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Schema Exception
 *
 * Exception for schema operations errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class SchemaException extends DBException
{
    /**
     * Create exception for column not found
     */
    public static function columnNotFound(string $table, string $column): self
    {
        return new self("Column '{$column}' not found in table '{$table}'");
    }

    /**
     * Create exception for invalid blueprint
     */
    public static function invalidBlueprint(string $reason): self
    {
        return new self("Invalid blueprint: {$reason}");
    }

    /**
     * Create exception for invalid column type
     */
    public static function invalidColumnType(string $type): self
    {
        return new self("Invalid column type: {$type}");
    }

    /**
     * Create exception for table already exists
     */
    public static function tableExists(string $table): self
    {
        return new self("Table already exists: {$table}");
    }
    /**
     * Create exception for table not found
     */
    public static function tableNotFound(string $table): self
    {
        return new self("Table not found: {$table}");
    }

    /**
     * Create exception for unsupported driver
     */
    public static function unsupportedDriver(string $driver): self
    {
        return new self("Unsupported database driver for schema operations: {$driver}");
    }
}
