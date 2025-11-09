<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Exception;
use Throwable;

/**
 * Base database exception
 */
class DBException extends Exception
{
}

/**
 * Connection related exceptions
 */
class ConnectionException extends DBException
{
}

/**
 * Query execution exceptions with detailed context
 */
class QueryException extends DBException
{
    public function __construct(
        string $message,
        public readonly string $sql = '',
        public readonly array $bindings = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function getFullQuery(): string
    {
        $query = $this->sql;
        foreach ($this->bindings as $binding) {
            $value = is_string($binding) ? "'{$binding}'" : (string) $binding;
            $query = preg_replace('/\?/', $value, $query, 1);
        }
        return $query;
    }
}

/**
 * Security related exceptions
 */
class SecurityException extends DBException
{
}

/**
 * Transaction related exceptions
 */
class TransactionException extends DBException
{
}

/**
 * Schema/DDL related exceptions
 */
class SchemaException extends DBException
{
}

/**
 * Migration related exceptions
 */
class MigrationException extends DBException
{
}

/**
 * Record not found exception
 */
class RecordNotFoundException extends DBException
{
    public function __construct(string $model = '', mixed $id = null)
    {
        $message = $model 
            ? "No query results for model [{$model}]" . ($id ? " with ID [{$id}]" : '')
            : "Record not found";
        
        parent::__construct($message);
    }
}

/**
 * Mass assignment exception
 */
class MassAssignmentException extends DBException
{
}

/**
 * Invalid argument exception
 */
class InvalidArgumentException extends DBException
{
}

/**
 * Async operation exception
 */
class AsyncException extends DBException
{
}
