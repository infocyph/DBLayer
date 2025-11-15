<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Security Validator
 *
 * Stateless helpers for database security checks:
 *  - input sanitization
 *  - LIKE pattern escaping
 *  - name validation (tables/columns)
 *  - query length
 *  - IN clause size
 */
final class SecurityValidator
{
    /**
     * Sanitize input value (basic guard).
     */
    public static function sanitizeInput(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        // Remove null bytes.
        $value = str_replace("\0", '', $value);

        // Remove control chars except \n and \t.
        $value = (string) preg_replace(
          '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
          '',
          $value
        );

        return $value;
    }

    /**
     * Sanitize LIKE pattern (escape %, _ and backslash).
     */
    public static function sanitizeLikePattern(string $pattern): string
    {
        return str_replace(
          ['%', '_', '\\'],
          ['\\%', '\\_', '\\\\'],
          $pattern
        );
    }

    /**
     * Validate column name.
     *
     * Allows alphanumeric, underscore and dot (for schema-qualified names).
     *
     * @throws SecurityException
     */
    public static function validateColumnName(string $column): void
    {
        // First char must be letter or underscore; rest can include dots.
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw SecurityException::invalidConfiguration(
              "Invalid column name [{$column}]."
            );
        }

        if (strlen($column) > 64) {
            throw SecurityException::invalidConfiguration(
              "Column name [{$column}] is too long (max 64 characters)."
            );
        }
    }

    /**
     * Validate IN clause size.
     *
     * @throws SecurityException
     */
    public static function validateInClauseSize(array $values, int $maxSize = 1000): void
    {
        $count = count($values);

        if ($count > $maxSize) {
            throw SecurityException::unsafeQuery(
              "IN clause contains {$count} items (max allowed: {$maxSize})."
            );
        }
    }

    /**
     * Validate query length.
     *
     * @throws SecurityException
     */
    public static function validateQueryLength(string $sql, int $maxLength = 50000): void
    {
        $length = strlen($sql);

        if ($length > $maxLength) {
            throw SecurityException::unsafeQuery(
              "Query length {$length} exceeds maximum {$maxLength} bytes."
            );
        }
    }

    /**
     * Validate table name.
     *
     * Same rules as column name (schema.table allowed).
     *
     * @throws SecurityException
     */
    public static function validateTableName(string $table): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $table)) {
            throw SecurityException::invalidConfiguration(
              "Invalid table name [{$table}]."
            );
        }

        if (strlen($table) > 64) {
            throw SecurityException::invalidConfiguration(
              "Table name [{$table}] is too long (max 64 characters)."
            );
        }
    }
}
