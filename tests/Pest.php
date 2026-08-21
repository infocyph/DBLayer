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

/** @return array<string,array<string,mixed>> */
function dblayerTestConnections(): array
{
    static $connections = null;
    if (is_array($connections)) {
        return $connections;
    }
    $connections = ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']];
    foreach ([
        'mysql' => dblayerMysqlConfigFromEnv(),
        'mariadb' => dblayerMariaDbConfigFromEnv(),
        'pgsql' => dblayerPgsqlConfigFromEnv(),
        'mssql' => dblayerMsSqlConfigFromEnv(),
    ] as $name => $config) {
        if ($config !== null && dblayerCanConnect($config)) {
            $connections[$name] = $config;
        }
    }

    return $connections;
}

/** @return list<string> */ function dblayerAvailableDrivers(): array
{
    return array_keys(dblayerTestConnections());
}
/** @param array<string,mixed> $overrides @return array<string,mixed> */
function dblayerAddConnectionForDriver(string $driver, string $name = 'default', array $overrides = []): array
{
    $config = array_replace_recursive(dblayerRequireDriver($driver), $overrides);
    DB::addConnection($config, $name);
    if ($name === 'default') {
        DB::setDefaultConnection($name);
    }

    return $config;
}
function dblayerAutoIncrementPrimaryKey(string $driver, string $column = 'id'): string
{
    return match ($driver) {
        'mysql', 'mariadb' => "{$column} bigint unsigned not null auto_increment primary key", 'pgsql' => "{$column} bigserial primary key", 'mssql' => "{$column} bigint identity(1,1) primary key", default => "{$column} integer primary key autoincrement",
    };
}
function dblayerStringType(string $driver, int $length = 255): string
{
    return match ($driver) {
        'mysql', 'mariadb', 'pgsql' => "varchar({$length})", 'mssql' => "nvarchar({$length})", default => 'text',
    };
}
function dblayerDateTimeType(string $driver): string
{
    return match ($driver) {
        'mysql', 'mariadb' => 'datetime', 'pgsql' => 'timestamp', 'mssql' => 'datetime2', default => 'text',
    };
}
function dblayerTransientDeadlockMessage(string $driver): string
{
    return match ($driver) {
        'mysql' => 'deadlock found when trying to get lock', 'mariadb' => 'deadlock', 'pgsql' => 'deadlock detected', 'mssql' => 'transaction was deadlocked on lock resources and has been chosen as the deadlock victim', default => 'database is locked',
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
/** @return array<string,mixed>|null */ function dblayerConnectionConfig(string $driver): ?array
{
    return dblayerTestConnections()[$driver] ?? null;
}
/** @return array<string,mixed> */
function dblayerRequireDriver(string $driver): array
{
    $config = dblayerConnectionConfig($driver);
    if ($config === null) {
        DB::purge();
        test()->markTestSkipped(sprintf('Driver [%s] is not available in this environment. Configure env vars to enable it.', $driver));

        throw new RuntimeException('Skipped');
    }

    return $config;
}

/** @return array<string,mixed>|null */
function dblayerMysqlConfigFromEnv(): ?array
{
    $dsn = dblayerDsnOptions(dblayerEnvFirst(['IC_MYSQL_DSN']));
    $host = dblayerEnvFirst(['DBLAYER_MYSQL_HOST', 'MYSQL_HOST']) ?? ($dsn['host'] ?? null);
    $database = dblayerEnvFirst(['DBLAYER_MYSQL_DATABASE', 'IC_SERVICE_DATABASE', 'MYSQL_DATABASE']) ?? ($dsn['dbname'] ?? null);
    $username = dblayerEnvFirst(['DBLAYER_MYSQL_USERNAME', 'IC_MYSQL_USER', 'IC_SERVICE_USERNAME', 'MYSQL_USERNAME', 'MYSQL_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MYSQL_PASSWORD', 'IC_MYSQL_PASSWORD', 'IC_SERVICE_PASSWORD', 'MYSQL_PASSWORD']) ?? '';
    if ($host === null || $database === null || $username === null) {
        return null;
    }

    return ['driver' => 'mysql', 'host' => $host, 'port' => (int) (dblayerEnvFirst(['DBLAYER_MYSQL_PORT', 'MYSQL_PORT']) ?? ($dsn['port'] ?? '3306')), 'database' => $database, 'username' => $username, 'password' => $password, 'options' => [PDO::ATTR_TIMEOUT => 1]];
}
/** @return array<string,mixed>|null */
function dblayerMariaDbConfigFromEnv(): ?array
{
    $dsn = dblayerDsnOptions(dblayerEnvFirst(['IC_MARIADB_DSN']));
    $host = dblayerEnvFirst(['DBLAYER_MARIADB_HOST', 'MARIADB_HOST']) ?? ($dsn['host'] ?? null);
    $database = dblayerEnvFirst(['DBLAYER_MARIADB_DATABASE', 'IC_SERVICE_DATABASE', 'MARIADB_DATABASE']) ?? ($dsn['dbname'] ?? null);
    $username = dblayerEnvFirst(['DBLAYER_MARIADB_USERNAME', 'IC_MARIADB_USER', 'IC_SERVICE_USERNAME', 'MARIADB_USERNAME', 'MARIADB_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MARIADB_PASSWORD', 'IC_MARIADB_PASSWORD', 'IC_SERVICE_PASSWORD', 'MARIADB_PASSWORD']) ?? '';
    if ($host === null || $database === null || $username === null) {
        return null;
    }

    return ['driver' => 'mariadb', 'host' => $host, 'port' => (int) (dblayerEnvFirst(['DBLAYER_MARIADB_PORT', 'MARIADB_PORT']) ?? ($dsn['port'] ?? '3306')), 'database' => $database, 'username' => $username, 'password' => $password, 'options' => [PDO::ATTR_TIMEOUT => 1]];
}
/** @return array<string,mixed>|null */
function dblayerPgsqlConfigFromEnv(): ?array
{
    $dsn = dblayerDsnOptions(dblayerEnvFirst(['IC_POSTGRES_DSN']));
    $host = dblayerEnvFirst(['DBLAYER_PGSQL_HOST', 'PGSQL_HOST', 'POSTGRES_HOST']) ?? ($dsn['host'] ?? null);
    $database = dblayerEnvFirst(['DBLAYER_PGSQL_DATABASE', 'IC_SERVICE_DATABASE', 'PGSQL_DATABASE', 'POSTGRES_DB']) ?? ($dsn['dbname'] ?? null);
    $username = dblayerEnvFirst(['DBLAYER_PGSQL_USERNAME', 'IC_POSTGRES_USER', 'IC_SERVICE_USERNAME', 'PGSQL_USERNAME', 'POSTGRES_USER']);
    $password = dblayerEnvFirst(['DBLAYER_PGSQL_PASSWORD', 'IC_POSTGRES_PASSWORD', 'IC_SERVICE_PASSWORD', 'PGSQL_PASSWORD', 'POSTGRES_PASSWORD']) ?? '';
    if ($host === null || $database === null || $username === null) {
        return null;
    }

    return ['driver' => 'pgsql', 'host' => $host, 'port' => (int) (dblayerEnvFirst(['DBLAYER_PGSQL_PORT', 'PGSQL_PORT', 'POSTGRES_PORT']) ?? ($dsn['port'] ?? '5432')), 'database' => $database, 'username' => $username, 'password' => $password, 'options' => [PDO::ATTR_TIMEOUT => 1]];
}
/** @return array<string,mixed>|null */
function dblayerMsSqlConfigFromEnv(): ?array
{
    $dsn = dblayerDsnOptions(dblayerEnvFirst(['IC_MSSQL_DSN']));
    [$dsnHost, $dsnPort] = dblayerMsSqlServer($dsn['server'] ?? null);
    $host = dblayerEnvFirst(['DBLAYER_MSSQL_HOST', 'MSSQL_HOST']) ?? $dsnHost;
    $database = dblayerEnvFirst(['DBLAYER_MSSQL_DATABASE', 'IC_SERVICE_DATABASE', 'MSSQL_DATABASE']) ?? ($dsn['database'] ?? null);
    $username = dblayerEnvFirst(['DBLAYER_MSSQL_USERNAME', 'IC_MSSQL_USER', 'IC_SERVICE_USERNAME', 'MSSQL_USERNAME', 'MSSQL_USER']);
    $password = dblayerEnvFirst(['DBLAYER_MSSQL_PASSWORD', 'IC_MSSQL_PASSWORD', 'IC_SERVICE_PASSWORD', 'MSSQL_PASSWORD']) ?? '';
    if ($host === null || $database === null || $username === null) {
        return null;
    }

    return ['driver' => 'mssql', 'host' => $host, 'port' => (int) (dblayerEnvFirst(['DBLAYER_MSSQL_PORT', 'MSSQL_PORT']) ?? $dsnPort ?? '1433'), 'database' => $database, 'username' => $username, 'password' => $password, 'timeout' => 1, 'encrypt' => filter_var(dblayerEnvFirst(['DBLAYER_MSSQL_ENCRYPT']) ?? ($dsn['encrypt'] ?? 'true'), FILTER_VALIDATE_BOOL), 'trust_server_certificate' => filter_var(dblayerEnvFirst(['DBLAYER_MSSQL_TRUST_SERVER_CERTIFICATE']) ?? ($dsn['trustservercertificate'] ?? 'false'), FILTER_VALIDATE_BOOL)];
}

/** @return array<string,string> */
function dblayerDsnOptions(?string $dsn): array
{
    if ($dsn === null || !str_contains($dsn, ':')) {
        return [];
    }

    [, $settings] = explode(':', $dsn, 2);
    $options = [];
    foreach (explode(';', $settings) as $setting) {
        if (!str_contains($setting, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $setting, 2);
        $key = strtolower(trim($key));
        $value = trim($value);
        if ($key !== '' && $value !== '') {
            $options[$key] = $value;
        }
    }

    return $options;
}

/** @return array{0:?string,1:?string} */
function dblayerMsSqlServer(?string $server): array
{
    if ($server === null || $server === '') {
        return [null, null];
    }

    $server = preg_replace('/\Atcp:/i', '', $server) ?? $server;
    if (!str_contains($server, ',')) {
        return [$server, null];
    }

    [$host, $port] = explode(',', $server, 2);

    return [trim($host), trim($port)];
}

/**
 * @return array<string,array{primary:array<string,mixed>,replica:array<string,mixed>}>
 */
function dblayerReplicationConnections(): array
{
    $connections = [];
    foreach ([
        'mysql' => ['IC_MYSQL_PRIMARY_DSN', 'IC_MYSQL_REPLICA_DSN'],
        'mariadb' => ['IC_MARIADB_PRIMARY_DSN', 'IC_MARIADB_REPLICA_DSN'],
        'pgsql' => ['IC_POSTGRES_PRIMARY_DSN', 'IC_POSTGRES_REPLICA_DSN'],
        'mssql' => ['IC_MSSQL_PRIMARY_DSN', 'IC_MSSQL_REPLICA_DSN'],
    ] as $driver => [$primaryVariable, $replicaVariable]) {
        $primaryDsn = dblayerEnvFirst([$primaryVariable]);
        $replicaDsn = dblayerEnvFirst([$replicaVariable]);
        if ($primaryDsn === null && $replicaDsn === null) {
            continue;
        }
        if ($primaryDsn === null || $replicaDsn === null) {
            throw new RuntimeException(sprintf(
                'Physical replication testing for [%s] requires both %s and %s.',
                $driver,
                $primaryVariable,
                $replicaVariable,
            ));
        }

        $base = dblayerConnectionConfig($driver);
        if ($base === null) {
            throw new RuntimeException(sprintf(
                'Physical replication testing for [%s] cannot connect to its configured primary service.',
                $driver,
            ));
        }

        $primary = dblayerDsnOptions($primaryDsn);
        $replica = dblayerDsnOptions($replicaDsn);

        if ($driver === 'mssql') {
            [$primaryHost, $primaryPort] = dblayerMsSqlServer($primary['server'] ?? null);
            [$replicaHost, $replicaPort] = dblayerMsSqlServer($replica['server'] ?? null);
            $primaryConfig = array_replace($base, [
                'host' => $primaryHost,
                'port' => (int) ($primaryPort ?? 0),
                'database' => $primary['database'] ?? null,
            ]);
            $replicaConfig = array_replace($base, [
                'host' => $replicaHost,
                'port' => (int) ($replicaPort ?? 0),
                'database' => $replica['database'] ?? null,
                'application_intent' => 'ReadOnly',
            ]);
        } else {
            $primaryConfig = array_replace($base, [
                'host' => $primary['host'] ?? null,
                'port' => (int) ($primary['port'] ?? 0),
                'database' => $primary['dbname'] ?? null,
            ]);
            $replicaConfig = array_replace($base, [
                'host' => $replica['host'] ?? null,
                'port' => (int) ($replica['port'] ?? 0),
                'database' => $replica['dbname'] ?? null,
            ]);
        }

        if (!dblayerCanConnect($primaryConfig) || !dblayerCanConnect($replicaConfig)) {
            throw new RuntimeException(sprintf(
                'Physical replication testing for [%s] cannot connect to both configured endpoints.',
                $driver,
            ));
        }

        $connections[$driver] = [
            'primary' => $primaryConfig,
            'replica' => $replicaConfig,
        ];
    }

    return $connections;
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
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'] ?? 3306, $config['database']), (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('select 1');

            return true;
        }
        if ($driver === 'pgsql') {
            $pdo = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=1', $config['host'], $config['port'] ?? 5432, $config['database']), (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->query('select 1');

            return true;
        }
        if ($driver === 'mssql') {
            if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
                return false;
            }
            $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=1', $config['host'], $config['port'] ?? 1433, $config['database'], !empty($config['encrypt']) ? 'yes' : 'no', !empty($config['trust_server_certificate']) ? 'yes' : 'no');
            $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return null;
}

dataset('dblayer_drivers', static fn(): array => dblayerAvailableDrivers());
