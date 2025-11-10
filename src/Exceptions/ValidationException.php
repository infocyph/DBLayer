<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Validation Exception
 * 
 * Thrown when data validation errors occur.
 * Handles input validation and data integrity checks.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ValidationException extends DBLayerException
{
    /**
     * Validation errors
     */
    protected array $errors = [];

    /**
     * Create a new validation exception
     */
    public function __construct(string $message = '', array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        
        parent::__construct(
            $message ?: 'The given data was invalid.',
            $code ?: 5001,
            $previous,
            ['errors' => $errors]
        );
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Create exception with validation errors
     */
    public static function withErrors(array $errors): static
    {
        return new static('Validation failed', $errors);
    }

    /**
     * Create exception for invalid parameter
     */
    public static function invalidParameter(string $parameter, string $expected, mixed $actual): static
    {
        return new static(
            "Invalid parameter '{$parameter}': expected {$expected}, got " . gettype($actual),
            [$parameter => "Expected {$expected}"],
            5002
        );
    }

    /**
     * Create exception for required field
     */
    public static function requiredField(string $field): static
    {
        return new static(
            "Required field '{$field}' is missing",
            [$field => 'This field is required'],
            5003
        );
    }

    /**
     * Create exception for invalid value
     */
    public static function invalidValue(string $field, mixed $value, string $reason = ''): static
    {
        $message = "Invalid value for field '{$field}'";
        if ($reason) {
            $message .= ": {$reason}";
        }

        return new static(
            $message,
            [$field => $reason ?: 'Invalid value'],
            5004
        );
    }

    /**
     * Create exception for value out of range
     */
    public static function outOfRange(string $field, mixed $value, $min, $max): static
    {
        return new static(
            "Value '{$value}' for field '{$field}' is out of range [{$min}, {$max}]",
            [$field => "Value must be between {$min} and {$max}"],
            5005
        );
    }

    /**
     * Check if has errors for specific field
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    /**
     * Get errors for specific field
     */
    public function getError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Get exception as array with errors
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'errors' => $this->errors,
        ]);
    }
}
