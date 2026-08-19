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
    /** @var array<string,DriverInterface> */
    private static array $cache = [];

    /** @var array<string,class-string<DriverInterface>> */
    private static array $map = [
        'mysql' => \Infocyph\DBLayer\Driver\MySQL\MySQLDriver::class,
        'pdo_mysql' => \Infocyph\DBLayer\Driver\MySQL\MySQLDriver::class,
        'mysqli' => \Infocyph\DBLayer\Driver\MySQL\MySQLDriver::class,
        'mariadb' => \Infocyph\DBLayer\Driver\MariaDB\MariaDBDriver::class,
        'pgsql' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
        'postgres' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
        'postgresql' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
        'psql' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
        'pdo_pgsql' => \Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver::class,
        'mssql' => \Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver::class,
        'sqlsrv' => \Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver::class,
        'sqlserver' => \Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver::class,
        'pdo_sqlsrv' => \Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver::class,
        'sqlite' => \Infocyph\DBLayer\Driver\SQLite\SQLiteDriver::class,
        'sqlite3' => \Infocyph\DBLayer\Driver\SQLite\SQLiteDriver::class,
        'pdo_sqlite' => \Infocyph\DBLayer\Driver\SQLite\SQLiteDriver::class,
    ];

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::$map);
    }

    /** @param class-string<DriverInterface> $class */
    public static function register(string $name, string $class): void
    {
        $name = strtolower($name);

        if (!is_subclass_of($class, DriverInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'Driver class "%s" must implement %s.',
                $class,
                DriverInterface::class,
            ));
        }

        self::$map[$name] = $class;
        unset(self::$cache[$name]);
    }

    public static function resolve(string $driver): DriverInterface
    {
        $driver = strtolower($driver);

        if (isset(self::$cache[$driver])) {
            return self::$cache[$driver];
        }

        if (!isset(self::$map[$driver])) {
            throw ConnectionException::unsupportedDriver($driver);
        }

        $class = self::$map[$driver];
        $instance = new $class();

        return self::$cache[$driver] = $instance;
    }
}
