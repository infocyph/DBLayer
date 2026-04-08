<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

$configs = [
    'sqlite' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ],
];

$mysql = envConnectionConfig('mysql');
if ($mysql !== null) {
    $configs['mysql'] = $mysql;
}

$pgsql = envConnectionConfig('pgsql');
if ($pgsql !== null) {
    $configs['pgsql'] = $pgsql;
}

foreach ($configs as $driver => $config) {
    DB::purge();
    DB::addConnection($config, 'matrix');
    DB::setDefaultConnection('matrix');

    $table = 'matrix_items_' . $driver;

    DB::statement("drop table if exists {$table}");
    DB::statement(
        "create table {$table} (
            id integer primary key,
            name varchar(100) not null,
            qty integer not null
        )",
    );

    DB::table($table)->insert([
        'id' => 1,
        'name' => 'alpha',
        'qty' => 1,
    ]);

    DB::transaction(static function (Connection $connection) use ($table): void {
        $connection->table($table)->where('id', '=', 1)->update(['qty' => 2]);
    });

    try {
        DB::transaction(static function (Connection $connection) use ($table): void {
            $connection->table($table)->insert([
                'id' => 2,
                'name' => 'rolled-back',
                'qty' => 99,
            ]);

            throw new RuntimeException('rollback me');
        });
    } catch (Throwable) {
        // Expected rollback path for demo.
    }

    $count = (int) DB::table($table)->count();
    $qty = (int) DB::table($table)->where('id', '=', 1)->value('qty');

    echo sprintf("[%s] count=%d qty=%d\n", $driver, $count, $qty);
}

DB::purge();

/**
 * @return array<string,mixed>|null
 */
function envConnectionConfig(string $driver): ?array
{
    if ($driver === 'mysql') {
        $host = envFirst(['DBLAYER_MYSQL_HOST', 'MYSQL_HOST']);
        $database = envFirst(['DBLAYER_MYSQL_DATABASE', 'MYSQL_DATABASE']);
        $username = envFirst(['DBLAYER_MYSQL_USERNAME', 'MYSQL_USERNAME', 'MYSQL_USER']);
        $password = envFirst(['DBLAYER_MYSQL_PASSWORD', 'MYSQL_PASSWORD']) ?? '';

        if ($host === null || $database === null || $username === null) {
            return null;
        }

        return [
            'driver' => 'mysql',
            'host' => $host,
            'port' => (int) (envFirst(['DBLAYER_MYSQL_PORT', 'MYSQL_PORT']) ?? '3306'),
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }

    if ($driver === 'pgsql') {
        $host = envFirst(['DBLAYER_PGSQL_HOST', 'PGSQL_HOST', 'POSTGRES_HOST']);
        $database = envFirst(['DBLAYER_PGSQL_DATABASE', 'PGSQL_DATABASE', 'POSTGRES_DB']);
        $username = envFirst(['DBLAYER_PGSQL_USERNAME', 'PGSQL_USERNAME', 'POSTGRES_USER']);
        $password = envFirst(['DBLAYER_PGSQL_PASSWORD', 'PGSQL_PASSWORD', 'POSTGRES_PASSWORD']) ?? '';

        if ($host === null || $database === null || $username === null) {
            return null;
        }

        return [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => (int) (envFirst(['DBLAYER_PGSQL_PORT', 'PGSQL_PORT', 'POSTGRES_PORT']) ?? '5432'),
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }

    return null;
}

/**
 * @param  list<string>  $keys
 */
function envFirst(array $keys): ?string
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
