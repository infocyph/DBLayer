<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Exceptions;

/**
 * Security Exception
 *
 * Exception thrown when security violations or attacks are detected.
 * Handles SQL injection attempts, rate limiting, and validation failures.
 *
 * @package Infocyph\DBLayer\Exceptions
 * @author Hasan
 */
class SecurityException extends DBException
{
    /**
     * Create exception for SQL injection attempt
     *
     * @param string $input Suspicious input that triggered detection
     * @param string $pattern Pattern that matched the injection attempt
     * @return self
     */
    public static function sqlInjectionDetected(string $input, string $pattern): self
    {
        return new self(
            "Potential SQL injection detected. Pattern matched: {$pattern}. " .
            "Input has been blocked for security."
        );
    }

    /**
     * Create exception for rate limit exceeded
     *
     * @param string $identifier Identifier that exceeded limit (IP, user, etc.)
     * @param int $limit Rate limit threshold
     * @param int $window Time window in seconds
     * @return self
     */
    public static function rateLimitExceeded(string $identifier, int $limit, int $window): self
    {
        return new self(
            "Rate limit exceeded for '{$identifier}'. " .
            "Maximum {$limit} requests allowed per {$window} seconds."
        );
    }

    /**
     * Create exception for invalid input validation
     *
     * @param string $field Field name
     * @param string $reason Validation failure reason
     * @return self
     */
    public static function validationFailed(string $field, string $reason): self
    {
        return new self("Validation failed for field '{$field}': {$reason}");
    }

    /**
     * Create exception for XSS attempt detection
     *
     * @param string $input Suspicious input
     * @return self
     */
    public static function xssAttemptDetected(string $input): self
    {
        return new self("Potential XSS attack detected. Input has been blocked for security.");
    }

    /**
     * Create exception for unauthorized access
     *
     * @param string $resource Resource being accessed
     * @param string $reason Reason for denial
     * @return self
     */
    public static function unauthorized(string $resource, string $reason = ''): self
    {
        $message = "Unauthorized access to resource: {$resource}";
        if ($reason) {
            $message .= ". Reason: {$reason}";
        }
        
        return new self($message);
    }

    /**
     * Create exception for forbidden operation
     *
     * @param string $operation Operation that was attempted
     * @return self
     */
    public static function forbidden(string $operation): self
    {
        return new self("Forbidden operation: {$operation}");
    }

    /**
     * Create exception for dangerous query detection
     *
     * @param string $pattern Dangerous pattern detected (DROP, TRUNCATE, etc.)
     * @return self
     */
    public static function dangerousQueryDetected(string $pattern): self
    {
        return new self(
            "Dangerous query pattern detected: {$pattern}. " .
            "Query has been blocked for security."
        );
    }

    /**
     * Create exception for invalid credentials
     *
     * @return self
     */
    public static function invalidCredentials(): self
    {
        return new self("Invalid database credentials provided");
    }

    /**
     * Create exception for token validation failure
     *
     * @param string $reason Validation failure reason
     * @return self
     */
    public static function invalidToken(string $reason): self
    {
        return new self("Invalid security token: {$reason}");
    }

    /**
     * Create exception for CSRF token mismatch
     *
     * @return self
     */
    public static function csrfTokenMismatch(): self
    {
        return new self("CSRF token mismatch. Request has been rejected.");
    }

    /**
     * Create exception for suspicious activity
     *
     * @param string $description Description of suspicious activity
     * @return self
     */
    public static function suspiciousActivity(string $description): self
    {
        return new self("Suspicious activity detected: {$description}");
    }
}
