<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Security\Security;

/**
 * Immutable connection configuration wrapper.
 *
 * Responsibilities:
 *  - Normalize driver names / aliases
 *  - Merge with sensible defaults
 *  - Apply driver-specific defaults via DriverProfile
 *  - Delegate advanced validation to the driver when available
 */
final class ConnectionConfig
{
    /**
     * Base defaults for all drivers.
     *
     * @var array<string,mixed>
     */
    private const array DEFAULTS = [
        'driver'    => 'mysql',
        'host'      => '127.0.0.1',
        'port'      => null,
        'database'  => '',
        'username'  => '',
        'password'  => '',
        'charset'   => null,
        'collation' => null,
        'schema'    => null,
        'prefix'    => '',
        'options'   => [],
        'write'     => [],
        'read'      => [],
        'read_strategy' => 'random',
        'read_health_cooldown' => 30,
        'sticky'    => false,
        'security'  => [],
    ];

    /**
     * Common aliases → canonical driver name.
     *
     * @var array<string,string>
     */
    private const array DRIVER_ALIASES = [
        'pdo_mysql'  => 'mysql',
        'mysqli'     => 'mysql',
        'mariadb'    => 'mysql',

        'pgsql'      => 'pgsql',
        'postgres'   => 'pgsql',
        'postgresql' => 'pgsql',

        'sqlite3'    => 'sqlite',
    ];

    /**
     * Default SQL security configuration.
     *
     * @var array<string,mixed>
     */
    private const array SECURITY_DEFAULT = [
        'enabled'         => true,
        'max_sql_length'  => 16_384,
        'max_params'      => 512,
        'max_param_bytes' => 1_024,
        'queries_per_second' => 0,
        'queries_per_minute' => 0,
        'rate_limit_key' => null,
        'rate_limit_callback' => null,
        'strict_identifiers' => true,
        'require_tls' => null,
        'raw_sql_policy' => 'allow',
        'raw_sql_allowlist' => [],
    ];

    /**
     * Normalized configuration.
     *
     * @var array<string,mixed>
     */
    private array $config;

    /**
     * Create a new configuration instance.
     *
     * @param  array<string,mixed>  $config
     */
    public function __construct(array $config)
    {
        // Normalize driver name & aliases first.
        if (isset($config['driver']) && is_string($config['driver'])) {
            $config['driver'] = $this->normalizeDriverName($config['driver']);
        }

        // Merge with generic defaults (shallow; security handled separately).
        $config = array_replace(self::DEFAULTS, $config);

        // Normalize security configuration.
        $security = $config['security'] ?? [];
        if (! is_array($security)) {
            $security = [];
        }

        $config['security'] = array_replace(self::SECURITY_DEFAULT, $security);
        $this->validateSecurityConfig($config['security']);

        // Apply driver-specific connection defaults via DriverProfile.
        $config = DriverProfile::applyConnectionDefaults($config);

        // Basic structural validation.
        $this->validateConfig($config);

        // Let driver perform additional validation / normalization if registered.
        $this->validateWithDriver($config);

        $this->config = $config;
    }

    /**
     * Convenience factory.
     *
     * @param  array<string,mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Get a config value with optional default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get the configured database name.
     */
    public function getDatabase(): string
    {
        $database = $this->config['database'] ?? '';

        return is_string($database) ? $database : '';
    }

    /**
     * Get the configured driver name.
     */
    public function getDriver(): string
    {
        $driver = $this->config['driver'] ?? '';

        if (! is_string($driver) || $driver === '') {
            throw ConnectionException::invalidConfiguration('Database driver is required.');
        }

        return $driver;
    }

    /**
     * Get the read replica configuration (if any).
     *
     * Returns the first read replica config for backward compatibility.
     *
     * @return array<string,mixed>
     */
    public function getReadConfig(): array
    {
        $read = $this->getReadConfigs();

        return $read[0] ?? [];
    }

    /**
     * Get all read replica configurations.
     *
     * Supports:
     *  - read => ['host' => 'replica']
     *  - read => [['host' => 'replica1'], ['host' => 'replica2']]
     *
     * @return list<array<string,mixed>>
     */
    public function getReadConfigs(): array
    {
        $read = $this->config['read'] ?? [];

        if (! is_array($read) || $read === []) {
            return [];
        }

        return $this->expandReplicaHostVariants(
            $this->normalizeReplicaConfigs($read),
        );
    }

    /**
     * Get read-replica health cooldown in seconds after a failed probe.
     */
    public function getReadHealthCooldown(): int
    {
        $seconds = $this->config['read_health_cooldown'] ?? 30;

        if (! is_int($seconds) && ! is_numeric($seconds)) {
            return 30;
        }

        return max(0, (int) $seconds);
    }

    /**
     * Get read-replica selection strategy.
     *
     * Supported values:
     *  - random
     *  - round_robin
     *  - least_latency
     *  - weighted
     */
    public function getReadStrategy(): string
    {
        $strategy = $this->config['read_strategy'] ?? 'random';

        if (! is_string($strategy)) {
            return 'random';
        }

        $strategy = strtolower(trim($strategy));

        return match ($strategy) {
            'round-robin' => 'round_robin',
            'least-latency' => 'least_latency',
            'weighted-random' => 'weighted',
            'health-aware' => 'weighted',
            'random', 'round_robin', 'least_latency', 'weighted' => $strategy,
            default => 'random',
        };
    }

    /**
     * Get the write override configuration (if any).
     *
     * Returns the first normalized write config for backward compatibility.
     *
     * @return array<string,mixed>
     */
    public function getWriteConfig(): array
    {
        $write = $this->getWriteConfigs();

        return $write[0] ?? [];
    }

    /**
     * Get all write override configurations.
     *
     * Supports:
     *  - write => ['host' => 'writer']
     *  - write => [['host' => 'writer-1'], ['host' => 'writer-2']]
     *  - write => ['host' => ['writer-1', 'writer-2']]
     *
     * @return list<array<string,mixed>>
     */
    public function getWriteConfigs(): array
    {
        $write = $this->config['write'] ?? [];

        if (! is_array($write) || $write === []) {
            return [];
        }

        return $this->expandReplicaHostVariants(
            $this->normalizeReplicaConfigs($write),
        );
    }

    /**
     * Whether read replica configuration is present.
     */
    public function hasReadConfig(): bool
    {
        return $this->getReadConfigs() !== [];
    }

    /**
     * Whether write override configuration is present.
     */
    public function hasWriteConfig(): bool
    {
        return $this->getWriteConfigs() !== [];
    }

    /**
     * Whether SQL security checks are enabled.
     */
    public function isSecurityEnabled(): bool
    {
        $security = $this->config['security'] ?? [];

        return is_array($security) && ! empty($security['enabled']);
    }

    /**
     * Whether sticky read-after-write is enabled.
     */
    public function isSticky(): bool
    {
        return (bool) ($this->config['sticky'] ?? false);
    }

    /**
     * Get the full security configuration array.
     *
     * @return array<string,mixed>
     */
    public function securityConfig(): array
    {
        $security = $this->config['security'] ?? [];

        return is_array($security) ? $security : self::SECURITY_DEFAULT;
    }

    /**
     * Export the underlying configuration array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Return a new instance with one key changed.
     */
    public function with(string $key, mixed $value): self
    {
        $config       = $this->config;
        $config[$key] = $value;

        return new self($config);
    }

    /**
     * Expand config fragments that define host as a list into one fragment per host.
     *
     * @param  list<array<string,mixed>>  $replicas
     * @return list<array<string,mixed>>
     */
    private function expandReplicaHostVariants(array $replicas): array
    {
        $expanded = [];

        foreach ($replicas as $replica) {
            $hosts = $replica['host'] ?? null;

            if (! is_array($hosts)) {
                $expanded[] = $replica;

                continue;
            }

            $hasExpandedHost = false;

            foreach ($hosts as $host) {
                if (! is_string($host) || trim($host) === '') {
                    continue;
                }

                $copy = $replica;
                $copy['host'] = trim($host);
                $expanded[] = $copy;
                $hasExpandedHost = true;
            }

            if (! $hasExpandedHost) {
                $expanded[] = $replica;
            }
        }

        return $expanded;
    }

    /**
     * Whether insecure transport override is enabled.
     */
    private function isInsecureTransportAllowed(): bool
    {
        $override = getenv('DBLAYER_ALLOW_INSECURE_TRANSPORT');

        return $override === '1' || strtolower((string) $override) === 'true';
    }

    /**
     * Whether current app environment is production-like.
     */
    private function isProductionEnvironment(): bool
    {
        $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: '')));

        return \in_array($appEnv, ['production', 'prod'], true);
    }

    /**
     * Normalize a driver name (aliases → canonical).
     */
    private function normalizeDriverName(string $driver): string
    {
        $driver = strtolower(trim($driver));

        return self::DRIVER_ALIASES[$driver] ?? $driver;
    }

    /**
     * Normalize replica configuration into a list of associative arrays.
     *
     * @param  array<int|string,mixed>  $replicas
     * @return list<array<string,mixed>>
     */
    private function normalizeReplicaConfigs(array $replicas): array
    {
        if ($replicas === []) {
            return [];
        }

        if (\array_is_list($replicas)) {
            $normalized = [];

            foreach ($replicas as $replica) {
                if (is_array($replica) && $replica !== []) {
                    /** @var array<string,mixed> $replica */
                    $normalized[] = $replica;
                }
            }

            return $normalized;
        }

        /** @var array<string,mixed> $single */
        $single = $replicas;

        return $single === [] ? [] : [$single];
    }

    /**
     * Basic validation that does not depend on any particular driver.
     *
     * @param  array<string,mixed>  $config
     */
    private function validateConfig(array $config): void
    {
        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw ConnectionException::invalidConfiguration('Database driver must be a non-empty string.');
        }

        // Built-in relational engines: require database name.
        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            if (
                ! isset($config['database'])
                || ! is_string($config['database'])
                || $config['database'] === ''
            ) {
                throw ConnectionException::invalidConfiguration(
                    sprintf("Config key 'database' is required for driver '%s'.", $driver),
                );
            }
        }

        // Host/username for typical client/server engines (skip sqlite).
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            foreach (['host', 'username'] as $key) {
                if (
                    ! isset($config[$key])
                    || ! is_string($config[$key])
                    || $config[$key] === ''
                ) {
                    throw ConnectionException::invalidConfiguration(
                        sprintf("Config key '%s' is required for driver '%s'.", $key, $driver),
                    );
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $security
     */
    private function validateRawSqlPolicy(array $security): void
    {
        $rawSqlPolicy = $security['raw_sql_policy'] ?? 'allow';

        if (! is_string($rawSqlPolicy)) {
            throw ConnectionException::invalidConfiguration("Security config key 'raw_sql_policy' must be a string.");
        }

        $rawSqlPolicy = strtolower(trim($rawSqlPolicy));

        if (! \in_array($rawSqlPolicy, ['allow', 'deny', 'allowlist'], true)) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'raw_sql_policy' must be one of: allow, deny, allowlist.",
            );
        }

        $rawSqlAllowlist = $security['raw_sql_allowlist'] ?? [];

        if (! is_array($rawSqlAllowlist)) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'raw_sql_allowlist' must be an array of patterns.",
            );
        }

        foreach ($rawSqlAllowlist as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                throw ConnectionException::invalidConfiguration(
                    "Security config key 'raw_sql_allowlist' must contain only non-empty string patterns.",
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
     * Validate normalized security configuration values.
     *
     * @param  array<string,mixed>  $security
     */
    private function validateSecurityConfig(array $security): void
    {
        $this->validateSecurityEnabled($security);
        $this->validateSecurityNumericLimits($security);
        $this->validateSecurityScalarTypes($security);
        $this->validateRawSqlPolicy($security);
        $this->validateSecurityTlsPolicy($security);
    }

    /**
     * @param  array<string,mixed>  $security
     */
    private function validateSecurityEnabled(array $security): void
    {
        $enabled = $security['enabled'] ?? true;

        if (! is_bool($enabled)) {
            throw ConnectionException::invalidConfiguration("Security config key 'enabled' must be a boolean.");
        }

        if ($enabled === false && ! Security::isInsecureModeAllowed()) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'enabled=false' is blocked outside local/testing environments.",
            );
        }
    }

    /**
     * @param  array<string,mixed>  $security
     */
    private function validateSecurityNumericLimits(array $security): void
    {
        foreach (['max_sql_length', 'max_params', 'max_param_bytes', 'queries_per_second', 'queries_per_minute'] as $key) {
            if (! array_key_exists($key, $security)) {
                continue;
            }

            $value = $security[$key];

            if ($value !== null && ! is_int($value) && ! is_numeric($value)) {
                throw ConnectionException::invalidConfiguration(
                    sprintf("Security config key '%s' must be numeric.", $key),
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $security
     */
    private function validateSecurityScalarTypes(array $security): void
    {
        if (array_key_exists('rate_limit_key', $security) && $security['rate_limit_key'] !== null && ! is_string($security['rate_limit_key'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'rate_limit_key' must be a string or null.");
        }

        if (array_key_exists('rate_limit_callback', $security) && $security['rate_limit_callback'] !== null && ! is_callable($security['rate_limit_callback'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'rate_limit_callback' must be callable or null.");
        }

        if (array_key_exists('strict_identifiers', $security) && ! is_bool($security['strict_identifiers'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'strict_identifiers' must be a boolean.");
        }

        if (array_key_exists('require_tls', $security) && $security['require_tls'] !== null && ! is_bool($security['require_tls'])) {
            throw ConnectionException::invalidConfiguration("Security config key 'require_tls' must be a boolean or null.");
        }
    }

    /**
     * @param  array<string,mixed>  $security
     */
    private function validateSecurityTlsPolicy(array $security): void
    {
        if (($security['require_tls'] ?? null) !== false) {
            return;
        }

        if (! $this->isProductionEnvironment() || $this->isInsecureTransportAllowed()) {
            return;
        }

        throw ConnectionException::invalidConfiguration(
            "Security config key 'require_tls=false' is blocked in production unless DBLAYER_ALLOW_INSECURE_TRANSPORT=1 is set.",
        );
    }

    /**
     * Delegate advanced validation / normalization to driver when registered.
     *
     * @param  array<string,mixed>  $config
     */
    private function validateWithDriver(array $config): void
    {
        $driverName = $config['driver'] ?? null;

        if (! is_string($driverName) || $driverName === '') {
            return;
        }

        try {
            $driver = DriverRegistry::resolve($driverName);
        } catch (ConnectionException) {
            // Unknown driver (custom or not registered): skip driver-level validation.
            return;
        }

        // Give driver a chance to throw a more specific exception.
        $driver->validateConfig($config);
    }
}
