<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Support;

use Infocyph\DBLayer\Exceptions\ConnectionException;
use PDOException;
use Throwable;

final class DriverProfile
{
    /** @var array<string,list<string>> */
    private const array DEADLOCK_ERROR_CODES = ['mysql' => ['1213'], 'mariadb' => ['1213'], 'mssql' => ['1205']];

    /** @var array<string,list<string>> */
    private const array DEADLOCK_MESSAGE_HINTS = [
        'mysql' => ['deadlock found when trying to get lock', 'lock wait timeout exceeded; try restarting transaction', 'deadlock'],
        'mariadb' => ['deadlock'], 'pgsql' => ['deadlock detected', 'deadlock'], 'postgres' => ['deadlock detected', 'deadlock'],
        'sqlite' => ['database is locked', 'deadlock'], 'mssql' => ['deadlock victim', 'deadlocked on lock resources', 'deadlock'], 'default' => ['deadlock'],
    ];

    /** @var array<string,list<string>> */
    private const array DEADLOCK_SQLSTATES = ['mysql' => ['40001'], 'mariadb' => ['40001'], 'mssql' => ['40001'], 'pgsql' => ['40P01', '40001'], 'postgres' => ['40P01', '40001']];

    /** @var array<string,list<string>> */
    private const array RETRYABLE_TX_ERROR_CODES = ['mysql' => ['1213', '1205'], 'mariadb' => ['1213', '1205'], 'mssql' => ['1205', '1222'], 'sqlite' => ['5', '6']];

    /** @var array<string,list<string>> */
    private const array RETRYABLE_TX_MESSAGE_HINTS = [
        'mysql' => ['deadlock found when trying to get lock', 'lock wait timeout exceeded', 'serialization failure', 'try restarting transaction'],
        'mariadb' => ['deadlock', 'lock wait timeout exceeded', 'serialization failure'],
        'pgsql' => ['deadlock detected', 'could not serialize access', 'serialization failure'],
        'postgres' => ['deadlock detected', 'could not serialize access', 'serialization failure'],
        'postgresql' => ['deadlock detected', 'could not serialize access', 'serialization failure'],
        'sqlite' => ['database is locked', 'database is busy', 'sqlstate[hy000]: general error: 5', 'sqlstate[hy000]: general error: 6', 'sqlite_busy', 'sqlite_locked'],
        'mssql' => ['deadlock victim', 'deadlocked on lock resources', 'lock request time out period exceeded', 'serialization failure'],
        'default' => ['deadlock', 'serialization failure', 'could not serialize access', 'database is locked', 'database is busy'],
    ];

    /** @var array<string,list<string>> */
    private const array RETRYABLE_TX_SQLSTATES = ['mysql' => ['40001', '41000'], 'mariadb' => ['40001', '41000'], 'mssql' => ['40001'], 'pgsql' => ['40P01', '40001'], 'postgres' => ['40P01', '40001'], 'postgresql' => ['40P01', '40001']];

    private function __construct() {}

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function applyConnectionDefaults(array $config): array
    {
        $driver = $config['driver'] ?? null;
        if (!is_string($driver) || $driver === '') {
            return $config;
        }

        try {
            return DriverRegistry::resolve($driver)->mergeDefaults($config);
        } catch (ConnectionException) {
            return $config;
        }
    }

    public static function causedByDeadlock(string $driver, Throwable $e): bool
    {
        return self::causedByDriverClassifier($driver, $e, self::messageSuggestsDeadlock(...), self::metadataSuggestsDeadlock(...));
    }

    public static function causedByRetryableTransactionError(string $driver, Throwable $e): bool
    {
        return self::causedByDriverClassifier($driver, $e, self::messageSuggestsRetryableTransactionConflict(...), self::metadataSuggestsRetryableTransactionConflict(...));
    }

    public static function createSavepointSql(string $driver, string $savepoint): string
    {
        return strtolower($driver) === 'mssql' ? 'SAVE TRANSACTION ' . $savepoint : 'SAVEPOINT ' . $savepoint;
    }

    public static function releaseSavepointSql(string $driver, string $savepoint): ?string
    {
        return strtolower($driver) === 'mssql' ? null : 'RELEASE SAVEPOINT ' . $savepoint;
    }

    public static function rollbackToSavepointSql(string $driver, string $savepoint): string
    {
        return strtolower($driver) === 'mssql' ? 'ROLLBACK TRANSACTION ' . $savepoint : 'ROLLBACK TO SAVEPOINT ' . $savepoint;
    }

    private static function causedByDriverClassifier(string $driver, Throwable $e, callable $messageClassifier, callable $metadataClassifier): bool
    {
        $driver = strtolower($driver);
        if ($messageClassifier($driver, $e->getMessage())) {
            return true;
        }

        return $e instanceof PDOException && $metadataClassifier($driver, $e);
    }

    /** @param list<string> $knownCodes */
    private static function matchesKnownDeadlockCode(?string $code, array $knownCodes): bool
    {
        return $code !== null && $code !== '' && in_array($code, $knownCodes, true);
    }

    private static function messageSuggestsDeadlock(string $driver, string $message): bool
    {
        $hints = self::DEADLOCK_MESSAGE_HINTS[$driver] ?? self::DEADLOCK_MESSAGE_HINTS['default'];

        return array_any($hints, fn(string $needle): bool => stripos($message, $needle) !== false);
    }

    private static function messageSuggestsRetryableTransactionConflict(string $driver, string $message): bool
    {
        $hints = self::RETRYABLE_TX_MESSAGE_HINTS[$driver] ?? self::RETRYABLE_TX_MESSAGE_HINTS['default'];

        return array_any($hints, fn(string $needle): bool => stripos($message, $needle) !== false);
    }

    /**
     * @param list<string> $states
     * @param list<string> $codes
     */
    private static function metadataMatchesStateAndCodeSets(PDOException $e, array $states, array $codes): bool
    {
        $info = $e->errorInfo;
        $state = is_array($info) && isset($info[0]) ? self::nullableString($info[0]) : null;
        $vendor = is_array($info) && isset($info[1]) ? self::nullableString($info[1]) : null;

        return self::matchesKnownDeadlockCode($state, $states) || self::matchesKnownDeadlockCode($vendor, $codes) || self::matchesKnownDeadlockCode((string) $e->getCode(), $codes);
    }

    private static function metadataSuggestsDeadlock(string $driver, PDOException $e): bool
    {
        return self::metadataMatchesStateAndCodeSets($e, self::DEADLOCK_SQLSTATES[$driver] ?? [], self::DEADLOCK_ERROR_CODES[$driver] ?? []);
    }

    private static function metadataSuggestsRetryableTransactionConflict(string $driver, PDOException $e): bool
    {
        return self::metadataMatchesStateAndCodeSets($e, self::RETRYABLE_TX_SQLSTATES[$driver] ?? [], self::RETRYABLE_TX_ERROR_CODES[$driver] ?? []);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
