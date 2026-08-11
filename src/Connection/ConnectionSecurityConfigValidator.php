<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Dedicated validator for normalized security configuration.
 */
final class ConnectionSecurityConfigValidator
{
    /**
     * @param array<string,mixed> $security
     */
    public static function validate(array $security): void
    {
        self::validateSecurityEnabled($security);
        self::validateSecurityNumericLimits($security);
        self::validateSecurityScalarTypes($security);
        self::validateRawSqlPolicy($security);
        self::validateSecurityTlsPolicy($security);
    }

    private static function isInvalidRegex(string $pattern): bool
    {
        set_error_handler(static fn(): bool => true);

        try {
            return preg_match($pattern, '') === false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string,mixed> $security
     */
    private static function validateRawSqlPolicy(array $security): void
    {
        $rawSqlPolicy = $security['raw_sql_policy'] ?? 'allow';

        if (!is_string($rawSqlPolicy)) {
            throw ConnectionException::invalidConfiguration("Security config key 'raw_sql_policy' must be a string.");
        }

        $rawSqlPolicy = strtolower(trim($rawSqlPolicy));

        if (!\in_array($rawSqlPolicy, ['allow', 'deny', 'allowlist'], true)) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'raw_sql_policy' must be one of: allow, deny, allowlist.",
            );
        }

        $rawSqlAllowlist = $security['raw_sql_allowlist'] ?? [];

        if (!is_array($rawSqlAllowlist)) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'raw_sql_allowlist' must be an array of patterns.",
            );
        }

        foreach ($rawSqlAllowlist as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw ConnectionException::invalidConfiguration(
                    "Security config key 'raw_sql_allowlist' must contain only non-empty string patterns.",
                );
            }

            $pattern = trim($pattern);
            if (str_starts_with($pattern, '/') && self::isInvalidRegex($pattern)) {
                throw ConnectionException::invalidConfiguration(
                    "Security config key 'raw_sql_allowlist' contains an invalid regex pattern.",
                );
            }
        }

        if ($rawSqlPolicy === 'allowlist' && $rawSqlAllowlist === []) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'raw_sql_allowlist' cannot be empty when 'raw_sql_policy' is set to 'allowlist'.",
            );
        }
    }

    /**
     * @param array<string,mixed> $security
     */
    private static function validateSecurityEnabled(array $security): void
    {
        $enabled = $security['enabled'] ?? true;
        $allowInsecure = (bool) ($security['allow_insecure'] ?? false);

        if (!is_bool($enabled)) {
            throw ConnectionException::invalidConfiguration("Security config key 'enabled' must be a boolean.");
        }

        if ($enabled === false && !$allowInsecure) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'enabled=false' requires 'allow_insecure=true' in the same security block.",
            );
        }
    }

    /**
     * @param array<string,mixed> $security
     */
    private static function validateSecurityNumericLimits(array $security): void
    {
        foreach (['max_sql_length', 'max_params', 'max_param_bytes', 'queries_per_second', 'queries_per_minute'] as $key) {
            if (!array_key_exists($key, $security)) {
                continue;
            }

            $value = $security[$key] ?? null;

            if (!is_int($value)) {
                throw ConnectionException::invalidConfiguration(
                    sprintf("Security config key '%s' must be an integer.", $key),
                );
            }

            $isRate = in_array($key, ['queries_per_second', 'queries_per_minute'], true);
            if (($isRate && $value < 0) || (!$isRate && $value <= 0)) {
                throw ConnectionException::invalidConfiguration(
                    sprintf("Security config key '%s' is outside its valid range.", $key),
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $security
     */
    private static function validateSecurityScalarTypes(array $security): void
    {
        if (isset($security['rate_limit_key']) && !is_string($security['rate_limit_key'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'rate_limit_key' must be a string or null.");
        }

        if (isset($security['rate_limit_callback']) && !is_callable($security['rate_limit_callback'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'rate_limit_callback' must be callable or null.");
        }

        if (array_key_exists('strict_identifiers', $security) && !is_bool($security['strict_identifiers'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'strict_identifiers' must be a boolean.");
        }

        $cursorSigningKey = $security['cursor_signing_key'] ?? null;
        if (
            $cursorSigningKey !== null
            && (!is_string($cursorSigningKey) || strlen($cursorSigningKey) < 32)
        ) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'cursor_signing_key' must be null or a string of at least 32 bytes.",
            );
        }

        if (isset($security['require_tls']) && !is_bool($security['require_tls'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'require_tls' must be a boolean or null.");
        }

        if (array_key_exists('allow_insecure', $security) && !is_bool($security['allow_insecure'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'allow_insecure' must be a boolean.");
        }
    }

    /**
     * @param array<string,mixed> $security
     */
    private static function validateSecurityTlsPolicy(array $security): void
    {
        if (($security['require_tls'] ?? null) !== false) {
            return;
        }

        if ((bool) ($security['allow_insecure'] ?? false)) {
            return;
        }

        throw ConnectionException::invalidConfiguration(
            "Security config key 'require_tls=false' requires 'allow_insecure=true' in the same security block.",
        );
    }
}
