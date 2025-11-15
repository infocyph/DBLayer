<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Query Validator
 *
 * Heuristic SQL injection detection and binding sanity checks.
 * Does NOT enforce length or "dangerous operation" rules – those
 * are handled at Security facade level depending on SecurityMode.
 */
final class QueryValidator
{
    /**
     * SQL injection patterns (focused on classic payloads):
     *  - UNION SELECT
     *  - OR/AND tautologies
     *  - comment abuse
     *  - "col = col --" patterns
     *
     * NOTE: This is intentionally conservative; patterns may be tuned
     * via Security facade mode (NORMAL/STRICT/OFF).
     */
    private const INJECTION_PATTERNS = [
        // UNION-based injections
      '/\bunion\b\s+.*\bselect\b/i',

        // "SELECT ... WHERE ... OR ..." style injections
      '/\bselect\b.*\bfrom\b.*\bwhere\b.*\bor\b.*=.*\b/i',

        // Tautologies / OR 1=1 style
      '/(\s|^)(or|and)\s+[\w\'"]+\s*=\s*[\w\'"]+/i',
      '/[\'"]\s*(or|and)\s+[\'"]/i',
      '/[\w]+\s*=\s*[\w]+\s*--/i',

        // Comment-based truncation
      '/\/\*.*\*\//s',
      '/--\s*$/m',
      '/#.*$/m',
    ];

    /**
     * Validate query for basic safety (mode-agnostic).
     *
     * @param array<int|string, mixed> $bindings
     *
     * @throws SecurityException
     */
    public function validateQuery(string $sql, array $bindings = []): void
    {
        // Null bytes in SQL are always suspicious.
        if (str_contains($sql, "\0")) {
            throw SecurityException::unsafeQuery('Query contains null bytes.');
        }

        $this->detectSqlInjection($sql);
        $this->validateBindings($bindings);
    }

    /**
     * Detect SQL injection attempts using heuristic patterns.
     *
     * @throws SecurityException
     */
    public function detectSqlInjection(string $sql): void
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                $fragment = mb_substr($sql, 0, 256, 'UTF-8');

                throw SecurityException::sqlInjectionDetected($pattern, $fragment);
            }
        }
    }

    /**
     * Validate query bindings.
     *
     * @param array<int|string, mixed> $bindings
     *
     * @throws SecurityException
     */
    private function validateBindings(array $bindings): void
    {
        foreach ($bindings as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                throw SecurityException::unsafeQuery('Binding contains null bytes.');
            }
        }
    }
}
