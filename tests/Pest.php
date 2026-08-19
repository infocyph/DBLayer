<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;

beforeEach(function (): void {
    DB::purge();
});

afterEach(function (): void {
    DB::purge();
});

/**
 * Resolve database test connection configs that are available in this environment.
 *
 * SQLite is always available. Network drivers are included only if env vars are
 * present and a short connectivity check succeeds.
 *
 * @return array<string,array<string,mixed>>
 */
function dblayerTestConnections(): array
{
    static $connections = null;

    if (is_array($connections)) {
        return $connections;
    }

    $connections = [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ],
    ];

    $mysql = dblayerMysqlConfigFromEnv();
    if ($mysql !== null && dblayerCanConnect($mysql)) {
        $connections['mysql'] = $mysql;
    }

    $mariadb = dblayerMariaDbConfigFromEnv();
    if ($mariadb !== null && dblayerCanConnect($mariadb)) {
        $connections['mariadb'] = $mariadb;
    }

    $pgsql = dblayerPgsqlConfigFromEnv();
    if ($pgsql !== null && dblayerCanConnect($pgsql)) {
        $connections['pgsql'] = $pgsql;
    }

    $mssql = dblayerMsSqlConfigFromEnv();
    if ($mssql !== null && dblayerCanConnect($mssql)) {
        $connections['mssql'] = $mssql;
    }

    return $connections;
}

/** @return list<string> */
function dblayerAvailableDrivers(): array
{
    return array_keys(dblayerTestConnections());
}

/**
 * Add a connection for the requested driver and return the effective config.
 *
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function dblayerAddConnectionForDriver(
    string $driver,
    string $name = 'default',
    array $overrides = [],
): array {
    $config = dblayerRequireDriver($driver);
    $config = array_replace_recursive($config, $overrides);

    DB::addConnection($config, $name);
    if ($name === 'default') {
        DB::setDefaultConnection($name);
    }

    return $config;
}

function dblayerAutoIncrementPrimaryKey(string $driver, string $column = 'id'): string
{
    return match ($driver) {
        'mysql', 'mariadb' => "{$column} bigint unsigned not null auto_increment primary key",
        'pgsql' => "{$column} bigserial primary key",
        'mssql' => "{$column} bigint identity(1,1) primary key",
        default => "{$column} integer primary key autoincrement",
    };
}

function dblayerStringType(string $driver, int $length = 255): string
{
    return match ($driver) {
        'mysql', 'mariadb', 'pgsql' => "varchar({$length})",
        'mssql' => "nvarchar({$length})",
        default => 'text',
    };
}

function dblayerDateTimeType(string $driver): string
{
    return match ($driver) {
        'mysql', 'mariadb' => 'datetime',
        'pgsql' => 'timestamp',
        'mssql' => 'datetime2',
        default => 'text',
    };
}

function dblayerTransientDeadlockMessage(string $driver): string
{
    return match ($driver) {
        'mysql' => 'deadlock found when trying to get lock',
        'mariadb' => 'deadlock',
        'pgsql' => 'deadlock detected',
        'mssql' => 'transaction was deadlocked on lock resources and has been chosen as the deadlock victim',
        default => 'database is locked',
    };
}

function dblayerConnectionDriver(?string $connection = null): string
{
    return DB::connection($connection)->getDriverName();
}

function dblayerTable(string $prefix): string
{
    return strtolower($prefix . '_' . bin2hex(random_bytes(4)));
}

function dblayerDropTable(string $table, ?string $connection = null): void
{
    DB::statement(sprintf('drop table if exists %s', $table), [], $connection);
}

/** @return array<string,mixed>|null */
function dblayerConnectionConfig(string $driver): ?array
{
    $connections = dblayerTestConnections();

    return $connections[$driver] ?? null;
}

/** @return array<string,mixed> */
function dblayerRequireDriver(string $driver): array
{
    $config = dblayerConnectionConfig($driver);

    if ($config === null) {
        DB::purge();
        test()->markTestSkipped(sprintf(
            'Driver [%s] is not available in this environment. Configure env vars to enable it.',
            $driver,
        ));

        throw new RuntimeException('Skipped');
    }

    return $config;
}

/** @return array<string,mixed>|null */
function dblayerMysqlConfigFromEnv(): ?array
{
    $host = dblayerEnvFirst(['DBLAYER_MYSQL_HOST', 'MYSQL_HOST']);
    $database = dblayerEnvFirst(['DBLAYER_MYSQL_DATABASE', 'IC_SERVICE_DATABASE', 'MYSQL_DATABASE']);
    $username = dblayerEnvFirst(['DBLAYER_MYSQL_USERNAME', 'IC_SERVICE_USERNAME', 'MYSQL_USERNAME', 'MYSQL_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MYSQL_PASSWORD', 'IC_SERVICE_PASSWORD', 'MYSQL_PASSWORD']) ?? '';

    if ($host === null || $database === null || $username === null) {
        return null;
    }

    $port = (int) (dblayerEnvFirst(['DBLAYER_MYSQL_PORT', 'MYSQL_PORT']) ?? '3306');

    return [
        'driver' => 'mysql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'timeout' => 1,
    ];
}

/** @return array<string,mixed>|null */
function dblayerMariaDbConfigFromEnv(): ?array
{
    $host = dblayerEnvFirst(['DBLAYER_MARIADB_HOST', 'MARIADB_HOST']);
    $database = dblayerEnvFirst(['DBLAYER_MARIADB_DATABASE', 'MARIADB_DATABASE']);
    $username = dblayerEnvFirst(['DBLAYER_MARIADB_USERNAME', 'MARIADB_USERNAME', 'MARIADB_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MARIADB_PASSWORD', 'MARIADB_PASSWORD']) ?? '';

    if ($host === null || $database === null || $username === null) {
        return null;
    }

    $port = (int) (dblayerEnvFirst(['DBLAYER_MARIADB_PORT', 'MARIADB_PORT']) ?? '3306');

    return [
        'driver' => 'mariadb',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'timeout' => 1,
    ];
}

/** @return array<string,mixed>|null */
function dblayerPgsqlConfigFromEnv(): ?array
{
    $host = dblayerEnvFirst(['DBLAYER_PGSQL_HOST', 'PGSQL_HOST', 'POSTGRES_HOST']);
    $database = dblayerEnvFirst(['DBLAYER_PGSQL_DATABASE', 'IC_SERVICE_DATABASE', 'PGSQL_DATABASE', 'POSTGRES_DB']);
    $username = dblayerEnvFirst(['DBLAYER_PGSQL_USERNAME', 'IC_SERVICE_USERNAME', 'PGSQL_USERNAME', 'POSTGRES_USER']);
    $password = dblayerEnvFirst([
        'DBLAYER_PGSQL_PASSWORD',
        'IC_SERVICE_PASSWORD',
        'PGSQL_PASSWORD',
        'POSTGRES_PASSWORD',
    ]) ?? '';

    if ($host === null || $database === null || $username === null) {
        return null;
    }

    $port = (int) (dblayerEnvFirst(['DBLAYER_PGSQL_PORT', 'PGSQL_PORT', 'POSTGRES_PORT']) ?? '5432');

    return [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'timeout' => 1,
    ];
}

/** @return array<string,mixed>|null */
function dblayerMsSqlConfigFromEnv(): ?array
{
    $host = dblayerEnvFirst(['DBLAYER_MSSQL_HOST', 'MSSQL_HOST']);
    $database = dblayerEnvFirst(['DBLAYER_MSSQL_DATABASE', 'MSSQL_DATABASE']);
    $username = dblayerEnvFirst(['DBLAYER_MSSQL_USERNAME', 'MSSQL_USERNAME', 'MSSQL_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MSSQL_PASSWORD', 'MSSQL_PASSWORD']) ?? '';

    if ($host === null || $database === null || $username === null) {
        return null;
    }

    $port = (int) (dblayerEnvFirst(['DBLAYER_MSSQL_PORT', 'MSSQL_PORT']) ?? '1433');

    return [
        'driver' => 'mssql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'timeout' => 1,
        'encrypt' => filter_var(dblayerEnvFirst(['DBLAYER_MSSQL_ENCRYPT']) ?? 'true', FILTER_VALIDATE_BOOL),
        'trust_server_certificate' => filter_var(
            dblayerEnvFirst(['DBLAYER_MSSQL_TRUST_SERVER_CERTIFICATE']) ?? 'false',
            FILTER_VALIDATE_BOOL,
        ),
    ];
}

/** @param array<string,mixed> $config */
function dblayerCanConnect(array $config): bool
{
    $driver = (string) ($config['driver'] ?? '');

    if ($driver === 'sqlite') {
        return true;
    }

    try {
        ConnectionConfig::fromArray($config);
    } catch (Throwable) {
        return false;
    }

    try {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 3306),
                (string) ($config['database'] ?? ''),
            );

            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_TIMEOUT => 1,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            );
            $pdo->query('select 1');

            return true;
        }

        if ($driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=1',
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 5432),
                (string) ($config['database'] ?? ''),
            );

            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->query('select 1');

            return true;
        }

        if ($driver === 'mssql') {
            if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
                return false;
            }

            $dsn = sprintf(
                'sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=1',
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 1433),
                (string) ($config['database'] ?? ''),
                !empty($config['encrypt']) ? 'yes' : 'no',
                !empty($config['trust_server_certificate']) ? 'yes' : 'no',
            );

            $pdo = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo->query('select 1');

            return true;
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

/** @param list<string> $keys */
function dblayerEnvFirst(array $keys): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value === false) {
            continue;
        }

        $trimmed = trim((string) $value);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return null;
}

dataset('dblayer_drivers', static fn(): array => dblayerAvailableDrivers());
