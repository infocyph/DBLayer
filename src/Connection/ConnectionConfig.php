<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * Connection Configuration
 *
 * Manages database connection configuration.
 * Optimized as a readonly immutable class for PHP 8.4.
 */
readonly class ConnectionConfig
{
    /**
     * Default configuration values.
     *
     * @var array<string, mixed>
     */
    private const DEFAULTS = [
      'driver'    => 'mysql',
      'host'      => 'localhost',
      'port'      => 3306,
      'database'  => '',
      'username'  => 'root',
      'password'  => '',
      'charset'   => 'utf8mb4',
      'collation' => 'utf8mb4_unicode_ci',
      'prefix'    => '',
      'strict'    => true,
      'engine'    => 'InnoDB',
      'options'   => [],
      'read'      => [],
        // Optional security configuration:
        // [
        //     'enabled' => bool,
        //     'mode'    => 'off'|'normal'|'strict' (consumed by bootstrap, not here)
        // ]
      'security'  => [],
    ];

    /**
     * Driver-specific default ports.
     *
     * @var array<string, int|null>
     */
    private const DRIVER_PORTS = [
      'mysql'  => 3306,
      'pgsql'  => 5432,
      'sqlite' => null,
    ];

    /**
     * Configuration array.
     *
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * Create a new configuration instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);
        $this->config = $this->mergeDefaults($config);
    }

    /**
     * Create configuration from array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * Create MySQL configuration.
     *
     * @param  array<string, mixed>  $options
     */
    public static function mysql(
      string $host,
      string $database,
      string $username,
      string $password,
      int $port = 3306,
      array $options = []
    ): self {
        return new self(array_merge([
          'driver'   => 'mysql',
          'host'     => $host,
          'port'     => $port,
          'database' => $database,
          'username' => $username,
          'password' => $password,
        ], $options));
    }

    /**
     * Create PostgreSQL configuration.
     *
     * @param  array<string, mixed>  $options
     */
    public static function pgsql(
      string $host,
      string $database,
      string $username,
      string $password,
      int $port = 5432,
      array $options = []
    ): self {
        return new self(array_merge([
          'driver'   => 'pgsql',
          'host'     => $host,
          'port'     => $port,
          'database' => $database,
          'username' => $username,
          'password' => $password,
        ], $options));
    }

    /**
     * Create SQLite configuration.
     *
     * @param  array<string, mixed>  $options
     */
    public static function sqlite(string $database, array $options = []): self
    {
        return new self(array_merge([
          'driver'   => 'sqlite',
          'database' => $database,
        ], $options));
    }

    /**
     * Copy configuration.
     * Renamed from 'clone' to avoid syntax error with reserved keyword.
     */
    public function copy(): self
    {
        return new self($this->config);
    }

    /**
     * Get a specific configuration value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getCharset(): string
    {
        return $this->config['charset'];
    }

    public function getCollation(): string
    {
        return $this->config['collation'];
    }

    public function getDatabase(): string
    {
        return $this->config['database'];
    }

    public function getDriver(): string
    {
        return $this->config['driver'];
    }

    public function getHost(): string
    {
        return $this->config['host'] ?? '';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getOptions(): array
    {
        return $this->config['options'];
    }

    public function getPassword(): string
    {
        return $this->config['password'] ?? '';
    }

    public function getPort(): int
    {
        return isset($this->config['port']) ? (int) $this->config['port'] : 0;
    }

    public function getPrefix(): string
    {
        return $this->config['prefix'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getReadConfig(): array
    {
        return $this->config['read'];
    }

    public function getUsername(): string
    {
        return $this->config['username'] ?? '';
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }

    public function hasReadConfig(): bool
    {
        return ! empty($this->config['read']);
    }

    /**
     * Get security configuration as array.
     *
     * @return array<string, mixed>
     */
    public function getSecurityConfig(): array
    {
        $security = $this->config['security'] ?? [];

        return is_array($security) ? $security : [];
    }

    /**
     * Whether security checks are enabled at config level.
     */
    public function isSecurityEnabled(): bool
    {
        $security = $this->getSecurityConfig();

        return (bool) ($security['enabled'] ?? false);
    }

    /**
     * Get a new instance with a modified value.
     */
    public function with(string $key, mixed $value): self
    {
        $config       = $this->config;
        $config[$key] = $value;

        return new self($config);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Apply MySQL-specific defaults.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyMySqlDefaults(array $config): array
    {
        $config['charset']   = $config['charset']   ?? 'utf8mb4';
        $config['collation'] = $config['collation'] ?? 'utf8mb4_unicode_ci';
        $config['engine']    = $config['engine']    ?? 'InnoDB';

        return $config;
    }

    /**
     * Apply PostgreSQL-specific defaults.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPostgreSqlDefaults(array $config): array
    {
        $config['charset'] = $config['charset'] ?? 'utf8';
        $config['schema']  = $config['schema']  ?? 'public';

        return $config;
    }

    /**
     * Apply SQLite-specific defaults.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySqliteDefaults(array $config): array
    {
        // SQLite doesn't need host/port; username/password are harmless.
        unset($config['host'], $config['port']);

        return $config;
    }

    /**
     * Merge with default configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function mergeDefaults(array $config): array
    {
        $driver = $config['driver'];

        // Set default port based on driver.
        if (! isset($config['port']) && array_key_exists($driver, self::DRIVER_PORTS)) {
            $config['port'] = self::DRIVER_PORTS[$driver];
        }

        // Merge with defaults.
        $merged = array_merge(self::DEFAULTS, $config);

        // Driver-specific defaults.
        return match ($driver) {
            'mysql'  => $this->applyMySqlDefaults($merged),
            'pgsql'  => $this->applyPostgreSqlDefaults($merged),
            'sqlite' => $this->applySqliteDefaults($merged),
            default  => $merged,
        };
    }

    /**
     * Validate configuration.
     *
     * @param  array<string, mixed>  $config
     */
    private function validateConfig(array $config): void
    {
        if (! isset($config['driver'])) {
            throw ConnectionException::missingConfigKey('driver');
        }

        $driver = $config['driver'];

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw ConnectionException::unsupportedDriver($driver);
        }

        // SQLite only requires database path.
        if ($driver === 'sqlite') {
            if (! isset($config['database'])) {
                throw ConnectionException::missingConfigKey('database');
            }

            return;
        }

        // MySQL and PostgreSQL require more configuration.
        foreach (['host', 'database', 'username'] as $key) {
            if (! isset($config[$key])) {
                throw ConnectionException::missingConfigKey($key);
            }
        }
    }
}
