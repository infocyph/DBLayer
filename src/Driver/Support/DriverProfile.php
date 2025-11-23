<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

use Infocyph\DBLayer\Driver\MySQL\MySQLGrammar;
use Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLGrammar;
use Infocyph\DBLayer\Driver\SQLite\SQLiteGrammar;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Grammar\Grammar;
use PDOException;
use Throwable;

/**
 * DriverProfile
 *
 * Central place for driver-specific configuration that is needed
 * by non-driver classes (Connection, Transaction, ConnectionConfig).
 *
 * Responsibilities:
 *  - Create Grammar instances for a given driver
 *  - Provide default connection options (port/charset/collation/schema)
 *  - Classify deadlock errors per driver
 */
final class DriverProfile
{
    /**
     * Vendor-specific error codes that indicate a deadlock, per driver.
     *
     * @var array<string,list<string>>
     */
    private const DEADLOCK_ERROR_CODES = [
        // MySQL / MariaDB: ER_LOCK_DEADLOCK
      'mysql'   => ['1213'],
      'mariadb' => ['1213'],
        // PostgreSQL typically uses only SQLSTATE for deadlock ⇒ no extra here
    ];

    /**
     * Message fragments that strongly suggest a deadlock.
     *
     * @var array<string,list<string>>
     */
    private const DEADLOCK_MESSAGE_HINTS = [
      'mysql' => [
        'deadlock found when trying to get lock',
        'lock wait timeout exceeded; try restarting transaction',
        'deadlock',
      ],
      'mariadb' => [
        'deadlock',
      ],
      'pgsql' => [
        'deadlock detected',
        'deadlock',
      ],
      'postgres' => [
        'deadlock detected',
        'deadlock',
      ],
      'sqlite' => [
        'database is locked',
        'deadlock',
      ],
      'default' => [
        'deadlock',
      ],
    ];

    /**
     * SQLSTATE codes that indicate a deadlock, per driver.
     *
     * @var array<string,list<string>>
     */
    private const DEADLOCK_SQLSTATES = [
        // MySQL / MariaDB
      'mysql'   => ['40001'],
      'mariadb' => ['40001'],

        // PostgreSQL
      'pgsql'    => ['40P01', '40001'],
      'postgres' => ['40P01', '40001'],
    ];

    /**
     * Default ports by driver (when none is explicitly provided).
     *
     * @var array<string,int|null>
     */
    private const DEFAULT_PORTS = [
      'mysql'   => 3306,
      'mariadb' => 3306,
      'pgsql'   => 5432,
      'postgres'=> 5432,
      'sqlite'  => null,
    ];

    private function __construct()
    {
        // Static-only utility.
    }

    /**
     * Apply driver-specific connection defaults (port, charset, collation, schema, etc.).
     *
     * This is intended to be called from ConnectionConfig, after base defaults
     * and user config are merged.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public static function applyConnectionDefaults(array $config): array
    {
        $driver = isset($config['driver']) && is_string($config['driver'])
          ? strtolower($config['driver'])
          : '';

        if ($driver === '') {
            return $config;
        }

        // Default port.
        if (! array_key_exists('port', $config) || $config['port'] === null || $config['port'] === '') {
            $port = self::DEFAULT_PORTS[$driver] ?? null;

            if ($port !== null) {
                $config['port'] = $port;
            }
        }

        // Charset / collation / schema defaults per dialect.
        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                if (! isset($config['charset']) || ! is_string($config['charset']) || $config['charset'] === '') {
                    $config['charset'] = 'utf8mb4';
                }

                if (! isset($config['collation']) || ! is_string($config['collation']) || $config['collation'] === '') {
                    $config['collation'] = 'utf8mb4_unicode_ci';
                }

                break;

            case 'pgsql':
            case 'postgres':
            case 'postgresql':
                if (! isset($config['charset']) || ! is_string($config['charset']) || $config['charset'] === '') {
                    $config['charset'] = 'utf8';
                }

                if (! isset($config['schema']) || ! is_string($config['schema']) || $config['schema'] === '') {
                    $config['schema'] = 'public';
                }

                break;

            case 'sqlite':
            case 'sqlite3':
                // Nothing extra for now. Host/port generally unused.
                break;
        }

        return $config;
    }

    /**
     * Classify whether a Throwable is caused by a deadlock for a given driver.
     */
    public static function causedByDeadlock(string $driver, Throwable $e): bool
    {
        $driver  = strtolower($driver);
        $message = $e->getMessage();

        $hints = self::DEADLOCK_MESSAGE_HINTS[$driver]
          ?? self::DEADLOCK_MESSAGE_HINTS['default'];

        foreach ($hints as $needle) {
            if ($needle === '') {
                continue;
            }

            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        if (! $e instanceof PDOException) {
            return false;
        }

        $errorInfo  = $e->errorInfo;
        $sqlState   = is_array($errorInfo) && isset($errorInfo[0]) ? (string) $errorInfo[0] : null;
        $vendorCode = is_array($errorInfo) && isset($errorInfo[1]) ? (string) $errorInfo[1] : null;
        $code       = (string) $e->getCode();

        $states = self::DEADLOCK_SQLSTATES[$driver] ?? [];
        $codes  = self::DEADLOCK_ERROR_CODES[$driver] ?? [];

        if ($sqlState !== null && $sqlState !== '' && in_array($sqlState, $states, true)) {
            return true;
        }

        if ($vendorCode !== null && $vendorCode !== '' && in_array($vendorCode, $codes, true)) {
            return true;
        }

        if ($code !== '' && in_array($code, $codes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Create a Grammar instance for the given driver.
     */
    public static function createGrammar(string $driver): Grammar
    {
        $driver = strtolower($driver);

        return match ($driver) {
            'mysql', 'mariadb'              => new MySQLGrammar(),
            'pgsql', 'postgres', 'postgresql' => new PostgreSQLGrammar(),
            'sqlite', 'sqlite3'             => new SQLiteGrammar(),
            default                         => throw ConnectionException::unsupportedDriver($driver),
        };
    }
}
