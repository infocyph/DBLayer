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
    private const DEFAULTS = [
      'driver'   => 'mysql',
      'host'     => '127.0.0.1',
      'port'     => null,
      'database' => '',
      'username' => '',
      'password' => '',
      'charset'  => null,
      'collation' => null,
      'schema'   => null,
      'prefix'   => '',
      'options'  => [],
      'read'     => [],
      'security' => [],
    ];

    /**
     * Common aliases → canonical driver name.
     *
     * @var array<string,string>
     */
    private const DRIVER_ALIASES = [
      'pdo_mysql'   => 'mysql',
      'mysqli'      => 'mysql',
      'mariadb'     => 'mysql',

      'pgsql'       => 'pgsql',
      'postgres'    => 'pgsql',
      'postgresql'  => 'pgsql',

      'sqlite3'     => 'sqlite',
    ];

    /**
     * Default SQL security configuration.
     *
     * @var array<string,mixed>
     */
    private const SECURITY_DEFAULT = [
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
     * @return array<string,mixed>
     */
    public function getReadConfig(): array
    {
        $read = $this->config['read'] ?? [];

        return is_array($read) ? $read : [];
    }

    /**
     * Whether read replica configuration is present.
     */
    public function hasReadConfig(): bool
    {
        return isset($this->config['read']) && is_array($this->config['read']) && $this->config['read'] !== [];
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
        $config         = $this->config;
        $config[$key]   = $value;

        return new self($config);
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
            if (! isset($config['database']) || ! is_string($config['database']) || $config['database'] === '') {
                throw ConnectionException::invalidConfig(
                    sprintf("Config key 'database' is required for driver '%s'.", $driver)
                );
            }
        }

        // Host/username for typical client/server engines (skip sqlite).
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            foreach (['host', 'username'] as $key) {
                if (! isset($config[$key]) || ! is_string($config[$key]) || $config[$key] === '') {
                    throw ConnectionException::invalidConfig(
                        sprintf("Config key '%s' is required for driver '%s'.", $key, $driver)
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
