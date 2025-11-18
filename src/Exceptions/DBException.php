<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for all DBLayer-related errors.
 *
 * All domain-specific exceptions MUST extend this class.
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
     * Wrap a lower-level throwable into a DBLayer exception.
     *
     * @param Throwable   $throwable The original error.
     * @param string|null $prefix    Optional context prefix for the message.
     */
    public static function fromThrowable(
        Throwable $throwable,
        ?string $prefix = null
    ): static {
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
