<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to schema operations (DDL, migrations, metadata).
 */
class SchemaException extends DBException
{
    public static function tableNotFound(string $table): self
    {
        return new self("Table [{$table}] does not exist.");
    }

    public static function tableAlreadyExists(string $table): self
    {
        return new self("Table [{$table}] already exists.");
    }

    public static function columnNotFound(string $table, string $column): self
    {
        return new self("Column [{$column}] does not exist on table [{$table}].");
    }

    public static function indexNotFound(string $table, string $index): self
    {
        return new self("Index [{$index}] does not exist on table [{$table}].");
    }

    public static function invalidDefinition(string $message): self
    {
        return new self('Invalid schema definition: ' . $message);
    }

    public static function migrationError(string $message): self
    {
        return new self('Schema migration error: ' . $message);
    }

    public static function foreignKeyError(string $message): self
    {
        return new self('Foreign key constraint error: ' . $message);
    }

    public static function driverNotSupported(string $driver, string $operation): self
    {
        return new self("Schema operation [{$operation}] is not supported for driver [{$driver}].");
    }
}
