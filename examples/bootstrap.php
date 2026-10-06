<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

// Demo credentials are defaults only; supply real credentials through the host.
// Registration validates config but opens no PDO handles or worker pools.
$exampleEnv = static function (string $name, string $fallback): string {
    $value = getenv($name);

    return $value === false ? $fallback : $value;
};

$mysqlConfig = [
    'driver' => 'mysql',
    'host' => $exampleEnv('DBLAYER_MYSQL_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_MYSQL_PORT', '3306'),
    'database' => $exampleEnv('DBLAYER_MYSQL_DATABASE', 'app_db'),
    'username' => $exampleEnv('DBLAYER_MYSQL_USERNAME', 'app_user'),
    'password' => $exampleEnv('DBLAYER_MYSQL_PASSWORD', 'secret'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'timeout' => 5,
    'persistent' => false,
    'statement_cache_enabled' => false,

    // Query limits are connection-owned. Cursor signing needs a stable secret.
    'security' => [
        'enabled' => true,
        'max_sql_length' => 8000,
        'max_params' => 500,
        'max_param_bytes' => 4096,
        // Optional: at least 32 bytes. Keep it stable across every app node.
        'cursor_signing_key' => null,
    ],
];
DB::addConnection($mysqlConfig, 'mysql_main');

$pgsqlConfig = [
    'driver' => 'pgsql',
    'host' => $exampleEnv('DBLAYER_PGSQL_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_PGSQL_PORT', '5432'),
    'database' => $exampleEnv('DBLAYER_PGSQL_DATABASE', 'reporting_db'),
    'username' => $exampleEnv('DBLAYER_PGSQL_USERNAME', 'report_user'),
    'password' => $exampleEnv('DBLAYER_PGSQL_PASSWORD', 'secret'),
    'charset' => 'UTF8',
    'schema' => 'public',
    'timeout' => 5,
    'sslmode' => 'prefer',
];
DB::addConnection($pgsqlConfig, 'pgsql_reporting');

// Local SQLite connection; client/server and TLS keys intentionally do not apply.
DB::addConnection([
    'driver' => 'sqlite',
    'database' => $exampleEnv('DBLAYER_SQLITE_PATH', __DIR__ . '/database.sqlite'),
], 'sqlite_local');

// Direct replica routing selects a handle on connect, not on every query.
// Configure actual replication outside DBLayer; read failures fall back to write.
DB::addConnection(array_replace($mysqlConfig, [
    'sticky' => true,
    'read_strategy' => 'round_robin',
    'read_health_cooldown' => 30,
    'read_latency_ttl' => 15,
    'read_probe_sample_size' => 0,
    'read_session_read_only' => false,
    'write' => [['host' => $mysqlConfig['host']]],
    'read' => [
        ['host' => $exampleEnv('DBLAYER_MYSQL_REPLICA_1_HOST', 'replica1.internal')],
        ['host' => $exampleEnv('DBLAYER_MYSQL_REPLICA_2_HOST', 'replica2.internal')],
    ],
]), 'mysql_split');

// A routing proxy owns backend selection. Single-endpoint profiles omit read/write.
DB::addConnection(array_replace($mysqlConfig, [
    'host' => $exampleEnv('DBLAYER_PROXYSQL_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_PROXYSQL_PORT', '6033'),
]), 'mysql_proxy');

DB::addConnection(array_replace($mysqlConfig, [
    'driver' => 'mariadb',
    'host' => $exampleEnv('DBLAYER_MAXSCALE_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_MAXSCALE_PORT', '4006'),
    'database' => $exampleEnv('DBLAYER_MARIADB_DATABASE', 'app_db'),
    'username' => $exampleEnv('DBLAYER_MARIADB_USERNAME', 'app_user'),
    'password' => $exampleEnv('DBLAYER_MARIADB_PASSWORD', 'secret'),
]), 'mariadb_proxy');

// Session pooling is the conservative PgBouncer example. Transaction pooling
// needs prepared-statement and session-setting validation; disabling the
// statement cache does not disable native PDO prepares. The schema default
// sends startup search_path, so configure the proxy to preserve that parameter.
DB::addConnection(array_replace($pgsqlConfig, [
    'host' => $exampleEnv('DBLAYER_PGBOUNCER_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_PGBOUNCER_PORT', '6432'),
]), 'pgsql_pooler');

DB::addConnection(array_replace($pgsqlConfig, [
    'host' => $exampleEnv('DBLAYER_PGPOOL_HOST', '127.0.0.1'),
    'port' => (int) $exampleEnv('DBLAYER_PGPOOL_PORT', '9999'),
]), 'pgsql_middleware');

// Always On routing requires an existing AG listener and readable secondary.
// timeout becomes LoginTimeout. MultiSubnetFailover is not currently exposed.
// ODBC pooling is configured separately; it is not controlled by persistent.
$listenerHost = $exampleEnv('DBLAYER_MSSQL_HOST', 'ag-listener.internal');
DB::addConnection([
    'driver' => 'mssql',
    'host' => $listenerHost,
    'port' => (int) $exampleEnv('DBLAYER_MSSQL_PORT', '1433'),
    'database' => $exampleEnv('DBLAYER_MSSQL_DATABASE', 'app_db'),
    'username' => $exampleEnv('DBLAYER_MSSQL_USERNAME', 'app_user'),
    'password' => $exampleEnv('DBLAYER_MSSQL_PASSWORD', 'secret'),
    'timeout' => 5,
    'encrypt' => true,
    'trust_server_certificate' => false,
    'application_intent' => 'ReadWrite',
    'sticky' => true,
    'read' => [['host' => $listenerHost]],
], 'mssql_listener');

DB::setDefaultConnection('mysql_main');

// Optional worker-local pool settings; the host creates and warms its pool
// after fork. Limits count Connection objects, each with up to two PDO handles.
// Durations are seconds; zero disables expiry/lifetime/probe checks.
return [
    'min_connections' => 2,
    'max_connections' => 10,
    'idle_timeout' => 60,
    'max_lifetime' => 3600,
    'health_check_interval' => 30,
];
