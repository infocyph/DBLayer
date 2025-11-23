<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use InvalidArgumentException;

/**
 * Static driver registry.
 *
 * - Core resolves drivers by ConnectionConfig::driver
 * - Users can register custom drivers at runtime.
 */
final class DriverRegistry
{
    /**
     * Cached driver instances.
     *
     * @var array<string,DriverInterface>
     */
    private static array $cache = [];

    /**
     * Driver name → class map.
     *
     * @var array<string,class-string<DriverInterface>>
     */
    private static array $map = [
        // defaults; concrete classes implemented in driver sub-namespaces
      'mysql'    => \Infocyph\DBLayer\Driver\MySQL\MySQLDriver::class,
      'mariadb'  => \Infocyph\DBLayer\Driver\MySQL\MySQLDriver::class,
      'pgsql'    => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
      'postgres' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
      'sqlite'   => \Infocyph\DBLayer\Driver\SQLite\SQLiteDriver::class,
      'sqlite3'  => \Infocyph\DBLayer\Driver\SQLite\SQLiteDriver::class,
    ];

    /**
     * Clear cached driver instances (keeps the map).
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get currently registered driver names.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::$map);
    }

    /**
     * Register or override a driver.
     *
     * @param  class-string<DriverInterface>  $class
     */
    public static function register(string $name, string $class): void
    {
        $name = strtolower($name);

        if (! is_subclass_of($class, DriverInterface::class)) {
            throw new InvalidArgumentException(sprintf(
              'Driver class "%s" must implement %s.',
              $class,
              DriverInterface::class
            ));
        }

        self::$map[$name] = $class;
        unset(self::$cache[$name]);
    }

    /**
     * Resolve a driver implementation by logical name.
     */
    public static function resolve(string $driver): DriverInterface
    {
        $driver = strtolower($driver);

        if (isset(self::$cache[$driver])) {
            return self::$cache[$driver];
        }

        if (! isset(self::$map[$driver])) {
            throw ConnectionException::unsupportedDriver($driver);
        }

        $class = self::$map[$driver];

        /** @var DriverInterface $instance */
        $instance = new $class();

        return self::$cache[$driver] = $instance;
    }
}
