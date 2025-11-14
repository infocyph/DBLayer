<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for all DBLayer-related errors.
 */
class DBException extends RuntimeException
{
    public function __construct(
      string $message = '',
      int $code = 0,
      ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Wrap a lower-level throwable into a DBException subclass.
     */
    public static function fromThrowable(Throwable $throwable, ?string $prefix = null): static
    {
        $message = $throwable->getMessage();

        if ($prefix !== null && $prefix !== '') {
            $message = $prefix . ': ' . $message;
        }

        return new static($message, (int) $throwable->getCode(), $throwable);
    }

    /**
     * Generic invalid configuration error.
     */
    public static function invalidConfiguration(string $message): static
    {
        return new static('Invalid configuration: ' . $message);
    }
}
