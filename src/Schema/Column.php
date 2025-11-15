<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

/**
 * Column Definition
 *
 * Represents a single column with all its attributes:
 * - Type and name
 * - Modifiers (nullable, default, unsigned, etc.)
 * - Constraints
 * - Comments
 *
 * @package Infocyph\DBLayer\Schema
 * @author Hasan
 */
final class Column
{
    /**
     * Column attributes.
     *
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * Column name.
     */
    private string $name;

    /**
     * Column parameters (type-dependent options).
     *
     * @var array<string, mixed>
     */
    private array $parameters;

    /**
     * Column type.
     */
    private string $type;

    /**
     * Create a new column instance.
     *
     * @param array<string,mixed> $parameters
     */
    public function __construct(string $type, string $name, array $parameters = [])
    {
        $this->type       = $type;
        $this->name       = $name;
        $this->parameters = $parameters;
    }

    /**
     * Place column after another column.
     */
    public function after(string $column): self
    {
        $this->attributes['after'] = $column;

        return $this;
    }

    /**
     * Mark column as auto-increment.
     */
    public function autoIncrement(): self
    {
        $this->attributes['autoIncrement'] = true;

        return $this;
    }

    /**
     * Change the column definition.
     */
    public function change(): self
    {
        $this->attributes['change'] = true;

        return $this;
    }

    /**
     * Set the character set.
     */
    public function charset(string $charset): self
    {
        $this->attributes['charset'] = $charset;

        return $this;
    }

    /**
     * Set the collation.
     */
    public function collation(string $collation): self
    {
        $this->attributes['collation'] = $collation;

        return $this;
    }

    /**
     * Add a comment to the column.
     */
    public function comment(string $comment): self
    {
        $this->attributes['comment'] = $comment;

        return $this;
    }

    /**
     * Set the default value.
     */
    public function default(mixed $value): self
    {
        $this->attributes['default'] = $value;

        return $this;
    }

    /**
     * Place column first.
     */
    public function first(): self
    {
        $this->attributes['first'] = true;

        return $this;
    }

    /**
     * Get a specific attribute.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Get column attributes.
     *
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get the default value.
     */
    public function getDefault(): mixed
    {
        return $this->getAttribute('default');
    }

    /**
     * Get column name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get column parameters.
     *
     * @return array<string,mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get column type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Check if attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Check if column has default value.
     */
    public function hasDefault(): bool
    {
        return $this->hasAttribute('default');
    }

    /**
     * Add an index to the column.
     */
    public function index(): self
    {
        $this->attributes['index'] = true;

        return $this;
    }

    /**
     * Check if column is auto-increment.
     */
    public function isAutoIncrement(): bool
    {
        return (bool) $this->getAttribute('autoIncrement', false);
    }

    /**
     * Check if column is nullable.
     */
    public function isNullable(): bool
    {
        return (bool) $this->getAttribute('nullable', false);
    }

    /**
     * Check if column is unsigned.
     */
    public function isUnsigned(): bool
    {
        return (bool) $this->getAttribute('unsigned', false);
    }

    /**
     * Make the column nullable.
     */
    public function nullable(bool $value = true): self
    {
        $this->attributes['nullable'] = $value;

        return $this;
    }

    /**
     * Mark column as primary key.
     */
    public function primary(): self
    {
        $this->attributes['primary'] = true;

        return $this;
    }

    /**
     * Mark column as stored generated.
     */
    public function storedAs(string $expression): self
    {
        $this->attributes['storedAs'] = $expression;

        return $this;
    }

    /**
     * Mark column as unique.
     */
    public function unique(): self
    {
        $this->attributes['unique'] = true;

        return $this;
    }

    /**
     * Mark column as unsigned.
     */
    public function unsigned(): self
    {
        $this->attributes['unsigned'] = true;

        return $this;
    }

    /**
     * Mark column as CURRENT_TIMESTAMP default.
     */
    public function useCurrent(): self
    {
        $this->attributes['useCurrent'] = true;

        return $this;
    }

    /**
     * Mark column as CURRENT_TIMESTAMP on update.
     */
    public function useCurrentOnUpdate(): self
    {
        $this->attributes['useCurrentOnUpdate'] = true;

        return $this;
    }

    /**
     * Mark column as virtual generated.
     */
    public function virtualAs(string $expression): self
    {
        $this->attributes['virtualAs'] = $expression;

        return $this;
    }
}
