<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Query Validator
 *
 * Validates query safety and detects SQL injection attempts.
 *
 * @package Infocyph\DBLayer\Security
 * @author Hasan
 */
class QueryValidator
{
    /**
     * SQL injection patterns
     */
    private const INJECTION_PATTERNS = [
        '/(\bunion\b.*\bselect\b)/i',
        '/(\bselect\b.*\bfrom\b.*\bwhere\b.*\bor\b.*=.*)/i',
        '/(;\s*drop\b)/i',
        '/(;\s*delete\b)/i',
        '/(;\s*update\b)/i',
        '/(\bexec\b|\bexecute\b)\s*\(/i',
        '/(\binto\b\s+\boutfile\b)/i',
        '/(\bload_file\b)/i',
        '/(\/\*.*\*\/)/i',
    ];

    /**
     * Detect SQL injection attempts
     */
    public function detectSqlInjection(string $sql): void
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw SecurityException::sqlInjectionDetected();
            }
        }
    }

    /**
     * Validate query is safe
     */
    public function validateQuery(string $sql, array $bindings = []): void
    {
        // Check for null bytes
        if (str_contains($sql, "\0")) {
            throw SecurityException::sqlInjectionDetected();
        }

        // Validate query length
        SecurityValidator::validateQueryLength($sql);

        // Check for injection patterns
        $this->detectSqlInjection($sql);

        // Validate bindings
        $this->validateBindings($bindings);
    }

    /**
     * Validate query bindings
     */
    private function validateBindings(array $bindings): void
    {
        foreach ($bindings as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw SecurityException::sqlInjectionDetected();
            }
        }
    }
}
