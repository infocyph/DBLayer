<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Errors related to ORM models and relationships.
 */
class ModelException extends DBException
{
    public static function notFound(string|int $id, string $modelClass): self
    {
        return new self("Model [{$modelClass}] with identifier [{$id}] was not found.");
    }

    public static function relationNotFound(string $relation, string $modelClass): self
    {
        return new self("Relation [{$relation}] is not defined on model [{$modelClass}].");
    }

    public static function massAssignmentViolation(string $attribute, string $modelClass): self
    {
        return new self(
          "Mass assignment violation for attribute [{$attribute}] on model [{$modelClass}]."
        );
    }

    public static function invalidCast(string $attribute, string $type, string $modelClass): self
    {
        return new self(
          "Invalid cast type [{$type}] for attribute [{$attribute}] on model [{$modelClass}]."
        );
    }

    public static function invalidState(string $message): self
    {
        return new self('Invalid model state: ' . $message);
    }
}
