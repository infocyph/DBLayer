<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Query;

/**
 * SQL Expression
 *
 * Represents a raw SQL expression that should not be escaped or quoted.
 * Useful for database functions, raw SQL, and complex expressions.
 *
 * @package Infocyph\DBLayer\Query
 * @author Hasan
 */
class Expression
{
    /**
     * The value of the expression
     */
    private string $value;

    /**
     * Create a new expression instance
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Get the string representation
     */
    public function __toString(): string
    {
        return $this->getValue();
    }

    /**
     * Create a new expression instance (static factory)
     */
    public static function make(string $value): self
    {
        return new self($value);
    }

    /**
     * Get the value of the expression
     */
    public function getValue(): string
    {
        return $this->value;
    }
}