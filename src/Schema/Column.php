<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * Column Definition
 * 
 * Represents a single column in a database table with all its properties
 * and modifiers. Provides a fluent interface for configuring column attributes.
 * 
 * @package DBLayer\Schema
 * @author Hasan
 */
class Column
{
    /**
     * The column attributes
     */
    protected array $attributes = [];

    /**
     * Create a new column instance
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Allow NULL values to be inserted into the column
     */
    public function nullable(bool $value = true): static
    {
        $this->attributes['nullable'] = $value;

        return $this;
    }

    /**
     * Specify a default value for the column
     */
    public function default(mixed $value): static
    {
        $this->attributes['default'] = $value;

        return $this;
    }

    /**
     * Set INTEGER column as auto-increment
     */
    public function autoIncrement(): static
    {
        $this->attributes['autoIncrement'] = true;

        return $this;
    }

    /**
     * Set the column as unsigned (MySQL)
     */
    public function unsigned(): static
    {
        $this->attributes['unsigned'] = true;

        return $this;
    }

    /**
     * Add a comment to the column
     */
    public function comment(string $comment): static
    {
        $this->attributes['comment'] = $comment;

        return $this;
    }

    /**
     * Specify the character set for the column (MySQL)
     */
    public function charset(string $charset): static
    {
        $this->attributes['charset'] = $charset;

        return $this;
    }

    /**
     * Specify the collation for the column
     */
    public function collation(string $collation): static
    {
        $this->attributes['collation'] = $collation;

        return $this;
    }

    /**
     * Set the column as primary key
     */
    public function primary(): static
    {
        $this->attributes['primary'] = true;

        return $this;
    }

    /**
     * Set the column as unique
     */
    public function unique(): static
    {
        $this->attributes['unique'] = true;

        return $this;
    }

    /**
     * Set the column as an index
     */
    public function index(): static
    {
        $this->attributes['index'] = true;

        return $this;
    }

    /**
     * Place the column "first" in the table (MySQL)
     */
    public function first(): static
    {
        $this->attributes['first'] = true;

        return $this;
    }

    /**
     * Place the column "after" another column (MySQL)
     */
    public function after(string $column): static
    {
        $this->attributes['after'] = $column;

        return $this;
    }

    /**
     * Set the column as STORED GENERATED
     */
    public function storedAs(string $expression): static
    {
        $this->attributes['storedAs'] = $expression;

        return $this;
    }

    /**
     * Set the column as VIRTUAL GENERATED
     */
    public function virtualAs(string $expression): static
    {
        $this->attributes['virtualAs'] = $expression;

        return $this;
    }

    /**
     * Set the column to use current timestamp as default
     */
    public function useCurrent(): static
    {
        $this->attributes['useCurrent'] = true;

        return $this;
    }

    /**
     * Set the column to use current timestamp on update
     */
    public function useCurrentOnUpdate(): static
    {
        $this->attributes['useCurrentOnUpdate'] = true;

        return $this;
    }

    /**
     * Specify that the column should be invisible (MySQL 8.0.23+)
     */
    public function invisible(): static
    {
        $this->attributes['invisible'] = true;

        return $this;
    }

    /**
     * Change the column definition
     */
    public function change(): static
    {
        $this->attributes['change'] = true;

        return $this;
    }

    /**
     * Get an attribute from the column definition
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Set an attribute on the column definition
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get all of the column attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get the column type
     */
    public function getType(): string
    {
        return $this->attributes['type'] ?? 'string';
    }

    /**
     * Get the column name
     */
    public function getName(): string
    {
        return $this->attributes['name'] ?? '';
    }

    /**
     * Determine if the column is nullable
     */
    public function isNullable(): bool
    {
        return $this->attributes['nullable'] ?? false;
    }

    /**
     * Determine if the column has a default value
     */
    public function hasDefault(): bool
    {
        return array_key_exists('default', $this->attributes);
    }

    /**
     * Get the default value
     */
    public function getDefault(): mixed
    {
        return $this->attributes['default'] ?? null;
    }

    /**
     * Determine if the column is auto-incrementing
     */
    public function isAutoIncrement(): bool
    {
        return $this->attributes['autoIncrement'] ?? false;
    }

    /**
     * Determine if the column is unsigned
     */
    public function isUnsigned(): bool
    {
        return $this->attributes['unsigned'] ?? false;
    }

    /**
     * Determine if the column is a primary key
     */
    public function isPrimary(): bool
    {
        return $this->attributes['primary'] ?? false;
    }

    /**
     * Determine if the column is unique
     */
    public function isUnique(): bool
    {
        return $this->attributes['unique'] ?? false;
    }

    /**
     * Determine if the column is an index
     */
    public function isIndex(): bool
    {
        return $this->attributes['index'] ?? false;
    }

    /**
     * Get the column length
     */
    public function getLength(): ?int
    {
        return $this->attributes['length'] ?? null;
    }

    /**
     * Get the column precision
     */
    public function getPrecision(): ?int
    {
        return $this->attributes['precision'] ?? null;
    }

    /**
     * Get the column scale
     */
    public function getScale(): ?int
    {
        return $this->attributes['scale'] ?? null;
    }

    /**
     * Convert the column to an array
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the column definition to a string
     */
    public function __toString(): string
    {
        return $this->getName();
    }

    /**
     * Dynamically retrieve attributes on the column
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    /**
     * Dynamically set attributes on the column
     */
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Dynamically check if an attribute is set
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
}
