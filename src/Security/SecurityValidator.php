<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Security Validator
 *
 * Stateless validator for database security checks.
 * Breaks circular dependency between Connection and Security.
 *
 * @package Infocyph\DBLayer\Security
 * @author Hasan
 */
class SecurityValidator
{
    /**
     * Check for dangerous SQL patterns
     */
    public static function checkDangerousPatterns(string $sql): void
    {
        $dangerous = [
            'TRUNCATE',
            'DROP TABLE',
            'DROP DATABASE',
            'ALTER TABLE',
            'GRANT',
            'REVOKE',
        ];

        $upperSql = strtoupper($sql);

        foreach ($dangerous as $pattern) {
            if (str_contains($upperSql, $pattern)) {
                throw SecurityException::dangerousQuery($sql);
            }
        }
    }

    /**
     * Sanitize input value
     */
    public static function sanitizeInput(mixed $value): mixed
    {
        if (is_string($value)) {
            // Remove null bytes
            $value = str_replace("\0", '', $value);
        }

        return $value;
    }

    /**
     * Sanitize LIKE pattern
     */
    public static function sanitizeLikePattern(string $pattern): string
    {
        // Escape special LIKE characters
        return str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
    }

    /**
     * Validate column name
     */
    public static function validateColumnName(string $column): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw SecurityException::invalidColumnName($column);
        }

        if (strlen($column) > 64) {
            throw SecurityException::invalidColumnName($column);
        }
    }

    /**
     * Validate IN clause size
     */
    public static function validateInClauseSize(array $values, int $maxSize = 1000): void
    {
        if (count($values) > $maxSize) {
            throw SecurityException::inClauseTooLarge(count($values), $maxSize);
        }
    }

    /**
     * Validate query length
     */
    public static function validateQueryLength(string $sql, int $maxLength = 50000): void
    {
        if (strlen($sql) > $maxLength) {
            throw SecurityException::queryTooLong(strlen($sql), $maxLength);
        }
    }
    /**
     * Validate table name
     */
    public static function validateTableName(string $table): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw SecurityException::invalidTableName($table);
        }

        if (strlen($table) > 64) {
            throw SecurityException::invalidTableName($table);
        }
    }
}
