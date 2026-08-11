<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Query Guard Validator
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
     * Sanitize LIKE pattern (escape %, _ and backslash).
     */
    public static function sanitizeLikePattern(string $pattern): string
    {
        return str_replace(
            ['%', '_', '\\'],
            ['\\%', '\\_', '\\\\'],
            $pattern,
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
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw SecurityException::invalidConfiguration(
                "Invalid column name [{$column}].",
            );
        }

        self::validateIdentifierSegmentLengths($column, 'Column');
    }

    /**
     * Validate IN clause size.
     *
     * @param list<mixed> $values
     *
     * @throws SecurityException
     */
    public static function validateInClauseSize(array $values, int $maxSize = 1000): void
    {
        $count = count($values);

        if ($count > $maxSize) {
            throw SecurityException::unsafeQuery(
                "IN clause contains {$count} items (max allowed: {$maxSize}).",
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
                "Query length {$length} exceeds maximum {$maxLength} bytes.",
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
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $table)) {
            throw SecurityException::invalidConfiguration(
                "Invalid table name [{$table}].",
            );
        }

        self::validateIdentifierSegmentLengths($table, 'Table');
    }

    private static function validateIdentifierSegmentLengths(string $identifier, string $kind): void
    {
        foreach (explode('.', $identifier) as $segment) {
            if (strlen($segment) > 64) {
                throw SecurityException::invalidConfiguration(
                    "{$kind} identifier segment [{$segment}] is too long (max 64 bytes).",
                );
            }
        }
    }
}
