<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;

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
            throw ConnectionException::invalidConfig('Database driver is required.');
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
            throw ConnectionException::invalidConfig('Database driver must be a non-empty string.');
        }

        // Built-in relational engines: require database name.
        if (in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            if (
                ! isset($config['database'])
                || ! is_string($config['database'])
                || $config['database'] === ''
            ) {
                throw ConnectionException::invalidConfig(
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
                    throw ConnectionException::invalidConfig(
                        sprintf("Config key '%s' is required for driver '%s'.", $key, $driver),
                    );
                }
            }
        }
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
