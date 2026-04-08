<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Security;

use Infocyph\DBLayer\Exceptions\SecurityException;

/**
 * Security Manager
 *
 * Provides:
 *  - SQL injection detection (via QueryValidator)
 *  - Rate limiting (per-minute, per-second)
 *  - Query validation (length, dangerous patterns, injection)
 *  - Dangerous operation detection & confirmation
 *  - Input sanitization & LIKE escaping
 *  - Security event logging
 *
 * All checks are controlled by SecurityMode.
 */
final class Security
{
    /**
     * Dangerous SQL patterns (regex) for STRICT mode.
     *
     * These are high-risk operations; we log and block them when
     * STRICT validation is enabled.
     *
     * NOTE: This is defense-in-depth on top of prepared statements.
     */
    private const DANGEROUS_PATTERNS = [
        '/;\s*(drop|truncate|delete)\s+/i',
        '/into\s+(outfile|dumpfile)/i',
        '/load_file\s*\(/i',
        '/benchmark\s*\(/i',
        '/sleep\s*\(/i',
        '/waitfor\s+delay/i',
        '/exec\s*\(/i',
        '/execute\s+immediate/i',
    ];

    private const DEFAULT_MAX_IN_ITEMS = 1000;

    private const DEFAULT_MAX_QUERY_LENGTH = 10000;

    /**
     * Defaults (compatible with old constants).
     */
    private const DEFAULT_QUERIES_PER_MINUTE = 1000;

    private const DEFAULT_QUERIES_PER_SECOND = 100;

    private const STRICT_MAX_IN_ITEMS = 800;

    /**
     * STRICT mode can be a bit tighter.
     */
    private const STRICT_MAX_QUERY_LENGTH = 8000;

    /**
     * Global security mode.
     */
    private static SecurityMode $mode = SecurityMode::NORMAL;

    /**
     * Shared rate limiter instance.
     */
    private static ?RateLimiter $rateLimiter = null;

    /**
     * Check rate limits (per-second & per-minute) for an identifier.
     *
     * Mode does NOT affect rate limiting; this is orthogonal.
     *
     * @param array{
     *   queries_per_minute?:int,
     *   queries_per_second?:int
     * } $limits
     *
     * @throws SecurityException
     */
    public static function checkRateLimit(string $identifier, array $limits = []): void
    {
        $perSecond = $limits['queries_per_second'] ?? self::DEFAULT_QUERIES_PER_SECOND;
        $perMinute = $limits['queries_per_minute'] ?? self::DEFAULT_QUERIES_PER_MINUTE;

        $limiter = self::limiter();

        if ($perSecond > 0) {
            $limiter->check($identifier . ':sec', $perSecond, 1);
        }

        if ($perMinute > 0) {
            $limiter->check($identifier . ':min', $perMinute, 60);
        }
    }

    /**
     * Check transaction timeout (simple elapsed-time guard).
     *
     * Mode does not affect this; it's always active if you call it.
     *
     * @throws SecurityException
     */
    public static function checkTransactionTimeout(float $startTime, int $maxTime = 30): void
    {
        $elapsed = microtime(true) - $startTime;

        if ($elapsed > $maxTime) {
            throw SecurityException::unsafeOperation(
                "Transaction exceeded max time of {$maxTime}s (elapsed: " . round($elapsed, 3) . 's).',
            );
        }
    }

    /**
     * Clear all rate limits.
     */
    public static function clearAllRateLimits(): void
    {
        self::limiter()->clear();
    }

    /**
     * Detect SQL injection attempts with logging, respecting SecurityMode.
     *
     * @throws SecurityException
     */
    public static function detectSqlInjection(string $sql): void
    {
        if (self::$mode === SecurityMode::OFF) {
            return;
        }

        $validator = new QueryValidator();

        try {
            $validator->detectSqlInjection($sql);
        } catch (SecurityException $e) {
            self::logSecurityEvent(
                'injection_attempt',
                'Possible SQL injection detected',
                [
                    'sql' => mb_substr($sql, 0, 512, 'UTF-8'),
                    'error' => $e->getMessage(),
                ],
            );

            throw $e;
        }
    }

    /**
     * Generate secure token.
     *
     * NOTE: Output length is 2 * $length hex characters.
     */
    public static function generateToken(int $length = 32): string
    {
        if ($length <= 0) {
            return '';
        }

        return bin2hex(random_bytes($length));
    }

    /**
     * Get current security mode.
     */
    public static function getMode(): SecurityMode
    {
        return self::$mode;
    }

    /**
     * Get rate limit status (current counts + configured defaults).
     *
     * @return array{
     *   queries_this_minute:int,
     *   queries_this_second:int,
     *   limit_per_minute:int,
     *   limit_per_second:int
     * }
     */
    public static function getRateLimitStatus(string $identifier): array
    {
        $limiter = self::limiter();

        return [
            'queries_this_minute' => $limiter->getCount($identifier . ':min', 60),
            'queries_this_second' => $limiter->getCount($identifier . ':sec', 1),
            'limit_per_minute' => self::DEFAULT_QUERIES_PER_MINUTE,
            'limit_per_second' => self::DEFAULT_QUERIES_PER_SECOND,
        ];
    }

    /**
     * Hash sensitive data.
     */
    public static function hashSensitiveData(string $data, string $algo = 'sha256'): string
    {
        return hash($algo, $data);
    }

    /**
     * Check if operation is obviously dangerous by leading keyword.
     */
    public static function isDangerousOperation(string $sql): bool
    {
        $sql = strtolower(ltrim($sql));

        $dangerous = [
            'drop table',
            'drop database',
            'truncate',
            'delete from',
            'grant ',
            'revoke ',
            'alter table',
            'create table',
        ];
        return array_any($dangerous, fn($operation) => str_starts_with($sql, $operation));
    }

    /**
     * Log a security event (kept simple and JSON-structured).
     *
     * @param  array<string,mixed>  $context
     */
    public static function logSecurityEvent(string $type, string $message, array $context = []): void
    {
        $payload = [
            'type' => 'security',
            'event' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $json = '{"type":"security","event":"encoding_error"}';
        }

        error_log($json);
    }

    /**
     * Require confirmation for dangerous operation.
     *
     * @throws SecurityException
     */
    public static function requireConfirmation(string $sql, bool $confirmed = false): void
    {
        if (self::$mode === SecurityMode::OFF) {
            return;
        }

        if (self::isDangerousOperation($sql) && ! $confirmed) {
            throw SecurityException::unsafeOperation(
                'Dangerous SQL operation requires explicit confirmation.',
            );
        }
    }

    /**
     * Reset rate limits for identifier.
     */
    public static function resetRateLimit(string $identifier): void
    {
        $limiter = self::limiter();
        $limiter->reset($identifier . ':sec');
        $limiter->reset($identifier . ':min');
    }

    /**
     * Sanitize input value.
     */
    public static function sanitizeInput(mixed $value): mixed
    {
        return SecurityValidator::sanitizeInput($value);
    }

    /**
     * Sanitize LIKE pattern.
     */
    public static function sanitizeLikePattern(string $pattern): string
    {
        return SecurityValidator::sanitizeLikePattern($pattern);
    }

    /**
     * Set global security mode.
     */
    public static function setMode(SecurityMode $mode): void
    {
        self::$mode = $mode;
    }

    /**
     * Validate column name.
     *
     * @throws SecurityException
     */
    public static function validateColumnName(string $column): void
    {
        SecurityValidator::validateColumnName($column);
    }

    /**
     * Validate IN clause size using mode-appropriate max.
     *
     * @throws SecurityException
     */
    public static function validateInClauseSize(array $values): void
    {
        $maxItems = self::$mode === SecurityMode::STRICT
          ? self::STRICT_MAX_IN_ITEMS
          : self::DEFAULT_MAX_IN_ITEMS;

        SecurityValidator::validateInClauseSize($values, $maxItems);
    }

    /**
     * Validate a SQL string and its bindings using the current global mode
     * and optional per-connection security configuration.
     *
     * Global SecurityMode:
     *  - OFF:   hard switch, no checks
     *  - NORMAL/STRICT: enabled, with STRICT adding dangerous-pattern scan
     *
     * Per-connection "security" config (from ConnectionConfig):
     *  - enabled          (bool)  → can disable checks for a specific connection
     *  - max_sql_length   (int)   → max query length in bytes
     *  - max_params       (int)   → max number of bound parameters
     *  - max_param_bytes  (int)   → max bytes per string parameter
     *
     * @param  array<int|string,mixed>  $bindings
     * @param  array<string,mixed>|null  $config
     *
     * @throws SecurityException
     */
    public static function validateQuery(
        string $sql,
        array $bindings = [],
        ?array $config = null,
    ): void {
        $mode = self::$mode;

        // Global OFF is a hard switch: nothing is validated.
        if ($mode === SecurityMode::OFF) {
            return;
        }

        // Per-connection switch: allow disabling checks for a specific connection.
        if (self::shouldSkipForConnection($config)) {
            return;
        }

        // 1) Length check.
        $maxLength = self::resolveMaxQueryLength($mode, $config);
        if ($maxLength > 0) {
            SecurityValidator::validateQueryLength($sql, $maxLength);
        }

        // 2) Binding limits (count + per-param size).
        [$maxParams, $maxParamBytes] = self::extractBindingLimits($config);
        self::enforceBindingLimits($bindings, $maxParams, $maxParamBytes);

        // 3) STRICT dangerous-pattern scan (DDL / file / timing abuse).
        if ($mode === SecurityMode::STRICT) {
            self::scanDangerousPatterns($sql);
        }

        // 4) Always do injection + binding checks in NORMAL / STRICT.
        new QueryValidator()->validateQuery($sql, $bindings);
    }

    /**
     * Validate table name.
     *
     * @throws SecurityException
     */
    public static function validateTableName(string $table): void
    {
        SecurityValidator::validateTableName($table);

        // Extra guard against using raw SQL keywords as bare table names.
        $keywords = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'UNION'];

        if (in_array(strtoupper($table), $keywords, true)) {
            throw SecurityException::invalidConfiguration(
                "Invalid table name [{$table}].",
            );
        }
    }

    /**
     * Validate token equality in constant time.
     */
    public static function validateToken(string $token, string $expected): bool
    {
        return hash_equals($expected, $token);
    }

    /**
     * Enforce binding limits (count + per-string size).
     *
     * @param  array<int|string,mixed>  $bindings
     *
     * @throws SecurityException
     */
    private static function enforceBindingLimits(
        array $bindings,
        ?int $maxParams,
        ?int $maxParamBytes,
    ): void {
        if ($maxParams !== null) {
            $paramCount = count($bindings);

            if ($paramCount > $maxParams) {
                throw SecurityException::unsafeQuery(
                    "Number of query parameters {$paramCount} exceeds maximum {$maxParams}.",
                );
            }
        }

        if ($maxParamBytes === null) {
            return;
        }

        foreach ($bindings as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $length = strlen($value);

            if ($length > $maxParamBytes) {
                $paramKey = is_int($key) ? (string) $key : (string) $key;

                throw SecurityException::unsafeQuery(
                    "Parameter [{$paramKey}] length {$length} exceeds maximum {$maxParamBytes} bytes.",
                );
            }
        }
    }

    /**
     * Extract binding-related limits from per-connection config.
     *
     * @param  array<string,mixed>|null  $config
     * @return array{0:?int,1:?int} [maxParams, maxParamBytes]
     */
    private static function extractBindingLimits(?array $config): array
    {
        $maxParams = null;
        $maxParamBytes = null;

        if (! is_array($config)) {
            return [null, null];
        }

        if (isset($config['max_params']) && is_numeric($config['max_params'])) {
            $maxParams = (int) $config['max_params'];

            if ($maxParams <= 0) {
                $maxParams = null;
            }
        }

        if (isset($config['max_param_bytes']) && is_numeric($config['max_param_bytes'])) {
            $maxParamBytes = (int) $config['max_param_bytes'];

            if ($maxParamBytes <= 0) {
                $maxParamBytes = null;
            }
        }

        return [$maxParams, $maxParamBytes];
    }

    /**
     * Shared RateLimiter instance.
     */
    private static function limiter(): RateLimiter
    {
        if (self::$rateLimiter === null) {
            self::$rateLimiter = new RateLimiter();
        }

        return self::$rateLimiter;
    }

    /**
     * Resolve effective maximum query length given mode + per-connection config.
     */
    private static function resolveMaxQueryLength(SecurityMode $mode, ?array $config): int
    {
        if (is_array($config) && isset($config['max_sql_length']) && is_numeric($config['max_sql_length'])) {
            return (int) $config['max_sql_length'];
        }

        return $mode === SecurityMode::STRICT
          ? self::STRICT_MAX_QUERY_LENGTH
          : self::DEFAULT_MAX_QUERY_LENGTH;
    }

    /**
     * STRICT-mode dangerous pattern scan with logging.
     *
     * @throws SecurityException
     */
    private static function scanDangerousPatterns(string $sql): void
    {
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $sql) !== 1) {
                continue;
            }

            self::logSecurityEvent(
                'dangerous_query',
                'Query contains dangerous pattern',
                [
                    'sql' => mb_substr($sql, 0, 512, 'UTF-8'),
                    'pattern' => $pattern,
                ],
            );

            throw SecurityException::unsafeQuery('Query contains dangerous SQL pattern.');
        }
    }

    /**
     * Decide whether to skip validation for a specific connection config.
     */
    private static function shouldSkipForConnection(?array $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        if (! array_key_exists('enabled', $config)) {
            return false;
        }

        return ! (bool) $config['enabled'];
    }
}
