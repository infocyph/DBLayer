<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

use Exception;

/**
 * Base DBLayer Exception
 * 
 * Base exception class for all DBLayer-related exceptions.
 * Provides common functionality for exception handling.
 * 
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class DBLayerException extends Exception
{
    /**
     * Additional context data
     */
    protected array $context = [];

    /**
     * Create a new exception instance
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the exception context
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context
     */
    public function setContext(array $context): static
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Get exception as array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
        ];
    }

    /**
     * Get exception as JSON string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
