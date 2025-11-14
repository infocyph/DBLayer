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
class Security
{
    /**
     * Dangerous SQL patterns (regex) for STRICT mode.
     *
     * These are high-risk operations; we log and block them when
     * STRICT validation is enabled.
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

    /**
     * Defaults (compatible with old constants).
     */
    private const DEFAULT_QUERIES_PER_MINUTE = 1000;
    private const DEFAULT_QUERIES_PER_SECOND = 100;
    private const DEFAULT_MAX_QUERY_LENGTH   = 10000;
    private const DEFAULT_MAX_IN_ITEMS       = 1000;

    /**
     * STRICT mode can be a bit tighter.
     */
    private const STRICT_MAX_QUERY_LENGTH = 8000;
    private const STRICT_MAX_IN_ITEMS     = 800;

    /**
     * Global security mode.
     */
    private static SecurityMode $mode = SecurityMode::NORMAL;

    /**
     * Shared rate limiter instance.
     */
    private static ?RateLimiter $rateLimiter = null;

    /**
     * Get current security mode.
     */
    public static function getMode(): SecurityMode
    {
        return self::$mode;
    }

    /**
     * Set global security mode.
     */
    public static function setMode(SecurityMode $mode): void
    {
        self::$mode = $mode;
    }

    private static function limiter(): RateLimiter
    {
        if (self::$rateLimiter === null) {
            self::$rateLimiter = new RateLimiter();
        }

        return self::$rateLimiter;
    }

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
              "Transaction exceeded max time of {$maxTime}s (elapsed: " . round($elapsed, 3) . 's).'
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
          'limit_per_minute'    => self::DEFAULT_QUERIES_PER_MINUTE,
          'limit_per_second'    => self::DEFAULT_QUERIES_PER_SECOND,
        ];
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
                'sql'   => mb_substr($sql, 0, 512, 'UTF-8'),
                'error' => $e->getMessage(),
              ]
            );

            throw $e;
        }
    }

    /**
     * Validate a query according to current SecurityMode.
     *
     * NORMAL:
     *   - Length check (DEFAULT_MAX_QUERY_LENGTH)
     *   - Injection heuristics + binding checks
     *
     * STRICT:
     *   - Tighter length (STRICT_MAX_QUERY_LENGTH)
     *   - Injection heuristics + bindings
     *   - Regex-based dangerous pattern detection
     *
     * OFF:
     *   - No checks (no-op)
     *
     * @param array<int|string, mixed> $bindings
     *
     * @throws SecurityException
     */
    public static function validateQuery(string $sql, array $bindings = []): void
    {
        $mode = self::$mode;

        if ($mode === SecurityMode::OFF) {
            return;
        }

        // Length guard (mode-dependent).
        $maxLength = $mode === SecurityMode::STRICT
          ? self::STRICT_MAX_QUERY_LENGTH
          : self::DEFAULT_MAX_QUERY_LENGTH;

        SecurityValidator::validateQueryLength($sql, $maxLength);

        // STRICT: deep dangerous pattern scan (DDL / file / timing abuse).
        if ($mode === SecurityMode::STRICT) {
            foreach (self::DANGEROUS_PATTERNS as $pattern) {
                if (preg_match($pattern, $sql) === 1) {
                    self::logSecurityEvent(
                      'dangerous_query',
                      'Query contains dangerous pattern',
                      [
                        'sql'     => mb_substr($sql, 0, 512, 'UTF-8'),
                        'pattern' => $pattern,
                      ]
                    );

                    throw SecurityException::unsafeQuery('Query contains dangerous SQL pattern.');
                }
            }
        }

        // Always do injection + binding checks in NORMAL / STRICT.
        $validator = new QueryValidator();
        $validator->validateQuery($sql, $bindings);
    }

    /**
     * Generate secure token.
     */
    public static function generateToken(int $length = 32): string
    {
        if ($length <= 0) {
            return '';
        }

        return bin2hex(random_bytes($length));
    }

    /**
     * Hash sensitive data.
     */
    public static function hashSensitiveData(string $data, string $algo = 'sha256'): string
    {
        return hash($algo, $data);
    }

    /**
     * Validate token equality in constant time.
     */
    public static function validateToken(string $token, string $expected): bool
    {
        return hash_equals($expected, $token);
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

        foreach ($dangerous as $operation) {
            if (str_starts_with($sql, $operation)) {
                return true;
            }
        }

        return false;
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
              'Dangerous SQL operation requires explicit confirmation.'
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
              "Invalid table name [{$table}]."
            );
        }
    }

    /**
     * Log a security event (kept simple and JSON-structured).
     */
    public static function logSecurityEvent(string $type, string $message, array $context = []): void
    {
        $payload = [
          'type'      => 'security',
          'event'     => $type,
          'message'   => $message,
          'context'   => $context,
          'timestamp' => date('Y-m-d H:i:s'),
          'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
          'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ];

        error_log(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
