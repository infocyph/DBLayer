<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Security Manager
 *
 * Provides comprehensive security features:
 * - SQL injection prevention
 * - Rate limiting (per-minute, per-second)
 * - Query pattern validation
 * - Dangerous operation detection
 * - Input sanitization
 * - Security event logging
 *
 * @package Infocyph\DBLayer\Security
 * @author Hasan
 */
class Security
{
    /**
     * Dangerous SQL patterns
     */
    private const DANGEROUS_PATTERNS = [
        '/;\s*(drop|truncate|delete)\s+/i',
        '/union\s+.*select/i',
        '/into\s+(outfile|dumpfile)/i',
        '/load_file\s*\(/i',
        '/benchmark\s*\(/i',
        '/sleep\s*\(/i',
        '/waitfor\s+delay/i',
        '/exec\s*\(/i',
        '/execute\s+immediate/i',
    ];

    /**
     * Default rate limits
     */
    private const DEFAULT_LIMITS = [
        'queries_per_minute' => 1000,
        'queries_per_second' => 100,
        'max_query_length' => 10000,
        'max_in_clause_items' => 1000,
    ];

    /**
     * SQL injection patterns
     */
    private const INJECTION_PATTERNS = [
        '/(\s|^)(or|and)\s+[\w\'"]+\s*=\s*[\w\'"]+/i',
        '/[\'"]\s*(or|and)\s+[\'"]/i',
        '/[\w]+\s*=\s*[\w]+\s*--/i',
        '/\/\*.*\*\//i',
        '/--\s*$/m',
        '/#.*$/m',
    ];
    /**
     * Rate limit tracking (per minute)
     */
    private static array $rateLimitMinute = [];

    /**
     * Rate limit tracking (per second)
     */
    private static array $rateLimitSecond = [];

    /**
     * Check rate limit for identifier
     */
    public static function checkRateLimit(string $identifier, array $limits = []): void
    {
        $limits = array_merge(self::DEFAULT_LIMITS, $limits);

        // Check per-second rate limit
        self::checkPerSecondLimit($identifier, $limits['queries_per_second']);

        // Check per-minute rate limit
        self::checkPerMinuteLimit($identifier, $limits['queries_per_minute']);
    }

    /**
     * Check transaction timeout
     */
    public static function checkTransactionTimeout(float $startTime, int $maxTime = 30): void
    {
        $elapsed = microtime(true) - $startTime;

        if ($elapsed > $maxTime) {
            throw SecurityException::transactionTimeout($elapsed, $maxTime);
        }
    }

    /**
     * Clear all rate limits
     */
    public static function clearAllRateLimits(): void
    {
        self::$rateLimitMinute = [];
        self::$rateLimitSecond = [];
    }

    /**
     * Detect SQL injection attempts
     */
    public static function detectSqlInjection(string $input): void
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                self::logSecurityEvent('injection_attempt', 'Possible SQL injection detected', [
                    'input' => $input,
                    'pattern' => $pattern,
                ]);

                throw SecurityException::sqlInjectionDetected();
            }
        }
    }

    /**
     * Generate secure token
     */
    public static function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * Get rate limit status
     */
    public static function getRateLimitStatus(string $identifier): array
    {
        $currentMinute = self::getCurrentMinute();
        $currentSecond = self::getCurrentSecond();

        return [
            'queries_this_minute' => self::$rateLimitMinute[$identifier][$currentMinute] ?? 0,
            'queries_this_second' => self::$rateLimitSecond[$identifier][$currentSecond] ?? 0,
            'limit_per_minute' => self::DEFAULT_LIMITS['queries_per_minute'],
            'limit_per_second' => self::DEFAULT_LIMITS['queries_per_second'],
        ];
    }

    /**
     * Hash sensitive data
     */
    public static function hashSensitiveData(string $data, string $algo = 'sha256'): string
    {
        return hash($algo, $data);
    }

    /**
     * Check if operation is dangerous
     */
    public static function isDangerousOperation(string $sql): bool
    {
        $sql = strtolower(trim($sql));

        $dangerous = [
            'drop table',
            'drop database',
            'truncate',
            'delete from',
            'grant',
            'revoke',
            'alter table',
            'create table',
        ];
        return array_any($dangerous, fn ($operation) => str_starts_with($sql, $operation));
    }

    /**
     * Log security event
     */
    public static function logSecurityEvent(string $type, string $message, array $context = []): void
    {
        error_log(json_encode([
            'type' => 'security',
            'event' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]));
    }

    /**
     * Require confirmation for dangerous operation
     */
    public static function requireConfirmation(string $sql, bool $confirmed = false): void
    {
        if (self::isDangerousOperation($sql) && !$confirmed) {
            throw SecurityException::confirmationRequired($sql);
        }
    }

    /**
     * Reset rate limits for identifier
     */
    public static function resetRateLimit(string $identifier): void
    {
        unset(self::$rateLimitMinute[$identifier]);
        unset(self::$rateLimitSecond[$identifier]);
    }

    /**
     * Sanitize input value
     */
    public static function sanitizeInput(mixed $value): mixed
    {
        if (is_string($value)) {
            // Remove null bytes
            $value = str_replace("\0", '', $value);

            // Remove control characters except newline and tab
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        }

        return $value;
    }

    /**
     * Sanitize LIKE pattern
     */
    public static function sanitizeLikePattern(string $pattern): string
    {
        // Escape special LIKE characters
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $pattern);
    }

    /**
     * Validate column name
     */
    public static function validateColumnName(string $column): void
    {
        // Column name should only contain alphanumeric, underscore, and dot
        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $column)) {
            throw SecurityException::invalidColumnName($column);
        }
    }

    /**
     * Validate IN clause size
     */
    public static function validateInClauseSize(array $values): void
    {
        if (count($values) > self::DEFAULT_LIMITS['max_in_clause_items']) {
            throw SecurityException::inClauseTooLarge(
                count($values),
                self::DEFAULT_LIMITS['max_in_clause_items']
            );
        }
    }

    /**
     * Validate query for dangerous patterns
     */
    public static function validateQuery(string $sql): void
    {
        // Check query length
        if (strlen($sql) > self::DEFAULT_LIMITS['max_query_length']) {
            self::logSecurityEvent('query_too_long', 'Query exceeds maximum length', [
                'length' => strlen($sql),
                'max' => self::DEFAULT_LIMITS['max_query_length'],
            ]);

            throw SecurityException::queryTooLong(strlen($sql), self::DEFAULT_LIMITS['max_query_length']);
        }

        // Check for dangerous patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql)) {
                self::logSecurityEvent('dangerous_query', 'Query contains dangerous pattern', [
                    'sql' => $sql,
                    'pattern' => $pattern,
                ]);

                throw SecurityException::dangerousQuery($sql);
            }
        }

        // Check for SQL injection attempts
        self::detectSqlInjection($sql);
    }

    /**
     * Validate table name
     */
    public static function validateTableName(string $table): void
    {
        // Table name should only contain alphanumeric, underscore, and dot
        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $table)) {
            throw SecurityException::invalidTableName($table);
        }

        // Check for SQL keywords that shouldn't be table names
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'UNION'];
        if (in_array(strtoupper($table), $keywords, true)) {
            throw SecurityException::invalidTableName($table);
        }
    }

    /**
     * Validate token
     */
    public static function validateToken(string $token, string $expected): bool
    {
        return hash_equals($expected, $token);
    }

    /**
     * Check per-minute rate limit
     */
    private static function checkPerMinuteLimit(string $identifier, int $limit): void
    {
        $currentMinute = self::getCurrentMinute();

        if (!isset(self::$rateLimitMinute[$identifier])) {
            self::$rateLimitMinute[$identifier] = [];
        }

        // Clean old minutes
        foreach (self::$rateLimitMinute[$identifier] as $minute => $count) {
            if ($minute !== $currentMinute) {
                unset(self::$rateLimitMinute[$identifier][$minute]);
            }
        }

        // Increment counter
        if (!isset(self::$rateLimitMinute[$identifier][$currentMinute])) {
            self::$rateLimitMinute[$identifier][$currentMinute] = 0;
        }

        self::$rateLimitMinute[$identifier][$currentMinute]++;

        // Check limit
        if (self::$rateLimitMinute[$identifier][$currentMinute] > $limit) {
            self::logSecurityEvent('rate_limit_exceeded', 'Per-minute rate limit exceeded', [
                'identifier' => $identifier,
                'count' => self::$rateLimitMinute[$identifier][$currentMinute],
                'limit' => $limit,
            ]);

            throw SecurityException::rateLimitExceeded($limit, 'minute');
        }
    }

    /**
     * Check per-second rate limit
     */
    private static function checkPerSecondLimit(string $identifier, int $limit): void
    {
        $currentSecond = self::getCurrentSecond();

        if (!isset(self::$rateLimitSecond[$identifier])) {
            self::$rateLimitSecond[$identifier] = [];
        }

        // Clean old seconds
        foreach (self::$rateLimitSecond[$identifier] as $second => $count) {
            if ($second !== $currentSecond) {
                unset(self::$rateLimitSecond[$identifier][$second]);
            }
        }

        // Increment counter
        if (!isset(self::$rateLimitSecond[$identifier][$currentSecond])) {
            self::$rateLimitSecond[$identifier][$currentSecond] = 0;
        }

        self::$rateLimitSecond[$identifier][$currentSecond]++;

        // Check limit
        if (self::$rateLimitSecond[$identifier][$currentSecond] > $limit) {
            self::logSecurityEvent('rate_limit_exceeded', 'Per-second rate limit exceeded', [
                'identifier' => $identifier,
                'count' => self::$rateLimitSecond[$identifier][$currentSecond],
                'limit' => $limit,
            ]);

            throw SecurityException::rateLimitExceeded($limit, 'second');
        }
    }

    /**
     * Get current minute key
     */
    private static function getCurrentMinute(): string
    {
        return date('Y-m-d H:i');
    }

    /**
     * Get current second key
     */
    private static function getCurrentSecond(): string
    {
        return date('Y-m-d H:i:s');
    }
}
