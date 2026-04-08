<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use Throwable;

/**
 * Data validation errors (e.g. config validation, input checks).
 */
final class ValidationException extends DBException
{
    /**
     * @var array<string, array<int, string>|string>
     */
    protected array $errors;

    /**
     * @param array<string, array<int, string>|string> $errors
     */
    public function __construct(
        string $message = 'Validation failed.',
        array $errors = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);

        $this->errors = $errors;
    }

    /**
     * Create a validation exception with an error bag.
     *
     * @param array<string, array<int, string>|string> $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self('Validation failed.', $errors);
    }

    /**
     * Get the first error message if available.
     */
    public function firstError(): ?string
    {
        if ($this->errors === []) {
            return null;
        }

        foreach ($this->errors as $error) {
            if (is_array($error)) {
                if ($error === []) {
                    continue;
                }

                return reset($error);
            }

            return (string) $error;
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
