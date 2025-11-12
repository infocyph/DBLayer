<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Model Exception
 *
 * Exception thrown when ORM model operations fail.
 * Handles errors related to model creation, updates, relationships, and queries.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class ModelException extends DBException
{
    /**
     * Create exception for model not found
     *
     * @param string $model Model class name
     * @param mixed $identifier Model identifier (ID, key, etc.)
     * @return self
     */
    public static function notFound(string $model, mixed $identifier): self
    {
        return new self("Model {$model} with identifier '{$identifier}' not found");
    }

    /**
     * Create exception for mass assignment violation
     *
     * @param string $model Model class name
     * @param array<int, string> $attributes Attempted attributes
     * @return self
     */
    public static function massAssignmentViolation(string $model, array $attributes): self
    {
        $attrs = implode(', ', $attributes);
        return new self(
            "Mass assignment violation on {$model}. " .
            "Attributes [{$attrs}] are not fillable."
        );
    }

    /**
     * Create exception for invalid attribute
     *
     * @param string $model Model class name
     * @param string $attribute Invalid attribute name
     * @return self
     */
    public static function invalidAttribute(string $model, string $attribute): self
    {
        return new self("Invalid attribute '{$attribute}' on model {$model}");
    }

    /**
     * Create exception for relationship not found
     *
     * @param string $model Model class name
     * @param string $relationship Relationship method name
     * @return self
     */
    public static function relationshipNotFound(string $model, string $relationship): self
    {
        return new self("Relationship '{$relationship}' not found on model {$model}");
    }

    /**
     * Create exception for invalid relationship configuration
     *
     * @param string $model Model class name
     * @param string $relationship Relationship name
     * @param string $reason Configuration error reason
     * @return self
     */
    public static function invalidRelationship(string $model, string $relationship, string $reason): self
    {
        return new self(
            "Invalid relationship '{$relationship}' on model {$model}: {$reason}"
        );
    }

    /**
     * Create exception for missing primary key
     *
     * @param string $model Model class name
     * @return self
     */
    public static function missingPrimaryKey(string $model): self
    {
        return new self("Model {$model} has no primary key value");
    }

    /**
     * Create exception for missing table name
     *
     * @param string $model Model class name
     * @return self
     */
    public static function missingTableName(string $model): self
    {
        return new self("Model {$model} has no table name defined");
    }

    /**
     * Create exception for save failure
     *
     * @param string $model Model class name
     * @param string $reason Failure reason
     * @return self
     */
    public static function saveFailed(string $model, string $reason): self
    {
        return new self("Failed to save model {$model}: {$reason}");
    }

    /**
     * Create exception for delete failure
     *
     * @param string $model Model class name
     * @param string $reason Failure reason
     * @return self
     */
    public static function deleteFailed(string $model, string $reason): self
    {
        return new self("Failed to delete model {$model}: {$reason}");
    }

    /**
     * Create exception for cast errors
     *
     * @param string $attribute Attribute name
     * @param string $castType Cast type attempted
     * @param string $message Error message
     * @return self
     */
    public static function castError(string $attribute, string $castType, string $message): self
    {
        return new self(
            "Failed to cast attribute '{$attribute}' to type '{$castType}': {$message}"
        );
    }

    /**
     * Create exception for immutable model modification
     *
     * @param string $model Model class name
     * @return self
     */
    public static function immutableModel(string $model): self
    {
        return new self("Cannot modify immutable model {$model}");
    }
}
