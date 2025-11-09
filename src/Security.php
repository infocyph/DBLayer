<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use Closure;

/**
 * Security layer providing SQL injection protection, validation, and audit logging
 */
class Security
{
    /**
     * Allowed SQL operators
     */
    private const ALLOWED_OPERATORS = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE',
        'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'IS', 'IS NOT', 'IS NULL', 'IS NOT NULL',
        'EXISTS', 'NOT EXISTS',
        'REGEXP', 'NOT REGEXP',
        'RLIKE', 'NOT RLIKE',
        'SIMILAR TO', 'NOT SIMILAR TO',
        'SOUNDS LIKE',
        '~', '~*', '!~', '!~*',
        'GLOB', 'MATCH',
    ];

    /**
     * Valid identifier pattern (table/column names)
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Suspicious SQL patterns for injection detection
     */
    private const SUSPICIOUS_PATTERNS = [
        '/;\s*(DROP|DELETE|TRUNCATE|ALTER|CREATE|GRANT|REVOKE)/i',
        '/UNION\s+(ALL\s+)?SELECT/i',
        '/\/\*.*\*\//s',
        '/--[^\r\n]*/m',
        '/#[^\r\n]*/m',
        '/xp_cmdshell/i',
        '/exec\s*\(/i',
        '/execute\s*\(/i',
        '/SLEEP\s*\(/i',
        '/BENCHMARK\s*\(/i',
        '/WAITFOR\s+DELAY/i',
        '/LOAD_FILE\s*\(/i',
        '/INTO\s+(OUT|DUMP)FILE/i',
        '/SELECT\s+.*\s+FROM\s+.*\s+WHERE\s+.*\s+OR\s+[\'"]?1[\'"]?\s*=\s*[\'"]?1/i',
        '/\bAND\b\s+[\'"]?1[\'"]?\s*=\s*[\'"]?1/i',
        '/\bOR\b\s+[\'"]?1[\'"]?\s*=\s*[\'"]?1/i',
    ];

    /**
     * Maximum query length (1MB)
     */
    private const MAX_QUERY_LENGTH = 1048576;

    /**
     * Maximum number of bindings
     */
    private const MAX_BINDINGS_COUNT = 10000;

    /**
     * Rate limiting configuration
     */
    private const MAX_QUERIES_PER_MINUTE = 1000;
    private const MAX_QUERIES_PER_SECOND = 100;

    /**
     * Query counters for rate limiting
     */
    private static array $queryCounters = [];

    /**
     * Validate identifier (table/column name)
     */
    public static function validateIdentifier(string $identifier): bool
    {
        // Allow dotted notation (schema.table.column)
        $parts = explode('.', $identifier);

        foreach ($parts as $part) {
            if (!preg_match(self::IDENTIFIER_PATTERN, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Escape identifier for safe use in SQL
     */
    public static function escapeIdentifier(string $identifier, string $driver): string
    {
        if (!self::validateIdentifier($identifier)) {
            throw new SecurityException(
                "Invalid identifier: {$identifier}. Only alphanumeric characters and underscores allowed."
            );
        }

        $quote = match ($driver) {
            'mysql' => '`',
            'pgsql', 'sqlite' => '"',
            default => throw new InvalidArgumentException("Unknown driver: {$driver}")
        };

        // Handle dotted notation
        $parts = explode('.', $identifier);
        $escaped = array_map(
            fn($part) => $quote . str_replace($quote, $quote . $quote, $part) . $quote,
            $parts
        );

        return implode('.', $escaped);
    }

    /**
     * Sanitize table name
     */
    public static function sanitizeTableName(string $table): string
    {
        if (!self::validateIdentifier($table)) {
            throw new SecurityException("Invalid table name: {$table}");
        }

        return $table;
    }

    /**
     * Sanitize column name
     */
    public static function sanitizeColumnName(string $column): string
    {
        if ($column === '*') {
            return $column;
        }

        if (!self::validateIdentifier($column)) {
            throw new SecurityException("Invalid column name: {$column}");
        }

        return $column;
    }

    /**
     * Validate SQL operator
     */
    public static function validateOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::ALLOWED_OPERATORS, true)) {
            throw new SecurityException(
                "Invalid operator: {$operator}. Must be one of: " . implode(', ', self::ALLOWED_OPERATORS)
            );
        }

        return $normalized;
    }

    /**
     * Check if operator is allowed
     */
    public static function isAllowedOperator(string $operator): bool
    {
        $normalized = strtoupper(trim($operator));
        return in_array($normalized, self::ALLOWED_OPERATORS, true);
    }

    /**
     * Generate parameter placeholder
     */
    public static function bindValue(mixed $value): string
    {
        return '?';
    }

    /**
     * Bind multiple values
     */
    public static function bindValues(array $values): array
    {
        return array_fill(0, count($values), '?');
    }

    /**
     * Detect SQL injection patterns
     */
    public static function detectInjection(string $sql): bool
    {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan for suspicious patterns and return matches
     */
    public static function scanForSuspiciousPatterns(string $sql): array
    {
        $matches = [];

        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql, $found)) {
                $matches[] = [
                    'pattern' => $pattern,
                    'match' => $found[0] ?? null,
                ];
            }
        }

        return $matches;
    }

    /**
     * Validate raw SQL expression
     */
    public static function validateRawExpression(string $expression, bool $trusted = false): void
    {
        if ($trusted) {
            return;
        }

        if (self::detectInjection($expression)) {
            $patterns = self::scanForSuspiciousPatterns($expression);
            throw new SecurityException(
                "Raw expression contains suspicious SQL patterns: " .
                json_encode($patterns, JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * Filter fillable attributes for mass assignment protection
     */
    public static function filterFillable(array $attributes, array $fillable, array $guarded = []): array
    {
        // If fillable is empty, use guarded
        if (empty($fillable)) {
            if (in_array('*', $guarded, true)) {
                throw new MassAssignmentException(
                    "Mass assignment is disabled. No fillable attributes defined and guarded contains '*'."
                );
            }

            return array_diff_key($attributes, array_flip($guarded));
        }

        // Only allow fillable attributes
        return array_intersect_key($attributes, array_flip($fillable));
    }

    /**
     * Check rate limit
     */
    public static function checkRateLimit(string $identifier = 'default'): void
    {
        $currentMinute = self::getCurrentMinute();
        $currentSecond = self::getCurrentSecond();

        // Initialize counters
        if (!isset(self::$queryCounters[$identifier])) {
            self::$queryCounters[$identifier] = [
                'minute' => ['time' => $currentMinute, 'count' => 0],
                'second' => ['time' => $currentSecond, 'count' => 0],
            ];
        }

        $counters = &self::$queryCounters[$identifier];

        // Reset minute counter if needed
        if ($counters['minute']['time'] !== $currentMinute) {
            $counters['minute'] = ['time' => $currentMinute, 'count' => 0];
        }

        // Reset second counter if needed
        if ($counters['second']['time'] !== $currentSecond) {
            $counters['second'] = ['time' => $currentSecond, 'count' => 0];
        }

        // Check limits
        if ($counters['minute']['count'] >= self::MAX_QUERIES_PER_MINUTE) {
            throw new SecurityException(
                "Rate limit exceeded: Maximum " . self::MAX_QUERIES_PER_MINUTE . " queries per minute"
            );
        }

        if ($counters['second']['count'] >= self::MAX_QUERIES_PER_SECOND) {
            throw new SecurityException(
                "Rate limit exceeded: Maximum " . self::MAX_QUERIES_PER_SECOND . " queries per second"
            );
        }
    }

    /**
     * Increment query count
     */
    public static function incrementQueryCount(string $identifier = 'default'): void
    {
        if (!isset(self::$queryCounters[$identifier])) {
            self::checkRateLimit($identifier);
        }

        self::$queryCounters[$identifier]['minute']['count']++;
        self::$queryCounters[$identifier]['second']['count']++;
    }

    /**
     * Reset rate limits
     */
    public static function resetRateLimits(): void
    {
        self::$queryCounters = [];
    }

    /**
     * Validate query size
     */
    public static function validateQuerySize(string $sql, array $bindings): void
    {
        if (strlen($sql) > self::MAX_QUERY_LENGTH) {
            throw new SecurityException(
                "Query exceeds maximum length of " . self::MAX_QUERY_LENGTH . " bytes"
            );
        }

        if (count($bindings) > self::MAX_BINDINGS_COUNT) {
            throw new SecurityException(
                "Query exceeds maximum bindings count of " . self::MAX_BINDINGS_COUNT
            );
        }
    }

    /**
     * Log query for audit
     */
    public static function logQuery(
        string $sql,
        array $bindings,
        float $executionTime,
        array $context = []
    ): void {
        // In production, this would write to a proper logging system
        if (getenv('DBLAYER_AUDIT_LOG') === 'true') {
            error_log(json_encode([
                'type' => 'query',
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => $executionTime,
                'context' => $context,
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }
    }

    /**
     * Log suspicious activity
     */
    public static function logSuspiciousActivity(string $sql, string $reason, array $context = []): void
    {
        error_log(json_encode([
            'type' => 'security_alert',
            'sql' => $sql,
            'reason' => $reason,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ]));
    }

    /**
     * Log security event
     */
    public static function logSecurityEvent(string $type, string $message, array $context = []): void
    {
        error_log(json_encode([
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
        ]));
    }

    /**
     * Check transaction timeout
     */
    public static function checkTransactionTimeout(float $startTime, int $maxTime = 30): void
    {
        $elapsed = microtime(true) - $startTime;

        if ($elapsed > $maxTime) {
            throw new TransactionException(
                "Transaction exceeded maximum time ({$maxTime}s). Elapsed: " . round($elapsed, 2) . "s"
            );
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
     * Get current minute for rate limiting
     */
    private static function getCurrentMinute(): string
    {
        return date('Y-m-d H:i');
    }

    /**
     * Get current second for rate limiting
     */
    private static function getCurrentSecond(): string
    {
        return date('Y-m-d H:i:s');
    }
}
