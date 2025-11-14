<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use Throwable;

/**
 * Data validation errors (e.g. before persisting models/queries).
 */
class ValidationException extends DBException
{
    /**
     * @var array<string, array<int, string>|string>
     */
    protected array $errors;

    public function __construct(
      string $message = 'Validation failed.',
      array $errors = [],
      int $code = 0,
      ?Throwable $previous = null
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
