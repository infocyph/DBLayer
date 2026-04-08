<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

DB::purge();

// SQLite
DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
], 'sqlite_conn');

// MySQL grammar demo (connection is not opened while generating SQL)
DB::addConnection([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'app_db',
    'username' => 'app_user',
    'password' => 'secret',
], 'mysql_conn');

// PostgreSQL grammar demo (connection is not opened while generating SQL)
DB::addConnection([
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'database' => 'reporting_db',
    'username' => 'report_user',
    'password' => 'secret',
], 'pgsql_conn');

echo "SQLite lockForUpdate SQL:\n";
echo DB::table('users', 'sqlite_conn')->lockForUpdate()->toSql() . PHP_EOL;

echo "MySQL lockForUpdate SQL:\n";
echo DB::table('users', 'mysql_conn')->lockForUpdate()->toSql() . PHP_EOL;

echo "PostgreSQL sharedLock SQL:\n";
echo DB::table('users', 'pgsql_conn')->sharedLock()->toSql() . PHP_EOL;
