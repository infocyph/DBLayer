<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Validation Exception
 *
 * Exception for data validation errors.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ValidationException extends DBException
{
    protected array $errors = [];

    public function __construct(string $message = '', array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    public static function withErrors(array $errors): self
    {
        return new self('Validation failed', $errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
